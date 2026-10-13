<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Services\TripOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * 乘客端行程管理（ARCHITECTURE.md 6.1）。
 *
 * POST   /api/trips              createTrip — 从 RoutePlanner 结果落库 + 选路线
 * POST   /api/trips/{id}/match   match      — 触发司机匹配
 * GET    /api/trips/{id}         show       — 行程详情 + 实时状态
 * GET    /api/trips              index      — 我的历史行程
 * POST   /api/trips/{id}/cancel  cancel     — 取消行程（TripOrchestrator 状态机）
 *
 * 所有写操作统一走 TripOrchestrator，Controller 禁止直接改 trip.status。
 */
class TripController extends Controller
{
    public function __construct(private readonly TripOrchestrator $orchestrator)
    {
    }

    /**
     * 创建行程 + 选路线（两步合一：PlanController 已返回多方案，乘客选中某条后提交）。
     *
     * 请求体：
     *   origin {text, location}
     *   destination {text, location}
     *   routes [{id, label, distance_m, duration_s, polyline, tolls}]  — PlanController 原样返回
     *   selected_route_id string
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin'            => ['required', 'array'],
            'origin.text'       => ['required', 'string'],
            'origin.location'   => ['required', 'string'], // "lng,lat"
            'destination'       => ['required', 'array'],
            'destination.text'  => ['required', 'string'],
            'destination.location' => ['required', 'string'],
            'routes'            => ['required', 'array', 'min:1'],
            'routes.*.id'       => ['required', 'string'],
            'selected_route_id' => ['required', 'string'],
        ]);

        $user = auth()->user();

        try {
            $trip = $this->orchestrator->createTrip($user, $data, $data['selected_route_id']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'trip' => $this->tripPayload($trip),
        ], 201);
    }

    /**
     * 触发司机匹配（乘客确认选路线后调用；trip 从 pending → matched）。
     */
    public function match(Request $request, int $id): JsonResponse
    {
        $trip = Trip::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $trip) {
            return response()->json(['error' => 'trip_not_found'], 404);
        }

        try {
            $trip = $this->orchestrator->match($trip);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'trip' => $this->tripPayload($trip, withDriver: true),
        ]);
    }

    /**
     * 行程详情（乘客自己的行程）。
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $trip = Trip::query()
            ->with(['driver.user', 'order'])
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $trip) {
            return response()->json(['error' => 'trip_not_found'], 404);
        }

        return response()->json([
            'trip' => $this->tripPayload($trip, withDriver: true),
        ]);
    }

    /**
     * 我的历史行程列表（含 completed + cancelled 等）。
     */
    public function index(Request $request): JsonResponse
    {
        $trips = Trip::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'trips' => $trips->map(fn ($t) => $this->tripPayload($t))->values(),
        ]);
    }

    /**
     * 取消行程（TripOrchestrator 状态机校验 + 司机状态释放 + Pub/Sub）。
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $trip = Trip::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $trip) {
            return response()->json(['error' => 'trip_not_found'], 404);
        }

        try {
            $trip = $this->orchestrator->cancel($trip);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'trip' => $this->tripPayload($trip),
        ]);
    }

    /**
     * Step 4：路线改道（Timer tick 检测到偏离后由前端调用）。
     *
     * 优先 RoutePlanner 真实重算（高德 API），API 不可用时降级到 mock reroute。
     * 改道后自动 publish trip:route-update 事件推送给订阅乘客。
     */
    public function reroute(Request $request, int $id): JsonResponse
    {
        $trip = Trip::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $trip) {
            return response()->json(['error' => 'trip_not_found'], 404);
        }

        if ($trip->isTerminal()) {
            return response()->json(['error' => 'trip_terminal'], 422);
        }

        try {
            // 尝试 RoutePlanner 真实重算
            $origin = "{$trip->origin_lng},{$trip->origin_lat}";
            $dest = "{$trip->destination_lng},{$trip->destination_lat}";

            /** @var \App\Services\Geo\RoutePlanner $planner */
            $planner = app(\App\Services\Geo\RoutePlanner::class);
            $result = $planner->compareCoords($origin, $dest);
            $selected = $result['routes'][0] ?? null;

            if ($selected) {
                $trip = $this->orchestrator->reroute($trip, $selected);
                return response()->json([
                    'trip'        => $this->tripPayload($trip, withDriver: true),
                    'rerouted_by' => 'amap',
                ]);
            }
        } catch (\Throwable) {
            // 高德 API 不可用（无 key / 网络）→ 降级 mock
        }

        // Mock 改道：在现有 polyline 中间点偏移 500m
        $trip = $this->orchestrator->rerouteMock($trip);

        return response()->json([
            'trip'        => $this->tripPayload($trip, withDriver: true),
            'rerouted_by' => 'mock',
        ]);
    }

    /**
     * Step 5：行程报告 — 费用明细 + 路线统计 + AI 总结。
     */
    public function summary(int $id): JsonResponse
    {
        $trip = Trip::with(['driver.user', 'order'])
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $trip) {
            return response()->json(['error' => 'trip_not_found'], 404);
        }

        $cost = $trip->cost_snapshot ?? [];
        $distanceKm = (float) ($cost['distance_km'] ?? 0);
        $durationMin = (float) ($cost['duration_min'] ?? 0);

        return response()->json([
            'trip' => [
                'id'           => $trip->id,
                'status'       => $trip->status,
                'origin'       => ['text' => $trip->origin_text, 'lng' => $trip->origin_lng, 'lat' => $trip->origin_lat],
                'destination'  => ['text' => $trip->destination_text, 'lng' => $trip->destination_lng, 'lat' => $trip->destination_lat],
                'start_time'   => $trip->actual_pickup_time?->toIso8601String(),
                'end_time'     => $trip->actual_dropoff_time?->toIso8601String(),
                'driver'       => $trip->driver ? [
                    'name'          => $trip->driver->user?->name,
                    'license_plate' => $trip->driver->license_plate,
                    'rating'        => $trip->driver->rating,
                ] : null,
            ],
            'cost' => [
                'base'      => $cost['base'] ?? 0,
                'distance'  => $cost['distance'] ?? 0,
                'duration'  => $cost['duration'] ?? 0,
                'tolls'     => $cost['tolls'] ?? 0,
                'total'     => $cost['total'] ?? 0,
                'breakdown' => $cost['breakdown'] ?? [
                    ['name' => '起步价', 'amount' => $cost['base'] ?? 0],
                    ['name' => '里程费', 'amount' => $cost['distance'] ?? 0],
                    ['name' => '时长费', 'amount' => $cost['duration'] ?? 0],
                    ['name' => '过路费', 'amount' => $cost['tolls'] ?? 0],
                ],
            ],
            'stats' => [
                'distance_km'   => $distanceKm,
                'duration_min'  => $durationMin,
                'avg_speed_kmh' => $durationMin > 0 ? round($distanceKm / ($durationMin / 60), 1) : 0,
            ],
            'order' => $trip->order ? [
                'id'             => $trip->order->id,
                'amount'         => $trip->order->amount,
                'payment_status' => $trip->order->payment_status,
            ] : null,
            'summary' => $trip->summary_text, // AI 出行日记（LLM 有 key 时生成；null = 跳过）
        ]);
    }

    /**
     * 行程响应统一 payload。
     */
    private function tripPayload(Trip $trip, bool $withDriver = false): array
    {
        $data = [
            'id'                   => $trip->id,
            'status'               => $trip->status,
            'driver_id'            => $trip->driver_id,
            'origin'               => ['text' => $trip->origin_text, 'lng' => $trip->origin_lng, 'lat' => $trip->origin_lat],
            'destination'          => ['text' => $trip->destination_text, 'lng' => $trip->destination_lng, 'lat' => $trip->destination_lat],
            'pickup_time'          => $trip->pickup_time?->toIso8601String(),
            'actual_pickup_time'   => $trip->actual_pickup_time?->toIso8601String(),
            'dropoff_time'         => $trip->dropoff_time?->toIso8601String(),
            'actual_dropoff_time'  => $trip->actual_dropoff_time?->toIso8601String(),
            'route_plan'           => $trip->route_plan,
            'cost_snapshot'        => $trip->cost_snapshot,
            'created_at'           => $trip->created_at?->toIso8601String(),
        ];

        if ($withDriver && $trip->driver_id) {
            $driver = $trip->driver;
            $data['driver'] = $driver ? [
                'id'            => $driver->id,
                'name'          => $driver->user?->name,
                'license_plate' => $driver->license_plate,
                'rating'        => $driver->rating,
            ] : null;
        }

        if ($withDriver && $trip->status === Trip::STATUS_COMPLETED && $trip->order) {
            $data['order'] = [
                'id'             => $trip->order->id,
                'amount'         => $trip->order->amount,
                'payment_status' => $trip->order->payment_status,
            ];
        }

        return $data;
    }
}
