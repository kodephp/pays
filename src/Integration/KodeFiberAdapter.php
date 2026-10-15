<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * kode/fibers 协程集成适配器
 *
 * 当项目中安装了 kode/fibers 时，提供更强大的协程调度能力，
 * 支持批量并发请求、协程池、超时控制等。
 */
class KodeFiberAdapter
{
    /**
     * 批量并发执行支付请求
     *
     * @param array<int, array{gateway: GatewayInterface, method: string, params: array<mixed>}> $tasks 任务列表
     * @param int $timeout 超时时间（秒）
     * @return array<int, mixed> 执行结果
     * @throws PayException
     */
    public static function concurrent(array $tasks, int $timeout = 30): array
    {
        // 优先使用 kode/fibers
        if (class_exists(\Kode\Fibers\Fibers::class)) {
            return self::runWithKode($tasks, $timeout);
        }

        // 降级到 Swoole
        if (extension_loaded('swoole')) {
            return self::runWithSwoole($tasks, $timeout);
        }

        // 降级到 PHP 原生 Fiber（PHP ≥ 8.4 恒可用）
        return self::runWithNativeFiber($tasks);
    }

    /**
     * 使用 kode/fibers 执行
     *
     * @param array<int, array{gateway: GatewayInterface, method: string, params: array<mixed>}> $tasks
     * @param int $timeout
     * @return array<int, mixed>
     */
    protected static function runWithKode(array $tasks, int $timeout): array
    {
        $results = [];
        $wrapped = [];

        foreach ($tasks as $index => $task) {
            $wrapped[$index] = function () use ($task, $index, &$results): void {
                try {
                    $gateway = $task['gateway'];
                    $method = $task['method'];
                    $results[$index] = $gateway->$method(...$task['params']);
                } catch (\Throwable $e) {
                    $results[$index] = $e;
                }
            };
        }

        \Kode\Fibers\Fibers::concurrent($wrapped, (float) $timeout);

        return $results;
    }

    /**
     * 使用 Swoole 协程执行
     *
     * @param array<int, array{gateway: GatewayInterface, method: string, params: array<mixed>}> $tasks
     * @param int $timeout
     * @return array<int, mixed>
     */
    protected static function runWithSwoole(array $tasks, int $timeout): array
    {
        $results = [];

        \Swoole\Coroutine\run(function () use ($tasks, &$results): void {
            foreach ($tasks as $index => $task) {
                \Swoole\Coroutine\create(function () use ($task, $index, &$results): void {
                    try {
                        $gateway = $task['gateway'];
                        $method = $task['method'];
                        $results[$index] = $gateway->$method(...$task['params']);
                    } catch (\Throwable $e) {
                        $results[$index] = $e;
                    }
                });
            }
        });

        return $results;
    }

    /**
     * 使用 PHP 原生 Fiber 执行
     *
     * @param array<int, array{gateway: GatewayInterface, method: string, params: array<mixed>}> $tasks
     * @return array<int, mixed>
     */
    protected static function runWithNativeFiber(array $tasks): array
    {
        $results = [];
        $fibers = [];

        foreach ($tasks as $index => $task) {
            $fiber = new \Fiber(function () use ($task) {
                $gateway = $task['gateway'];
                $method = $task['method'];
                return $gateway->$method(...$task['params']);
            });
            $fiber->start();
            $fibers[$index] = $fiber;
        }

        foreach ($fibers as $index => $fiber) {
            try {
                $results[$index] = $fiber->getReturn();
            } catch (\Throwable $e) {
                $results[$index] = $e;
            }
        }

        return $results;
    }

    /**
     * 判断是否支持协程
     */
    public static function isSupported(): bool
    {
        // PHP ≥ 8.4 原生 Fiber 恒可用
        return true;
    }
}
