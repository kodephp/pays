<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Revolut 网关「个人收款」原生方法单元测试
 *
 * 覆盖 Merchant Order 收款链接、Orders 列表查询记录，
 * 以及复用 /api/1.0/pay 的提现与按 request_id 查询提现结果。
 */
class RevolutPersonalReceiveTest extends TestCase
{
    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): RevolutGateway
    {
        $config = array_merge([
            'api_key' => 'rev_key_test',
            'merchant_id' => 'acc_merchant',
            'sandbox' => true,
        ], $config);

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

    public function testCreateQrCodeBuildsOrderCheckoutLink(): void
    {
        $gateway = $this->createGateway([
            'api/1.0/orders' => json_encode([
                'id' => 'ord_1',
                'checkout_url' => 'https://checkout.revolut.com/pay/ord_1',
            ]),
        ]);

        $result = $gateway->createQrCode([
            'amount' => 4999,
            'description' => 'Freelance work',
            'currency' => 'gbp',
            'out_trade_no' => 'PR_3001',
        ]);

        $this->assertSame('PR_3001', $result['out_trade_no']);
        $this->assertSame('ord_1', $result['order_id']);
        $this->assertSame('https://checkout.revolut.com/pay/ord_1', $result['qr_code']);
        $this->assertSame('https://checkout.revolut.com/pay/ord_1', $result['payment_link']);
        $this->assertSame(4999, $result['amount']);
        $this->assertSame('GBP', $result['currency']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('api/1.0/orders', $last['url']);
        $this->assertSame(4999, $last['data']['amount']);
        $this->assertSame('GBP', $last['data']['currency']);
        $this->assertSame('PR_3001', $last['data']['merchant_order_ext_ref']);
        $this->assertSame('AUTOMATIC', $last['data']['capture_mode']);
        $this->assertSame('Bearer rev_key_test', $last['headers']['Authorization'] ?? '');
    }

    public function testCreateQrCodeMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：description');

        $gateway->createQrCode(['amount' => 100]);
    }

    public function testQueryRecordsHitsOrdersList(): void
    {
        $gateway = $this->createGateway(['api/1.0/orders' => json_encode([])]);

        $gateway->queryRecords([
            'start_time' => '2026-08-01 00:00:00',
            'end_time' => '2026-08-10 00:00:00',
            'limit' => 30,
            'email' => 'payer@example.com',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('api/1.0/orders', $last['url']);
        $this->assertSame(30, $last['data']['limit']);
        $this->assertSame('payer@example.com', $last['data']['email']);
        $this->assertStringEndsWith('Z', $last['data']['from_created_date']);
    }

    public function testQueryRecordsRejectsBadTime(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('时间格式无法解析');

        $gateway->queryRecords(['end_time' => 'not-a-time']);
    }

    public function testWithdrawReusesPayEndpoint(): void
    {
        $gateway = $this->createGateway(['api/1.0/pay' => json_encode(['id' => 'tx_1', 'state' => 'pending'])]);

        $gateway->withdraw([
            'out_biz_no' => 'WD_3001',
            'amount' => 25000,
            'account' => 'counterparty_1',
            'currency' => 'eur',
            'real_name' => 'John Doe',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('api/1.0/pay', $last['url']);
        $this->assertSame('WD_3001', $last['data']['request_id']);
        $this->assertSame(250.0, $last['data']['amount']);
        $this->assertSame('EUR', $last['data']['currency']);
        $this->assertSame('counterparty_1', $last['data']['receiver']['counterparty_id']);
    }

    public function testWithdrawSupportsIban(): void
    {
        $gateway = $this->createGateway(['api/1.0/pay' => json_encode(['id' => 'tx_2'])]);

        $gateway->withdraw([
            'out_biz_no' => 'WD_3002',
            'amount' => 1000,
            'account' => 'ignored',
            'iban' => 'GB33BUKB20201555555555',
            'real_name' => 'Jane Doe',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GB33BUKB20201555555555', $last['data']['receiver']['iban']);
        $this->assertSame('Jane Doe', $last['data']['receiver']['holderName']);
    }

    public function testWithdrawMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：account');

        $gateway->withdraw(['out_biz_no' => 'WD_1', 'amount' => 100]);
    }

    public function testQueryWithdrawFiltersByRequestId(): void
    {
        $gateway = $this->createGateway(['api/1.0/transactions' => json_encode([])]);

        $gateway->queryWithdraw('WD_3001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('api/1.0/transactions', $last['url']);
        $this->assertSame('WD_3001', $last['data']['request_id']);
    }

    public function testImplementsPersonalReceiveContract(): void
    {
        $this->assertTrue(
            is_subclass_of(RevolutGateway::class, PersonalReceiveCapableInterface::class),
        );
    }
}
