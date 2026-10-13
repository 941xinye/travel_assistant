<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 行程主表（ARCHITECTURE.md 5.1）。
 *
 * 状态流转由 TripOrchestrator 统一管理（勿在 Controller 直接 update status）：
 *   pending → matched → driver_en_route → on_trip → completed
 *   * → cancelled  （任意中间态可取消；终态 completed/cancelled/exception 不可再变）
 */
class Trip extends Model
{
    // 状态常量
    public const STATUS_PENDING        = 'pending';
    public const STATUS_MATCHED        = 'matched';
    public const STATUS_DRIVER_EN_ROUTE = 'driver_en_route';
    public const STATUS_ON_TRIP        = 'on_trip';
    public const STATUS_COMPLETED      = 'completed';
    public const STATUS_CANCELLED      = 'cancelled';
    public const STATUS_EXCEPTION      = 'exception';

    /** 终态集合（不可再流转） */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_EXCEPTION,
    ];

    protected $fillable = [
        'user_id', 'driver_id',
        'origin_text', 'origin_lng', 'origin_lat',
        'destination_text', 'destination_lng', 'destination_lat',
        'pickup_time', 'actual_pickup_time', 'dropoff_time', 'actual_dropoff_time',
        'status', 'constraints', 'route_plan', 'cost_snapshot', 'summary_text', 'exception_info',
    ];

    protected function casts(): array
    {
        return [
            'pickup_time'         => 'datetime',
            'actual_pickup_time'  => 'datetime',
            'dropoff_time'        => 'datetime',
            'actual_dropoff_time' => 'datetime',
            'constraints'         => 'array',
            'route_plan'          => 'array',
            'cost_snapshot'       => 'array',
            'exception_info'      => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /** 是否已到终态 */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
