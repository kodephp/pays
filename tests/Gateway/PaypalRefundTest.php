<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * PayPal 网关「退款」原生方法单元测试
 *
 * 验证 applyRefund / queryRefund / cancelRefund 三个原生方法正确组装请求（Bearer 鉴权）；
 * cancelRefund 未提供能力，调用报「无此方法」。
 */
class PaypalRefundTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): PaypalGateway
    {
        $config = array_merge([
            'client_id' => 'cid_test',
            'client_secret' => 'csec_test',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new PaypalGateway($config, $mock);
    }

    private function getMockClient(PaypalGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        $client = $prop->getValue($gateway);
        $this->assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    /**
     * @return array{method: string, url: string, data: array<string, mixed>, headers: array<string, string>}
     */
    private function findRequest(MockHttpClient $client, string $urlFragment): array
    {
        foreach ($client->getHistory() as $request) {
            if (str_contains($request['url'], $urlFragment)) {
                return $request;
            }
        }

        $this->fail("未找到 URL 包含 {$urlFragment} 的请求");
    }

    public function testApplyRefund(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v2/payments/captures' => json_encode([
                'id' => 'ref_1',
                'status' => 'COMPLETED',
            ]),
        ]);

        $result = $gateway->applyRefund([
            'transaction_id' => 'CAP_1',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
            'currency' => 'USD',
            'refund_desc' => '商品质量问题',
        ]);

        $this->assertSame('ref_1', $result['id']);

        $req = $this->findRequest($this->getMockClient($gateway), 'v2/payments/captures/CAP_1/refund');
        $this->assertSame('Bearer pp_token', $req['headers']['Authorization'] ?? '');
        $this->assertSame('50.00', $req['data']['amount']['value'] ?? '');
        $this->assertSame('USD', $req['data']['amount']['currency_code'] ?? '');
        $this->assertSame('REFUND_001', $req['data']['invoice_id'] ?? '');
        $this->assertSame('商品质量问题', $req['data']['note_to_payer'] ?? '');
    }

    public function testApplyRefundMissingCaptureId(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
        ]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('PayPal 退款需要提供 capture_id');

        $gateway->applyRefund([
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
        ]);
    }

    public function testQueryRefund(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v2/payments/refunds' => json_encode([
                'id' => 'ref_1',
                'status' => 'COMPLETED',
            ]),
        ]);

        $gateway->queryRefund('REFUND_001');

        $req = $this->findRequest($this->getMockClient($gateway), 'v2/payments/refunds/REFUND_001');
        $this->assertSame('GET', $req['method']);
        $this->assertSame('Bearer pp_token', $req['headers']['Authorization'] ?? '');
    }

    public function testCancelRefundNotSupported(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
        ]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->cancelRefund('REFUND_001');
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('paypal', PaypalGateway::getName());
    }
}
