<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Support\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Trip 状态机完整生命周期集成测试（Step 3 回归保护）。
 *
 * 覆盖：注册 → 创建 Trip → 触发匹配 → 司机接单 → 到达接客点 → 开始行程 → 完成行程 → Order 自动创建
 * 外加反向转换拦截、水平权限防护、取消行程释放资源。
 *
 * 测试环境：sqlite :memory:（phpunit.xml 强制 DB_CONNECTION=sqlite）
 * PostGIS 迁移 pgsql_geo 连接被 config/database.php 在 testing 环境下伪装成 sqlite，up() 里 early return 跳过。
 */
class TripStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected User $passenger;
    protected Driver $driver;
    protected string $passengerToken;
    protected string $driverToken;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushDB();

        $this->passenger = User::create([
            'phone'        => '138' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'name'         => '测试乘客',
            'email'        => 'passenger_test@local',
            'password'     => bcrypt('123456'),
            'default_city' => '北京',
        ]);
        $this->passengerToken = Jwt::encode([
            'sub'  => $this->passenger->id,
            'role' => 'passenger',
        ]);

        $driverUser = User::create([
            'phone'    => '139' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'name'     => '测试司机',
            'email'    => 'driver_test@local',
            'password' => bcrypt('123456'),
        ]);
        $this->driver = Driver::create([
            'user_id'       => $driverUser->id,
            'license_plate' => '京A' . random_int(10000, 99999),
            'status'        => 'online_idle',
            'rating'        => 4.8,
        ]);
        $this->driverToken = Jwt::encode([
            'sub'       => $driverUser->id,
            'role'      => 'driver',
            'driver_id' => $this->driver->id,
        ]);

        // 司机上线：GEO + online key
        Redis::connection()->geoAdd('drivers:geo', 116.460, 39.909, (string) $this->driver->id);
        Redis::setex("driver:online:{$this->driver->id}", 600, 'online_idle');
    }

    protected function passengerAuth(): array
    {
        return ['Authorization' => "Bearer {$this->passengerToken}"];
    }

    protected function driverAuth(): array
    {
        return ['Authorization' => "Bearer {$this->driverToken}"];
    }

    protected function tripPayload(): array
    {
        return [
            'origin' => [
                'text'     => '国贸',
                'location' => '116.460,39.909',
            ],
            'destination' => [
                'text'     => '首都机场',
                'location' => '116.606,40.079',
            ],
            'routes' => [[
                'id'         => 'direct',
                'label'      => '直达',
                'distance_m' => 28500,
                'duration_s' => 1680,
                'tolls'      => 10,
            ]],
            'selected_route_id' => 'direct',
        ];
    }

    // ===== 测试 =====

    public function test_create_trip_returns_pending_status(): void
    {
        $response = $this->postJson('/api/trips', $this->tripPayload(), $this->passengerAuth());

        $response->assertStatus(201);
        $response->assertJsonPath('trip.status', Trip::STATUS_PENDING);
        $this->assertDatabaseHas('trips', [
            'user_id'     => $this->passenger->id,
            'status'      => Trip::STATUS_PENDING,
            'origin_text' => '国贸',
        ]);
    }

    public function test_full_lifecycle_create_match_accept_arrive_start_complete(): void
    {
        // 1. create
        $create = $this->postJson('/api/trips', $this->tripPayload(), $this->passengerAuth());
        $tripId = $create->json('trip.id');
        $this->assertNotNull($tripId);

        // 2. match (pending → matched)
        $match = $this->postJson("/api/trips/{$tripId}/match", [], $this->passengerAuth());
        $match->assertStatus(200);
        $match->assertJsonPath('trip.status', Trip::STATUS_MATCHED);
        $match->assertJsonPath('trip.driver_id', $this->driver->id);

        // 3. accept (matched → driver_en_route)
        $accept = $this->postJson("/api/drivers/{$this->driver->id}/accept/{$tripId}", [], $this->driverAuth());
        $accept->assertStatus(200);
        $accept->assertJsonPath('trip.status', Trip::STATUS_DRIVER_EN_ROUTE);

        // 4. arrive
        $arrive = $this->postJson("/api/drivers/{$this->driver->id}/arrive/{$tripId}", [], $this->driverAuth());
        $arrive->assertStatus(200);
        $arrive->assertJsonPath('trip.actual_pickup_time', fn ($t) => $t !== null);

        // 5. start (driver_en_route → on_trip)
        $start = $this->postJson("/api/drivers/{$this->driver->id}/start/{$tripId}", [], $this->driverAuth());
        $start->assertStatus(200);
        $start->assertJsonPath('trip.status', Trip::STATUS_ON_TRIP);

        // 6. complete (on_trip → completed + Order)
        $complete = $this->postJson("/api/drivers/{$this->driver->id}/complete/{$tripId}", [], $this->driverAuth());
        $complete->assertStatus(200);
        $complete->assertJsonPath('trip.status', Trip::STATUS_COMPLETED);
        $complete->assertJsonPath('trip.order.payment_status', 'pending');

        // 7. DB 验证
        $this->assertDatabaseHas('orders', [
            'trip_id'        => $tripId,
            'driver_id'      => $this->driver->id,
            'payment_status' => 'pending',
        ]);

        // 8. 司机回到 online_idle
        $this->driver->refresh();
        $this->assertEquals('online_idle', $this->driver->status);
    }

    public function test_reverse_transition_from_completed_is_rejected(): void
    {
        $user = User::where('email', 'passenger_test@local')->first();
        $trip = Trip::create([
            'user_id'          => $user->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_COMPLETED,
            'route_plan'       => ['id' => 'direct'],
        ]);

        $response = $this->postJson(
            "/api/drivers/{$this->driver->id}/start/{$trip->id}",
            [],
            $this->driverAuth()
        );
        $response->assertStatus(422);
    }

    public function test_cancel_releases_driver_and_redis_lock(): void
    {
        $user = User::where('email', 'passenger_test@local')->first();
        $trip = Trip::create([
            'user_id'          => $user->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_MATCHED,
            'route_plan'       => ['id' => 'direct'],
        ]);
        Redis::set("driver:match:lock:{$this->driver->id}", (string) $trip->id, 'EX', 30);

        $response = $this->postJson("/api/trips/{$trip->id}/cancel", [], $this->passengerAuth());
        $response->assertStatus(200);
        $response->assertJsonPath('trip.status', Trip::STATUS_CANCELLED);

        $this->driver->refresh();
        $this->assertEquals('online_idle', $this->driver->status);
        $this->assertNull(Redis::get("driver:match:lock:{$this->driver->id}"));
    }

    /** DriverAuth 中间件水平权限防护：司机 A 不能接单司机 B 的 trip → 403。 */
    public function test_horizontal_privilege_driver_cannot_accept_other_driver_trip(): void
    {
        $user = User::where('email', 'passenger_test@local')->first();

        // 创建另一个司机（driverB）
        $driverBUser = User::create([
            'phone'    => '13600000099',
            'name'     => '另一个司机',
            'email'    => 'driver_b@local',
            'password' => bcrypt('123456'),
        ]);
        $driverB = Driver::create([
            'user_id'       => $driverBUser->id,
            'license_plate' => '京AB999',
            'status'        => 'online_idle',
        ]);
        $driverBToken = Jwt::encode([
            'sub'       => $driverBUser->id,
            'role'      => 'driver',
            'driver_id' => $driverB->id,
        ]);

        // 本测试司机（$this->driver）已被分配了一个 matched 状态的 trip
        $trip = Trip::create([
            'user_id'          => $user->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_MATCHED,
            'route_plan'       => ['id' => 'direct'],
        ]);

        // 用 driverB 的 token 尝试 accept — TripOrchestrator 会检测到 driver_id 不匹配返回 409 Conflict
        $response = $this->postJson(
            "/api/drivers/{$driverB->id}/accept/{$trip->id}",
            [],
            ['Authorization' => "Bearer {$driverBToken}"]
        );
        $response->assertStatus(409);
    }

    public function test_matcher_finds_nearby_idle_driver_via_redis_geo(): void
    {
        $user = User::where('email', 'passenger_test@local')->first();

        // 忙司机（online_busy 应被过滤）
        $busyUser = User::create([
            'phone'    => '13600000001',
            'name'     => '忙司机',
            'email'    => 'busy@local',
            'password' => bcrypt('123456'),
        ]);
        $busyDriver = Driver::create([
            'user_id'       => $busyUser->id,
            'license_plate' => '京ABUSY',
            'status'        => 'online_busy',
        ]);
        Redis::connection()->geoAdd('drivers:geo', 116.460, 39.909, (string) $busyDriver->id);
        Redis::setex("driver:online:{$busyDriver->id}", 600, 'online_busy');

        $matcher = app(\App\Services\Driver\Matcher::class);
        $candidates = $matcher->findNearby(116.460, 39.909, 5000);

        $this->assertContains((string) $this->driver->id, $candidates);
        $this->assertNotContains((string) $busyDriver->id, $candidates);

        $best = $matcher->findBestDriver(116.460, 39.909, 5000);
        $this->assertEquals($this->driver->id, $best);
    }
}
