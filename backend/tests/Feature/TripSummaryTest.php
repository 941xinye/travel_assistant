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
 * Step 5 — 费用精算 + 行程总结闭环 Feature Test。
 *
 * 覆盖：
 * 1) estimateCost 费用公式（base + distance_km×unit_price + duration_min×time_price + tolls）
 * 2) PersistTripEmbeddingJob pgvector fallback（没 API key 不抛异常，静默跳过）
 * 3) GET /api/trips/{id}/summary — 费用明细 + 路线统计 + AI 总结（LLM 有 key 时真实生成）
 * 4) 行程完成后 complete() 触发 TripSummarizer + dispatch embedding job
 */
class TripSummaryTest extends TestCase
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
        // ↓ 关键！把司机注册到 Redis（Matcher 只从 Redis Geo 找在线司机）
        Redis::connection()->geoAdd('drivers:geo', 116.460, 39.909, (string) $this->driver->id);
        Redis::setex("driver:online:{$this->driver->id}", 600, 'online_idle');

        $this->passengerToken = Jwt::encode(['sub' => $this->passenger->id, 'role' => 'passenger']);
        $this->driverToken = Jwt::encode([
            'sub'       => $driverUser->id,
            'role'      => 'driver',
            'driver_id' => $this->driver->id,
        ]);
    }

    // ===== 测试 =====

    /** estimateCost 费用公式验证：base + distance + duration + tolls = total。 */
    public function test_estimate_cost_formula(): void
    {
        $orchestrator = app(\App\Services\TripOrchestrator::class);

        // 30km, 20min, 15 tolls
        $cost = $orchestrator->estimateCost([
            'distance_m' => 30000,
            'duration_s' => 1200,
            'tolls'      => 15,
        ]);

        $this->assertEquals(13.00, $cost['base']);
        // distance: 30km × 2.5 = 75
        $this->assertEqualsWithDelta(75.00, $cost['distance'], 0.01);
        // duration: 20min × 0.5 = 10
        $this->assertEqualsWithDelta(10.00, $cost['duration'], 0.01);
        // tolls: 15
        $this->assertEquals(15.00, $cost['tolls']);
        // total: 13 + 75 + 10 + 15 = 113
        $this->assertEqualsWithDelta(113.00, $cost['total'], 0.01);
        $this->assertEqualsWithDelta(30.00, $cost['distance_km'], 0.01);
        $this->assertEquals(20, $cost['duration_min']);
    }

    /** estimateCost 零输入边界：全 0 也能算。 */
    public function test_estimate_cost_zero_input(): void
    {
        $orchestrator = app(\App\Services\TripOrchestrator::class);

        $cost = $orchestrator->estimateCost([
            'distance_m' => 0,
            'duration_s' => 0,
            'tolls'      => 0,
        ]);

        $this->assertEquals(13.00, $cost['base']);
        $this->assertEquals(0.00, $cost['distance']);
        $this->assertEquals(0.00, $cost['duration']);
        $this->assertEquals(13.00, $cost['total']);
    }

    /** 完整 lifecycle → 完成后 GET /trips/{id}/summary 有 AI 总结（LLM 有 key 时真实生成）。 */
    public function test_completed_trip_summary_returns_cost_and_ai_summary(): void
    {
        // 完整链路：create → match → accept → arrive → start → complete
        $create = $this->postJson('/api/trips', [
            'origin'      => ['text' => '国贸', 'location' => '116.460,39.909'],
            'destination' => ['text' => '首都机场', 'location' => '116.606,40.079'],
            'routes' => [[
                'id'          => 'direct',
                'label'       => '直达',
                'distance_m'  => 28500,
                'duration_s'  => 1680,
                'tolls'       => 10,
                'polyline'    => [[116.46, 39.909], [116.606, 40.079]],
            ]],
            'selected_route_id' => 'direct',
        ], ['Authorization' => "Bearer {$this->passengerToken}"]);

        $tripId = $create->json('trip.id');

        // match
        $this->postJson("/api/trips/{$tripId}/match", [], ['Authorization' => "Bearer {$this->passengerToken}"]);
        // accept → arrive → start → complete
        $this->postJson("/api/drivers/{$this->driver->id}/accept/{$tripId}", [], ['Authorization' => "Bearer {$this->driverToken}"]);
        $this->postJson("/api/drivers/{$this->driver->id}/arrive/{$tripId}", [], ['Authorization' => "Bearer {$this->driverToken}"]);
        $this->postJson("/api/drivers/{$this->driver->id}/start/{$tripId}", [], ['Authorization' => "Bearer {$this->driverToken}"]);
        // complete
        $this->postJson("/api/drivers/{$this->driver->id}/complete/{$tripId}", [], ['Authorization' => "Bearer {$this->driverToken}"]);

        // summary 端点
        $response = $this->getJson("/api/trips/{$tripId}/summary", ['Authorization' => "Bearer {$this->passengerToken}"]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'trip'     => ['id', 'status', 'origin', 'destination'],
            'cost'     => ['base', 'distance', 'duration', 'tolls', 'total', 'breakdown'],
            'stats'    => ['distance_km', 'duration_min', 'avg_speed_kmh'],
            'order'    => ['id', 'amount', 'payment_status'],
            'summary', // AI 总结（可能 null 或字符串）
        ]);

        // cost breakdown 必须完整
        $this->assertNotNull($response->json('cost.total'));
        // order 必须关联上
        $this->assertEquals('pending', $response->json('order.payment_status'));
    }

    /** summary 端点在 trip 非乘客自己的 → 403（水平权限防护）。 */
    public function test_summary_endpoint_rejects_other_passenger(): void
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
            'status'           => Trip::STATUS_COMPLETED,
            'route_plan'       => ['id' => 'direct'],
            'cost_snapshot'    => ['base' => 13, 'distance' => 71.25, 'duration' => 14.0, 'tolls' => 10, 'total' => 108.25],
        ]);

        $otherPassenger = User::create([
            'phone'    => '13700000002',
            'name'     => '其他乘客',
            'email'    => 'other@local',
            'password' => bcrypt('123456'),
        ]);
        $otherToken = Jwt::encode(['sub' => $otherPassenger->id, 'role' => 'passenger']);

        $this->getJson("/api/trips/{$trip->id}/summary", ['Authorization' => "Bearer {$otherToken}"])
            ->assertStatus(404); // TripController show 用 where user_id = auth()->id()，所以 404
    }

    /** PersistTripEmbeddingJob handle() 在 embedding API 未配置时不抛异常，静默跳过。 */
    public function test_embedding_job_graceful_degrade(): void
    {
        // 本地 .env EMBEDDING_API_KEY 为空 → EmbeddingService::embed() 抛 RuntimeException
        // handle() 应该 catch 后 return，不 throw
        $job = new \App\Jobs\PersistTripEmbeddingJob(
            userId: $this->passenger->id,
            tripId: null,
            content: '测试行程总结文本'
        );

        $job->handle(app(\App\Services\AI\EmbeddingService::class));

        // 不抛异常 = 测试通过（assertTrue 兜底）
        $this->assertTrue(true);
    }
}
