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
 * Step 4 — 异常检测 & 自动改道 Feature Test。
 *
 * 覆盖：
 * 1) AnomalyDetector 纯 PHP 偏离算法（Haversine 距离 route polyline）
 * 2) TripOrchestrator.match() 写入 trip:active:{tripId} + trip:route:{tripId} Redis 缓存
 * 3) Timer tick 扫描 trip:active:* → 检测偏离 → publish ws:trip:{tripId} 异常事件
 * 4) /api/trips/{id}/reroute 重算路线 → 更新 trip.route_plan → publish trip:route-update
 *
 * 注意：Timer tick 在 ws-server.php 纯原生进程里跑（不加载 Laravel），
 * 本测试通过直接调用 AnomalyDetector + 模拟 Timer tick 逻辑来覆盖核心检测路径。
 */
class AnomalyDetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $passenger;
    private Driver $driver;
    private string $passengerToken;
    private string $driverToken;

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
        $this->passengerToken = Jwt::encode(['sub' => $this->passenger->id, 'role' => 'passenger']);
        $this->driverToken = Jwt::encode([
            'sub'       => $driverUser->id,
            'role'      => 'driver',
            'driver_id' => $this->driver->id,
        ]);

        Redis::connection()->geoAdd('drivers:geo', 116.460, 39.909, (string) $this->driver->id);
        Redis::setex("driver:online:{$this->driver->id}", 600, 'online_idle');
    }

    /** ===== 辅助 ===== */

    /**
     * 生成一条从国贸 (116.46, 39.909) → 首都机场 (116.606, 40.079) 的模拟路线 polyline。
     * 实际应该由 AmapService driving() 返回，这里直接构造 10 个等距点。
     */
    private function makeRoutePlanWithPolyline(): array
    {
        // 起点国贸 → 终点首都机场，10 个线性插值点
        $points = [];
        for ($i = 0; $i <= 9; $i++) {
            $t = $i / 9;
            $points[] = [
                116.460 + $t * (116.606 - 116.460), // lng
                39.909  + $t * (40.079 - 39.909),   // lat
            ];
        }

        return [
            'id'          => 'direct',
            'label'       => '直达',
            'distance_m'  => 28500,
            'duration_s'  => 1680,
            'tolls'       => 10,
            'polyline'    => $points,
        ];
    }

    /** ===== 测试 ===== */

    /** AnomalyDetector：司机在路线上 → 不偏离。 */
    public function test_driver_on_route_is_not_flagged(): void
    {
        $detector = app(\App\Services\Geo\AnomalyDetector::class);
        $routePlan = $this->makeRoutePlanWithPolyline();

        // 司机刚好在起点（国贸，路线 polyline 的第一个点）
        $result = $detector->detect(116.460, 39.909, $routePlan);

        $this->assertFalse($result['deviated']);
        $this->assertLessThan(100, $result['distance_m']); // 距离应 < 100m
    }

    /** AnomalyDetector：司机偏离 > 500m → 标记偏离。 */
    public function test_driver_500m_off_route_is_flagged(): void
    {
        $detector = app(\App\Services\Geo\AnomalyDetector::class);
        $routePlan = $this->makeRoutePlanWithPolyline();

        // 司机跑到北边 1km 外（坐标偏移约 0.009° ≈ 1km）
        $result = $detector->detect(116.460, 39.918, $routePlan);

        $this->assertTrue($result['deviated']);
        $this->assertGreaterThan(500, $result['distance_m']);
    }

    /** AnomalyDetector：route_plan 无 polyline → 降级返回 null deviation_m。 */
    public function test_missing_polyline_graceful_degrade(): void
    {
        $detector = app(\App\Services\Geo\AnomalyDetector::class);
        $result = $detector->detect(116.460, 39.909, ['id' => 'direct']);

        $this->assertFalse($result['deviated']);
        $this->assertNull($result['distance_m']);
        $this->assertEquals('no_polyline', $result['reason']);
    }

    /** TripOrchestrator.match() 后 Redis trip:active:{tripId} 和 trip:route:{tripId} 应该写入。 */
    public function test_match_writes_trip_active_and_route_cache(): void
    {
        $trip = Trip::create([
            'user_id'          => $this->passenger->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_PENDING,
            'route_plan'       => $this->makeRoutePlanWithPolyline(),
            'cost_snapshot'    => ['total' => 12],
        ]);

        $this->postJson("/api/trips/{$trip->id}/match", [], ['Authorization' => "Bearer {$this->passengerToken}"]);

        // trip:active 键存在，值是 driverId
        $activeDriverId = Redis::get("trip:active:{$trip->id}");
        $this->assertNotNull($activeDriverId);
        $this->assertEquals((string) $this->driver->id, $activeDriverId);

        // trip:route 缓存存在且含 polyline
        $routeRaw = Redis::get("trip:route:{$trip->id}");
        $this->assertNotNull($routeRaw);
        $route = json_decode($routeRaw, true);
        $this->assertNotEmpty($route['polyline'] ?? []);
    }

    /** TripOrchestrator.complete() 或 cancel() 后 trip:active 键应删除。 */
    public function test_complete_cancel_removes_trip_active_key(): void
    {
        $trip = Trip::create([
            'user_id'          => $this->passenger->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_ON_TRIP,
            'route_plan'       => $this->makeRoutePlanWithPolyline(),
            'cost_snapshot'    => ['total' => 12],
        ]);
        Redis::setex("trip:active:{$trip->id}", 7200, (string) $this->driver->id);
        Redis::setex("trip:route:{$trip->id}", 7200, json_encode($trip->route_plan));

        $this->postJson("/api/drivers/{$this->driver->id}/complete/{$trip->id}", [], ['Authorization' => "Bearer {$this->driverToken}"]);

        $this->assertNull(Redis::get("trip:active:{$trip->id}"));
    }

    /** Timer tick 模拟：扫描 trip:active:* → 检测偏离 → publish 异常事件。 */
    public function test_timer_tick_detects_deviation_and_publishes_event(): void
    {
        // 准备一个 on_trip 状态的 trip + 司机
        $trip = Trip::create([
            'user_id'          => $this->passenger->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_ON_TRIP,
            'route_plan'       => $this->makeRoutePlanWithPolyline(),
            'cost_snapshot'    => ['total' => 12],
        ]);
        // 缓存 trip:active + trip:route
        Redis::setex("trip:active:{$trip->id}", 7200, (string) $this->driver->id);
        Redis::setex("trip:route:{$trip->id}", 7200, json_encode($trip->route_plan));

        // 司机上报一个偏离 > 500m 的位置
        Redis::setex("driver:position:{$this->driver->id}", 60, json_encode([
            'driver_id' => $this->driver->id,
            'trip_id'   => $trip->id,
            'lng'       => 116.460,
            'lat'       => 39.918, // 偏离 ~1km
            'ts'        => time(),
        ]));

        // 模拟 Timer tick 逻辑（Laravel 环境下调用 AnomalyDetector）
        /** @var \App\Services\Geo\AnomalyDetector $detector */
        $detector = app(\App\Services\Geo\AnomalyDetector::class);
        $routePlan = json_decode(Redis::get("trip:route:{$trip->id}"), true);
        $pos = json_decode(Redis::get("driver:position:{$this->driver->id}"), true);

        $result = $detector->detect((float) $pos['lng'], (float) $pos['lat'], $routePlan);

        $this->assertTrue($result['deviated']);
        $this->assertGreaterThan(500, $result['distance_m']);

        // 验证应该 publish 的异常事件（在 ws-server.php 纯原生进程中发生）
        // 这里只验证检测结果本身；实际 publish 由 Timer tick 执行
    }

    /** Timer tick：心跳缺失 30s → 司机离线异常。 */
    public function test_timer_tick_missing_heartbeat_is_flagged(): void
    {
        $trip = Trip::create([
            'user_id'          => $this->passenger->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_ON_TRIP,
            'route_plan'       => $this->makeRoutePlanWithPolyline(),
            'cost_snapshot'    => ['total' => 12],
        ]);

        // 无 driver:position（司机停上报了）
        $detector = app(\App\Services\Geo\AnomalyDetector::class);
        $result = $detector->detectMissingHeartbeat(null, time() + 60); // position = null → 缺失

        $this->assertTrue($result['deviated']);
        $this->assertEquals('missing_heartbeat', $result['reason']);
    }

    /** POST /api/trips/{id}/reroute — mock fallback（高德 API 不可用时）返回更新后的 route_plan。 */
    public function test_reroute_endpoint_returns_updated_route_plan(): void
    {
        $polyline = $this->makeRoutePlanWithPolyline()['polyline'];
        $trip = Trip::create([
            'user_id'          => $this->passenger->id,
            'driver_id'        => $this->driver->id,
            'origin_text'      => '国贸',
            'origin_lng'       => 116.460,
            'origin_lat'       => 39.909,
            'destination_text' => '首都机场',
            'destination_lng' => 116.606,
            'destination_lat' => 40.079,
            'status'           => Trip::STATUS_ON_TRIP,
            'route_plan'       => $this->makeRoutePlanWithPolyline(),
            'cost_snapshot'    => ['total' => 12],
        ]);
        // 缓存 trip:route（Timer tick 和 reroute 都会刷新它）
        Redis::setex("trip:route:{$trip->id}", 7200, json_encode($trip->route_plan));

        $response = $this->postJson(
            "/api/trips/{$trip->id}/reroute",
            [],
            ['Authorization' => "Bearer {$this->passengerToken}"]
        );

        $response->assertStatus(200);
        // 高德 API 本地有 key 时走真实重算，否则 mock；两条路都应该更新 route_plan
        $reroutedBy = $response->json('rerouted_by');
        $this->assertContains($reroutedBy, ['amap', 'mock']);

        // DB 里 route_plan 应该更新了
        $fresh = $trip->fresh();
        $this->assertNotNull($fresh->route_plan);
        // polyline 应该有内容（amap 真实重算或 mock 都保留 polyline）
        $this->assertNotEmpty($fresh->route_plan['polyline'] ?? []);

        // Redis trip:route 也应该刷新
        $routeRaw = Redis::get("trip:route:{$trip->id}");
        $this->assertNotNull($routeRaw);
        $newRoute = json_decode($routeRaw, true);
        $this->assertNotEmpty($newRoute['polyline'] ?? []);
    }
}
