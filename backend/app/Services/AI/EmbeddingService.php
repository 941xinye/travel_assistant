<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 文本向量化（OpenAI-compatible /embeddings HTTP 直调，与 LLM 同款集成路径决策，见 ARCHITECTURE.md 2.3）。
 * DeepSeek 无 embedding API → 独立 EMBEDDING_* 配置：
 * 硅基流动 BAAI/bge-m3 = 1024 维 / OpenAI text-embedding-3-small = 1536 维。
 */
class EmbeddingService
{
    private string $baseUrl;

    private string $apiKey;

    private string $model;

    private int $dim;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.embedding.base_url'), '/');
        $this->apiKey = (string) config('services.embedding.api_key');
        $this->model = (string) config('services.embedding.model');
        $this->dim = (int) config('services.embedding.dim', 1024);
    }

    /**
     * 文本 → 向量。
     *
     * @return float[] 长度 = EMBEDDING_DIM
     *
     * @throws RuntimeException API 未配置 / 请求失败 / 维度不匹配
     */
    public function embed(string $text): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('EMBEDDING_API_KEY 未配置，请检查 backend/.env');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $this->model,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Embedding 请求失败 [{$response->status()}]: {$response->body()}");
        }

        $vector = $response->json('data.0.embedding');
        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('Embedding 响应缺少 data.0.embedding');
        }

        if ($this->dim > 0 && count($vector) !== $this->dim) {
            throw new RuntimeException('Embedding 维度不匹配: 期望 '.$this->dim.'，实际 '.count($vector).'（检查 EMBEDDING_DIM 与 EMBEDDING_MODEL 是否一致）');
        }

        return array_map('floatval', $vector);
    }

    /**
     * 语义检索"常去哪儿""喜欢什么路线"（pgvector 余弦距离 <=> 排序，原生 SQL）。
     *
     * @return array[] [{id, trip_id, content, similarity}, ...] similarity ∈ [0,1] 降序
     */
    public function searchSimilar(int $userId, string $queryText, int $limit = 5): array
    {
        $literal = $this->toPgvectorLiteral($this->embed($queryText));

        return DB::connection('pgsql_geo')->select(
            'SELECT id, trip_id, content, 1 - (embedding <=> ?::vector) AS similarity '
            .'FROM user_trip_embeddings WHERE user_id = ? '
            .'ORDER BY embedding <=> ?::vector LIMIT ?',
            [$literal, $userId, $literal, $limit]
        );
    }

    private function toPgvectorLiteral(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }
}
