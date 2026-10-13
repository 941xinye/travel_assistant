<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 司机表（ARCHITECTURE.md 5.1） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('license_plate', 20)->nullable();
            $table->json('vehicle_info')->nullable();       // {"model":"丰田凯美瑞","year":2022}
            $table->decimal('rating', 3, 2)->default(5.00); // 平均评分
            $table->unsignedInteger('total_rides')->default(0);
            $table->enum('status', ['offline', 'online_idle', 'online_busy', 'on_trip'])->default('offline');
            $table->boolean('certified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
