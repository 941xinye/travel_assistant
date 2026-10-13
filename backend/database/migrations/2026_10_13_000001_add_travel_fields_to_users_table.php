<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 表补充出行业务字段（ARCHITECTURE.md 5.1）。
 * 保留 Laravel 默认的 name/email/password 列以兼容生态，新增手机号/偏好等。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->unique()->after('id');
            $table->string('avatar', 255)->nullable()->after('name');
            $table->string('default_city', 50)->default('北京')->after('avatar');
            $table->json('preferences')->nullable()->after('default_city'); // {"vehicle_pref":"comfort","price_sensitivity":0.7}
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar', 'default_city', 'preferences']);
        });
    }
};
