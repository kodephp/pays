<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\PayResponse;
use Kode\Pays\Enum\TradeStatus;
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
}
