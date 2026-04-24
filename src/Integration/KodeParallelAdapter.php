<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Core\PayException;

/**
 * kode/parallel 多线程集成适配器
 *
 * 当项目中安装了 kode/parallel 且启用了 ext-parallel 扩展时，
 * 提供真正的多线程并行处理能力，适用于高并发场景。
 */
class KodeParallelAdapter
{
    /**
     * 使用多线程并行执行任务
     *
     * @param array<int, \Closure> $tasks 任务闭包列表
     * @return array<int, mixed> 执行结果
     * @throws PayException
     */
    public static function run(array $tasks): array
    {
        if (class_exists(\Kode\Parallel\Runtime\Runtime::class)) {
            return self::runWithKode($tasks);
        }

        // 降级为串行处理
        return self::runSequential($tasks);
    }

    /**
     * 使用 kode/parallel 执行
     *
     * @param array<int, \Closure> $tasks
     * @return array<int, mixed>
     * @throws PayException
     */
    protected static function runWithKode(array $tasks): array
    {
        try {
            $runtime = new \Kode\Parallel\Runtime\Runtime();
            $futures = [];

            foreach ($tasks as $index => $task) {
                $taskWrapper = new \Kode\Parallel\Task\Task($task);
                $futures[$index] = $runtime->run($taskWrapper);
            }

            $results = [];
            foreach ($futures as $index => $future) {
                $results[$index] = $future->get();
            }

            $runtime->close();

            return $results;
        } catch (\Throwable $e) {
            throw PayException::configError('kode/parallel 多线程执行失败：' . $e->getMessage(), $e);
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
     * 判断是否支持多线程
     */
    public static function isSupported(): bool
    {
        return class_exists(\Kode\Parallel\Runtime\Runtime::class)
            && extension_loaded('parallel');
    }
}
