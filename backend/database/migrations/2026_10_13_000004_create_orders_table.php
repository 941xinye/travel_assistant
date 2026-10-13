<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 订单/支付表（ARCHITECTURE.md 5.1） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->decimal('amount', 10, 2);
            $table->json('cost_breakdown')->nullable();   // {"base":18,"distance":32.4,"tolls":10}
            $table->enum('payment_method', ['alipay', 'wechat', 'credit'])->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'disputed'])->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
