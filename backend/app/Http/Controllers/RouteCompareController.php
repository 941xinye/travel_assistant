<?php

namespace App\Http\Controllers;

use App\Services\Geo\RoutePlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * GET /api/routes/compare
 * 坐标直连版路线对比（跳过 AI 意图解析；origin/destination 为 "lng,lat"）
 */
class RouteCompareController extends Controller
{
    private const COORD_PATTERN = '/^\d{1,3}\.\d{1,7},\d{1,3}\.\d{1,7}$/';

    public function index(Request $request, RoutePlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'origin'        => ['required', 'regex:' . self::COORD_PATTERN],
            'destination'   => ['required', 'regex:' . self::COORD_PATTERN],
            'waypoints'     => ['nullable', 'array', 'max:16'],
            'waypoints.*'   => ['required', 'string', 'min:1', 'max:100'],
        ]);

        try {
            $comparison = $planner->compareCoords(
                $data['origin'],
                $data['destination'],
                $data['waypoints'] ?? [],
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'error'   => 'route_planning_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json($comparison);
    }
}
