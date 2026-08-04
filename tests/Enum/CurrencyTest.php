<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Enum;

use Kode\Pays\Enum\Currency;
use Kode\Pays\Tests\TestCase;

/**
 * 货币枚举单元测试
 */
class CurrencyTest extends TestCase
{
    /**
     * 测试大小写不敏感的代码解析
     */
    public function testFromCodeIsCaseInsensitive(): void
    {
        $this->assertSame(Currency::CNY, Currency::fromCode('CNY'));
        $this->assertSame(Currency::CNY, Currency::fromCode('cny'));
        $this->assertSame(Currency::USD, Currency::fromCode('usd'));
    }

    /**
     * 测试未知代码返回 null
     */
    public function testFromCodeReturnsNullForUnknown(): void
    {
        $this->assertNull(Currency::fromCode('XYZ'));
    }

    /**
     * 测试零小数位币种
     */
    public function testZeroDecimalCurrencies(): void
    {
        $this->assertSame(0, Currency::JPY->minorUnits());
        $this->assertSame(0, Currency::KRW->minorUnits());
        $this->assertSame(0, Currency::IDR->minorUnits());
        $this->assertTrue(Currency::JPY->isZeroDecimal());
        $this->assertFalse(Currency::CNY->isZeroDecimal());
    }

    /**
     * 测试常见币种小数位为 2
     */
    public function testTwoDecimalCurrencies(): void
    {
        foreach ([Currency::CNY, Currency::USD, Currency::EUR, Currency::GBP, Currency::HKD] as $c) {
            $this->assertSame(2, $c->minorUnits());
        }
    }

    /**
     * 测试 ISO 数字代码
     */
    public function testNumericCode(): void
    {
        $this->assertSame('156', Currency::CNY->numericCode());
        $this->assertSame('840', Currency::USD->numericCode());
        $this->assertSame('392', Currency::JPY->numericCode());
    }

    /**
     * 测试货币符号
     */
    public function testSymbol(): void
    {
        $this->assertSame('¥', Currency::CNY->symbol());
        $this->assertSame('$', Currency::USD->symbol());
        $this->assertSame('€', Currency::EUR->symbol());
    }
}
