<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Event;

use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Tests\TestCase;

/**
 * 事件分发器单元测试
 *
 * 验证监听器注册、优先级、payload 修改、传播中断、查询与移除等行为。
 */
class EventDispatcherTest extends TestCase
{
    /**
     * 测试注册监听器并触发分发
     */
    public function testListenAndDispatch(): void
    {
        $dispatcher = new EventDispatcher();
        $received = null;

        $dispatcher->listen('pay.success', static function ($payload) use (&$received) {
            $received = $payload;
        });

        $dispatcher->dispatch('pay.success', ['order_id' => 'O1']);

        $this->assertSame(['order_id' => 'O1'], $received);
    }

    /**
     * 测试优先级顺序：高优先级先执行
     */
    public function testPriorityOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $sequence = [];

        // 注册顺序故意打乱，验证按优先级排序
        $dispatcher->listen('evt', static function () use (&$sequence) {
            $sequence[] = 'low';
        }, 0);
        $dispatcher->listen('evt', static function () use (&$sequence) {
            $sequence[] = 'high';
        }, 100);
        $dispatcher->listen('evt', static function () use (&$sequence) {
            $sequence[] = 'mid';
        }, 50);

        $dispatcher->dispatch('evt');

        // 优先级数值越大越先执行：high -> mid -> low
        $this->assertSame(['high', 'mid', 'low'], $sequence);
    }

    /**
     * 测试监听器修改 payload 并传递给下一个
     */
    public function testPayloadModification(): void
    {
        $dispatcher = new EventDispatcher();
        $final = null;

        // 第一个监听器（高优先级）修改 payload
        $dispatcher->listen('evt', static function ($payload) {
            $payload['step'] = 'first';

            return $payload;
        }, 10);

        // 第二个监听器（低优先级）接收修改后的 payload
        $dispatcher->listen('evt', static function ($payload) use (&$final) {
            $payload['step'] .= '-second';
            $final = $payload;

            return $payload;
        }, 0);

        $result = $dispatcher->dispatch('evt', ['step' => 'initial']);

        $this->assertSame('first-second', $final['step']);
        $this->assertSame('first-second', $result['step']);
    }

    /**
     * 测试监听器返回 false 中断传播
     */
    public function testFalseStopsPropagation(): void
    {
        $dispatcher = new EventDispatcher();
        $secondCalled = false;

        // 第一个监听器（高优先级）返回 false
        $dispatcher->listen('evt', static function () {
            return false;
        }, 10);

        // 第二个监听器不应被调用
        $dispatcher->listen('evt', static function () use (&$secondCalled) {
            $secondCalled = true;

            return 'should-not-reach';
        }, 0);

        $result = $dispatcher->dispatch('evt', 'initial');

        $this->assertFalse($result);
        $this->assertFalse($secondCalled, '第二个监听器不应被调用');
    }

    /**
     * 测试 hasListeners 查询
     */
    public function testHasListeners(): void
    {
        $dispatcher = new EventDispatcher();

        $this->assertFalse($dispatcher->hasListeners('evt'));

        $dispatcher->listen('evt', static fn () => null);

        $this->assertTrue($dispatcher->hasListeners('evt'));
        $this->assertFalse($dispatcher->hasListeners('other'));
    }

    /**
     * 测试 forget 移除监听器
     */
    public function testForget(): void
    {
        $dispatcher = new EventDispatcher();
        $called = false;

        $dispatcher->listen('evt', static function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($dispatcher->hasListeners('evt'));

        $dispatcher->forget('evt');

        $this->assertFalse($dispatcher->hasListeners('evt'));

        $dispatcher->dispatch('evt');
        $this->assertFalse($called, 'forget 后监听器不应被调用');
    }

    /**
     * 测试分发无监听器的事件，返回原始 payload
     */
    public function testDispatchNoListeners(): void
    {
        $dispatcher = new EventDispatcher();

        $payload = ['key' => 'value'];
        $result = $dispatcher->dispatch('no.listeners', $payload);

        $this->assertSame($payload, $result);
    }
}
