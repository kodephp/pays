<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Adyen 退款能力单元测试（RefundCapableInterface）
 *
 * 对齐 Adyen 真实退款规范：
 * - 申请退款 POST /pal/servlet/Payment/v68/refund
 * - 查询退款 POST /pal/servut/Payment/v68/refundWithData
 * Adyen 不支持取消退款，cancelRefund 统一报「无此方法」。
 */
class AdyenRefundTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): AdyenGateway
    {
        $config = array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'environment' => 'test',
        ], $config);

        $responses = $responses === []
            ? ['adyen.com' => json_encode(['id' => 'X', 'status' => 'received'])]
            : $responses;

        return new AdyenGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(AdyenGateway $gateway): MockHttpClient
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

    public function testApplyRefundPostsToRefundEndpoint(): void
    {
        $gateway = $this->createGateway();

        $gateway->applyRefund([
            'out_refund_no' => 'R_ADYEN_1',
            'refund_fee' => 5000,
            'transaction_id' => 'PSP_882211',
            'refund_currency' => 'EUR',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Payment/v68/refund', $last['url']);
        $this->assertSame('R_ADYEN_1', $last['data']['reference']);
        $this->assertSame('PSP_882211', $last['data']['originalReference']);
        $this->assertSame(5000, $last['data']['amount']['value']);
        $this->assertSame('EUR', $last['data']['amount']['currency']);
        $this->assertSame('AdyenMerchant', $last['data']['merchantAccount']);
    }

    public function testApplyRefundFallsBackToOutTradeNo(): void
    {
        $gateway = $this->createGateway();

        $gateway->applyRefund([
            'out_refund_no' => 'R_ADYEN_2',
            'refund_fee' => 100,
            'out_trade_no' => 'ORDER_999',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Payment/v68/refund', $last['url']);
        $this->assertSame('ORDER_999', $last['data']['originalReference']);
    }

    public function testQueryRefundPostsToRefundWithData(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryRefund('PSP_882211');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Payment/v68/refundWithData', $last['url']);
        $this->assertSame('PSP_882211', $last['data']['originalReference']);
        $this->assertSame('AdyenMerchant', $last['data']['merchantAccount']);
    }

    public function testCancelRefundThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->cancelRefund('R_ADYEN_1');
    }

    public function testGetName(): void
    {
        $this->assertSame('adyen', AdyenGateway::getName());
    }
}
