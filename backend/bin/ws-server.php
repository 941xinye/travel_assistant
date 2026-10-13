<?php

/**
 * 全栈智能出行助手 — 独立原生 Swoole WebSocket Server（ARCHITECTURE.md 模块 4 / 7.1）。
 *
 * 进程边界（勿破坏）：
 * - 本进程不加载 Laravel，禁用 Redis::/DB::/now() 等 facade
 * - 职责仅限：连接管理 + 司机位置实时管道 + 行程订阅广播
 * - 持久化走 Redis Streams（driver:location:stream）→ Octane 侧 positions:consume 消费
 * - 广播走 Redis Pub/Sub（ws:trip:{trip_id}）：HTTP Worker 的司机 HTTP 上报与本进程互通
 *   → worker_num > 1 时连接分布在不同 worker，直推 fd 会丢，必须经 Pub/Sub 扇出（含本 worker 自身）
 *
 * 鉴权：JWT 校验（Step 1 新增）
 *   - 连接 URL 带 ?token={jwt} 参数
 *   - JWT payload: sub=user_id, role=driver|passenger, driver_id（司机才有）
 *   - 未带 token 或无效 → 立即 close fd
 *   - 纯原生 firebase/php-jwt，不走 Laravel Auth
 *
 * 运行：php bin/ws-server.php（需 swoole 扩展；本地 Windows 无 swoole → docker compose up ws）
 */

// —— 原生 Swoole 环境下手动加载 firebase/php-jwt autoload ——
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',      // composer install 后
    __DIR__ . '/../../vendor/autoload.php',   // docker 容器内挂载路径
];
foreach ($autoloadPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key as FirebaseKey;
use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

$port = (int) (getenv('WS_PORT') ?: 8081);
$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);

// —— JWT 密钥读取（.env 文件 fallback，原生环境下 dotenv 未加载）——
function getJwtSecret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;

    $secret = getenv('JWT_SECRET') ?: '';
    if ($secret === '') {
        // 手动解析 .env
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            foreach (file($envFile) as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '#') && str_starts_with($line, 'JWT_SECRET=')) {
                    $secret = trim(substr($line, 11));
                    break;
                }
            }
        }
    }

    return $secret;
}

/**
 * 解析 URL query token 参数（连接 URL: ws://host:8081?token=xxx）。
 * 失败时返回 null。
 */
function parseJwtFromUrl(Request $request): ?array
{
    $query = $request->get ?? [];
    $token = $query['token'] ?? null;
    if (! $token) return null;

    try {
        $secret = getJwtSecret();
        if ($secret === '') {
            fwrite(STDERR, '[ws] JWT_SECRET not configured, auth disabled\n');
            return ['role' => 'anonymous']; // 开发期兜底：无密钥时放行所有连接
        }
        return (array) FirebaseJWT::decode($token, new FirebaseKey($secret, 'HS256'));
    } catch (\Throwable $e) {
        fwrite(STDERR, '[ws] JWT verify failed: ' . $e->getMessage() . "\n");
        return null;
    }
}

// —— Server 启动 ——
$server = new Server('0.0.0.0', $port);

// fd → {trip_id, driver_id, role, user_id}，进程内订阅表（跨事件共享）
$fdMap = new Table(1024);
$fdMap->column('trip_id', Table::TYPE_INT, 8);
$fdMap->column('driver_id', Table::TYPE_INT, 8);
$fdMap->column('user_id', Table::TYPE_INT, 8);
$fdMap->column('role', Table::TYPE_STRING, 16);
$fdMap->create();

$server->on('workerStart', function (Server $server, int $workerId) use ($fdMap, $redisHost, $redisPort) {
    // 每个 worker 一个常驻订阅协程：HTTP 侧 / 其他 worker publish 的消息扇出给本 worker 内订阅中的 fd
    go(function () use ($server, $fdMap, $redisHost, $redisPort) {
        $redis = new Swoole\Coroutine\Redis();
        if (! $redis->connect($redisHost, $redisPort)) {
            fwrite(STDERR, "[ws] pubsub redis connect failed ({$redisHost}:{$redisPort})\n");
            return;
        }

        $redis->pSubscribe(['ws:trip:*'], function ($redis, $pattern, $channel, $msg) use ($server, $fdMap) {
            // channel = ws:trip:{trip_id}
            $tripId = (int) substr($channel, strlen('ws:trip:'));

            foreach ($fdMap as $fd => $row) {
                if ((int) $row['trip_id'] === $tripId && $server->isEstablished((int) $fd)) {
                    $server->push((int) $fd, $msg);
                }
            }
        });
    });
});

$server->on('open', function (Server $server, Request $request) use ($fdMap) {
    // Step 1 JWT 鉴权（纯原生，不依赖 Laravel Auth）
    $payload = parseJwtFromUrl($request);

    if ($payload === null) {
        fwrite(STDERR, "[ws] fd={$request->fd} rejected: invalid JWT\n");
        $server->close($request->fd);
        return;
    }

    // 开发期兜底：anonymous role 放行（无 JWT_SECRET 配置时）
    $role = $payload['role'] ?? 'anonymous';

    // 初始化 fdMap（trip_id 等具体订阅在 passenger:subscribe / driver:login 消息后写入）
    $fdMap->set($request->fd, [
        'trip_id'   => 0,
        'driver_id' => (int) ($payload['driver_id'] ?? 0),
        'user_id'   => (int) ($payload['sub'] ?? 0),
        'role'      => $role,
    ]);

    echo "[ws] fd={$request->fd} connected (role={$role}, user_id=" . ($payload['sub'] ?? '?') . ")\n";
});

$server->on('message', function (Server $server, Frame $frame) use ($fdMap, $redisHost, $redisPort) {
    $data = json_decode($frame->data, true);
    if (! is_array($data) || ! isset($data['type'])) {
        return;
    }

    switch ($data['type']) {
        case 'driver:login': // C→S 连接后上报身份（6.3）
            $fdMap->set($frame->fd, [
                'trip_id'   => 0,
                'driver_id' => (int) ($data['driver_id'] ?? 0),
                'user_id'   => (int) ($fdMap->get($frame->fd, 'user_id')),
                'role'      => 'driver',
            ]);
            break;

        case 'passenger:subscribe': // C→S 订阅行程实时频道（6.3）
            $tripId = (int) ($data['trip_id'] ?? 0);
            if ($tripId > 0) {
                $old = $fdMap->get($frame->fd);
                $fdMap->set($frame->fd, [
                    'trip_id'   => $tripId,
                    'driver_id' => (int) ($old['driver_id'] ?? 0),
                    'user_id'   => (int) ($old['user_id'] ?? 0),
                    'role'      => 'passenger',
                ]);
                $server->push($frame->fd, json_encode(['event' => 'subscribed', 'data' => ['trip_id' => $tripId]]));
            }
            break;

        case 'driver:location': // C→S 每秒上报位置（6.3）→ 三步管道，不直推 fd（统一经 Pub/Sub 扇出）
            // 司机身份从 fdMap 取（open 事件已由 JWT 注入；driver:login 消息可覆盖 driver_id）
            $fdDriverId = (int) $fdMap->get($frame->fd, 'driver_id');
            $driverId = $fdDriverId > 0 ? $fdDriverId : (int) ($data['driver_id'] ?? 0);

            if ($driverId <= 0 || ! isset($data['lng'], $data['lat'])) {
                return;
            }

            $pos = [
                'driver_id' => $driverId,
                'trip_id'   => (int) ($data['trip_id'] ?? 0),
                'lng'       => round((float) $data['lng'], 6),
                'lat'       => round((float) $data['lat'], 6),
                'speed'     => isset($data['speed']) ? (float) $data['speed'] : null,
                'heading'   => isset($data['heading']) ? (int) $data['heading'] : null,
                'ts'        => (int) ($data['ts'] ?? time()),
            ];

            // onMessage 是并发协程，共享连接会串包 → 每次短连接
            $redis = new Swoole\Coroutine\Redis();
            if (! $redis->connect($redisHost, $redisPort)) {
                fwrite(STDERR, "[ws] redis connect failed on driver:location\n");
                return;
            }

            $position = json_encode($pos);
            // 1) 实时位置（60s TTL，匹配初筛读取）
            $redis->setex("driver:position:{$driverId}", 60, $position);
            // 1b) GEO 集合跟随司机移动更新（Matcher::findNearby 实时搜索依赖）
            $redis->geoAdd('drivers:geo', $pos['lng'], $pos['lat'], (string) $driverId);
            // 2) Streams 管道 → Octane 侧 positions:consume 持久化（MySQL + PostGIS）
            $redis->lPush('driver:location:stream', $position);
            // 3) Pub/Sub → 所有 WS worker 扇出给订阅乘客
            if ($pos['trip_id'] > 0) {
                $redis->publish("ws:trip:{$pos['trip_id']}", json_encode(['event' => 'driver:location-update', 'data' => $pos]));
            }
            $redis->close();

            $server->push($frame->fd, json_encode(['event' => 'ack', 'data' => ['type' => 'driver:location']]));
            break;

        default:
            // trip:assigned / trip:route-update 等 S→C 事件由 HTTP Worker publish，本进程只负责转发
            break;
    }
});

$server->on('close', function (Server $server, int $fd) use ($fdMap) {
    $fdMap->del($fd);
});

// —— Step 4：Swoole Timer tick（10s）— 异常检测（心跳缺失 + 路线偏离）——
// 纯原生，直接 PSR-4 加载 AnomalyDetector（vendor/autoload.php 已 require）
// 分布式锁 tick:anomaly:lock NX EX 8 防止多 worker 重复跑
//
// ⚠️ 当前改道流程为**半自动**：Timer tick publish trip:anomaly → 前端收到后调 POST /api/trips/{id}/reroute → TripController 触发 RoutePlanner 重算
//    完整自动改道（Timer tick 直接触发 reroute 无需前端 round-trip）需：
//    - 要么让 ws-server.php 直接 reroute（需纯 PHP 版 RoutePlanner，不走 Laravel Http facade）
//    - 要么 Laravel Queue consumer 订阅 ws:trip:{id} 频道触发
//    → Step 5+ tech debt
$server->on('start', function () use ($redisHost, $redisPort) {
    Swoole\Timer::tick(10000, function () use ($redisHost, $redisPort) {
        go(function () use ($redisHost, $redisPort) {
            $redis = new Swoole\Coroutine\Redis();
            if (! $redis->connect($redisHost, $redisPort)) {
                return;
            }

            // 分布式锁：8s TTL（tick 10s 一次，锁刚好在下次触发前过期）
            if (! $redis->set('tick:anomaly:lock', (string) getmypid(), 'EX', 8, 'NX')) {
                $redis->close();
                return; // 其他 worker 在跑本轮
            }

            $keys = $redis->keys('trip:active:*');
            if ($keys === []) {
                $redis->close();
                return;
            }

            // PSR-4 自动加载（vendor/autoload.php 已 require）
            $detector = new \App\Services\Geo\AnomalyDetector();
            $now = time();

            foreach ($keys as $key) {
                $tripId = (int) substr($key, strlen('trip:active:'));
                $driverId = (int) $redis->get($key);

                $posRaw = $redis->get("driver:position:{$driverId}");
                $pos = $posRaw ? json_decode($posRaw, true) : null;

                // 1) 心跳缺失
                $hb = $detector->detectMissingHeartbeat($pos, $now);
                if ($hb['deviated']) {
                    $redis->publish("ws:trip:{$tripId}", json_encode([
                        'event' => 'trip:anomaly',
                        'data'  => ['trip_id' => $tripId, 'driver_id' => $driverId] + $hb,
                    ]));
                    continue; // 心跳缺失时偏离检测也无意义
                }

                // 2) 路线偏离
                $routeRaw = $redis->get("trip:route:{$tripId}");
                if ($routeRaw) {
                    $routePlan = json_decode($routeRaw, true);
                    $dev = $detector->detect((float) $pos['lng'], (float) $pos['lat'], $routePlan);
                    if ($dev['deviated']) {
                        $redis->publish("ws:trip:{$tripId}", json_encode([
                            'event' => 'trip:anomaly',
                            'data'  => ['trip_id' => $tripId, 'driver_id' => $driverId] + $dev,
                        ]));
                    }
                }
            }

            $redis->close();
        });
    });
});

echo "[ws] Swoole WebSocket Server listening on 0.0.0.0:{$port}\n";
$server->start();
