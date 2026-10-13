<?php

namespace App\Support;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

/**
 * 纯静态 JWT 工具（firebase/php-jwt 包装）。
 *
 * 设计要点：
 * - 无 Laravel 依赖 — bin/ws-server.php（原生 Swoole，无 Container）也能直接 use
 * - 密钥来源：优先 config('services.jwt.secret')，其次 env('JWT_SECRET')
 * - 算法：HS256（对称签名，足够当前规模）
 * - Token 结构：sub=user_id 或 driver_id, role=passenger|driver, exp=iat+TTL
 */
class Jwt
{
    /** 默认 24h 有效期（秒） */
    public const TTL = 86400;

    public const ALG = 'HS256';

    /**
     * 生成 JWT。
     *
     * @param  array  $payload  业务字段（sub, role 必带；其他自由扩展）
     * @param  int    $ttl      有效期秒数（默认 24h）
     * @return string
     */
    public static function encode(array $payload, int $ttl = self::TTL): string
    {
        $now = time();
        $claims = [
            'iat' => $now,
            'exp' => $now + $ttl,
            'iss' => 'fullstack-trip-assistant',
        ] + $payload;

        return FirebaseJWT::encode($claims, self::secret(), self::ALG);
    }

    /**
     * 解析并验证 JWT。
     *
     * @param  string  $token  JWT 字符串（支持含 "Bearer " 前缀）
     * @return array  payload 数组
     * @throws \RuntimeException  过期 / 签名无效 / 解析失败
     */
    public static function decode(string $token): array
    {
        $token = trim(str_starts_with($token, 'Bearer ') ? substr($token, 7) : $token);

        try {
            $decoded = FirebaseJWT::decode($token, new Key(self::secret(), self::ALG));
        } catch (ExpiredException $e) {
            throw new \RuntimeException('Token 已过期', 401);
        } catch (SignatureInvalidException $e) {
            throw new \RuntimeException('Token 签名无效', 401);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Token 解析失败: ' . $e->getMessage(), 401);
        }

        return (array) $decoded;
    }

    /**
     * 取密钥（不抛异常 — ws-server.php 环境下 config() 不可用时返回 env 值）。
     */
    protected static function secret(): string
    {
        // Laravel 环境：config() 可用
        if (function_exists('config')) {
            $secret = config('services.jwt.secret');
            if (! empty($secret)) {
                return $secret;
            }
        }

        // 通用：env() 直接读
        $secret = getenv('JWT_SECRET') ?: '';
        if ($secret === '') {
            // 兜底 — 从 .env 文件手动解析（ws-server.php 原生环境下 dotenv 未加载）
            $envFile = __DIR__ . '/../../.env';
            if (file_exists($envFile)) {
                foreach (file($envFile) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (str_starts_with($line, 'JWT_SECRET=')) {
                        $secret = trim(substr($line, 11));
                        break;
                    }
                }
            }
        }

        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET 未配置');
        }

        return $secret;
    }
}
