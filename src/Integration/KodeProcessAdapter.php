<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Core\PayException;

/**
 * kode/process 多进程集成适配器
 *
 * 当项目中安装了 kode/process 时，提供多进程并行处理能力，
 * 适用于批量对账、批量退款等 CPU 密集型或 IO 密集型任务。
 */
class KodeProcessAdapter
{
    /**
     * 使用进程池批量处理支付任务
     *
     * @param array<int, \Closure> $tasks 任务闭包列表
     * @param int $poolSize 进程池大小
     * @return array<int, mixed> 处理结果
     * @throws PayException
     */
    public static function pool(array $tasks, int $poolSize = 4): array
    {
        if (class_exists(\Kode\Process\ProcessPool::class)) {
            return self::runWithKodePool($tasks, $poolSize);
        }

        // 降级为串行处理
        return self::runSequential($tasks);
    }

    /**
     * 使用 kode/process 进程池执行
     *
     * @param array<int, \Closure> $tasks
     * @param int $poolSize
     * @return array<int, mixed>
     * @throws PayException
     */
    protected static function runWithKodePool(array $tasks, int $poolSize): array
    {
        try {
            $pool = new \Kode\Process\ProcessPool($poolSize);
            $results = [];

            foreach ($tasks as $index => $task) {
                $pool->submit(function () use ($task, $index, &$results) {
                    $results[$index] = $task();
                });
            }

            $pool->wait();

            return $results;
        } catch (\Throwable $e) {
            throw PayException::configError('kode/process 进程池执行失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 串行执行（降级方案）
     *
     * @param array<int, \Closure> $tasks
     * @return array<int, mixed>
     */
    protected static function runSequential(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $index => $task) {
            $results[$index] = $task();
        }

        return $results;
    }

    /**
     * 判断是否支持多进程
     */
    public static function isSupported(): bool
    {
        return class_exists(\Kode\Process\ProcessPool::class);
    }
}
