<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 行程结束后 AI 出行日记生成（Phase 5：闭环 + 报告）。
 * 与 IntentParser 共用同一 LLM 配置（OpenAI-compatible HTTP 直调）。
 */
class TripSummarizer
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
     * 根据行程数据生成中文出行日记。
     *
     * @param array $trip 行程聚合数据：
     *   origin_text, destination_text, distance_m, duration_s, tolls,
     *   driver_info, started_at, finished_at, waypoints[]
     * @return string 行程总结文本
     */
    public function summarize(array $trip): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('LLM_API_KEY 未配置，请检查 backend/.env');
        }

        $system = <<<'PROMPT'
你是出行报告撰稿人。根据行程数据用中文写一段 150 字以内的出行日记，
语气轻松自然，包含：行程概览（起终点/距离/时长）、亮点或贴心提示。
严格输出纯文本，不要 Markdown 标记。
PROMPT;

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post("{$this->baseUrl}/chat/completions", [
                'model'       => $this->model,
                'temperature' => 0.7,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($trip, JSON_UNESCAPED_UNICODE)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("LLM 请求失败 [{$response->status()}]: {$response->body()}");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('LLM 响应为空');
        }

        return trim($content);
    }
}
