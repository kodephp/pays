<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\PayException;
use Kode\Pays\Core\QrEntry;
use Kode\Pays\Tests\TestCase;

/**
 * UnifiedQrRouter 单元测试（不发起真实 HTTP）
 */
class UnifiedQrRouterTest extends TestCase
{
    private function router(): TestableQrRouter
    {
        $router = new TestableQrRouter(['fakechan' => ['app_id' => 'x']]);
        $router->fake = new FakeGateway();

        return $router;
    }

    public function testCreateEntryReturnsQrEntry(): void
    {
        $entry = $this->router()->createEntry(['fakechan'], 100, '商品付款');

        $this->assertInstanceOf(QrEntry::class, $entry);
        $this->assertSame(['fakechan'], $entry->getChannels());
        $this->assertSame(100, $entry->getAmount());
        $this->assertSame('商品付款', $entry->getDescription());
        $this->assertTrue($entry->isPending());
        $this->assertNotNull($entry->getQrContent());
    }

    public function testCreateEntryRejectsEmptyChannels(): void
    {
        $this->expectException(PayException::class);
        $this->router()->createEntry([], 100, 'x');
    }

    public function testCreateEntryRejectsUnconfiguredChannel(): void
    {
        $this->expectException(PayException::class);
        $this->router()->createEntry(['unknown'], 100, 'x');
    }

    public function testRouteOrdersAndReturnsQrEntry(): void
    {
        $router = $this->router();
        $entry = $router->createEntry(['fakechan'], 100, '商品付款');
        $order = $router->route($entry->getRouterId(), 'fakechan');

        $this->assertInstanceOf(QrEntry::class, $order);
        $this->assertTrue($order->isOrdered());
        $this->assertNotNull($order->getOutTradeNo());
        $this->assertStringStartsWith('weixin://wxpay/bizpayurl', (string) $order->getCodeUrl());
        $this->assertCount(1, $router->fake->calls);
    }

    public function testRouteIsIdempotentWhenOrdered(): void
    {
        $router = $this->router();
        $entry = $router->createEntry(['fakechan'], 100, '商品付款');

        $router->route($entry->getRouterId(), 'fakechan');
        $router->route($entry->getRouterId(), 'fakechan');

        // 已下单后再次 route 应直接返回已有订单，不再调用网关
        $this->assertCount(1, $router->fake->calls);
    }

    public function testRouteRejectsDisallowedChannel(): void
    {
        $router = $this->router();
        $entry = $router->createEntry(['fakechan'], 100, '商品付款');

        $this->expectException(PayException::class);
        $router->route($entry->getRouterId(), 'otherchan');
    }

    public function testRouteRejectsClosedEntry(): void
    {
        $router = $this->router();
        $entry = $router->createEntry(['fakechan'], 100, '商品付款');
        $router->close($entry->getRouterId());

        $this->expectException(PayException::class);
        $router->route($entry->getRouterId(), 'fakechan');
    }

    public function testCloseUnknownEntryThrows(): void
    {
        $this->expectException(PayException::class);
        $this->router()->close('UR_NOT_EXIST');
    }

    public function testClosePaidEntryThrows(): void
    {
        $router = $this->router();
        $entry = $router->createEntry(['fakechan'], 100, '商品付款');
        $router->markPaid($entry->getRouterId());

        $this->expectException(PayException::class);
        $router->close($entry->getRouterId());
    }

    public function testMarkPaidAndMarkClosedTransitions(): void
    {
        $router = $this->router();
        $entry = $router->createEntry(['fakechan'], 100, '商品付款');

        $this->assertTrue($router->markPaid($entry->getRouterId()));
        $this->assertTrue($router->getEntry($entry->getRouterId())->isPaid());

        // 重新打开一个入口测试关闭
        $entry2 = $router->createEntry(['fakechan'], 200, '第二笔');
        $this->assertTrue($router->markClosed($entry2->getRouterId()));
        $this->assertTrue($router->getEntry($entry2->getRouterId())->isClosed());
    }

    public function testGetPendingEntriesExcludesTerminal(): void
    {
        $router = $this->router();
        $a = $router->createEntry(['fakechan'], 100, 'A');
        $b = $router->createEntry(['fakechan'], 200, 'B');
        $router->markPaid($a->getRouterId());
        $c = $router->createEntry(['fakechan'], 300, 'C');
        $router->markClosed($c->getRouterId());

        $pending = $router->getPendingEntries();

        $this->assertArrayHasKey($b->getRouterId(), $pending);
        $this->assertArrayNotHasKey($a->getRouterId(), $pending);
        $this->assertArrayNotHasKey($c->getRouterId(), $pending);
        $this->assertContainsOnlyInstancesOf(QrEntry::class, $pending);
    }
}
