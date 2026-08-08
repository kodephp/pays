<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin\ProfitSharing;

use Kode\Pays\Enum\Currency;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\TestCase;

/**
 * 分账接收方值对象测试
 */
class ReceiverTest extends TestCase
{
    /**
     * 基于数组构建（amount 视为最小货币单位）
     */
    public function testFromArrayUsesMinorUnits(): void
    {
        $receiver = Receiver::fromArray([
            'type' => 'MERCHANT_ID',
            'account' => '123456',
            'name' => '供应商',
            'amount' => 100,
            'currency' => 'CNY',
        ]);

        $this->assertSame(100, $receiver->amount->getMinorAmount());
        $this->assertSame(Currency::CNY, $receiver->amount->getCurrency());
        $this->assertSame('供应商', $receiver->name);
        $this->assertSame('SERVICE_PROVIDER', $receiver->relationType);
    }

    /**
     * 直接传入 Money 实例
     */
    public function testFromArrayAcceptsMoney(): void
    {
        $money = Money::fromMinor(50, 'USD');
        $receiver = Receiver::fromArray([
            'type' => 'PERSONAL_OPENID',
            'account' => 'openid_abc',
            'amount' => $money,
        ]);

        $this->assertSame($money, $receiver->amount);
        $this->assertSame('USD', $receiver->amount->getCurrency()->value);
    }

    /**
     * 缺省币种为 CNY
     */
    public function testFromArrayDefaultsToCny(): void
    {
        $receiver = Receiver::fromArray(['type' => 'x', 'account' => 'a', 'amount' => 1]);
        $this->assertSame(Currency::CNY, $receiver->amount->getCurrency());
    }

    /**
     * 微信映射：amount 为分
     */
    public function testToWechatArray(): void
    {
        $receiver = new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER');
        $this->assertSame([
            'type' => 'MERCHANT_ID',
            'account' => '123',
            'amount' => 100,
            'description' => '分账',
        ], $receiver->toWechatArray());
    }

    /**
     * 支付宝映射：amount 转为主单位元，PERSONAL_OPENID -> loginName
     */
    public function testToAlipayArray(): void
    {
        $receiver = new Receiver('PERSONAL_OPENID', 'openid1', '推广者', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER');
        $this->assertSame([
            'trans_in_type' => 'loginName',
            'trans_in' => 'openid1',
            'amount' => '1.00',
            'desc' => '分账',
        ], $receiver->toAlipayArray());
    }

    /**
     * 支付宝映射：商户类型默认为 userId
     */
    public function testToAlipayArrayMerchantDefaultsToUserId(): void
    {
        $receiver = new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(200, 'CNY'), '分账', 'SERVICE_PROVIDER');
        $alipay = $receiver->toAlipayArray();
        $this->assertSame('userId', $alipay['trans_in_type']);
        $this->assertSame('2.00', $alipay['amount']);
    }

    /**
     * Stripe 映射：amount 为最小单位，币种转小写
     */
    public function testToStripeArray(): void
    {
        $receiver = new Receiver('MERCHANT_ID', 'acct_1', null, Money::fromMinor(300, 'USD'), '分账', 'SERVICE_PROVIDER');
        $this->assertSame([
            'account' => 'acct_1',
            'amount' => 300,
            'currency' => 'usd',
        ], $receiver->toStripeArray());
    }

    /**
     * 归一化数组含币种与最小单位金额
     */
    public function testToArray(): void
    {
        $receiver = new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER');
        $this->assertSame([
            'type' => 'MERCHANT_ID',
            'account' => '123',
            'name' => '供应商',
            'amount' => 100,
            'currency' => 'CNY',
            'description' => '分账',
            'relation_type' => 'SERVICE_PROVIDER',
        ], $receiver->toArray());
    }
}
