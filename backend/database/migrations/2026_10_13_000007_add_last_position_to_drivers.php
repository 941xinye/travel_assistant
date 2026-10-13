<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 司机最后位置上报时间（PersistDriverLocationJob 双写 MySQL 侧，ARCHITECTURE.md 模块 4） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('last_position_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('last_position_at');
        });
    }
};
