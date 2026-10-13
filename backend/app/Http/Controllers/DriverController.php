<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\User;
use App\Support\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;

/**
 * 司机端鉴权 + 上下线管理（ARCHITECTURE.md 6.2）。
 *
 * 注册：POST /api/drivers/register  {phone, name, password, license_plate, vehicle_info?}
 * 登录：POST /api/drivers/login     {phone, password} → {token, driver}
 * 上下线：POST /api/drivers/{id}/status {status: online_idle|offline}
 */
class DriverController extends Controller
{
    /**
     * 司机注册（创建 User + Driver 双表记录）。
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'         => ['required', 'string', 'regex:/^1[3-9]\d{9}$/', Rule::unique('users', 'phone')],
            'name'          => ['required', 'string', 'max:50'],
            'password'      => ['required', 'string', 'min:6', 'max:64'],
            'license_plate' => ['required', 'string', 'max:20'],
            'vehicle_info'  => ['nullable', 'array'],
        ]);

        $user = User::create([
            'phone'        => $data['phone'],
            'name'         => $data['name'],
            'email'        => 'd_' . $data['phone'] . '@local', // 完整手机号避免碰撞
            'password'     => Hash::make($data['password']),
            'default_city' => '北京',
        ]);

        $driver = Driver::create([
            'user_id'       => $user->id,
            'license_plate' => $data['license_plate'],
            'vehicle_info'  => $data['vehicle_info'] ?? null,
            'status'        => 'offline',
        ]);

        return $this->respondWithToken($driver);
    }

    /**
     * 司机登录（手机号 + 密码）。
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

        $driver = Driver::where('user_id', $user->id)->first();
        if (! $driver) {
            return response()->json(['error' => 'not_a_driver'], 403);
        }

        return $this->respondWithToken($driver);
    }

    /**
     * 司机上下线（Redis 同步状态 + GEO 集合）。
     *
     * 已在 Router 层被 DriverAuth 中间件保护（含 URL id 匹配 JWT driver_id）。
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['offline', 'online_idle'])],
            // 上线时可选：司机实时位置（driver.auth 已校验身份，接受 body 位置坐标）
            'lng'    => ['nullable', 'numeric', 'between:-180,180'],
            'lat'    => ['nullable', 'numeric', 'between:-90,90'],
        ]);

        $driver = Driver::findOrFail($id);
        $driver->update(['status' => $data['status']]);

        $redisKey = "driver:online:{$driver->id}";
        if ($data['status'] === 'online_idle') {
            Redis::setex($redisKey, 600, 'online_idle'); // 10min TTL，司机心跳续期
            // GEOADD 加入地理空间集合（Matcher::findNearby 初筛依赖）
            $lng = isset($data['lng']) ? (float) $data['lng'] : null;
            $lat = isset($data['lat']) ? (float) $data['lat'] : null;
            if ($lng !== null && $lat !== null) {
                Redis::connection()->geoAdd('drivers:geo', $lng, $lat, (string) $driver->id);
            }
        } else {
            Redis::del($redisKey);
            Redis::connection()->zRem('drivers:geo', (string) $driver->id);
        }

        return response()->json([
            'ok'     => true,
            'driver' => $this->driverPayload($driver),
        ]);
    }

    /**
     * 构造司机 token + 响应。
     */
    private function respondWithToken(Driver $driver): JsonResponse
    {
        $user = $driver->user;

        $token = Jwt::encode([
            'sub'       => $user->id,
            'role'      => 'driver',
            'driver_id' => $driver->id,
        ]);

        return response()->json([
            'token'  => $token,
            'driver' => $this->driverPayload($driver),
        ]);
    }

    /**
     * 司机响应统一 payload（Driver::$hidden 隐藏了 id/user_id，手动注入）。
     */
    private function driverPayload(Driver $driver): array
    {
        $user = $driver->user;

        return [
            'id'            => $driver->id,
            'name'          => $user->name,
            'phone'         => $user->phone,
            'license_plate' => $driver->license_plate,
            'status'        => $driver->status,
            'rating'        => $driver->rating,
            'certified'     => $driver->certified,
        ];
    }
}
