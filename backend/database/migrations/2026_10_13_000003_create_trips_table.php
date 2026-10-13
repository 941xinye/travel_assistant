<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 行程主表（ARCHITECTURE.md 5.1） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();

            $table->string('origin_text', 200);
            $table->decimal('origin_lng', 10, 7);
            $table->decimal('origin_lat', 10, 7);
            $table->string('destination_text', 200);
            $table->decimal('destination_lng', 10, 7);
            $table->decimal('destination_lat', 10, 7);

            $table->dateTime('pickup_time')->nullable();          // 期望上车时间
            $table->dateTime('actual_pickup_time')->nullable();
            $table->dateTime('dropoff_time')->nullable();
            $table->dateTime('actual_dropoff_time')->nullable();

            $table->enum('status', [
                'pending', 'matched', 'driver_en_route',
                'on_trip', 'completed', 'cancelled', 'exception',
            ])->default('pending');

            $table->json('constraints')->nullable();       // ["顺路可以接"]
            $table->json('route_plan')->nullable();        // 规划路线方案快照
            $table->json('cost_snapshot')->nullable();     // 费用预估快照
            $table->json('exception_info')->nullable();    // 异常详情

            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_user_status');
            $table->index(['driver_id', 'status'], 'idx_driver_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
