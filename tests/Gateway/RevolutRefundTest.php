<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Revolut 退款能力单元测试（RefundCapableInterface）
 *
 * 对齐 Revolut 真实退款规范：
 * - 申请退款 POST /api/1.0/orders/{order_id}/refund（金额按分传入，网关内部 ×100 转最小货币单位）
 * - 查询退款 GET /api/orders/{refundOrderId}（退款生成新的 refund 类型 order，检索该退款订单）
 * Revolut 不支持取消退款，cancelRefund 统一报「无此方法」。
 */
class RevolutRefundTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): RevolutGateway
    {
        $config = array_merge([
            'api_key' => 'revolut_key',
            'merchant_id' => 'REV_MERCHANT',
            'account_id' => 'rev_src_account',
        ], $config);

        $responses = $responses === []
            ? ['merchant.revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])]
            : $responses;

        return new RevolutGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(RevolutGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);
        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        return $prop->getValue($gateway);
    }

    public function testImplementsRefundCapable(): void
    {
        $this->assertInstanceOf(RefundCapableInterface::class, $this->createGateway());
    }

    public function testApplyRefundPostsToOrderRefundEndpoint(): void
    {
        $gateway = $this->createGateway();

        $gateway->applyRefund([
            'out_refund_no' => 'R_REV_1',
            'refund_fee' => 10000,
            'transaction_id' => 'ORD_5512',
            'refund_desc' => '商品质量问题',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/orders/ORD_5512/refund', $last['url']);
        // refund_fee(分)=10000 → refund() 内部 ×100 → 10000 最小货币单位
        $this->assertSame(10000, $last['data']['amount']);
        $this->assertSame('商品质量问题', $last['data']['description']);
    }

    public function testApplyRefundConvertsCentsViaOutTradeNo(): void
    {
        $gateway = $this->createGateway();

        $gateway->applyRefund([
            'out_refund_no' => 'R_REV_2',
            'refund_fee' => 2500,
            'out_trade_no' => 'ORDER_X',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/orders/ORDER_X/refund', $last['url']);
        $this->assertSame(2500, $last['data']['amount']);
    }

    public function testQueryRefundRetrievesRefundOrder(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryRefund('REF_ORD_7722');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/orders/REF_ORD_7722', $last['url']);
    }

    public function testCancelRefundThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->cancelRefund('R_REV_1');
    }

    public function testGetName(): void
    {
        $this->assertSame('revolut', RevolutGateway::getName());
    }
}
