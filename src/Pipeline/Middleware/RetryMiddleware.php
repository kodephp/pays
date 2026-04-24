<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline\Middleware;

use Closure;
use Kode\Pays\Core\PayException;
use Kode\Pays\Exception\NetworkException;

/**
 * 重试中间件
 *
 * 当请求因网络异常失败时自动重试，支持指数退避策略。
 * 仅对网络异常（NetworkException）进行重试，业务异常直接抛出。
 *
 * 使用示例：
 * ```php
 * new RetryMiddleware([
 *     'max_attempts' => 3,       // 最大重试次数
 *     'delay_ms' => 1000,        // 初始延迟（毫秒）
 *     'backoff_multiplier' => 2, // 退避倍数
 *     'retry_on' => [NetworkException::class], // 可重试的异常类
 * ]);
 * ```
 */
class RetryMiddleware
{
    /**
     * 重试配置
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 重试配置
     *        - max_attempts: 最大重试次数（默认 3）
     *        - delay_ms: 初始延迟毫秒数（默认 1000）
     *        - backoff_multiplier: 退避倍数（默认 2）
     *        - retry_on: 可重试的异常类名数组（默认 [NetworkException::class]）
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2,
            'retry_on' => [NetworkException::class],
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
        $maxAttempts = (int) $this->config['max_attempts'];
        $delayMs = (int) $this->config['delay_ms'];
        $multiplier = (float) $this->config['backoff_multiplier'];
        $retryOn = (array) $this->config['retry_on'];

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $next($payload);
            } catch (\Throwable $e) {
                $lastException = $e;

                // 检查是否属于可重试的异常类型
                if (!$this->shouldRetry($e, $retryOn)) {
                    throw $e;
                }

                // 最后一次尝试，直接抛出
                if ($attempt >= $maxAttempts) {
                    break;
                }

                // 指数退避延迟
                $sleepMs = $delayMs * pow($multiplier, $attempt - 1);
                $this->sleep($sleepMs);
            }
        }

        throw $lastException ?? PayException::networkError('请求重试全部失败');
    }

    /**
     * 判断异常是否可重试
     *
     * @param \Throwable $exception 异常对象
     * @param string[] $retryOn 可重试的异常类名
     * @return bool
     */
    protected function shouldRetry(\Throwable $exception, array $retryOn): bool
    {
        foreach ($retryOn as $className) {
            if ($exception instanceof $className) {
                return true;
            }
        }

        return false;
    }

    /**
     * 休眠指定毫秒数
     *
     * @param int $milliseconds 毫秒数
     */
    protected function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
