<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 自然语言 → 结构化出行意图（LLM OpenAI-compatible HTTP 直调，POC 已验证）。
 * 暂不引入 Laravel AI SDK（见 ARCHITECTURE.md 2.3 集成路径决策）。
 */
class IntentParser
{
    private string $baseUrl;

    private string $apiKey;

    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.llm.base_url'), '/');
        $this->apiKey = (string) config('services.llm.api_key');
        $this->model = (string) config('services.llm.model');
    }

    /**
     * 解析自然语言出行需求。
     *
     * @return array 出结构化意图（origin/destination/waypoints/pickup_time/passengers/constraints/...）
     *
     * @throws RuntimeException LLM 响应非法或 JSON 解析失败
     */
    public function parse(string $input): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('LLM_API_KEY 未配置，请检查 backend/.env');
        }

        // 注入当前日期，供"明天下午3点"等相对时间推算
        $system = str_replace('{{today}}', now()->toDateString(), $this->systemPrompt());

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post("{$this->baseUrl}/chat/completions", [
                'model'       => $this->model,
                'temperature' => 0.1,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $input],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("LLM 请求失败 [{$response->status()}]: {$response->body()}");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('LLM 响应缺少 choices.0.message.content');
        }

        return $this->decodeJson($content);
    }

    /**
     * 从 LLM 输出中提取 JSON（容忍 ```json 围栏）。
     */
    private function decodeJson(string $content): array
    {
        $text = trim($content);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $text);
        }

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('LLM 输出不是合法 JSON: ' . mb_substr($content, 0, 200));
        }

        return $decoded;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
你是出行意图解析专家。严格输出一个 JSON 对象，不要输出任何 JSON 之外的文字。

当前日期是 {{today}}。

JSON schema:
{
    "origin": {"text": "起点文字", "coords": null},
    "destination": {"text": "终点文字", "coords": null},
    "waypoints": [
        {"text": "中途停靠点文字或null", "coords": null, "purpose": "pickup_passenger|dropoff_passenger|via"}
    ],
    "pickup_time": "ISO8601时间或null",
    "pickup_time_range": {"earliest": null, "latest": null},
    "passengers": 1,
    "constraints": ["约束条件"],
    "vehicle_preference": null,
    "budget": null,
    "special_needs": null,
    "confidence": 0.0,
    "needs_clarification": false,
    "clarification_question": null
}

规则:
- waypoints 只有在有明确的中间停靠/接人/送人时才填，否则返回 []
- "顺路可以接" 属于模糊约束 → 放入 constraints
- "接一个朋友从望京" → waypoints = [{"text": "望京", "purpose": "pickup_passenger"}]
- 时间推断: "明天下午3点" = 明天 15:00（根据当前日期 {{today}} 推算具体日期）
- confidence < 0.6 时 needs_clarification=true 并给出追问
PROMPT;
    }
}
