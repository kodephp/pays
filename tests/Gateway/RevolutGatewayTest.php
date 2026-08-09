<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Plugin\TransferPlugin;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Revolut 网关单元测试（补齐 Transfer / Reconciliation 能力）
 *
 * 转账对齐 Revolut /pay 端点；对账对齐交易列表（/api/1.0/transactions）。
 * 注意：SDK 转账金额以最小货币单位（分）传入，Revolut /pay 的 amount 为主单位小数，
 * 故网关内做 ÷100 换算。
 */
class RevolutGatewayTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): RevolutGateway
    {
        $config = array_merge([
            'api_key' => 'revolut_key',
            'merchant_id' => 'REV_MERCHANT',
            'account_id' => 'rev_src_account',
        ], $config);

        $responses = $responses === []
            ? ['revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])]
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

        $client = $prop->getValue($gateway);
        $this->assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    /* ==================== 转账 ==================== */

    public function testSingleTransferPostsToPayEndpointAndConvertsAmount(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_1',
            'amount' => 10000,
            'currency' => 'eur',
            'recipient' => ['type' => 'bank', 'account' => 'CP_1', 'name' => '张三'],
            'description' => '佣金',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/pay', $last['url']);
        $this->assertSame('TF_1', $last['data']['request_id']);
        $this->assertSame('rev_src_account', $last['data']['account_id']);
        // SDK 最小货币单位 ÷100 → Revolut 主单位
        $this->assertSame(100.0, $last['data']['amount']);
        $this->assertSame('EUR', $last['data']['currency']);
        $this->assertSame(['counterparty_id' => 'CP_1'], $last['data']['receiver']);
        $this->assertSame('佣金', $last['data']['reference']);
    }

    public function testSingleTransferRevolutReceiver(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_R',
            'amount' => 5000,
            'recipient' => ['type' => 'revolut', 'account' => 'REV_ACC_1'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertSame(['account_id' => 'REV_ACC_1'], $last['data']['receiver']);
    }

    public function testSingleTransferCardReceiver(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_C',
            'amount' => 5000,
            'recipient' => ['type' => 'card', 'account' => 'CARD_1'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertSame(['card_id' => 'CARD_1'], $last['data']['receiver']);
    }

    public function testBatchTransferAggregatesTransfers(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'BTF_1',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['type' => 'bank', 'account' => 'A1'], 'remark' => 'a'],
                ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['type' => 'bank', 'account' => 'A2'], 'remark' => 'b'],
            ],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/pay', $last['url']);
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['transfers']);
    }

    public function testQueryTransferFiltersByRequestId(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryTransfer('TF_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/transactions', $last['url']);
        $this->assertSame('TF_1', $last['data']['request_id']);
    }

    public function testTransferReceiptThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->transferReceipt('TF_1');
    }

    public function testSingleTransferValidation(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $gateway->singleTransfer(['out_biz_no' => 'TF_1', 'amount' => 100]);
    }

    /* ==================== 对账 ==================== */

    public function testDownloadBillParsesTransactionRecords(): void
    {
        $json = json_encode([
            'data' => [
                ['id' => 'txn_1', 'amount' => 100, 'currency' => 'EUR', 'type' => 'transfer', 'state' => 'completed', 'created_at' => '2026-08-09T10:00:00Z', 'reference' => 'R1'],
                ['id' => 'txn_2', 'amount' => 200, 'currency' => 'EUR', 'type' => 'transfer', 'state' => 'pending', 'created_at' => '2026-08-09T11:00:00Z', 'reference' => 'R2'],
            ],
        ]);
        $gateway = $this->createGateway([
            'api/1.0/transactions' => $json,
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20260809']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/transactions', $last['url']);
        $this->assertSame('2026-08-09T00:00:00.000Z', $last['data']['from']);
        $this->assertSame('2026-08-09T23:59:59.999Z', $last['data']['to']);

        $this->assertSame('20260809', $result['bill_date']);
        $this->assertSame('transactions', $result['bill_type']);
        $this->assertCount(2, $result['records']);
        $this->assertSame('txn_1', $result['records'][0]['id']);
        $this->assertSame('200', (string) $result['records'][1]['amount']);
    }

    public function testDownloadFundFlowThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->downloadFundFlow(['bill_date' => '20260809']);
    }

    public function testParseBillHandlesMalformedJson(): void
    {
        $gateway = $this->createGateway();
        $this->assertSame([], $gateway->parseBill(''));
        $this->assertSame([], $gateway->parseBill('not json'));
    }

    /* ==================== 插件集成（端到端转发） ==================== */

    public function testTransferPluginForwardsToGateway(): void
    {
        $gateway = $this->createGateway();
        $plugin = new TransferPlugin($gateway);

        $plugin->single([
            'out_biz_no' => 'TF_P',
            'amount' => 9900,
            'recipient' => ['type' => 'bank', 'account' => 'CP_X'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/pay', $last['url']);
        $this->assertSame(['counterparty_id' => 'CP_X'], $last['data']['receiver']);
    }

    public function testGetName(): void
    {
        $this->assertSame('revolut', RevolutGateway::getName());
    }
}
