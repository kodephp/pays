<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Stripe 网关「退款」原生方法单元测试
 *
 * 验证 applyRefund / queryRefund / cancelRefund 三个原生方法正确组装请求（Bearer 鉴权）；
 * cancelRefund 先按商户退款单号定位 refund id 再发起取消。
 */
class StripeRefundTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): StripeGateway
    {
        $config = array_merge(['secret_key' => 'sk_test_123'], $config);

        $mock = new MockHttpClient($responses);

        return new StripeGateway($config, $mock);
    }

    private function getMockClient(StripeGateway $gateway): MockHttpClient
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
        $gateway = $this->createGateway(['v1/refunds' => json_encode([
            'id' => 're_1',
            'status' => 'succeeded',
        ])]);

        $result = $gateway->applyRefund([
            'transaction_id' => 'pi_1',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
            'refund_desc' => 'fraudulent request',
        ]);

        $this->assertSame('re_1', $result['id']);

        $req = $this->findRequest($this->getMockClient($gateway), 'v1/refunds');
        $this->assertSame('pi_1', $req['data']['payment_intent'] ?? '');
        $this->assertSame(5000, $req['data']['amount'] ?? 0);
        $this->assertSame('fraudulent', $req['data']['reason'] ?? '');
        $this->assertSame('REFUND_001', $req['data']['metadata']['out_refund_no'] ?? '');
        $this->assertSame('Bearer sk_test_123', $req['headers']['Authorization'] ?? '');
    }

    public function testQueryRefund(): void
    {
        $gateway = $this->createGateway(['v1/refunds' => json_encode([
            'data' => [['id' => 're_1']],
        ])]);

        $gateway->queryRefund('REFUND_001');

        $req = $this->findRequest($this->getMockClient($gateway), 'v1/refunds');
        $this->assertSame('GET', $req['method']);
        $this->assertSame('Bearer sk_test_123', $req['headers']['Authorization'] ?? '');
        $this->assertSame('REFUND_001', $req['data']['metadata[out_refund_no]'] ?? '');
    }

    public function testCancelRefund(): void
    {
        $gateway = $this->createGateway([
            'v1/refunds/re_1/cancel' => json_encode([
                'id' => 're_1',
                'status' => 'canceled',
            ]),
            'v1/refunds' => json_encode([
                'data' => [['id' => 're_1']],
            ]),
        ]);

        $result = $gateway->cancelRefund('REFUND_001');

        $this->assertSame('canceled', $result['status']);

        $cancelReq = $this->findRequest($this->getMockClient($gateway), 'v1/refunds/re_1/cancel');
        $this->assertSame('Bearer sk_test_123', $cancelReq['headers']['Authorization'] ?? '');
    }

    public function testCancelRefundNotFound(): void
    {
        $gateway = $this->createGateway(['v1/refunds' => json_encode(['data' => []])]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('未找到对应的 Stripe 退款记录');

        $gateway->cancelRefund('REFUND_001');
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('stripe', StripeGateway::getName());
    }
}
