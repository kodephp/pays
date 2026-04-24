<?php

declare(strict_types=1);

namespace Kode\Pays\Async;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Event\Events;
use Kode\Pays\Facade\Pay;

/**
 * 异步通知处理器
 *
 * 提供同步和异步（协程/多进程）两种模式处理支付网关的异步通知。
 * 协程模式需要 PHP 8.1+ 的 Fiber 支持或 Swoole/Workerman 扩展。
 *
 * 使用示例：
 * ```php
 * use Kode\Pays\Async\AsyncNotifyHandler;
 *
 * // 同步处理
 * $handler = new AsyncNotifyHandler();
 * $handler->handle($gateway, $_POST, function ($data) {
 *     // 处理业务逻辑
 *     return true;
 * });
 *
 * // 协程模式批量处理（需安装 kode/fiber 或 Swoole）
 * $handler->handleConcurrent([
 *     ['gateway' => $wechat, 'data' => $notify1],
 *     ['gateway' => $alipay, 'data' => $notify2],
 * ], $callback);
 * ```
 */
class AsyncNotifyHandler
{
    /**
     * 处理单条通知（同步模式）
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $data 通知数据
     * @param callable $callback 业务处理回调，参数为通知数据，返回 bool 表示处理是否成功
     * @return array<string, mixed> 处理结果
     * @throws PayException
     */
    public function handle(GatewayInterface $gateway, array $data, callable $callback): array
    {
        // 触发通知接收事件
        Pay::emit(Events::NOTIFY_RECEIVED, [
            'gateway' => $gateway::getName(),
            'data' => $data,
        ]);

        // 验证签名
        if (!$gateway->verifyNotify($data)) {
            return [
                'success' => false,
                'message' => '签名验证失败',
            ];
        }

        // 触发验证通过事件
        Pay::emit(Events::NOTIFY_VERIFIED, [
            'gateway' => $gateway::getName(),
            'data' => $data,
        ]);

        // 执行业务回调
        $result = $callback($data);

        return [
            'success' => (bool) $result,
            'message' => $result ? '处理成功' : '业务处理失败',
        ];
    }

    /**
     * 批量并发处理通知（协程模式）
     *
     * 需要 PHP 8.1+ Fiber 或 Swoole/Workerman 协程环境。
     * 如果环境不支持协程，自动降级为串行处理。
     *
     * @param array<int, array{gateway: GatewayInterface, data: array<string, mixed>}> $tasks 任务列表
     * @param callable $callback 业务处理回调
     * @return array<int, array<string, mixed>> 各任务处理结果
     */
    public function handleConcurrent(array $tasks, callable $callback): array
    {
        // 检查是否支持协程
        if ($this->isCoroutineSupported()) {
            return $this->handleWithCoroutine($tasks, $callback);
        }

        // 降级为串行处理
        $results = [];
        foreach ($tasks as $index => $task) {
            $results[$index] = $this->handle($task['gateway'], $task['data'], $callback);
        }

        return $results;
    }

    /**
     * 使用协程并发处理
     *
     * @param array<int, array{gateway: GatewayInterface, data: array<string, mixed>}> $tasks
     * @param callable $callback
     * @return array<int, array<string, mixed>>
     */
    protected function handleWithCoroutine(array $tasks, callable $callback): array
    {
        $results = [];

        // 如果安装了 kode/fiber，使用其协程调度器
        if (class_exists(\Kode\Fiber\Scheduler::class)) {
            $scheduler = new \Kode\Fiber\Scheduler();

            foreach ($tasks as $index => $task) {
                $scheduler->add(function () use ($task, $callback, $index, &$results): void {
                    $results[$index] = $this->handle($task['gateway'], $task['data'], $callback);
                });
            }

            $scheduler->run();

            return $results;
        }

        // 如果安装了 Swoole，使用 Swoole 协程
        if (extension_loaded('swoole')) {
            \Swoole\Coroutine\run(function () use ($tasks, $callback, &$results): void {
                foreach ($tasks as $index => $task) {
                    \Swoole\Coroutine\create(function () use ($task, $callback, $index, &$results): void {
                        $results[$index] = $this->handle($task['gateway'], $task['data'], $callback);
                    });
                }
            });

            return $results;
        }

        // 使用 PHP 原生 Fiber（PHP 8.1+）
        if (PHP_VERSION_ID >= 80100 && class_exists(\Fiber::class)) {
            $fibers = [];

            foreach ($tasks as $index => $task) {
                $fiber = new \Fiber(function () use ($task, $callback): array {
                    return $this->handle($task['gateway'], $task['data'], $callback);
                });
                $fiber->start();
                $fibers[$index] = $fiber;
            }

            foreach ($fibers as $index => $fiber) {
                $results[$index] = $fiber->getReturn();
            }

            return $results;
        }

        // 最终降级为串行
        foreach ($tasks as $index => $task) {
            $results[$index] = $this->handle($task['gateway'], $task['data'], $callback);
        }

        return $results;
    }

    /**
     * 判断是否支持协程
     */
    protected function isCoroutineSupported(): bool
    {
        return class_exists(\Kode\Fiber\Scheduler::class)
            || extension_loaded('swoole')
            || (PHP_VERSION_ID >= 80100 && class_exists(\Fiber::class));
    }
}
