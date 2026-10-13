<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 消费 WS Server / HTTP 上报推入 Redis Streams 的司机位置，批量双写。
 *
 * 进程边界（ARCHITECTURE.md 模块 4 关键）：
 * - bin/ws-server.php（原生 Swoole，无 Laravel）只负责：
 *     1) setex driver:position:{id} 实时位置（60s TTL）
 *     2) lPush driver:location:stream
 *     3) publish ws:trip:{trip_id}（乘客 WS 扇出）
 * - 本 Job 运行在完整 Laravel 环境（positions:consume 常驻消费 / Queue Worker），
 *   双写落库：PostGIS trip_trajectories（历史轨迹）+ MySQL drivers.last_position_at。
 */
class PersistDriverLocationJob implements ShouldQueue
{
    use Queueable;

    /**
     * 队列名：位置写入量大，与业务队列隔离。
     * 注意：不能重新声明 $queue 属性（Queueable trait 已带 $queue = 'default'，组合冲突）。
     */
    public function viaQueue(): string
    {
        return 'driver-positions';
    }

    public function handle(): int
    {
        $processed = 0;

        // 单条 rpop 兼容所有 phpredis 版本；上限 500 条/轮，防消息洪峰长期占用
        while ($processed < 500 && ($raw = Redis::connection()->rpop('driver:location:stream')) !== null) {
            $data = json_decode((string) $raw, true);
            if (empty($data['driver_id']) || ! isset($data['lng'], $data['lat'])) {
                continue;
            }

            $driverId = (int) $data['driver_id'];

            // MySQL：司机最后上报时间
            DB::table('drivers')->where('id', $driverId)->update(['last_position_at' => now()]);

            // PostGIS：行程轨迹（无 trip_id = 空驶上报，不入轨迹表）
            // 降级策略：PostGIS 不可用时跳过并告警，位置数据仍存于 Redis 实时位，不阻塞消费
            if (! empty($data['trip_id'])) {
                try {
                    DB::connection('pgsql_geo')->insert(
                        'INSERT INTO trip_trajectories (trip_id, driver_id, location, speed, recorded_at) '
                        .'VALUES (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?, ?)',
                        [
                            (int) $data['trip_id'],
                            $driverId,
                            (float) $data['lng'],
                            (float) $data['lat'],
                            $data['speed'] ?? null,
                            now(),
                        ]
                    );
                } catch (Throwable $e) {
                    Log::warning('PostGIS 轨迹写入失败，降级跳过', [
                        'err'       => $e->getMessage(),
                        'driver_id' => $driverId,
                    ]);
                }
            }

            $processed++;
        }

        return $processed;
    }
}
