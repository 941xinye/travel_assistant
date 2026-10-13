<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Services\Driver\Matcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * 行程编排器 — 状态机唯一入口（勿在 Controller 直接改 status）。
 *
 * 职责：
 *  1. 创建 Trip（乘客确认路线后落库）
 *  2. 状态流转 + 合法性校验（锁保护 race condition）
 *  3. 司机匹配（Matcher 当前 Redis GEO；Phase 4 补 PostGIS + AI 评分）
 *  4. 每次状态变更 → Redis Pub/Sub ws:trip:{id} → WS Server 扇出乘客端
 *  5. 行程完成 → 创建 Order + 估算费用（Step 5 前占位）
 *
 * 状态流转表（ARCHITECTURE.md 3.2 数据流图）：
 *   pending → matched → driver_en_route → on_trip → completed
 *   * (非终态) → cancelled
 */
class TripOrchestrator
{
    /**
     * 合法状态流转 from → to。
     * cancel 是通配：任意非终态可取消。
     */
    private const TRANSITIONS = [
        Trip::STATUS_PENDING          => [Trip::STATUS_MATCHED, Trip::STATUS_CANCELLED],
        Trip::STATUS_MATCHED          => [Trip::STATUS_DRIVER_EN_ROUTE, Trip::STATUS_CANCELLED],
        Trip::STATUS_DRIVER_EN_ROUTE  => [Trip::STATUS_ON_TRIP, Trip::STATUS_CANCELLED],
        Trip::STATUS_ON_TRIP          => [Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED],
    ];

    /** 匹配原子性 Redis key：trip 被某 driver 锁住时 set NX EX 30 */
    private const MATCH_LOCK_PREFIX = 'trip:match:lock:';

    public function __construct(private readonly Matcher $matcher)
    {
    }

    /**
     * Step 2-1：创建行程（乘客确认路线方案后调用）。
     *
     * @param  User   $user
     * @param  array  $planResult RoutePlanner::buildComparison 结果
     * @param  string $selectedRouteId 用户选中的方案 id
     */
    public function createTrip(User $user, array $planResult, string $selectedRouteId): Trip
    {
        $origin = $planResult['origin'];
        $dest = $planResult['destination'];
        $routes = $planResult['routes'] ?? [];
        $selected = collect($routes)->firstWhere('id', $selectedRouteId);

        if (! $selected) {
            throw new RuntimeException('选中的路线方案不存在', 422);
        }

        // 粗略费用估算（Step 5 完善，先给个可用的 cost_snapshot）
        $costSnapshot = $this->estimateCost($selected);

        $trip = Trip::create([
            'user_id'            => $user->id,
            'origin_text'        => $origin['text'] ?? '',
            'origin_lng'         => $this->parseCoord($origin['location'] ?? ''),
            'origin_lat'         => $this->parseCoordLat($origin['location'] ?? ''),
            'destination_text'   => $dest['text'] ?? '',
            'destination_lng'   => $this->parseCoord($dest['location'] ?? ''),
            'destination_lat'   => $this->parseCoordLat($dest['location'] ?? ''),
            'status'             => Trip::STATUS_PENDING,
            'route_plan'         => $selected,
            'cost_snapshot'      => $costSnapshot,
        ]);

        return $trip;
    }

    /** 每个匹配到司机的 Redis NX 原子锁 key（防止同一司机被多个 trip 并发匹配） */
    private const DRIVER_MATCH_LOCK = 'driver:match:lock:';

    /** 每个 trip 被接受时的 Redis 锁 key（accept() race protection 已有） */
    private const TRIP_ACCEPT_LOCK = 'trip:match:lock:';

    /** Trip 活跃键：driver_id，Timer tick 扫描入口（Step 4 新增） */
    private const TRIP_ACTIVE = 'trip:active:';

    /** Trip 路线缓存：route_plan JSON，Timer tick 检测偏离用（Step 4 新增） */
    private const TRIP_ROUTE = 'trip:route:';

    /** Timer tick 每 10s 跑一次，60s 后 route_plan 早就没用了 → 7200s 足够覆盖整个行程 */
    private const ACTIVE_TTL = 7200;

    /**
     * Step 2-2：触发司机匹配（matched 状态入口）。
     *
     * 并发安全：遍历就近候选 + Redis NX 原子锁，确保一个司机同一时刻只分配给一个 trip。
     * 锁 30s 自动过期，若司机拒单/超时会自动释放。
     */
    public function match(Trip $trip): Trip
    {
        if ($trip->isTerminal()) {
            throw new RuntimeException('行程已终态，无法匹配', 422);
        }
        if ($trip->driver_id !== null) {
            throw new RuntimeException('行程已匹配司机', 422);
        }

        // 就近候选 → AI 评分排序（score 越高越先尝试 NX SET）
        $candidates = $this->matcher->findNearby(
            (float) $trip->origin_lng,
            (float) $trip->origin_lat
        );

        if ($candidates === []) {
            Log::info('trip match: no nearby idle driver', ['trip_id' => $trip->id]);
            return $trip;
        }

        // AI 评分排序（score 降序），最优优先声明 NX 锁
        $scored = $this->matcher->score(
            $candidates,
            (float) $trip->origin_lng,
            (float) $trip->origin_lat
        );

        // 遍历评分排序后的候选，原子声明第一个空闲的
        $driverId = null;
        foreach ($scored as $item) {
            $candidateId = $item['driver_id'];
            $lockKey = self::DRIVER_MATCH_LOCK . $candidateId;
            if (Redis::set($lockKey, (string) $trip->id, 'EX', 30, 'NX')) {
                $driverId = (int) $candidateId;
                break;
            }
        }

        if ($driverId === null) {
            Log::info('trip match: all nearby drivers locked', ['trip_id' => $trip->id]);
            return $trip;
        }

        // 流转到 matched
        $this->applyTransition($trip, Trip::STATUS_MATCHED, [
            'driver_id' => $driverId,
        ]);

        // 司机先置为 online_busy（已被派单）
        $driver = Driver::find($driverId);
        if ($driver && $driver->status === 'online_idle') {
            $driver->update(['status' => 'online_busy']);
        }

        // 推派单事件
        $this->publish($trip->id, 'trip:assigned', [
            'trip_id'    => $trip->id,
            'origin'     => ['text' => $trip->origin_text, 'lng' => $trip->origin_lng, 'lat' => $trip->origin_lat],
            'destination'=> ['text' => $trip->destination_text, 'lng' => $trip->destination_lng, 'lat' => $trip->destination_lat],
            'fare_preview' => $trip->cost_snapshot['total'] ?? 0,
        ]);

        // Step 4：写 Trip 活跃 + 路线缓存（Timer tick 扫描入口）
        Redis::setex(self::TRIP_ACTIVE . $trip->id, self::ACTIVE_TTL, (string) $driverId);
        Redis::setex(self::TRIP_ROUTE . $trip->id, self::ACTIVE_TTL, json_encode($trip->route_plan, JSON_UNESCAPED_UNICODE));

        return $trip->fresh();
    }

    /**
     * Step 2-3：司机接单（accept）— race condition 保护。
     *
     * 多司机可能同时收到派单通知，用 DB lockForUpdate + 匹配原子性 Redis 锁双重防护。
     */
    public function accept(Trip $trip, Driver $driver): Trip
    {
        // Fast pre-check：Redis 原子锁（30s 超时）
        $lockKey = self::MATCH_LOCK_PREFIX . $trip->id;
        if (! Redis::set($lockKey, (string) $driver->id, 'EX', 30, 'NX')) {
            throw new RuntimeException('行程已被其他司机接单', 409);
        }

        try {
            // 数据库事务 + 行锁
            return DB::transaction(function () use ($trip, $driver) {
                $fresh = Trip::where('id', $trip->id)->lockForUpdate()->first();
                if (! $fresh) {
                    throw new RuntimeException('行程不存在', 404);
                }

                // 匹配条件：trip 是 matched 且 driver_id 等于当前司机
                if ($fresh->status !== Trip::STATUS_MATCHED || $fresh->driver_id !== $driver->id) {
                    // 解锁 Redis
                    Redis::del(self::MATCH_LOCK_PREFIX . $trip->id);
                    throw new RuntimeException('行程不在可接单状态或已被其他司机锁定', 409);
                }

                $this->applyTransition($fresh, Trip::STATUS_DRIVER_EN_ROUTE, [
                    'actual_pickup_time' => null, // 由司机到达接客点后 arrive() 写入
                ]);

                return $fresh;
            });
        } catch (\Throwable $e) {
            Redis::del(self::MATCH_LOCK_PREFIX . $trip->id);
            throw $e;
        }
    }

    /**
     * Step 2-4：司机到达接客点（状态不变 driver_en_route，但写入 actual_pickup_time）。
     */
    public function arrive(Trip $trip, Driver $driver): Trip
    {
        $this->assertDriverMatches($trip, $driver);

        if ($trip->status !== Trip::STATUS_DRIVER_EN_ROUTE) {
            throw new RuntimeException('司机仅能在前往接客途中标记到达', 422);
        }

        $trip->update(['actual_pickup_time' => now()]);

        $this->publish($trip->id, 'trip:driver-arrived', [
            'trip_id' => $trip->id,
            'eta_min' => 0,
        ]);

        return $trip->fresh();
    }

    /**
     * Step 2-5：开始行程（driver_en_route → on_trip）。
     */
    public function start(Trip $trip, Driver $driver): Trip
    {
        $this->assertDriverMatches($trip, $driver);
        $this->applyTransition($trip, Trip::STATUS_ON_TRIP);

        $this->publish($trip->id, 'trip:started', [
            'trip_id' => $trip->id,
        ]);

        return $trip->fresh();
    }

    /**
     * Step 2-6：完成行程（on_trip → completed + 创建 Order）。
     * Step 5 闭环：事务外 Try/Catch TripSummarizer + dispatch PersistTripEmbeddingJob。
     */
    public function complete(Trip $trip, Driver $driver): Trip
    {
        $this->assertDriverMatches($trip, $driver);

        $completedTrip = DB::transaction(function () use ($trip, $driver) {
            $this->applyTransition($trip, Trip::STATUS_COMPLETED, [
                'actual_dropoff_time' => now(),
                'driver_id'           => $driver->id,
            ]);

            // 司机回到 online_idle（继续接单）
            $driver->update(['status' => 'online_idle']);

            // 创建 Order（Step 5 费用精算公式已在 estimateCost 升级）
            $snapshot = $trip->cost_snapshot ?? [];
            Order::create([
                'trip_id'         => $trip->id,
                'driver_id'       => $driver->id,
                'user_id'         => $trip->user_id,
                'amount'          => $snapshot['total'] ?? 0,
                'cost_breakdown'  => $snapshot,
                'payment_status'  => 'pending',
            ]);

            // 释放司机匹配 NX 锁
            if ($trip->driver_id) {
                Redis::del(self::DRIVER_MATCH_LOCK . $trip->driver_id);
            }

            // Step 4：清理 Trip 活跃键
            Redis::del(self::TRIP_ACTIVE . $trip->id);
            Redis::del(self::TRIP_ROUTE . $trip->id);

            $this->publish($trip->id, 'trip:completed', [
                'trip_id'   => $trip->id,
                'order_id'  => $trip->order?->id,
                'amount'    => $trip->cost_snapshot['total'] ?? 0,
            ]);

            return $trip->fresh();
        });

        // —— Step 5 闭环（事务外：HTTP 调用不能阻塞事务提交）——

        // 1) TripSummarizer 生成 AI 出行日记（LLM 有 key 就跑；没 key 静默跳过）
        try {
            $summarizer = app(\App\Services\AI\TripSummarizer::class);
            $summary = $summarizer->summarize([
                'origin'      => $completedTrip->origin_text,
                'destination' => $completedTrip->destination_text,
                'distance_m'  => $completedTrip->route_plan['distance_m'] ?? 0,
                'duration_s'  => $completedTrip->route_plan['duration_s'] ?? 0,
                'tolls'       => $completedTrip->route_plan['tolls'] ?? 0,
            ]);
            $completedTrip->update(['summary_text' => $summary]);
        } catch (\Throwable $e) {
            Log::warning('trip summarizer skipped (LLM unavailable): '.$e->getMessage(), ['trip_id' => $completedTrip->id]);
        }

        // 2) 异步向量化（Job 内部 handle() 已 try/catch pgvector fallback）
        $content = $completedTrip->summary_text ?? ($completedTrip->route_plan['label'] ?? '');
        \App\Jobs\PersistTripEmbeddingJob::dispatch($completedTrip->user_id, $completedTrip->id, $content);

        return $completedTrip->fresh();
    }

    /**
     * 乘客取消行程（任意非终态）。
     */
    public function cancel(Trip $trip): Trip
    {
        if ($trip->isTerminal()) {
            throw new RuntimeException('行程已终态，不可取消', 422);
        }

        $this->applyTransition($trip, Trip::STATUS_CANCELLED);

        // 若司机已被分配，释放其状态 + 匹配锁
        if ($trip->driver_id) {
            $driver = Driver::find($trip->driver_id);
            if ($driver && $driver->status === 'online_busy') {
                $driver->update(['status' => 'online_idle']);
            }
            Redis::del(self::DRIVER_MATCH_LOCK . $trip->driver_id);
        }

        Redis::del(self::TRIP_ACCEPT_LOCK . $trip->id); // accept 阶段的锁也一并清

        // Step 4：清理 Trip 活跃键（Timer tick 不再扫描）
        Redis::del(self::TRIP_ACTIVE . $trip->id);
        Redis::del(self::TRIP_ROUTE . $trip->id);

        $this->publish($trip->id, 'trip:cancelled', [
            'trip_id' => $trip->id,
        ]);

        return $trip->fresh();
    }

    // ==================== Step 4：异常检测 / 改道 ====================

    /**
     * 路线重算：更新 route_plan + 刷新 Redis trip:route 缓存 + 广播 trip:route-update。
     *
     * @param array $newRoute RoutePlanner::formatRoute 结果（含 polyline）
     */
    public function reroute(Trip $trip, array $newRoute): Trip
    {
        if ($trip->isTerminal()) {
            throw new RuntimeException('行程已终态，不可改道', 422);
        }

        $trip->update(['route_plan' => $newRoute]);

        // 刷新 Redis 路线缓存（Timer tick 后续偏离检测用新路线）
        Redis::setex(
            self::TRIP_ROUTE . $trip->id,
            self::ACTIVE_TTL,
            json_encode($newRoute, JSON_UNESCAPED_UNICODE)
        );

        $this->publish($trip->id, 'trip:route-update', [
            'trip_id'    => $trip->id,
            'route_plan' => $newRoute,
        ]);

        return $trip->fresh();
    }

    /**
     * Mock 改道（高德 API 不可用时的降级）：在现有 polyline 中间点偏移 500m 模拟改道。
     */
    public function rerouteMock(Trip $trip): Trip
    {
        if ($trip->isTerminal()) {
            throw new RuntimeException('行程已终态，不可改道', 422);
        }

        $route = $trip->route_plan ?? [];
        $polyline = $route['polyline'] ?? [];

        if (count($polyline) >= 3) {
            // 取中间点，纬度偏移 0.0045° ≈ 500m
            $midIdx = (int) floor(count($polyline) / 2);
            $polyline[$midIdx][1] = $polyline[$midIdx][1] + 0.0045; // 向北偏移
            $route['polyline'] = $polyline;
            $route['rerouted'] = true;
            $route['rerouted_at'] = now()->toIso8601String();
            $route['rerouted_by'] = 'mock';
        }

        return $this->reroute($trip, $route);
    }

    // ==================== 内部工具方法 ====================

    /**
     * 原子化状态流转（校验合法性 → 更新 status → 可选写入额外字段）。
     * 每次流转后自动 publish WS 事件。
     */
    private function applyTransition(Trip $trip, string $to, array $extra = []): void
    {
        $from = $trip->status;

        if (! $this->canTransition($from, $to)) {
            throw new RuntimeException("非法状态流转 {$from} → {$to}", 422);
        }

        $trip->update(array_merge(['status' => $to], $extra));

        // 匹配到司机时推送 trip:driver-matched
        if ($to === Trip::STATUS_MATCHED) {
            $this->publish($trip->id, 'trip:driver-matched', [
                'trip_id' => $trip->id,
                'driver'  => $trip->driver_id,
            ]);
        }
    }

    /** 查表：from 状态能否流转到 to 状态 */
    private function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** 校验发起操作的司机是否是该行程的分配司机 */
    private function assertDriverMatches(Trip $trip, Driver $driver): void
    {
        if ($trip->driver_id !== null && (int) $trip->driver_id !== (int) $driver->id) {
            throw new RuntimeException('司机非该行程的接单司机', 403);
        }
    }

    /** WS 事件广播：Redis Pub/Sub → WS Server pSubscribe 扇出 */
    private function publish(int $tripId, string $event, array $data): void
    {
        Redis::publish("ws:trip:{$tripId}", json_encode([
            'event' => $event,
            'data'  => $data + ['trip_id' => $tripId],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 粗略费用估算（Step 5 前占位）：
     *   里程费 = distance_m / 1000 × 2.5 元/km
     *   过路费 = tolls 或 toll 字段
     *   基础费 = 12 元（起步价）
     */
    /**
     * Step 5 费用精算公式（public 供测试 + Order 实际结算复用）。
     * 起步价 + 里程费 + 时长费 + 过路费。
     */
    public function estimateCost(array $route): array
    {
        $distanceKm = (float) ($route['distance_m'] ?? 0) / 1000;
        $durationMin = (int) ($route['duration_s'] ?? 0) / 60;

        // 费用公式（Step 5 精算）：起步价 + 里程费 + 时长费 + 过路费
        $base = 13.0;                // 起步价（3km 内）
        $unitPrice = 2.5;             // 元/km
        $timePrice = 0.5;             // 元/min（低速时长费）
        $distanceFee = round($distanceKm * $unitPrice, 2);
        $durationFee = round($durationMin * $timePrice, 2);
        $tolls = (float) ($route['tolls'] ?? 0);
        $total = round($base + $distanceFee + $durationFee + $tolls, 2);

        return [
            'base'         => $base,
            'distance'     => $distanceFee,
            'duration'     => $durationFee,
            'tolls'        => $tolls,
            'total'        => $total,
            'distance_km'  => round($distanceKm, 2),
            'duration_min' => round($durationMin, 1),
            'breakdown'    => [
                ['name' => '起步价', 'amount' => $base],
                ['name' => '里程费', 'amount' => $distanceFee],
                ['name' => '时长费', 'amount' => $durationFee],
                ['name' => '过路费', 'amount' => $tolls],
            ],
        ];
    }

    /** 解析 "lng,lat" 格式字符串的 lng 部分 */
    private function parseCoord(string $loc): float
    {
        $parts = explode(',', $loc);
        return (float) ($parts[0] ?? 0);
    }

    /** 解析 "lng,lat" 格式字符串的 lat 部分 */
    private function parseCoordLat(string $loc): float
    {
        $parts = explode(',', $loc);
        return (float) ($parts[1] ?? 0);
    }
}
