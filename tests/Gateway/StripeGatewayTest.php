<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Stripe 网关单元测试（含原生转账 / Payout 能力）
 */
class StripeGatewayTest extends TestCase
{
    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应
     * @param array<string, mixed> $config 网关配置
     */
    private function createGateway(array $responses = [], array $config = []): StripeGateway
    {
        $config = array_merge(['secret_key' => 'sk_test_123'], $config);

        $mock = new MockHttpClient($responses);

        return new StripeGateway($config, $mock);
    }

    /**
     * 获取网关内部的 MockHttpClient
     */
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
     * 测试单笔 Payout：验证端点、destination、金额与 Bearer 头
     */
    public function testSingleTransfer(): void
    {
        $resp = json_encode(['id' => 'po_1', 'amount' => 100, 'currency' => 'usd']);

        $gateway = $this->createGateway(['v1/payouts' => $resp]);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'currency' => 'usd',
            'recipient' => ['type' => 'connect_account', 'account' => 'acct_1'],
            'description' => '佣金',
        ]);

        $this->assertSame('po_1', $result['id']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payouts', $last['url']);
        $this->assertSame('acct_1', $last['data']['destination'] ?? '');
        $this->assertSame(100, $last['data']['amount'] ?? 0);
        $this->assertSame('T1', $last['data']['metadata']['out_biz_no'] ?? '');
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
    }

    /**
     * 测试单笔转账必填校验：缺 recipient 抛 PayException
     */
    public function testSingleTransferMissingRecipient(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $gateway->singleTransfer(['out_biz_no' => 'T1', 'amount' => 100]);
    }

    /**
     * 测试批量 Payout：逐笔调用并聚合
     */
    public function testBatchTransferLoopsSingle(): void
    {
        $resp = json_encode(['id' => 'po_1']);

        $gateway = $this->createGateway(['v1/payouts' => $resp]);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'B1',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['account' => 'acct_1'], 'remark' => 'a'],
                ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['account' => 'acct_2'], 'remark' => 'b'],
            ],
        ]);

        $this->assertSame(2, $result['count']);

        $client = $this->getMockClient($gateway);
        $this->assertCount(2, $client->getHistory());
    }

    /**
     * 测试查询 Payout：验证 metadata 过滤参数
     */
    public function testQueryTransfer(): void
    {
        $resp = json_encode(['id' => 'po_1']);

        $gateway = $this->createGateway(['v1/payouts' => $resp]);

        $gateway->queryTransfer('T1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payouts', $last['url']);
        $this->assertSame('T1', $last['data']['metadata[out_biz_no]'] ?? '');
    }

    /**
     * 测试查询电子回单：Stripe 不支持，应抛「无此方法」
     */
    public function testTransferReceiptNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->transferReceipt('T1');
    }

    /**
     * 测试获取网关标识
     */
    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('stripe', StripeGateway::getName());
    }
}
