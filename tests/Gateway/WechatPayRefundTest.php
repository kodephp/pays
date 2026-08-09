<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「退款」原生方法单元测试
 *
 * 验证 applyRefund / queryRefund / cancelRefund 三个原生方法正确组装请求，
 * 以及不支持的能力（cancelRefund）统一报「无此方法」。
 */
class WechatPayRefundTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): WechatPayGateway
    {
        $config = array_merge([
            'app_id' => 'wx123',
            'mch_id' => 'm1',
            'api_key' => 'testkey',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new WechatPayGateway($config, $mock);
    }

    private function getMockClient(WechatPayGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        return $prop->getValue($gateway);
    }

    private function okXml(array $extra = []): string
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<mch_id><![CDATA[m1]]></mch_id>';

        foreach ($extra as $k => $v) {
            $xml .= "<{$k}><![CDATA[{$v}]]></{$k}>";
        }

        return $xml . '</xml>';
    }

    public function testApplyRefund(): void
    {
        $gateway = $this->createGateway(['secapi/pay/refund' => $this->okXml([
            'refund_id' => 'REF_1',
        ])]);

        $result = $gateway->applyRefund([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'total_fee' => 100,
            'refund_fee' => 50,
            'refund_desc' => '商品质量问题',
        ]);

        $this->assertSame('REF_1', $result['refund_id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('secapi/pay/refund', $last['url']);

        $body = $last['data'];
        $this->assertSame('REFUND_001', $body['out_refund_no']);
        $this->assertSame(50, $body['refund_fee']);
        $this->assertSame(100, $body['total_fee']);
        $this->assertSame('商品质量问题', $body['refund_desc']);
        $this->assertSame('ORDER_001', $body['out_trade_no']);
        $this->assertSame('wx123', $body['appid']);
        $this->assertSame('m1', $body['mch_id']);
    }

    public function testApplyRefundWithTransactionId(): void
    {
        $gateway = $this->createGateway(['secapi/pay/refund' => $this->okXml()]);

        $gateway->applyRefund([
            'transaction_id' => 'TXN_1',
            'out_refund_no' => 'REFUND_002',
            'refund_fee' => 50,
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('TXN_1', $last['data']['transaction_id']);
        $this->assertArrayNotHasKey('out_trade_no', $last['data']);
    }

    public function testQueryRefund(): void
    {
        $gateway = $this->createGateway(['pay/refundquery' => $this->okXml()]);

        $gateway->queryRefund('REFUND_001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/refundquery', $last['url']);
        $this->assertSame('REFUND_001', $last['data']['out_refund_no']);
        $this->assertSame('wx123', $last['data']['appid']);
    }

    public function testCancelRefundNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->cancelRefund('REFUND_001');
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('wechat', WechatPayGateway::getName());
    }
}
