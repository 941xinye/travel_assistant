<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 司机端 JWT 鉴权中间件。
 *
 * 与 JwtAuth 类似，但：
 *  - role 必须为 'driver'
 *  - 注入 driver 到 request attributes（供后续 DriverController 使用）
 */
class DriverAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'missing_token'], 401);
        }

        try {
            $payload = Jwt::decode($authHeader);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }

        if (($payload['role'] ?? '') !== 'driver' || empty($payload['driver_id'])) {
            return response()->json(['error' => 'driver_token_required'], 403);
        }

        $driver = Driver::find((int) $payload['driver_id']);
        if (! $driver) {
            return response()->json(['error' => 'driver_not_found'], 401);
        }

        // 水平权限防护：URL 里带了 {id} 参数时，必须匹配 JWT payload 的 driver_id
        $routeId = $request->route('id');
        if ($routeId !== null && (int) $routeId !== (int) $payload['driver_id']) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $request->attributes->set('driver', $driver);

        return $next($request);
    }
}
