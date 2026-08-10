<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * PayPal 网关「个人收款」原生方法单元测试
 *
 * 覆盖 Invoicing 发票二维码收款、Transaction Search 记录查询、
 * Payouts 提现与按批次/明细查询提现结果。
 */
class PaypalPersonalReceiveTest extends TestCase
{
    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): PaypalGateway
    {
        $config = array_merge([
            'client_id' => 'cid_test',
            'client_secret' => 'secret_test',
            'sandbox' => true,
        ], $config);

        $responses = array_merge([
            'v1/oauth2/token' => json_encode(['access_token' => 'A21AA_test_token']),
        ], $responses);

        return new PaypalGateway($config, new MockHttpClient($responses));
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

    public function testCreateQrCodeCreatesSendsAndGeneratesQr(): void
    {
        $gateway = $this->createGateway([
            'generate-qr-code' => json_encode(['image' => 'data:image/png;base64,AAA']),
            '/send' => json_encode([]),
            'v2/invoicing/invoices' => json_encode(['id' => 'INV2-XXXX']),
        ]);

        $result = $gateway->createQrCode([
            'amount' => 9900,
            'description' => 'Design service',
            'currency' => 'usd',
            'out_trade_no' => 'PR_1001',
        ]);

        $this->assertSame('PR_1001', $result['out_trade_no']);
        $this->assertSame('INV2-XXXX', $result['invoice_id']);
        $this->assertSame('data:image/png;base64,AAA', $result['qr_code']);
        $this->assertSame(9900, $result['amount']);
        $this->assertSame('USD', $result['currency']);

        $client = $this->getMockClient($gateway);

        $create = $this->findRequest($client, 'v2/invoicing/invoices');
        $this->assertSame('POST', $create['method']);
        $this->assertSame('PR_1001', $create['data']['detail']['invoice_number']);
        $this->assertSame('USD', $create['data']['detail']['currency_code']);
        $this->assertSame('99.00', $create['data']['items'][0]['unit_amount']['value']);
        $this->assertSame('Bearer A21AA_test_token', $create['headers']['Authorization'] ?? '');

        $send = $this->findRequest($client, 'INV2-XXXX/send');
        $this->assertFalse($send['data']['send_to_invoicer']);

        $qr = $this->findRequest($client, 'INV2-XXXX/generate-qr-code');
        $this->assertSame(400, $qr['data']['width']);
    }

    public function testCreateQrCodeSkipsSendWhenAutoSendFalse(): void
    {
        $gateway = $this->createGateway([
            'generate-qr-code' => json_encode(['image' => 'img']),
            'v2/invoicing/invoices' => json_encode(['id' => 'INV2-YYYY']),
        ]);

        $gateway->createQrCode([
            'amount' => 1000,
            'description' => 'Draft only',
            'auto_send' => false,
        ]);

        foreach ($this->getMockClient($gateway)->getHistory() as $request) {
            $this->assertStringNotContainsString('/send', $request['url']);
        }
    }

    public function testCreateQrCodeExtractsInvoiceIdFromHref(): void
    {
        $gateway = $this->createGateway([
            'generate-qr-code' => json_encode(['image' => 'img']),
            'v2/invoicing/invoices' => json_encode([
                'href' => 'https://api-m.sandbox.paypal.com/v2/invoicing/invoices/INV2-HREF',
            ]),
        ]);

        $result = $gateway->createQrCode(['amount' => 500, 'description' => 'From href']);

        $this->assertSame('INV2-HREF', $result['invoice_id']);
    }

    public function testCreateQrCodeMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：description');

        $gateway->createQrCode(['amount' => 100]);
    }

    public function testQueryRecordsHitsReportingApi(): void
    {
        $gateway = $this->createGateway([
            'v1/reporting/transactions' => json_encode(['transaction_details' => []]),
        ]);

        $gateway->queryRecords([
            'start_time' => '2026-08-01 00:00:00',
            'end_time' => '2026-08-10 00:00:00',
            'limit' => 50,
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v1/reporting/transactions', $last['url']);
        $this->assertSame('all', $last['data']['fields']);
        $this->assertSame(50, $last['data']['page_size']);
        $this->assertStringEndsWith('Z', $last['data']['start_date']);
    }

    public function testWithdrawBuildsPayoutBatch(): void
    {
        $gateway = $this->createGateway([
            'v1/payments/payouts' => json_encode(['batch_header' => ['payout_batch_id' => 'BATCH_1']]),
        ]);

        $gateway->withdraw([
            'out_biz_no' => 'WD_2026',
            'amount' => 12345,
            'account' => 'payee@example.com',
            'currency' => 'eur',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('v1/payments/payouts', $last['url']);
        $this->assertSame('WD_2026', $last['data']['sender_batch_header']['sender_batch_id']);
        $this->assertSame('123.45', $last['data']['items'][0]['amount']['value']);
        $this->assertSame('EUR', $last['data']['items'][0]['amount']['currency']);
        $this->assertSame('EMAIL', $last['data']['items'][0]['recipient_type']);
        $this->assertSame('payee@example.com', $last['data']['items'][0]['receiver']);
    }

    public function testWithdrawMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：account');

        $gateway->withdraw(['out_biz_no' => 'WD_1', 'amount' => 100]);
    }

    public function testQueryWithdrawByBatchId(): void
    {
        $gateway = $this->createGateway(['v1/payments/payouts/BATCH_1' => json_encode(['batch_header' => []])]);

        $gateway->queryWithdraw('BATCH_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v1/payments/payouts/BATCH_1', $last['url']);
    }

    public function testQueryWithdrawByItemPrefix(): void
    {
        $gateway = $this->createGateway(['payouts-item' => json_encode(['payout_item_id' => 'ITEM_1'])]);

        $gateway->queryWithdraw('item:ITEM_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payments/payouts-item/ITEM_1', $last['url']);
    }

    public function testQueryWithdrawRejectsEmptyItemId(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('payout_item_id');

        $gateway->queryWithdraw('item:');
    }

    public function testImplementsPersonalReceiveContract(): void
    {
        $this->assertTrue(
            is_subclass_of(PaypalGateway::class, PersonalReceiveCapableInterface::class),
        );
    }
}
