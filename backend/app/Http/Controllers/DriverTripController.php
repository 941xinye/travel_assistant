<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Trip;
use App\Services\TripOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * 司机端行程操作（ARCHITECTURE.md 6.2）。
 *
 * POST   /api/drivers/{id}/accept/{trip_id}   accept   — 接单（race condition 保护）
 * POST   /api/drivers/{id}/arrive/{trip_id}   arrive   — 到达接客点
 * POST   /api/drivers/{id}/start/{trip_id}    start    — 开始行程
 * POST   /api/drivers/{id}/complete/{trip_id} complete — 完成行程 + 自动结算
 * GET    /api/drivers/{id}/orders             orders   — 我的历史行程
 *
 * 驱动身份从 DriverAuth 中间件注入的 request->attributes->get('driver') 取。
 * URL 里的 {id} 也会被中间件校验匹配（防水平权限）。
 */
class DriverTripController extends Controller
{
    public function __construct(private readonly TripOrchestrator $orchestrator)
    {
    }

    /**
     * 接单（race condition：Redis NX + DB lockForUpdate 双重保护）。
     */
    public function accept(Request $request, int $driverId, int $tripId): JsonResponse
    {
        $driver = $request->attributes->get('driver');
        $trip = Trip::find($tripId);

        if (! $trip) {
            return response()->json(['error' => 'trip_not_found'], 404);
        }

        try {
            $trip = $this->orchestrator->accept($trip, $driver);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            return response()->json(['error' => $e->getMessage()], $code >= 400 ? $code : 422);
        }

        return response()->json([
            'trip' => $this->tripPayload($trip),
        ]);
    }

    /** 到达接客点（司机已抵达乘客起点，状态不变 driver_en_route）。 */
    public function arrive(Request $request, int $driverId, int $tripId): JsonResponse
    {
        $driver = $request->attributes->get('driver');
        $trip = Trip::find($tripId);
        if (! $trip) return response()->json(['error' => 'trip_not_found'], 404);

        try {
            $trip = $this->orchestrator->arrive($trip, $driver);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['trip' => $this->tripPayload($trip)]);
    }

    /** 开始行程（driver_en_route → on_trip）。 */
    public function start(Request $request, int $driverId, int $tripId): JsonResponse
    {
        $driver = $request->attributes->get('driver');
        $trip = Trip::find($tripId);
        if (! $trip) return response()->json(['error' => 'trip_not_found'], 404);

        try {
            $trip = $this->orchestrator->start($trip, $driver);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['trip' => $this->tripPayload($trip)]);
    }

    /** 完成行程（on_trip → completed + Order 自动创建）。 */
    public function complete(Request $request, int $driverId, int $tripId): JsonResponse
    {
        $driver = $request->attributes->get('driver');
        $trip = Trip::find($tripId);
        if (! $trip) return response()->json(['error' => 'trip_not_found'], 404);

        try {
            $trip = $this->orchestrator->complete($trip, $driver);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['trip' => $this->tripPayload($trip, withOrder: true)]);
    }

    /** 我的历史行程（司机视角：driver_id = 当前 driver）。 */
    public function orders(Request $request, int $driverId): JsonResponse
    {
        $driver = $request->attributes->get('driver');
        $trips = Trip::query()
            ->with(['order'])
            ->where('driver_id', $driver->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'trips' => $trips->map(fn ($t) => $this->tripPayload($t, withOrder: true))->values(),
        ]);
    }

    private function tripPayload(Trip $trip, bool $withOrder = false): array
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

        if ($withOrder && $trip->order) {
            $data['order'] = [
                'id'             => $trip->order->id,
                'amount'         => $trip->order->amount,
                'payment_status' => $trip->order->payment_status,
            ];
        }

        return $data;
    }
}
