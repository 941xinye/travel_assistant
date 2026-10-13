<?php

namespace App\Services\Driver;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 司机匹配服务（Step 3：PostGIS 可选 + AI 评分排序）。
 *
 * 架构（两级策略）：
 *   1. findNearby()：空间初筛 — 优先 PostGIS ST_DWithin（pgsql + PostGIS 可用时），fallback Redis GEORADIUS
 *   2. score()：AI 评分排序 — 距离 0.4 + 司机评分 0.3 + 接单率 0.2 + 顺路度 0.1（顺路度占位，Step 4 补）
 *   3. findBestDriver()：findNearby → score 排序 → 取 top 1
 *
 * 并发保护在 TripOrchestrator::match() 内用 Redis NX SET 实现（一个司机同一时刻只分配给一个 trip）。
 */
class Matcher
{
    private const GEO_KEY = 'drivers:geo';

    /** PostGIS 空间查询距离阈值（默认 3km） */
    private const DEFAULT_RADIUS_METERS = 3000;

    /**
     * 检测 PostGIS 可用：DB 驱动是 pgsql + 存在 driver_positions 表 + 当前连接能跑 ST_DWithin。
     * 用 try/catch 包一层，PostgreSQL 但没装 PostGIS 时也能优雅返回 false。
     */
    private function postgisAvailable(): bool
    {
        try {
            $driver = DB::getDefaultConnection()->getDriverName();
            if ($driver !== 'pgsql') {
                return false;
            }
            // 探测：跑一个最小 ST_DWithin（PostGIS 未安装会抛 function does not exist）
            DB::select("SELECT ST_DWithin(ST_SetSRID(ST_MakePoint(0,0), 4326), ST_SetSRID(ST_MakePoint(0,0), 4326), 1) AS ok");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 就近司机初筛（带 PostGIS → Redis 两级 fallback）。
     *
     * @return array<int, string> 候选司机 ID（online_idle），按距离升序
     */
    public function findNearby(float $lng, float $lat, int $radiusMeters = self::DEFAULT_RADIUS_METERS): array
    {
        if ($this->postgisAvailable()) {
            return $this->findNearbyViaPostGIS($lng, $lat, $radiusMeters);
        }

        // Fallback：Redis GEO（本地开发 MySQL 环境 + docker 未启 PostGIS 时）
        return $this->findNearbyViaRedisGEO($lng, $lat, $radiusMeters);
    }

    /**
     * PostGIS ST_DWithin 精确空间查询。
     * 返回 driver_id 列表（online_idle + 3km 半径），按距离升序。
     */
    private function findNearbyViaPostGIS(float $lng, float $lat, int $radiusMeters): array
    {
        $sql = <<<'SQL'
            SELECT d.id, ST_Distance(dp.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)) AS distance_m
            FROM drivers d
            JOIN driver_positions dp ON d.id = dp.driver_id
            WHERE d.status = 'online_idle'
              AND ST_DWithin(dp.location, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?)
            ORDER BY distance_m ASC
            LIMIT 20
        SQL;

        $rows = DB::select($sql, [$lng, $lat, $lng, $lat, $radiusMeters]);

        return array_map(fn ($r) => (string) $r->id, $rows);
    }

    /**
     * Redis GEO 初筛（fallback 通道）。
     */
    private function findNearbyViaRedisGEO(float $lng, float $lat, int $radiusMeters): array
    {
        $ids = Redis::connection()->georadius(self::GEO_KEY, $lng, $lat, $radiusMeters, 'm');

        if ($ids === []) {
            return [];
        }

        $candidates = [];
        foreach ($ids as $id) {
            if (Redis::connection()->get("driver:online:{$id}") === 'online_idle') {
                $candidates[] = (string) $id;
            }
        }

        return $candidates;
    }

    /**
     * 对候选司机做 AI 评分排序。
     *
     * 权重（Step 3 简化版；Step 4 顺路度补完）：
     *   距离分 0.4    — 越近分越高（按 radiusMeters 归一化）
     *   评分分 0.3    — driver.rating / 5
     *   接单率 0.2    — 简化：total_rides 对数归一化（total_rides 越大越稳定）
     *   顺路度 0.1    — 占位 0.5（Step 4 用 PostGIS 轨迹 → trip route 偏离率替换）
     *
     * @param array<int, string> $candidates driver_id 列表
     * @return array<int, array{driver_id:string, score:float}> 按 score 降序
     */
    public function score(array $candidates, float $lng, float $lat, int $radiusMeters = self::DEFAULT_RADIUS_METERS): array
    {
        if ($candidates === []) {
            return [];
        }

        // 批量查 Driver 档案（rating / total_rides / redis 实时位置）
        $drivers = DB::table('drivers')->whereIn('id', $candidates)->get()->keyBy('id');

        $scored = [];
        foreach ($candidates as $driverId) {
            $driver = $drivers->get((int) $driverId);
            if (! $driver) continue;

            // 距离分：从 Redis position 算（PostGIS 位置存在也可以取，但 Redis position 实时更新）
            $posRaw = Redis::get("driver:position:{$driverId}");
            $distScore = 0.5; // 占位
            if ($posRaw) {
                $pos = json_decode($posRaw, true);
                if (isset($pos['lng'], $pos['lat'])) {
                    $distanceM = $this->haversine($lat, $lng, (float) $pos['lat'], (float) $pos['lng']);
                    // 距离分：半径内越近越高；>radius 给 0.1（兜底）
                    $distScore = $distanceM <= $radiusMeters
                        ? max(0.0, 1.0 - $distanceM / $radiusMeters)
                        : 0.1;
                }
            }

            $ratingScore = (float) $driver->rating / 5.0;

            // 接单率（total_rides 对数归一化，0~100 rides 映射到 0~1）
            $rides = (int) ($driver->total_rides ?? 0);
            $acceptanceScore = $rides > 0 ? min(1.0, log10($rides + 1)) : 0.2;

            $score = 0.4 * $distScore + 0.3 * $ratingScore + 0.2 * $acceptanceScore + 0.1 * 0.5;

            $scored[] = [
                'driver_id' => (string) $driverId,
                'score'     => round($score, 4),
            ];
        }

        // score 降序
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * 选最优司机：findNearby → score 排序 → top 1。
     *
     * TripOrchestrator::match() 已经对这个司机做了 Redis NX SET 原子声明。
     *
     * @return int|null
     */
    public function findBestDriver(float $lng, float $lat, int $radiusMeters = self::DEFAULT_RADIUS_METERS): ?int
    {
        $candidates = $this->findNearby($lng, $lat, $radiusMeters);

        if ($candidates === []) {
            return null;
        }

        $scored = $this->score($candidates, $lng, $lat, $radiusMeters);

        return $scored[0]['driver_id'] ?? null;
    }

    /**
     * Haversine 公式：两点间球面距离（米）。
     * 仅用于 Redis position → trip origin 的粗略距离分（不要求精确）。
     */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // 米
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $earthRadius * asin(sqrt($a));
    }
}
