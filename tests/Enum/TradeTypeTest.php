<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Enum;

use Kode\Pays\Enum\TradeType;
use Kode\Pays\Tests\TestCase;

/**
 * 交易类型枚举单元测试
 */
class TradeTypeTest extends TestCase
{
    /**
     * 测试直接匹配枚举名
     */
    public function testFromRawMatchesEnumName(): void
    {
        $this->assertSame(TradeType::JSAPI, TradeType::fromRaw('JSAPI'));
        $this->assertSame(TradeType::NATIVE, TradeType::fromRaw('NATIVE'));
        $this->assertSame(TradeType::MICROPAY, TradeType::fromRaw('MICROPAY'));
        $this->assertSame(TradeType::APP, TradeType::fromRaw('APP'));
    }

    /**
     * 测试常见别名归一化
     */
    public function testFromRawAliases(): void
    {
        $this->assertSame(TradeType::JSAPI, TradeType::fromRaw('OFFICIAL'));
        $this->assertSame(TradeType::JSAPI, TradeType::fromRaw('MP'));
        $this->assertSame(TradeType::NATIVE, TradeType::fromRaw('QRCODE'));
        $this->assertSame(TradeType::MINI, TradeType::fromRaw('MINIPROGRAM'));
        $this->assertSame(TradeType::BARCODE, TradeType::fromRaw('WAVECODE'));
        $this->assertSame(TradeType::QUICK_WAP, TradeType::fromRaw('QUICKWAP'));
    }

    /**
     * 测试 null 与未知值返回 null
     */
    public function testFromRawReturnsNull(): void
    {
        $this->assertNull(TradeType::fromRaw(null));
        $this->assertNull(TradeType::fromRaw('WEIRD_TYPE'));
    }
}
