<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Stripe 网关「个人收款」原生方法单元测试
 *
 * 验证 createQrCode（v1/prices + v1/payment_links）/ queryRecords（v1/payment_intents）
 * 与提现能力（v1/payouts 创建、按 payout ID 或 `meta:` 前缀查询）。
 */
class StripePersonalReceiveTest extends TestCase
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
     * 从请求历史中查找 URL 包含给定子串的请求
     *
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

    public function testCreateQrCode(): void
    {
        $gateway = $this->createGateway([
            'v1/prices' => json_encode(['id' => 'price_1']),
            'v1/payment_links' => json_encode([
                'id' => 'plink_1',
                'url' => 'https://stripe.com/p/plink_1',
                'metadata' => ['out_trade_no' => 'PERSONAL_20240425'],
            ]),
        ]);

        $result = $gateway->createQrCode([
            'amount' => 9900,
            'description' => '商品付款',
            'currency' => 'usd',
        ]);

        $this->assertSame('PERSONAL_20240425', $result['out_trade_no']);
        $this->assertSame('https://stripe.com/p/plink_1', $result['payment_link']);
        $this->assertSame(9900, $result['amount']);

        $client = $this->getMockClient($gateway);
        $priceReq = $this->findRequest($client, 'v1/prices');
        $this->assertSame(9900, $priceReq['data']['unit_amount'] ?? 0);
        $this->assertSame('usd', $priceReq['data']['currency'] ?? '');

        $linkReq = $this->findRequest($client, 'v1/payment_links');
        $this->assertSame('price_1', $linkReq['data']['line_items'][0]['price'] ?? '');
        $this->assertSame('Bearer sk_test_123', $linkReq['headers']['Authorization'] ?? '');
    }

    public function testCreateQrCodeMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：description');

        $gateway->createQrCode(['amount' => 9900]);
    }

    public function testQueryRecords(): void
    {
        $gateway = $this->createGateway(['v1/payment_intents' => json_encode(['data' => []])]);

        $gateway->queryRecords([
            'start_time' => '2024-04-01 00:00:00',
            'end_time' => '2024-04-25 23:59:59',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payment_intents', $last['url']);
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
        $this->assertSame('GET', $last['method']);
    }

    public function testWithdrawCreatesPayout(): void
    {
        $gateway = $this->createGateway(['v1/payouts' => json_encode(['id' => 'po_1', 'status' => 'pending'])]);

        $result = $gateway->withdraw([
            'out_biz_no' => 'WD_1',
            'amount' => 5000,
            'currency' => 'USD',
            'destination' => 'ba_123',
            'description' => '提现',
        ]);

        $this->assertSame('po_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payouts', $last['url']);
        $this->assertSame('POST', $last['method']);
        $this->assertSame(5000, $last['data']['amount']);
        $this->assertSame('usd', $last['data']['currency']);
        $this->assertSame('ba_123', $last['data']['destination']);
        $this->assertSame('WD_1', $last['data']['metadata']['out_biz_no']);
    }

    public function testWithdrawMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：amount');

        $gateway->withdraw(['out_biz_no' => 'WD_1']);
    }

    public function testQueryWithdrawByPayoutId(): void
    {
        $gateway = $this->createGateway(['v1/payouts/po_1' => json_encode(['id' => 'po_1'])]);

        $gateway->queryWithdraw('po_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payouts/po_1', $last['url']);
        $this->assertSame('GET', $last['method']);
    }

    public function testQueryWithdrawByMetadataPrefix(): void
    {
        $gateway = $this->createGateway(['v1/payouts' => json_encode(['data' => []])]);

        $gateway->queryWithdraw('meta:WD_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringNotContainsString('v1/payouts/', $last['url']);
        $this->assertSame('WD_1', $last['data']['metadata[out_biz_no]'] ?? '');
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('stripe', StripeGateway::getName());
    }
}
