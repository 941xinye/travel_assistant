<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * 乘客端鉴权（ARCHITECTURE.md 6.1）。
 *
 * 注册：POST /api/auth/register {phone, name, password, default_city?}
 * 登录：POST /api/auth/login    {phone, password} → {token, user}
 * 登出：POST /api/auth/logout   （前端删 token 即可，无服务端黑名单）
 */
class AuthController extends Controller
{
    /**
     * 手机号 + 密码注册。
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'        => ['required', 'string', 'regex:/^1[3-9]\d{9}$/', Rule::unique('users', 'phone')],
            'name'         => ['required', 'string', 'max:50'],
            'password'     => ['required', 'string', 'min:6', 'max:64'],
            'default_city' => ['nullable', 'string', 'max:50'],
        ]);

        $user = User::create([
            'phone'        => $data['phone'],
            'name'         => $data['name'],
            'email'        => 'passenger_' . $data['phone'] . '@local', // 完整手机号避免碰撞
            'password'     => Hash::make($data['password']),
            'default_city' => $data['default_city'] ?? '北京',
        ]);

        $token = Jwt::encode([
            'sub'  => $user->id,
            'role' => 'passenger',
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ], 201);
    }

    /**
     * 手机号 + 密码登录。
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        $token = Jwt::encode([
            'sub'  => $user->id,
            'role' => 'passenger',
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * 登出（无服务端黑名单；前端删 token 即失效）。
     */
    public function logout(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    /**
     * 登录/注册响应统一 payload。
     */
    private function userPayload(User $user): array
    {
        return [
            'id'            => $user->id,
            'phone'         => $user->phone,
            'name'          => $user->name,
            'default_city'  => $user->default_city,
        ];
    }
}
