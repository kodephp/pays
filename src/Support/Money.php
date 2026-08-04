<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use Kode\Pays\Enum\Currency;

/**
 * 金额值对象
 *
 * 以「最小货币单位（分）」的整数存储金额，从根本上规避浮点精度问题；
 * 所有算术运算均返回新的 Money 实例，保持不可变（immutable）语义。
 *
 * 使用示例：
 * ```php
 * $price = Money::fromMajor(99.90, 'CNY');   // 9990 分
 * $tax   = $price->multiply(0.06);            // 599 分（四舍五入）
 * $total = $price->add($tax);                 // 10589 分
 * echo $total->format();                      // ¥105.89
 * $total->equals(Money::fromMinor(10589, 'CNY')); // true
 * ```
 */
final class Money
{
    /**
     * 构造函数
     *
     * @param int $minorAmount 最小货币单位金额（如分），必须为整数
     * @param Currency $currency 币种
     */
    public function __construct(
        public readonly int $minorAmount,
        public readonly Currency $currency,
    ) {
    }

    /**
     * 由最小货币单位（分）创建金额
     *
     * @param int $minorAmount 最小货币单位金额
     * @param Currency|string $currency 币种枚举或代码
     * @return self
     */
    public static function fromMinor(int $minorAmount, Currency|string $currency): self
    {
        return new self($minorAmount, self::resolveCurrency($currency));
    }

    /**
     * 由主单位金额（元）创建金额，按币种小数位换算到最小单位
     *
     * @param int|float|string $major 主单位金额（如 99.90）
     * @param Currency|string $currency 币种枚举或代码
     * @return self
     */
    public static function fromMajor(int|float|string $major, Currency|string $currency): self
    {
        $currency = self::resolveCurrency($currency);
        $factor = 10 ** $currency->minorUnits();
        $minor = self::toMinor($major, $factor);

        return new self($minor, $currency);
    }

    /**
     * 解析币种参数（接受枚举或字符串代码）
     *
     * @param Currency|string $currency
     * @return Currency
     * @throws \InvalidArgumentException
     */
    private static function resolveCurrency(Currency|string $currency): Currency
    {
        return $currency instanceof Currency
            ? $currency
            : Currency::fromCodeOrFail($currency);
    }

    /**
     * 将主单位金额按因子换算为最小单位整数（优先使用 bcmath 保证精度）
     *
     * @param int|float|string $major
     * @param int $factor
     * @return int
     */
    private static function toMinor(int|float|string $major, int $factor): int
    {
        if (is_int($major)) {
            return $major * $factor;
        }

        if (function_exists('bcmul')) {
            return (int) bcmul((string) $major, (string) $factor, 0);
        }

        return (int) round((float) $major * $factor);
    }

    /**
     * 返回主单位金额字符串（如 "105.89"），避免浮点误差
     *
     * @return string
     */
    public function getAmount(): string
    {
        $factor = 10 ** $this->currency->minorUnits();

        if (function_exists('bcdiv')) {
            return bcdiv((string) $this->minorAmount, (string) $factor, $this->currency->minorUnits());
        }

        return number_format($this->minorAmount / $factor, $this->currency->minorUnits(), '.', '');
    }

    /**
     * 返回最小货币单位金额（整数分）
     *
     * @return int
     */
    public function getMinorAmount(): int
    {
        return $this->minorAmount;
    }

    /**
     * 返回币种
     *
     * @return Currency
     */
    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    /**
     * 判断两个金额币种是否一致
     *
     * @param self $other
     * @return bool
     */
    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /**
     * 金额相加，返回新实例
     *
     * @param self $other 加数（币种须一致）
     * @return self
     * @throws \InvalidArgumentException 币种不一致时抛出
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorAmount + $other->minorAmount, $this->currency);
    }

    /**
     * 金额相减，返回新实例
     *
     * @param self $other 减数（币种须一致）
     * @return self
     * @throws \InvalidArgumentException 币种不一致时抛出
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorAmount - $other->minorAmount, $this->currency);
    }

    /**
     * 按因子缩放（乘），返回新实例（四舍五入）
     *
     * @param int|float|string $factor 缩放因子
     * @return self
     */
    public function multiply(int|float|string $factor): self
    {
        if (function_exists('bcmul')) {
            $minor = (int) bcmul((string) $this->minorAmount, (string) $factor, 0);

            return new self($minor, $this->currency);
        }

        return new self((int) round($this->minorAmount * (float) $factor), $this->currency);
    }

    /**
     * 比较两金额大小
     *
     * @param self $other 比较对象（币种须一致）
     * @return int -1 小于 / 0 等于 / 1 大于
     * @throws \InvalidArgumentException 币种不一致时抛出
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorAmount <=> $other->minorAmount;
    }

    /**
     * 判断是否相等
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorAmount === $other->minorAmount;
    }

    /**
     * 是否为零金额
     *
     * @return bool
     */
    public function isZero(): bool
    {
        return $this->minorAmount === 0;
    }

    /**
     * 是否为正数
     *
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->minorAmount > 0;
    }

    /**
     * 是否为负数
     *
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->minorAmount < 0;
    }

    /**
     * 格式化为带符号的展示字符串（如 "¥105.89"）
     *
     * @param int|null $decimals 小数位，默认取币种小数位
     * @return string
     */
    public function format(?int $decimals = null): string
    {
        $decimals ??= $this->currency->minorUnits();
        $amount = $this->currency->isZeroDecimal()
            ? (string) $this->minorAmount
            : $this->getAmount();

        return $this->currency->symbol() . number_format((float) $amount, $decimals, '.', '');
    }

    /**
     * 断言币种一致，不一致抛出无效参数异常
     *
     * @param self $other
     * @throws \InvalidArgumentException
     */
    private function assertSameCurrency(self $other): void
    {
        if (!$this->isSameCurrency($other)) {
            throw new \InvalidArgumentException(sprintf(
                '金额币种不一致: %s 与 %s',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }

    /**
     * 字符串表示（带符号的金额）
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->format();
    }
}
