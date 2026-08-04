<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Support;

use Kode\Pays\Enum\Currency;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\TestCase;

/**
 * 金额值对象单元测试
 */
class MoneyTest extends TestCase
{
    /**
     * 测试由主单位构造（元换算为分）
     */
    public function testFromMajorConvertsToMinor(): void
    {
        $money = Money::fromMajor(99.90, 'CNY');
        $this->assertSame(9990, $money->getMinorAmount());
        $this->assertSame('99.90', $money->getAmount());
        $this->assertSame(Currency::CNY, $money->getCurrency());
    }

    /**
     * 测试由最小单位构造
     */
    public function testFromMinor(): void
    {
        $money = Money::fromMinor(10589, Currency::CNY);
        $this->assertSame(10589, $money->getMinorAmount());
        $this->assertSame('105.89', $money->getAmount());
    }

    /**
     * 测试加法返回新实例且不可变
     */
    public function testAddIsImmutable(): void
    {
        $a = Money::fromMinor(10000, 'CNY');
        $b = Money::fromMinor(5000, 'CNY');
        $sum = $a->add($b);

        $this->assertSame(15000, $sum->getMinorAmount());
        $this->assertSame(10000, $a->getMinorAmount(), '原对象不应被修改');
    }

    /**
     * 测试减法
     */
    public function testSubtract(): void
    {
        $a = Money::fromMinor(10000, 'CNY');
        $b = Money::fromMinor(3000, 'CNY');
        $this->assertSame(7000, $a->subtract($b)->getMinorAmount());
    }

    /**
     * 测试乘法并四舍五入
     */
    public function testMultiplyRounds(): void
    {
        $price = Money::fromMinor(9990, 'CNY');
        $tax = $price->multiply(0.06);
        $this->assertSame(599, $tax->getMinorAmount());
    }

    /**
     * 测试整数因子乘法
     */
    public function testMultiplyByInt(): void
    {
        $this->assertSame(200, Money::fromMinor(100, 'CNY')->multiply(2)->getMinorAmount());
    }

    /**
     * 测试比较
     */
    public function testCompareTo(): void
    {
        $a = Money::fromMinor(100, 'CNY');
        $b = Money::fromMinor(200, 'CNY');
        $this->assertSame(-1, $a->compareTo($b));
        $this->assertSame(1, $b->compareTo($a));
        $this->assertSame(0, $a->compareTo(Money::fromMinor(100, 'CNY')));
    }

    /**
     * 测试相等判断
     */
    public function testEquals(): void
    {
        $this->assertTrue(Money::fromMinor(100, 'CNY')->equals(Money::fromMinor(100, 'CNY')));
        $this->assertFalse(Money::fromMinor(100, 'CNY')->equals(Money::fromMinor(101, 'CNY')));
    }

    /**
     * 测试跨币种算术抛出无效参数异常
     */
    public function testDifferentCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromMinor(100, 'CNY')->add(Money::fromMinor(50, 'USD'));
    }

    /**
     * 测试格式化展示
     */
    public function testFormat(): void
    {
        $this->assertSame('¥99.90', Money::fromMinor(9990, 'CNY')->format());
        $this->assertSame('$10.00', Money::fromMinor(1000, 'USD')->format());
    }

    /**
     * 测试零小数位币种（日元）格式化
     */
    public function testZeroDecimalFormat(): void
    {
        $money = Money::fromMajor(100, 'JPY');
        $this->assertSame(100, $money->getMinorAmount());
        $this->assertSame('¥100', $money->format());
        $this->assertSame('100', $money->getAmount());
    }

    /**
     * 测试正负零判断
     */
    public function testSignHelpers(): void
    {
        $this->assertTrue(Money::fromMinor(0, 'CNY')->isZero());
        $this->assertTrue(Money::fromMinor(10, 'CNY')->isPositive());
        $this->assertTrue(Money::fromMinor(-10, 'CNY')->isNegative());
        $this->assertFalse(Money::fromMinor(10, 'CNY')->isNegative());
    }
}
