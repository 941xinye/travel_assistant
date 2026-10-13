<?php

namespace App\Services\Geo;

use RuntimeException;

/**
 * 多策略路线对比（Phase 2 核心）。
 *
 * POC 实测结论（ARCHITECTURE.md 2.3 / poc.php）：
 * - 高德 v3 strategy 0/4/10 返回完全相同路线 → 多 strategy 循环无意义
 * - 差异化方案唯一手段 = waypoints 强制绕行
 * 因此方案生成策略：直达 1 条 + 每个途经点各生成 1 条绕行方案，按时长排序推荐。
 */
class RoutePlanner
{
    public function __construct(private readonly AmapService $amap)
    {
    }

    /**
     * 意图解析结果 → 地理编码 → 多方案路线对比。
     *
     * @return array{origin: array, destination: array, routes: array}
     */
    public function planFromIntent(array $intent): array
    {
        $originText = $intent['origin']['text'] ?? null;
        $destinationText = $intent['destination']['text'] ?? null;

        if (! is_string($originText) || $originText === ''
            || ! is_string($destinationText) || $destinationText === '') {
            throw new RuntimeException('意图缺少有效的起点或终点');
        }

        $origin = $this->amap->geocode($originText);
        $destination = $this->amap->geocode($destinationText);

        // 显式途经点（pickup/dropoff/via）各生成一条绕行方案
        $waypoints = [];
        foreach ($intent['waypoints'] ?? [] as $wp) {
            if (is_string($wp['text'] ?? null) && $wp['text'] !== '') {
                $waypoints[] = $this->amap->geocode($wp['text']);
            }
        }

        return $this->buildComparison($origin, $destination, $waypoints);
    }

    /**
     * 坐标直连版对比（前端已持有坐标时跳过 AI/geocode）。
     *
     * @param array<int, string> $waypointTexts 途经点地址文字（geocode 后各自成方案）
     */
    public function compareCoords(string $origin, string $destination, array $waypointTexts = []): array
    {
        $originLoc = ['text' => $origin, 'location' => $origin, 'level' => 'coord'];
        $destinationLoc = ['text' => $destination, 'location' => $destination, 'level' => 'coord'];

        $waypoints = array_map(
            fn (string $text) => $this->amap->geocode($text),
            $waypointTexts,
        );

        return $this->buildComparison($originLoc, $destinationLoc, $waypoints);
    }

    /**
     * 生成方案集：直达 + 每途经点绕行，按 duration 升序，首条标记 recommended。
     *
     * @param array<int, array{text: string, location: string, level: string}> $waypoints
     */
    private function buildComparison(array $origin, array $destination, array $waypoints): array
    {
        $routes = [];

        // 方案 1：直达（extensions=all 拿 polyline）
        $direct = $this->amap->driving($origin['location'], $destination['location'], null, 0, true);
        $routes[] = $this->formatRoute('direct', '直达', [], $direct);

        // 方案 2..n：强制绕行途经点
        foreach ($waypoints as $i => $wp) {
            $r = $this->amap->driving($origin['location'], $destination['location'], $wp['location'], 0, true);
            $routes[] = $this->formatRoute("via_{$i}", "途经 {$wp['text']}", [$wp], $r);
        }

        usort($routes, fn (array $a, array $b) => $a['duration_s'] <=> $b['duration_s']);
        $routes[0]['recommended'] = true;

        return ['origin' => $origin, 'destination' => $destination, 'routes' => $routes];
    }

    private function formatRoute(string $id, string $label, array $via, array $driving): array
    {
        return [
            'id'          => $id,
            'label'       => $label,
            'via'         => $via,
            'distance_m'  => $driving['distance_m'],
            'duration_s'  => $driving['duration_s'],
            'tolls'       => $driving['tolls'],
            'polyline'    => $driving['polyline'],
            'recommended' => false,
        ];
    }
}
