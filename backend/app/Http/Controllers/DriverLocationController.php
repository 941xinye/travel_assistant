<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * 司机端位置上报 HTTP 通道（ARCHITECTURE.md 6.2）。
 * 与 bin/ws-server.php 的 driver:location 处理保持同一三步管道：
 *   setex 实时位置 → lPush Streams（持久化）→ publish 行程频道（乘客 WS 扇出）。
 *
 * 鉴权（Step 1 新增）：
 *   - 已被 DriverAuth 中间件保护（api.php 路由组内）
 *   - driver_id 从中间件注入的 request->attributes->get('driver') 取
 *   - 不信任 body 里的 driver_id（防伪造）
 */
class DriverLocationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // 已鉴权：DriverAuth 中间件注入 driver（不从 body 信任 driver_id）
        /** @var Driver $driver */
        $driver = $request->attributes->get('driver');
        $driverId = $driver?->id ?? 0;

        $data = $request->validate([
            'trip_id' => ['nullable', 'integer', 'min:1'],
            'lng'     => ['required', 'numeric', 'between:-180,180'],
            'lat'     => ['required', 'numeric', 'between:-90,90'],
            'speed'   => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'integer', 'between:0,360'],
        ]);

        $pos = [
            'driver_id' => $driverId,
            'trip_id'   => (int) ($data['trip_id'] ?? 0),
            'lng'       => round((float) $data['lng'], 6),
            'lat'       => round((float) $data['lat'], 6),
            'speed'     => isset($data['speed']) ? (float) $data['speed'] : null,
            'heading'   => isset($data['heading']) ? (int) $data['heading'] : null,
            'ts'        => time(),
        ];

        $position = json_encode($pos, JSON_UNESCAPED_UNICODE);

        Redis::setex("driver:position:{$driverId}", 60, $position);
        Redis::connection()->geoAdd('drivers:geo', $pos['lng'], $pos['lat'], (string) $driverId); // 跟随司机移动更新 GEO
        Redis::lpush('driver:location:stream', $position);

        if ($pos['trip_id'] > 0) {
            Redis::publish(
                "ws:trip:{$pos['trip_id']}",
                json_encode(['event' => 'driver:location-update', 'data' => $pos], JSON_UNESCAPED_UNICODE)
            );
        }

        return response()->json(['ok' => true]);
    }
}
