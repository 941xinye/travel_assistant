<?php

namespace App\Services\Geo;

/**
 * 行程异常检测服务（Step 4）。
 *
 * 两类检测：
 *   1. 路线偏离（Haversine 距离 route polyline）→ deviated=true, distance_m>500
 *   2. 心跳缺失（driver:position ts 超过 30s 未更新）→ deviated=true, reason=missing_heartbeat
 *
 * 纯 PHP 实现，MySQL/SQLite 本地开发环境可直接跑；PostGIS ST_DWithin 作为可选精度层同 Matcher 模式。
 */
class AnomalyDetector
{
    /** 偏离阈值（米）：司机距规划路线最小距离超过此值 → 标记偏离 */
    private const DEVIATION_THRESHOLD_M = 500;

    /** 心跳超时阈值（秒）：driver:position ts 距今超过此值 → 标记司机离线 */
    private const HEARTBEAT_TIMEOUT_S = 30;

    /**
     * 路线偏离检测：计算司机当前位置到 route polyline 最近点的 Haversine 距离。
     *
     * @param float      $lng       司机当前经度
     * @param float      $lat       司机当前纬度
     * @param array      $routePlan RoutePlanner::formatRoute 结果（含 polyline: [[lng, lat], ...]）
     * @return array{deviated: bool, distance_m: float|null, reason: string}
     */
    public function detect(float $lng, float $lat, array $routePlan): array
    {
        $polyline = $routePlan['polyline'] ?? null;

        if (! is_array($polyline) || $polyline === []) {
            return [
                'deviated'   => false,
                'distance_m' => null,
                'reason'     => 'no_polyline',
            ];
        }

        // 遍历 polyline 每个点，计算 Haversine 最小距离
        $minDist = PHP_FLOAT_MAX;
        foreach ($polyline as $point) {
            [$plng, $plat] = $point;
            $dist = $this->haversine($lat, $lng, (float) $plat, (float) $plng);
            if ($dist < $minDist) {
                $minDist = $dist;
            }
        }

        $deviated = $minDist > self::DEVIATION_THRESHOLD_M;

        return [
            'deviated'   => $deviated,
            'distance_m' => round($minDist, 1),
            'reason'     => $deviated ? 'route_deviation' : 'on_route',
        ];
    }

    /**
     * 心跳缺失检测：司机位置 JSON 里的 ts 距今超过 30s → 标记离线。
     *
     * @param array|null $position driver:position:{id} 解析后的数组（null = 连位置都没有）
     * @param int        $now      当前时间戳（便于测试注入）
     * @return array{deviated: bool, distance_m: float|null, reason: string}
     */
    public function detectMissingHeartbeat(?array $position, int $now): array
    {
        if ($position === null) {
            return [
                'deviated'   => true,
                'distance_m' => null,
                'reason'     => 'missing_heartbeat',
            ];
        }

        $ts = (int) ($position['ts'] ?? 0);
        $delta = $now - $ts;

        if ($delta > self::HEARTBEAT_TIMEOUT_S) {
            return [
                'deviated'   => true,
                'distance_m' => null,
                'reason'     => 'stale_position',
            ];
        }

        return [
            'deviated'   => false,
            'distance_m' => null,
            'reason'     => 'heartbeat_ok',
        ];
    }

    /**
     * Haversine 公式：两点间球面距离（米）。
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
