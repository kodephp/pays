<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Plugin\TransferPlugin;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Adyen 网关单元测试（补齐 Transfer / Reconciliation 能力）
 *
 * 转账对齐 Adyen Transfers API（/pal/servlet/Transfer/v68/transfer），
 * 对账对齐 Report API（/pal/servlet/Reports/v68/getReport → 下载 CSV）。
 */
class AdyenGatewayTest extends TestCase
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

        $client = $prop->getValue($gateway);
        $this->assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    /* ==================== 转账 ==================== */

    public function testSingleTransferPostsToTransferEndpoint(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_1',
            'amount' => 10000,
            'currency' => 'eur',
            'recipient' => ['type' => 'bank', 'account' => 'GB29NWBK60161331926819', 'name' => '张三'],
            'description' => '佣金',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Transfer/v68/transfer', $last['url']);
        $this->assertSame('TF_1', $last['data']['reference']);
        $this->assertSame(10000, $last['data']['amount']['value']);
        $this->assertSame('EUR', $last['data']['amount']['currency']);
        $this->assertSame('bank', $last['data']['category']);
        $this->assertSame('GB29NWBK60161331926819', $last['data']['counterparty']['bankAccount']['iban']);
        $this->assertSame('张三', $last['data']['counterparty']['bankAccount']['holderName']);
        $this->assertSame('佣金', $last['data']['description']);
    }

    public function testSingleTransferCardCounterparty(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_C',
            'amount' => 5000,
            'recipient' => ['type' => 'card', 'account' => '4111111111111111', 'name' => '李四'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertSame('card', $last['data']['category']);
        $this->assertSame('4111111111111111', $last['data']['counterparty']['cardAccount']['number']);
        $this->assertSame('李四', $last['data']['counterparty']['cardAccount']['holderName']);
    }

    public function testSingleTransferIncludesBalanceAccountWhenProvided(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_B',
            'amount' => 100,
            'recipient' => ['type' => 'bank', 'account' => 'IBAN1'],
            'balance_account_id' => 'BA123',
        ]);

        $this->assertSame('BA123', $this->getMockClient($gateway)->getLastRequest()['data']['balanceAccount']);
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
        $this->assertStringContainsString('pal/servlet/Transfer/v68/transfer', $last['url']);
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['transfers']);
    }

    public function testQueryTransferFiltersByReference(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryTransfer('TF_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Transfer/v68/transfer', $last['url']);
        $this->assertSame('TF_1', $last['data']['reference']);
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

    public function testDownloadBillGeneratesReportAndParsesCsv(): void
    {
        $csv = "id,amount,status\nT1,100,Settled\nT2,200,Settled";
        $gateway = $this->createGateway([
            'Reports/v68/getReport' => json_encode([
                'url' => 'https://reports.adyen.com/settlement_detail_report_2026-08-09.csv',
            ]),
            'settlement_detail_report_2026-08-09.csv' => $csv,
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20260809']);

        $history = $this->getMockClient($gateway)->getHistory();
        // 第 1 步：生成报表
        $this->assertStringContainsString('Reports/v68/getReport', $history[0]['url']);
        $this->assertSame('Settlement detail report', $history[0]['data']['reportType']);
        $this->assertSame('2026-08-09', $history[0]['data']['date']);
        // 第 2 步：下载 CSV
        $this->assertStringContainsString('settlement_detail_report_2026-08-09.csv', $history[1]['url']);

        $this->assertSame('20260809', $result['bill_date']);
        $this->assertSame('settlement_detail_report', $result['bill_type']);
        $this->assertCount(2, $result['records']);
        $this->assertSame('T1', $result['records'][0]['id']);
        $this->assertSame('200', $result['records'][1]['amount']);
    }

    public function testDownloadFundFlowUsesAccountingReport(): void
    {
        $csv = "id,amount\nT1,100";
        $gateway = $this->createGateway([
            'Reports/v68/getReport' => json_encode([
                'url' => 'https://reports.adyen.com/payment_accounting_report_2026-08-09.csv',
            ]),
            'payment_accounting_report_2026-08-09.csv' => $csv,
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260809']);

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertSame('Payment accounting report', $history[0]['data']['reportType']);
        $this->assertSame('payment_accounting_report', $result['bill_type']);
        $this->assertCount(1, $result['records']);
    }

    public function testDownloadBillThrowsWhenNoReportUrl(): void
    {
        $gateway = $this->createGateway([
            'Reports/v68/getReport' => json_encode(['status' => 'failed']),
        ]);

        $this->expectException(PayException::class);
        $gateway->downloadBill(['bill_date' => '20260809']);
    }

    public function testParseBillHandlesMalformedCsv(): void
    {
        $gateway = $this->createGateway();
        $this->assertSame([], $gateway->parseBill(''));
        $this->assertSame([], $gateway->parseBill("only_header\n"));
    }

    /* ==================== 插件集成（端到端转发） ==================== */

    public function testTransferPluginForwardsToGateway(): void
    {
        $gateway = $this->createGateway();
        $plugin = new TransferPlugin($gateway);

        $plugin->single([
            'out_biz_no' => 'TF_P',
            'amount' => 9900,
            'recipient' => ['type' => 'bank', 'account' => 'IBANX'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Transfer/v68/transfer', $last['url']);
        $this->assertSame('IBANX', $last['data']['counterparty']['bankAccount']['iban']);
    }

    public function testGetName(): void
    {
        $this->assertSame('adyen', AdyenGateway::getName());
    }
}
