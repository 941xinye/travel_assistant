<?php

namespace App\Jobs;

use App\Services\AI\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 行程完成后异步向量化写入 pgvector user_trip_embeddings（ARCHITECTURE.md 5.4 / 模块 6 行程闭环）。
 *
 * 触发点：TripSummarizer 生成行程总结后（TODO Phase 5 行程闭环接线）
 *   PersistTripEmbeddingJob::dispatch($userId, $tripId, $summaryText);
 * LLM 调用 30s 级耗时 → 必须走队列，不阻塞 HTTP 请求。
 */
class PersistTripEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly int $userId,
        private readonly ?int $tripId,
        private readonly string $content,
    ) {}

    public function handle(EmbeddingService $embeddings): void
    {
        try {
            $literal = '['.implode(',', $embeddings->embed($this->content)).']';

            DB::connection('pgsql_geo')->insert(
                'INSERT INTO user_trip_embeddings (user_id, trip_id, content, embedding, created_at) '
                .'VALUES (?, ?, ?, ?::vector, ?)',
                [$this->userId, $this->tripId, $this->content, $literal, now()]
            );

            Log::info('trip embedding persisted', ['user_id' => $this->userId, 'trip_id' => $this->tripId]);
        } catch (\Throwable $e) {
            // pgvector 不可用（无 API key / 本地无 Postgres / 无 vector 扩展）→ 静默跳过
            // 不要 throw 让队列重试（embed API key 缺失是永久性错误，重试无意义）
            Log::warning('trip embedding skipped (pgvector unavailable): '.$e->getMessage(), [
                'user_id' => $this->userId,
                'trip_id' => $this->tripId,
            ]);
        }
    }
}
