<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 订单/支付（ARCHITECTURE.md 5.1）。
 *
 * 由 TripOrchestrator 在行程 completed 时自动创建。
 * Step 5 前支付状态保持 pending（无真实支付接入）。
 */
class Order extends Model
{
    protected $fillable = [
        'trip_id', 'driver_id', 'user_id',
        'amount', 'cost_breakdown',
        'payment_method', 'payment_status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_breakdown' => 'array',
            'paid_at'        => 'datetime',
            'amount'         => 'float',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
