<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Pipeline;

use Kode\Pays\Pipeline\Pipeline;
use Kode\Pays\Tests\TestCase;

/**
 * 管道模式单元测试
 *
 * 验证 Pipeline 的中间件链式调用、顺序、短路等行为。
 */
class PipelineTest extends TestCase
{
    /**
     * 测试基础管道：send -> through(单个中间件) -> then(终点)
     */
    public function testBasicPipeline(): void
    {
        $result = (new Pipeline())
            ->send(10)
            ->through([
                static fn ($value, $next) => $next($value * 2),
            ])
            ->then(static fn ($value) => $value + 1);

        // 10 -> *2 = 20 -> +1 = 21
        $this->assertSame(21, $result);
    }

    /**
     * 测试多个中间件链式处理，验证执行顺序
     *
     * 中间件执行顺序（洋葱模型）：
     * - 第一个中间件先处理后传递给下一个
     * - 最后一个中间件处理后传递给终点
     */
    public function testMultipleMiddleware(): void
    {
        $calls = [];

        $result = (new Pipeline())
            ->send(1)
            ->through([
                // 通过引用记录调用顺序
                static function ($value, $next) use (&$calls) {
                    $calls[] = 'A-before';

                    return $next($value + 1);
                },
                static function ($value, $next) use (&$calls) {
                    $calls[] = 'B-before';

                    return $next($value + 10);
                },
            ])
            ->then(static function ($value) use (&$calls) {
                $calls[] = 'destination';

                return $value * 100;
            });

        // 数组反转后，第一个中间件是最后入栈的最外层
        // 执行顺序：A-before -> B-before -> destination
        $this->assertSame(['A-before', 'B-before', 'destination'], $calls);
        // 1 -> +1 = 2 -> +10 = 12 -> *100 = 1200
        $this->assertSame(1200, $result);
    }

    /**
     * 测试空中间件栈：直接传递给终点
     */
    public function testEmptyPipeline(): void
    {
        $result = (new Pipeline())
            ->send('hello')
            ->through([])
            ->then(static fn ($value) => strtoupper($value));

        $this->assertSame('HELLO', $result);
    }

    /**
     * 测试中间件短路：不调用 $next 直接返回值
     */
    public function testMiddlewareCanShortCircuit(): void
    {
        $destinationCalled = false;

        $result = (new Pipeline())
            ->send('input')
            ->through([
                // 第一个中间件短路，不调用 $next
                static function ($value, $next) {
                    return 'short-circuited:' . $value;
                },
                // 第二个中间件不应被调用
                static function ($value, $next) {
                    throw new \RuntimeException('第二个中间件不应被调用');
                },
            ])
            ->then(static function ($value) use (&$destinationCalled) {
                $destinationCalled = true;

                return 'destination:' . $value;
            });

        $this->assertSame('short-circuited:input', $result);
        $this->assertFalse($destinationCalled, '终点不应被调用');
    }
}
