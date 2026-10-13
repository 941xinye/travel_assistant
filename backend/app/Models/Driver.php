<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 司机档案（ARCHITECTURE.md 5.1 drivers 表）。
 * 与 User 1:1 关系，所有业务身份通过 user_id 关联。
 */
class Driver extends Model
{
    protected $fillable = [
        'user_id',
        'license_plate',
        'vehicle_info',
        'rating',
        'total_rides',
        'status',
        'certified',
    ];

    protected $hidden = [
        'id', // 司机 API 用 user_id 作为主键暴露
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_info' => 'array',
            'certified'    => 'boolean',
            'rating'       => 'float',
            'last_position_at' => 'datetime',
        ];
    }

    /**
     * 关联用户账号。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 在线状态快速判断。
     */
    public function isOnline(): bool
    {
        return in_array($this->status, ['online_idle', 'online_busy'], true);
    }
}
