<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline\Middleware;

use Closure;
use Kode\Pays\Core\PayException;

/**
 * 熔断器中间件
 *
 * 基于「断路器」模式保护不稳定支付通道：当连续失败次数达到阈值时，
 * 熔断器打开（OPEN），在冷却时间内直接拒绝请求，避免对故障网关的雪崩式调用；
 * 冷却结束后进入半开（HALF_OPEN）状态放行少量探测请求，成功则闭合（CLOSED），
 * 失败则重新打开。与 RetryMiddleware（重试）、RateLimitMiddleware（限流）互补。
 *
 * 使用示例：
 * ```php
 * new CircuitBreakerMiddleware([
 *     'failure_threshold' => 5,     // 连续失败达到该值后熔断
 *     'cooldown_ms' => 30000,        // 熔断后冷却时间（毫秒）
 *     'success_threshold' => 1,      // 半开状态下成功几次后闭合
 *     'key' => 'wechat',             // 熔断维度标识
 * ]);
 * ```
 */
class CircuitBreakerMiddleware
{
    /** 闭合态：正常放行请求 */
    public const string STATE_CLOSED = 'CLOSED';

    /** 打开态：拒绝请求，进入冷却 */
    public const string STATE_OPEN = 'OPEN';

    /** 半开态：放行少量探测请求以探测恢复情况 */
    public const string STATE_HALF_OPEN = 'HALF_OPEN';

    /**
     * 熔断状态存储（按 key 维度，进程内共享）
     *
     * @var array<string, array{ state: string, failures: int, opened_at: int, half_open_successes: int }>
     */
    protected static array $store = [];

    /**
     * 熔断配置
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 熔断配置
     *        - failure_threshold: 连续失败阈值（默认 5）
     *        - cooldown_ms: 熔断冷却时间毫秒数（默认 30000）
     *        - success_threshold: 半开态成功几次后闭合（默认 1）
     *        - key: 熔断维度标识（默认 'global'）
     *        - on_open_message: 熔断时抛出的错误信息
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'failure_threshold' => 5,
            'cooldown_ms' => 30000,
            'success_threshold' => 1,
            'key' => 'global',
            'on_open_message' => '支付通道熔断中，请稍后重试',
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
        $entry = $this->ensureEntry($key);

        // 打开态：未过冷却期则直接拒绝
        if ($entry['state'] === self::STATE_OPEN) {
            if (!$this->isCooldownElapsed($entry)) {
                throw PayException::gatewayError($this->config['on_open_message']);
            }

            // 冷却结束，进入半开态进行探测
            $entry = $this->transition($key, self::STATE_HALF_OPEN);
        }

        try {
            $result = $next($payload);
        } catch (\Throwable $e) {
            $this->onFailure($key);

            throw $e;
        }

        $this->onSuccess($key);

        return $result;
    }

    /**
     * 记录一次成功调用
     *
     * @param string $key
     */
    protected function onSuccess(string $key): void
    {
        $entry = $this->ensureEntry($key);

        if ($entry['state'] === self::STATE_HALF_OPEN) {
            $entry['half_open_successes'] += 1;

            if ($entry['half_open_successes'] >= (int) $this->config['success_threshold']) {
                // 探测成功，恢复闭合态
                $this->reset($key);

                return;
            }

            $this->persist($key, $entry);

            return;
        }

        // 闭合态成功：清零失败计数
        if ($entry['failures'] !== 0) {
            $entry['failures'] = 0;
            $this->persist($key, $entry);
        }
    }

    /**
     * 记录一次失败调用
     *
     * @param string $key
     */
    protected function onFailure(string $key): void
    {
        $entry = $this->ensureEntry($key);

        if ($entry['state'] === self::STATE_HALF_OPEN) {
            // 半开态下任何失败都立即重新打开
            $this->transition($key, self::STATE_OPEN);

            return;
        }

        $entry['failures'] += 1;

        if ($entry['failures'] >= (int) $this->config['failure_threshold']) {
            $this->transition($key, self::STATE_OPEN);

            return;
        }

        $this->persist($key, $entry);
    }

    /**
     * 判断打开态是否冷却结束
     *
     * @param array{ state: string, failures: int, opened_at: int, half_open_successes: int } $entry
     * @return bool
     */
    protected function isCooldownElapsed(array $entry): bool
    {
        $cooldown = (int) $this->config['cooldown_ms'];

        return (self::nowMs() - $entry['opened_at']) >= $cooldown;
    }

    /**
     * 切换状态并持久化
     *
     * @param string $key
     * @param string $state
     * @return array{ state: string, failures: int, opened_at: int, half_open_successes: int }
     */
    protected function transition(string $key, string $state): array
    {
        $entry = $this->ensureEntry($key);

        $entry['state'] = $state;

        if ($state === self::STATE_OPEN) {
            $entry['opened_at'] = self::nowMs();
            $entry['half_open_successes'] = 0;
        } elseif ($state === self::STATE_HALF_OPEN) {
            $entry['failures'] = 0;
            $entry['half_open_successes'] = 0;
        } elseif ($state === self::STATE_CLOSED) {
            $entry['failures'] = 0;
            $entry['half_open_successes'] = 0;
            $entry['opened_at'] = 0;
        }

        $this->persist($key, $entry);

        return $entry;
    }

    /**
     * 获取或初始化指定 key 的熔断条目
     *
     * @param string $key
     * @return array{ state: string, failures: int, opened_at: int, half_open_successes: int }
     */
    protected function ensureEntry(string $key): array
    {
        if (!isset(self::$store[$key])) {
            self::$store[$key] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'opened_at' => 0,
                'half_open_successes' => 0,
            ];
        }

        return self::$store[$key];
    }

    /**
     * 持久化指定 key 的熔断条目
     *
     * @param string $key
     * @param array{ state: string, failures: int, opened_at: int, half_open_successes: int } $entry
     */
    protected function persist(string $key, array $entry): void
    {
        self::$store[$key] = $entry;
    }

    /**
     * 重置指定 key 的熔断状态为闭合（测试或运维手动恢复用）
     *
     * @param string $key
     */
    public function reset(string $key = 'global'): void
    {
        self::$store[$key] = [
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'opened_at' => 0,
            'half_open_successes' => 0,
        ];
    }

    /**
     * 获取当前熔断状态
     *
     * @param string $key
     * @return string 见 STATE_* 常量
     */
    public function getState(string $key = 'global'): string
    {
        return $this->ensureEntry($key)['state'];
    }

    /**
     * 返回当前毫秒时间戳
     *
     * @return int
     */
    protected static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
