<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\PayException;
use Kode\Pays\Core\QrEntry;
use Kode\Pays\Tests\TestCase;

/**
 * QrEntry 值对象单元测试
 */
class QrEntryTest extends TestCase
{
    private function sampleArray(): array
    {
        return [
            'router_id' => 'UR20260808120000AB12CD',
            'channels' => ['wechat', 'alipay'],
            'amount' => 100,
            'description' => '商品付款',
            'status' => QrEntry::STATUS_PENDING,
            'channel' => null,
            'out_trade_no' => null,
            'pay_url' => null,
            'attach' => ['order_id' => 1],
            'created_at' => 1700000000,
            'paid_at' => null,
        ];
    }

    public function testFromArrayBuildsTypedAccessors(): void
    {
        $entry = QrEntry::fromArray($this->sampleArray());

        $this->assertSame('UR20260808120000AB12CD', $entry->getRouterId());
        $this->assertSame(['wechat', 'alipay'], $entry->getChannels());
        $this->assertSame(100, $entry->getAmount());
        $this->assertSame('商品付款', $entry->getDescription());
        $this->assertSame(QrEntry::STATUS_PENDING, $entry->getStatus());
        $this->assertNull($entry->getChannel());
        $this->assertNull($entry->getOutTradeNo());
        $this->assertNull($entry->getPayUrl());
        $this->assertSame(['order_id' => 1], $entry->getAttach());
        $this->assertSame(1700000000, $entry->getCreatedAt());
        $this->assertNull($entry->getPaidAt());
    }

    public function testToArrayRoundTrip(): void
    {
        $entry = QrEntry::fromArray($this->sampleArray());
        $back = QrEntry::fromArray($entry->toArray());

        $this->assertEquals($entry, $back);
        $this->assertSame($entry->toArray(), $back->toArray());
    }

    public function testStatusHelpers(): void
    {
        $pending = QrEntry::fromArray($this->sampleArray());
        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isOrdered());
        $this->assertTrue($pending->isRoutable());

        $ordered = QrEntry::fromArray(['status' => QrEntry::STATUS_ORDERED] + $this->sampleArray());
        $this->assertTrue($ordered->isOrdered());
        $this->assertTrue($ordered->isRoutable());

        $paid = QrEntry::fromArray(['status' => QrEntry::STATUS_PAID] + $this->sampleArray());
        $this->assertTrue($paid->isPaid());
        $this->assertFalse($paid->isRoutable());

        $closed = QrEntry::fromArray(['status' => QrEntry::STATUS_CLOSED] + $this->sampleArray());
        $this->assertTrue($closed->isClosed());
        $this->assertFalse($closed->isRoutable());
    }

    public function testQrContentUsesPayUrl(): void
    {
        $entry = QrEntry::fromArray(
            ['pay_url' => 'weixin://wxpay/bizpayurl?pr=abc'] + $this->sampleArray(),
        );

        $this->assertSame('weixin://wxpay/bizpayurl?pr=abc', $entry->getQrContent());
        $this->assertSame('weixin://wxpay/bizpayurl?pr=abc', $entry->getCodeUrl());
    }

    public function testMissingRouterIdThrows(): void
    {
        $this->expectException(PayException::class);
        QrEntry::fromArray(['amount' => 100]);
    }

    public function testMissingAmountThrows(): void
    {
        $this->expectException(PayException::class);
        QrEntry::fromArray(['router_id' => 'UR1']);
    }
}
