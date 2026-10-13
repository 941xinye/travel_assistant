<?php

namespace App\Console\Commands;

use App\Jobs\PersistDriverLocationJob;
use Illuminate\Console\Command;

/**
 * 司机位置持久化消费进程（ARCHITECTURE.md 模块 4 管道终端）。
 * 常驻循环：driver:location:stream（rpop）→ PersistDriverLocationJob 同步消费。
 * 部署：docker compose 的 queue 服务 / 本地 php artisan positions:consume。
 */
class ConsumeDriverPositions extends Command
{
    protected $signature = 'positions:consume {--once : 只消费一轮退出（调试用）}';

    protected $description = '消费 Redis Streams 司机位置并双写 MySQL / PostGIS';

    public function handle(): int
    {
        do {
            $n = (new PersistDriverLocationJob())->handle();

            if ($n === 0 && ! $this->option('once')) {
                usleep(500_000); // 空转 0.5s，避免忙轮询
            }
        } while (! $this->option('once'));

        $this->info("consumed {$n} position(s)");

        return self::SUCCESS;
    }
}
