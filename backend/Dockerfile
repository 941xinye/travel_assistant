# Octane HTTP Server 与 原生 Swoole WS Server 共用镜像
# ws 服务通过 command: php bin/ws-server.php 覆盖启动命令
FROM php:8.3-swoole-cli

# 系统依赖 + PHP 扩展
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql bcmath zip pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 先装依赖（利用层缓存）
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# 再拷贝源码
COPY . .
RUN composer dump-autoload --optimize \
    && php artisan config:cache || true

EXPOSE 8080 8081

CMD ["php", "artisan", "octane:start", "--host=0.0.0.0", "--port=8080"]
