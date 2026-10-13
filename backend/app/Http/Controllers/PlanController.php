<?php

namespace App\Http\Controllers;

use App\Services\AI\IntentParser;
use App\Services\Geo\RoutePlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/plan
 * 核心链路：自然语言 → LLM 意图解析 → geocode → 多方案路线对比
 */
class PlanController extends Controller
{
    public function store(Request $request, IntentParser $parser, RoutePlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'input' => 'required|string|min:2|max:500',
        ]);

        // 1. LLM 意图解析
        try {
            $intent = $parser->parse($data['input']);
        } catch (RuntimeException $e) {
            return response()->json([
                'error'   => 'intent_parse_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        // 2. 置信度不足 → 交还给前端追问
        if (! empty($intent['needs_clarification'])) {
            return response()->json([
                'needs_clarification' => true,
                'question'            => $intent['clarification_question'] ?? '请补充出行需求',
                'intent'              => $intent,
            ], 422);
        }

        // 3. geocode + 多方案路线对比
        try {
            $comparison = $planner->planFromIntent($intent);
        } catch (RuntimeException $e) {
            return response()->json([
                'error'   => 'route_planning_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'intent' => $intent,
            ...$comparison,
        ]);
    }
}
