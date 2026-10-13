<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 乘客端 JWT 鉴权中间件。
 *
 * 流程：
 *  1. 从 Authorization: Bearer {token} 取 JWT
 *  2. Jwt::decode() 验签 + 过期检查
 *  3. 按 payload['sub'] 查 User 并注入 auth
 *  4. payload['role'] 必须为 'passenger'（司机走 DriverAuth 中间件）
 */
class JwtAuth
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

        if (! isset($payload['sub']) || ! is_numeric($payload['sub'])) {
            return response()->json(['error' => 'invalid_token_payload'], 401);
        }

        $role = $payload['role'] ?? 'passenger';
        if ($role !== 'passenger') {
            return response()->json(['error' => 'driver_token_on_passenger_endpoint'], 403);
        }

        $user = User::find((int) $payload['sub']);
        if (! $user) {
            return response()->json(['error' => 'user_not_found'], 401);
        }

        // 同时设置 Auth guard + Request resolver（让 auth()->user() 和 $request->user() 都可用）
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
