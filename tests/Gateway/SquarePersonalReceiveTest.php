<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Square 网关「个人收款」原生方法单元测试
 *
 * 覆盖 Online Checkout 收款链接、Payments 记录查询、Payouts 打款查询，
 * 以及 Square 无主动提现接口时的「无此方法」路径。
 */
class SquarePersonalReceiveTest extends TestCase
{
    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): SquareGateway
    {
        $config = array_merge([
            'application_id' => 'app_test',
            'access_token' => 'sq_token_test',
            'location_id' => 'L_DEFAULT',
            'environment' => 'sandbox',
        ], $config);

        return new SquareGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(SquareGateway $gateway): MockHttpClient
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

    public function testCreateQrCodeBuildsQuickPayLink(): void
    {
        $gateway = $this->createGateway([
            'payment-links' => json_encode([
                'payment_link' => ['id' => 'PL_1', 'url' => 'https://square.link/u/abc'],
            ]),
        ]);

        $result = $gateway->createQrCode([
            'amount' => 2999,
            'description' => 'Consulting',
            'currency' => 'usd',
            'out_trade_no' => 'PR_2001',
            'return_url' => 'https://example.com/done',
        ]);

        $this->assertSame('PR_2001', $result['out_trade_no']);
        $this->assertSame('PL_1', $result['payment_link_id']);
        $this->assertSame('https://square.link/u/abc', $result['qr_code']);
        $this->assertSame('https://square.link/u/abc', $result['payment_link']);
        $this->assertSame(2999, $result['amount']);
        $this->assertSame('USD', $result['currency']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('v2/online-checkout/payment-links', $last['url']);
        $this->assertSame(2999, $last['data']['quick_pay']['price_money']['amount']);
        $this->assertSame('USD', $last['data']['quick_pay']['price_money']['currency']);
        $this->assertSame('L_DEFAULT', $last['data']['quick_pay']['location_id']);
        $this->assertSame('https://example.com/done', $last['data']['checkout_options']['redirect_url']);
        $this->assertSame('PR_2001', $last['data']['payment_note']);
        $this->assertSame('Bearer sq_token_test', $last['headers']['Authorization'] ?? '');
    }

    public function testCreateQrCodeOmitsCheckoutOptionsWithoutReturnUrl(): void
    {
        $gateway = $this->createGateway([
            'payment-links' => json_encode(['payment_link' => ['id' => 'PL_2', 'url' => 'u']]),
        ]);

        $gateway->createQrCode(['amount' => 100, 'description' => 'No redirect']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertArrayNotHasKey('checkout_options', $last['data']);
    }

    public function testCreateQrCodeMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：amount');

        $gateway->createQrCode(['description' => 'no amount']);
    }

    public function testQueryRecordsHitsPaymentsList(): void
    {
        $gateway = $this->createGateway(['v2/payments' => json_encode(['payments' => []])]);

        $gateway->queryRecords([
            'start_time' => '2026-08-01 00:00:00',
            'end_time' => '2026-08-10 00:00:00',
            'limit' => 20,
            'cursor' => 'CUR_1',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v2/payments', $last['url']);
        $this->assertSame(20, $last['data']['limit']);
        $this->assertSame('L_DEFAULT', $last['data']['location_id']);
        $this->assertSame('CUR_1', $last['data']['cursor']);
        $this->assertStringEndsWith('Z', $last['data']['begin_time']);
    }

    public function testQueryRecordsRejectsBadTime(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('时间格式无法解析');

        $gateway->queryRecords(['start_time' => 'not-a-time']);
    }

    public function testWithdrawNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->withdraw(['out_biz_no' => 'WD_1', 'amount' => 100]);
    }

    public function testQueryWithdrawByPayoutId(): void
    {
        $gateway = $this->createGateway(['v2/payouts/PO_1' => json_encode(['payout' => ['id' => 'PO_1']])]);

        $gateway->queryWithdraw('PO_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v2/payouts/PO_1', $last['url']);
    }

    public function testQueryWithdrawByEntriesPrefix(): void
    {
        $gateway = $this->createGateway(['payout-entries' => json_encode(['payout_entries' => []])]);

        $gateway->queryWithdraw('entries:PO_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v2/payouts/PO_1/payout-entries', $last['url']);
    }

    public function testQueryWithdrawRejectsEmptyPayoutId(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('payout_id');

        $gateway->queryWithdraw('entries:');
    }

    public function testImplementsPersonalReceiveContract(): void
    {
        $this->assertTrue(
            is_subclass_of(SquareGateway::class, PersonalReceiveCapableInterface::class),
        );
    }
}
