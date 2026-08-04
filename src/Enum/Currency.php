<?php

declare(strict_types=1);

namespace Kode\Pays\Enum;

/**
 * 货币枚举
 *
 * 以 ISO 4217 为标准，封装货币代码、数字代码、最小货币单位（小数位）与符号。
 * 用于 Money 值对象的币种上下文，避免在不同网关间手工维护小数位与格式化规则。
 *
 * 使用示例：
 * ```php
 * $cny = Currency::fromCode('CNY');          // Currency::CNY
 * $amount = Money::fromMajor(99.90, 'CNY');  // 9990 分
 * echo $amount->format();                    // ¥99.90
 * ```
 */
enum Currency: string
{
    case CNY = 'CNY';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case HKD = 'HKD';
    case MOP = 'MOP';
    case TWD = 'TWD';
    case KRW = 'KRW';
    case SGD = 'SGD';
    case AUD = 'AUD';
    case CAD = 'CAD';
    case CHF = 'CHF';
    case MYR = 'MYR';
    case THB = 'THB';
    case PHP = 'PHP';
    case IDR = 'IDR';
    case INR = 'INR';
    case RUB = 'RUB';
    case BRL = 'BRL';

    /**
     * 返回该币种的 ISO 4217 数字代码
     *
     * @return string
     */
    public function numericCode(): string
    {
        return match ($this) {
            self::CNY => '156',
            self::USD => '840',
            self::EUR => '978',
            self::GBP => '826',
            self::JPY => '392',
            self::HKD => '344',
            self::MOP => '446',
            self::TWD => '901',
            self::KRW => '410',
            self::SGD => '702',
            self::AUD => '036',
            self::CAD => '124',
            self::CHF => '756',
            self::MYR => '458',
            self::THB => '764',
            self::PHP => '608',
            self::IDR => '360',
            self::INR => '356',
            self::RUB => '643',
            self::BRL => '986',
        };
    }

    /**
     * 返回该币种的最小货币单位小数位数（如 CNY=2、JPY=0）
     *
     * @return int
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::JPY, self::KRW, self::IDR => 0,
            default => 2,
        };
    }

    /**
     * 返回该币种的展示符号
     *
     * @return string
     */
    public function symbol(): string
    {
        return match ($this) {
            self::CNY, self::MOP => '¥',
            self::USD, self::AUD, self::CAD => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::JPY => '¥',
            self::HKD => 'HK$',
            self::TWD => 'NT$',
            self::KRW => '₩',
            self::SGD => 'S$',
            self::CHF => 'Fr',
            self::MYR => 'RM',
            self::THB => '฿',
            self::PHP => '₱',
            self::IDR => 'Rp',
            self::INR => '₹',
            self::RUB => '₽',
            self::BRL => 'R$',
        };
    }

    /**
     * 根据货币代码解析枚举（大小写不敏感）
     *
     * @param string $code 货币字母代码，如 CNY / cny
     * @return self|null 不匹配时返回 null
     */
    public static function fromCode(string $code): ?self
    {
        return self::tryFrom(strtoupper(trim($code)));
    }

    /**
     * 解析货币，失败时抛出无效参数异常
     *
     * @param string $code 货币字母代码
     * @return self
     * @throws \InvalidArgumentException 当代码不被支持时抛出
     */
    public static function fromCodeOrFail(string $code): self
    {
        return self::fromCode($code)
            ?? throw new \InvalidArgumentException(sprintf('不支持的货币代码: %s', $code));
    }

    /**
     * 判断该币种是否使用零小数位（如日元、韩元、印尼盾）
     *
     * @return bool
     */
    public function isZeroDecimal(): bool
    {
        return $this->minorUnits() === 0;
    }
}
