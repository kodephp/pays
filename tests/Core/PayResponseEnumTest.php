<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\PayResponse;
use Kode\Pays\Enum\Currency;
use Kode\Pays\Enum\TradeStatus;
use Kode\Pays\Enum\TradeType;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\TestCase;

/**
 * PayResponse 交易状态枚举访问器测试
 */
class PayResponseEnumTest extends TestCase
{
    /**
     * 测试成功态枚举归一化
     */
    public function testGetTradeStatusEnumSuccess(): void
    {
        $response = new PayResponse(['trade_state' => 'SUCCESS']);
        $this->assertSame(TradeStatus::SUCCESS, $response->getTradeStatusEnum());
    }

    /**
     * 测试原始别名归一化（TRADE_SUCCESS -> SUCCESS）
     */
    public function testGetTradeStatusEnumAlias(): void
    {
        $response = new PayResponse(['trade_status' => 'TRADE_SUCCESS']);
        $this->assertSame(TradeStatus::SUCCESS, $response->getTradeStatusEnum());
    }

    /**
     * 测试待支付态
     */
    public function testGetTradeStatusEnumPending(): void
    {
        $response = new PayResponse(['status' => 'NOTPAY']);
        $this->assertSame(TradeStatus::PENDING, $response->getTradeStatusEnum());
    }

    /**
     * 测试无状态字段时返回 null
     */
    public function testGetTradeStatusEnumNullWhenAbsent(): void
    {
        $response = new PayResponse(['code' => '0']);
        $this->assertNull($response->getTradeStatusEnum());
    }

    /**
     * 测试币种枚举访问器（currency 字段）
     */
    public function testGetCurrencyEnum(): void
    {
        $response = new PayResponse(['currency' => 'USD']);
        $this->assertSame(Currency::USD, $response->getCurrencyEnum());
    }

    /**
     * 测试币种枚举访问器（fee_type 字段回退）
     */
    public function testGetCurrencyEnumFromFeeType(): void
    {
        $response = new PayResponse(['fee_type' => 'cny']);
        $this->assertSame(Currency::CNY, $response->getCurrencyEnum());
    }

    /**
     * 测试无币种字段时返回 null
     */
    public function testGetCurrencyEnumNullWhenAbsent(): void
    {
        $response = new PayResponse(['code' => '0']);
        $this->assertNull($response->getCurrencyEnum());
    }

    /**
     * 测试交易类型枚举访问器
     */
    public function testGetTradeTypeEnum(): void
    {
        $response = new PayResponse(['trade_type' => 'NATIVE']);
        $this->assertSame(TradeType::NATIVE, $response->getTradeTypeEnum());
    }

    /**
     * 测试交易类型枚举别名归一化（OFFICIAL -> JSAPI）
     */
    public function testGetTradeTypeEnumAlias(): void
    {
        $response = new PayResponse(['trade_type' => 'OFFICIAL']);
        $this->assertSame(TradeType::JSAPI, $response->getTradeTypeEnum());
    }

    /**
     * 测试主单位金额（支付宝式字符串）解析为 Money
     */
    public function testGetAmountMoneyMajorString(): void
    {
        $response = new PayResponse(['total_amount' => '0.01', 'currency' => 'CNY']);
        $money = $response->getAmountMoney();
        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(1, $money->getMinorAmount());
    }

    /**
     * 测试最小单位金额（微信式整数）解析为 Money
     */
    public function testGetAmountMoneyMinorInt(): void
    {
        $response = new PayResponse(['total_fee' => 100, 'fee_type' => 'CNY']);
        $money = $response->getAmountMoney();
        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(100, $money->getMinorAmount());
    }

    /**
     * 测试显式传入币种覆盖响应币种
     */
    public function testGetAmountMoneyExplicitCurrency(): void
    {
        $response = new PayResponse(['total_fee' => 100]);
        $money = $response->getAmountMoney(Currency::JPY);
        $this->assertSame(Currency::JPY, $money->getCurrency());
        $this->assertSame('¥100', $money->format());
    }

    /**
     * 测试退款金额访问器
     */
    public function testGetRefundAmountMoney(): void
    {
        $response = new PayResponse(['refund_fee' => 50, 'fee_type' => 'CNY']);
        $money = $response->getRefundAmountMoney();
        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(50, $money->getMinorAmount());
    }

    /**
     * 测试无金额字段时返回 null
     */
    public function testGetAmountMoneyNullWhenAbsent(): void
    {
        $response = new PayResponse(['code' => '0']);
        $this->assertNull($response->getAmountMoney());
    }
}
