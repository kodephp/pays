<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Enum;

use Kode\Pays\Enum\TradeStatus;
use Kode\Pays\Tests\TestCase;

/**
 * 交易状态枚举单元测试
 */
class TradeStatusTest extends TestCase
{
    /**
     * 测试直接匹配枚举名
     */
    public function testFromRawMatchesEnumName(): void
    {
        $this->assertSame(TradeStatus::SUCCESS, TradeStatus::fromRaw('SUCCESS'));
        $this->assertSame(TradeStatus::FAILED, TradeStatus::fromRaw('FAILED'));
    }

    /**
     * 测试常见别名归一化
     */
    public function testFromRawAliases(): void
    {
        $this->assertSame(TradeStatus::SUCCESS, TradeStatus::fromRaw('TRADE_SUCCESS'));
        $this->assertSame(TradeStatus::SUCCESS, TradeStatus::fromRaw('FINISHED'));
        $this->assertSame(TradeStatus::SUCCESS, TradeStatus::fromRaw('PAID'));
        $this->assertSame(TradeStatus::PENDING, TradeStatus::fromRaw('NOTPAY'));
        $this->assertSame(TradeStatus::PENDING, TradeStatus::fromRaw('WAIT_BUYER_PAY'));
        $this->assertSame(TradeStatus::FAILED, TradeStatus::fromRaw('FAIL'));
        $this->assertSame(TradeStatus::REFUNDING, TradeStatus::fromRaw('REFUNDING'));
    }

    /**
     * 测试大小写不敏感
     */
    public function testFromRawIsCaseInsensitive(): void
    {
        $this->assertSame(TradeStatus::SUCCESS, TradeStatus::fromRaw('trade_success'));
        $this->assertSame(TradeStatus::PENDING, TradeStatus::fromRaw('NotPay'));
    }

    /**
     * 测试 null 与未知值返回 null
     */
    public function testFromRawReturnsNull(): void
    {
        $this->assertNull(TradeStatus::fromRaw(null));
        $this->assertNull(TradeStatus::fromRaw('UNKNOWN_STATUS_XYZ'));
    }

    /**
     * 测试终态判断
     */
    public function testIsTerminal(): void
    {
        $this->assertTrue(TradeStatus::SUCCESS->isTerminal());
        $this->assertTrue(TradeStatus::FAILED->isTerminal());
        $this->assertTrue(TradeStatus::CLOSED->isTerminal());
        $this->assertTrue(TradeStatus::REVOKED->isTerminal());
        $this->assertFalse(TradeStatus::PENDING->isTerminal());
        $this->assertFalse(TradeStatus::USERPAYING->isTerminal());
    }

    /**
     * 测试成功态判断
     */
    public function testIsSuccess(): void
    {
        $this->assertTrue(TradeStatus::SUCCESS->isSuccess());
        $this->assertFalse(TradeStatus::PENDING->isSuccess());
    }
}
