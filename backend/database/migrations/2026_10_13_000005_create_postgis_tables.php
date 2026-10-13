<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PostGIS 空间表（ARCHITECTURE.md 5.2），运行在 pgsql_geo 连接。
 * 本地无 PostGIS 环境时跳过：php artisan migrate --path=... 或等 docker compose up postgis。
 */
return new class extends Migration
{
    protected $connection = 'pgsql_geo';

    public function up(): void
    {
        // 测试环境跳过：PostGIS 扩展不支持 SQLite，RefreshDatabase 会自动用 sqlite in-memory
        if (app()->environment('testing')) return;

        $conn = Schema::connection($this->connection);

        // 空间/向量扩展（postgis 容器镜像内已含 pgvector apt 包）
        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS vector');

        // 司机实时位置
        $conn->create('driver_positions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('driver_id');
            $table->decimal('speed', 6, 2)->nullable(); // km/h
            $table->integer('heading')->nullable();     // 0-360
            $table->timestampTz('recorded_at');
            // location 列在下方以原生 DDL 添加（Blueprint 不支持 geometry 类型）
        });

        DB::connection($this->connection)->statement(
            'ALTER TABLE driver_positions ADD COLUMN location geometry(Point, 4326) NOT NULL'
        );
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_driver_positions_gist ON driver_positions USING GIST (location)'
        );
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_driver_positions_time ON driver_positions (driver_id, recorded_at DESC)'
        );

        // 路线规划存储
        $conn->create('route_segments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('trip_id');
            $table->string('strategy', 20); // fastest / cheapest / scenic
            $table->integer('distance_m');
            $table->integer('duration_s');
            $table->decimal('cost', 8, 2)->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        DB::connection($this->connection)->statement(
            'ALTER TABLE route_segments ADD COLUMN geometry geometry(LineString, 4326) NOT NULL'
        );
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_route_segments_gist ON route_segments USING GIST (geometry)'
        );

        // 行程轨迹
        $conn->create('trip_trajectories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('trip_id');
            $table->unsignedBigInteger('driver_id');
            $table->decimal('speed', 6, 2)->nullable();
            $table->timestampTz('recorded_at');
        });

        DB::connection($this->connection)->statement(
            'ALTER TABLE trip_trajectories ADD COLUMN location geometry(Point, 4326) NOT NULL'
        );
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_trip_traj_time ON trip_trajectories (trip_id, recorded_at)'
        );
        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_trip_traj_gist ON trip_trajectories USING GIST (location)'
        );
    }

    public function down(): void
    {
        if (app()->environment('testing')) return;

        $conn = Schema::connection($this->connection);
        $conn->dropIfExists('trip_trajectories');
        $conn->dropIfExists('route_segments');
        $conn->dropIfExists('driver_positions');
    }
};
