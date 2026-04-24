<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Core\PayException;

/**
 * kode/limiting 限流器集成适配器
 *
 * 当项目中安装了 kode/limiting 时，提供更强大的限流能力，
 * 支持多种限流算法（令牌桶、漏桶、滑动窗口、固定窗口）。
 *
 * 使用示例：
 * ```php
 * $limiter = KodeLimitingAdapter::create('wechat', [
 *     'algorithm' => 'token_bucket',
 *     'capacity' => 10,
 *     'rate' => 1,
 * ]);
 *
 * if ($limiter->allow()) {
 *     // 执行支付请求
 * }
 * ```
 */
class KodeLimitingAdapter
{
    /**
     * 限流器实例缓存
     *
     * @var array<string, object>
     */
    protected static array $limiters = [];

    /**
     * 创建限流器
     *
     * @param string $key 限流标识
     * @param array<string, mixed> $config 限流配置
     *        - algorithm: 限流算法（token_bucket/leaky_bucket/sliding_window/fixed_window）
     *        - capacity: 容量
     *        - rate: 速率
     *        - window: 时间窗口（秒）
     * @return object|null
     */
    public static function create(string $key, array $config = []): ?object
    {
        if (isset(self::$limiters[$key])) {
            return self::$limiters[$key];
        }

        if (class_exists(\Kode\Limiting\Limiter::class)) {
            $limiter = self::createKodeLimiter($key, $config);
            self::$limiters[$key] = $limiter;

            return $limiter;
        }

        return null;
    }

    /**
     * 判断是否允许通过
     *
     * @param string $key 限流标识
     * @param int $tokens 需要的令牌数
     * @return bool
     */
    public static function allow(string $key, int $tokens = 1): bool
    {
        $limiter = self::$limiters[$key] ?? null;

        if ($limiter === null) {
            return true;
        }

        if (method_exists($limiter, 'allow')) {
            return $limiter->allow($tokens);
        }

        return true;
    }

    /**
     * 等待直到允许通过
     *
     * @param string $key 限流标识
     * @param int $tokens 需要的令牌数
     * @param int $timeout 超时时间（秒）
     * @return bool
     */
    public static function wait(string $key, int $tokens = 1, int $timeout = 10): bool
    {
        $limiter = self::$limiters[$key] ?? null;

        if ($limiter === null) {
            return true;
        }

        if (method_exists($limiter, 'wait')) {
            return $limiter->wait($tokens, $timeout);
        }

        return self::allow($key, $tokens);
    }

    /**
     * 使用 kode/limiting 创建限流器
     *
     * @param string $key
     * @param array<string, mixed> $config
     * @return object
     * @throws PayException
     */
    protected static function createKodeLimiter(string $key, array $config): object
    {
        try {
            $algorithm = $config['algorithm'] ?? 'token_bucket';

            return match ($algorithm) {
                'token_bucket' => new \Kode\Limiting\TokenBucket(
                    key: $key,
                    capacity: $config['capacity'] ?? 10,
                    rate: $config['rate'] ?? 1,
                ),
                'leaky_bucket' => new \Kode\Limiting\LeakyBucket(
                    key: $key,
                    capacity: $config['capacity'] ?? 10,
                    rate: $config['rate'] ?? 1,
                ),
                'sliding_window' => new \Kode\Limiting\SlidingWindow(
                    key: $key,
                    capacity: $config['capacity'] ?? 10,
                    window: $config['window'] ?? 60,
                ),
                'fixed_window' => new \Kode\Limiting\FixedWindow(
                    key: $key,
                    capacity: $config['capacity'] ?? 10,
                    window: $config['window'] ?? 60,
                ),
                default => throw PayException::configError("不支持的限流算法：{$algorithm}"),
            };
        } catch (\Throwable $e) {
            throw PayException::configError('kode/limiting 限流器创建失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 判断是否支持 kode/limiting
     */
    public static function isSupported(): bool
    {
        return class_exists(\Kode\Limiting\Limiter::class);
    }
}
