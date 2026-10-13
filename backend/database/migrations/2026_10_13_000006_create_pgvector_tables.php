<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pgvector 用户历史出行向量化（ARCHITECTURE.md 5.4），运行在 pgsql_geo 连接。
 * 本迁移自行 CREATE EXTENSION vector，不依赖 000005 是否已跑。
 * 运行验证依赖 docker PostGIS 容器（本机 ServBay PG 无 vector 扩展）：
 *   php artisan migrate --path=database/migrations/2026_10_13_000006_create_pgvector_tables.php
 */
return new class extends Migration
{
    protected $connection = 'pgsql_geo';

    public function up(): void
    {
        if (app()->environment('testing')) return;

        $conn = Schema::connection($this->connection);

        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS vector');

        // 用户出行向量（语义检索"常去哪儿""喜欢什么路线"）
        $conn->create('user_trip_embeddings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('trip_id')->nullable();
            $table->text('content'); // 行程摘要原始文本，用于相似度搜索返回
            $table->timestampTz('created_at')->nullable();
            // embedding vector(N) 列在下方以原生 DDL 添加（Blueprint 不支持 vector 类型）
        });

        // 维度与 EMBEDDING_MODEL 对齐：BAAI/bge-m3 = 1024，text-embedding-3-small = 1536
        $dim = max(1, (int) env('EMBEDDING_DIM', 1024));
        DB::connection($this->connection)->statement(
            "ALTER TABLE user_trip_embeddings ADD COLUMN embedding vector({$dim}) NOT NULL"
        );

        // HNSW 近似最近邻索引（余弦距离 <=> 操作符）
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_user_trip_embeddings_hnsw ON user_trip_embeddings '
            .'USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)'
        );
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_user_trip_embeddings_user ON user_trip_embeddings (user_id)'
        );
    }

    public function down(): void
    {
        if (app()->environment('testing')) return;

        Schema::connection($this->connection)->dropIfExists('user_trip_embeddings');
    }
};
