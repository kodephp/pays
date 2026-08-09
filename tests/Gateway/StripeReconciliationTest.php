<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Stripe 网关「对账」原生方法单元测试
 *
 * 验证 downloadBill（v1/balance_transactions）/ parseBill（JSON）；
 * downloadFundFlow 未提供能力，调用应报「无此方法」。
 */
class StripeReconciliationTest extends TestCase
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

    public function testDownloadBill(): void
    {
        $gateway = $this->createGateway([
            'v1/balance_transactions' => json_encode([
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'txn_1',
                        'amount' => 9900,
                        'currency' => 'usd',
                        'net' => 9600,
                        'fee' => 300,
                        'status' => 'available',
                        'type' => 'charge',
                        'created' => 1714000000,
                        'available_on' => 1714086400,
                        'description' => 'Charge',
                        'source' => 'ch_1',
                    ],
                ],
            ]),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20240425']);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertSame('txn_1', $result['data'][0]['id']);

        $client = $this->getMockClient($gateway);
        $req = $this->findRequest($client, 'v1/balance_transactions');
        $this->assertSame('GET', $req['method']);
        $this->assertSame('Bearer sk_test_123', $req['headers']['Authorization'] ?? '');
        $this->assertArrayHasKey('created[gte]', $req['data']);
        $this->assertArrayHasKey('created[lte]', $req['data']);
    }

    public function testDownloadBillMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $gateway->downloadBill([]);
    }

    public function testDownloadFundFlowNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->downloadFundFlow(['bill_date' => '20240425']);
    }

    public function testParseBill(): void
    {
        $gateway = $this->createGateway();

        $raw = json_encode([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'txn_2',
                    'amount' => 500,
                    'currency' => 'usd',
                    'net' => 480,
                    'fee' => 20,
                    'status' => 'available',
                    'type' => 'refund',
                    'created' => 1714000000,
                    'available_on' => 1714086400,
                    'description' => 'Refund',
                    'source' => 'ch_2',
                ],
            ],
        ]);

        $records = $gateway->parseBill($raw);

        $this->assertCount(1, $records);
        $this->assertSame('txn_2', $records[0]['id']);
        $this->assertSame('refund', $records[0]['type']);
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('stripe', StripeGateway::getName());
    }
}
