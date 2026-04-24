<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline\Middleware;

use Closure;
use Kode\Pays\Core\PayException;

/**
 * 限流中间件
 *
 * 基于令牌桶算法实现请求限流，防止短时间内发送过多请求导致被封禁。
 * 支持按网关独立限流和全局共享限流。
 *
 * 使用示例：
 * ```php
 * new RateLimitMiddleware([
 *     'max_requests' => 10,      // 每秒最大请求数
 *     'window_seconds' => 1,     // 时间窗口（秒）
 *     'key' => 'wechat',         // 限流标识
 * ]);
 * ```
 */
class RateLimitMiddleware
{
    /**
     * 限流配置
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * 令牌桶存储
     *
     * @var array<string, array{ tokens: float, last_time: int }>
     */
    protected static array $buckets = [];

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 限流配置
     *        - max_requests: 时间窗口内最大请求数（默认 10）
     *        - window_seconds: 时间窗口秒数（默认 1）
     *        - key: 限流标识（默认 'global'）
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_requests' => 10,
            'window_seconds' => 1,
            'key' => 'global',
        ], $config);
    }

    /**
     * 处理请求
     *
     * @param array<string, mixed> $payload 请求载荷
     * @param Closure $next 下一个处理步骤
     * @return array<string, mixed>
     * @throws PayException
     */
    public function handle(array $payload, Closure $next): array
    {
        $key = $this->config['key'];
        $maxRequests = (int) $this->config['max_requests'];
        $windowSeconds = (int) $this->config['window_seconds'];

        if (!$this->acquire($key, $maxRequests, $windowSeconds)) {
            throw PayException::configError('请求过于频繁，请稍后再试');
        }

        return $next($payload);
    }

    /**
     * 获取令牌
     *
     * @param string $key 限流标识
     * @param int $maxRequests 最大请求数
     * @param int $windowSeconds 时间窗口
     * @return bool 是否获取成功
     */
    protected function acquire(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $rate = (float) $maxRequests / $windowSeconds;

        if (!isset(self::$buckets[$key])) {
            self::$buckets[$key] = [
                'tokens' => (float) $maxRequests - 1,
                'last_time' => $now,
            ];

            return true;
        }

        $bucket = &self::$buckets[$key];
        $elapsed = $now - $bucket['last_time'];
        $bucket['tokens'] = min(
            (float) $maxRequests,
            $bucket['tokens'] + $elapsed * $rate,
        );
        $bucket['last_time'] = $now;

        if ($bucket['tokens'] < 1) {
            return false;
        }

        $bucket['tokens'] -= 1;

        return true;
    }
}
