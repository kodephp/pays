<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 支付宝网关「退款」原生方法单元测试
 *
 * 验证 applyRefund / queryRefund / cancelRefund 复用 buildRequestParams 标准 RSA2 签名，
 * 金额按分（/100）；cancelRefund 未提供能力，调用报「无此方法」。
 */
class AlipayRefundTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): AlipayGateway
    {
        $privateKey = $this->generateRsaPrivateKey();

        $config = array_merge([
            'app_id' => '2021000000000000',
            'private_key' => $privateKey,
            'public_key' => $privateKey,
            'notify_url' => 'https://example.com/notify',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new AlipayGateway($config, $mock);
    }

    private function generateRsaPrivateKey(): string
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
            'bits' => 2048,
        ]);

        if ($res === false) {
            $this->markTestSkipped('当前环境不支持 openssl 生成 RSA 私钥');
        }

        $exported = '';
        openssl_pkey_export($res, $exported);

        return $exported;
    }

    private function getMockClient(AlipayGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        return $prop->getValue($gateway);
    }

    private function okJson(string $method): string
    {
        return json_encode([
            "{$method}_response" => [
                'code' => '10000',
                'msg' => 'Success',
                'out_request_no' => 'REFUND_001',
            ],
        ]);
    }

    private function decodeBizContent(MockHttpClient $client): array
    {
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertArrayHasKey('biz_content', $last['data']);
        $biz = json_decode($last['data']['biz_content'], true);
        $this->assertIsArray($biz);

        return $biz;
    }

    public function testApplyRefund(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_trade_refund')]);

        $result = $gateway->applyRefund([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
            'refund_desc' => '商品质量问题',
        ]);

        $this->assertSame('REFUND_001', $result['out_request_no']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.trade.refund', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('REFUND_001', $biz['out_request_no']);
        $this->assertSame('ORDER_001', $biz['out_trade_no']);
        $this->assertSame('50.00', $biz['refund_amount']);
        $this->assertSame('商品质量问题', $biz['refund_reason']);
    }

    public function testApplyRefundWithTransactionId(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_trade_refund')]);

        $gateway->applyRefund([
            'transaction_id' => 'TXN_1',
            'out_refund_no' => 'REFUND_002',
            'refund_fee' => 5000,
        ]);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('TXN_1', $biz['trade_no']);
        $this->assertArrayNotHasKey('out_trade_no', $biz);
    }

    public function testQueryRefund(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_trade_fastpay_refund_query')]);

        $gateway->queryRefund('REFUND_001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.trade.fastpay.refund.query', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('REFUND_001', $biz['out_request_no']);
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

        $this->assertSame('alipay', AlipayGateway::getName());
    }
}
