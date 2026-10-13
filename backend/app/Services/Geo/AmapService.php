<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 高德 Web 服务 API 封装（REST 直调，POC 已验证参数契约）。
 *
 * 关键契约（来自 poc/poc.php 实测）：
 * - geocode:  GET geocode/geo?address=&city=&key=          → geocodes[0].location = "lng,lat"
 * - driving:  GET direction/driving?origin=&destination=&waypoints=&strategy=&extensions=&key=
 *             waypoints 多个用 "|" 分隔（最多 16 个）；polyline 仅在 extensions=all 的
 *             steps[].polyline 中，"lng,lat;lng,lat" 分号分隔，path 顶层 polyline 为空。
 * - strategy 0/4/10 实测返回相同路线；差异化方案唯一手段 = waypoints 强制绕行。
 */
class AmapService
{
    private string $key;

    private string $baseUri;

    private string $city;

    public function __construct()
    {
        $this->key = (string) config('services.amap.key');
        $this->baseUri = rtrim((string) config('services.amap.base_uri'), '/') . '/';
        $this->city = (string) config('services.amap.city', '北京');

        if ($this->key === '') {
            throw new RuntimeException('AMAP_KEY 未配置，请检查 backend/.env');
        }
    }

    /**
     * 地理编码：地址文字 → "lng,lat"。
     *
     * @return array{text: string, location: string, level: string}
     */
    public function geocode(string $address, ?string $city = null): array
    {
        $data = $this->get('geocode/geo', [
            'address' => $address,
            'city'    => $city ?? $this->city,
        ]);

        if (empty($data['geocodes'][0]['location'])) {
            throw new RuntimeException("geocode 无结果: {$address} " . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $geo = $data['geocodes'][0];

        return [
            'text'     => $address,
            'location' => $geo['location'],
            'level'    => $geo['level'] ?? '',
        ];
    }

    /**
     * 驾车路径规划。
     *
     * @param string      $origin      "lng,lat"
     * @param string      $destination "lng,lat"
     * @param string|null $waypoints   "lng,lat|lng,lat"（竖线分隔，最多 16 个）
     * @param int         $strategy    0=速度优先 4=费用优先 10=距离优先（实测三者同路线）
     * @param bool        $extended    true=extensions=all（含 steps/polyline）
     * @return array{distance_m: int, duration_s: int, tolls: string, traffic_lights: int, steps: array, polyline: array}
     */
    public function driving(
        string $origin,
        string $destination,
        ?string $waypoints = null,
        int $strategy = 0,
        bool $extended = false,
    ): array {
        $query = [
            'origin'      => $origin,
            'destination' => $destination,
            'strategy'    => $strategy,
            'extensions'  => $extended ? 'all' : 'base',
        ];

        if ($waypoints !== null && $waypoints !== '') {
            $query['waypoints'] = $waypoints;
        }

        $data = $this->get('direction/driving', $query);

        $path = $data['route']['paths'][0] ?? null;
        if ($path === null) {
            throw new RuntimeException('driving 无 paths: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return [
            'distance_m'     => (int) $path['distance'],
            'duration_s'     => (int) $path['duration'],
            'tolls'          => $path['tolls'] ?? '0',
            'traffic_lights' => (int) ($path['traffic_lights'] ?? 0),
            'steps'          => $path['steps'] ?? [],
            'polyline'       => $this->extractPolyline($path),
        ];
    }

    /**
     * 从 driving 结果中提取完整折线坐标串。
     * POC 实测：顶层 polyline 为空，真实折线在 steps[].polyline（"lng,lat;lng,lat"）。
     *
     * @return array<int, array{0: float, 1: float}> [[lng, lat], ...]
     */
    public function extractPolyline(array $path): array
    {
        $points = [];

        foreach ($path['steps'] ?? [] as $step) {
            $raw = $step['polyline'] ?? '';
            if ($raw === '') {
                continue;
            }

            foreach (explode(';', $raw) as $pair) {
                if (! str_contains($pair, ',')) {
                    continue;
                }
                [$lng, $lat] = array_map('floatval', explode(',', $pair, 2));
                $points[] = [$lng, $lat];
            }
        }

        return $points;
    }

    /**
     * 统一 GET 请求：处理高德 status 契约（status=1 成功）。
     */
    private function get(string $uri, array $query): array
    {
        $query['key'] = $this->key;

        try {
            $response = Http::baseUrl($this->baseUri)
                ->timeout(10)
                ->get($uri, $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException("高德 API 连接失败: {$e->getMessage()}", 0, $e);
        }

        $data = $response->json() ?? [];

        if (($data['status'] ?? '') !== '1') {
            $info = $data['info'] ?? 'unknown';
            throw new RuntimeException("高德 API 失败 [{$info}] uri={$uri}");
        }

        return $data;
    }
}
