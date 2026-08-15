<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * PayPal 网关单元测试（余额查询能力）
 *
 * 余额对齐 PayPal Reporting API：`GET /v1/reporting/balances`，`value` 为十进制主单位字符串需换算为分。
 * 日终余额通过 `as_of_time` 时间点快照支持。
 */
class PaypalGatewayTest extends TestCase
{
    private const BALANCE_RESPONSE = [
        'balances' => [
            [
                'currency' => 'USD',
                'total_balance' => ['currency_code' => 'USD', 'value' => '100.00'],
                'available_balance' => ['currency_code' => 'USD', 'value' => '80.00'],
            ],
        ],
    ];

    private function createGateway(array $responses = []): PaypalGateway
    {
        $config = [
            'client_id' => 'paypal_cid',
            'client_secret' => 'paypal_csec',
            'sandbox' => true,
        ];

        $responses = array_merge([
            'v1/oauth2/token' => json_encode(['access_token' => 'PP_TOKEN_123']),
            'v1/reporting/balances' => json_encode(self::BALANCE_RESPONSE),
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

    public function testQueryBalanceSuccess(): void
    {
        $gateway = $this->createGateway();
        $result = $gateway->queryBalance();

        // 主单位十进制（100.00 / 80.00 美元）→ 换算为分
        $this->assertSame(8000, $result['available_amount']);
        $this->assertSame(2000, $result['pending_amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertArrayHasKey('balances', $result['raw']);

        $history = $this->getMockClient($gateway)->getHistory();
        $balanceReq = null;
        foreach ($history as $req) {
            if (str_contains($req['url'], 'v1/reporting/balances')) {
                $balanceReq = $req;
            }
        }
        $this->assertNotNull($balanceReq);
        $this->assertStringContainsString('Bearer PP_TOKEN_123', $balanceReq['headers']['Authorization'] ?? '');
    }

    public function testQueryDayEndBalanceUsesAsOfTime(): void
    {
        $gateway = $this->createGateway();
        $result = $gateway->queryDayEndBalance('2026-08-15');

        $this->assertSame(8000, $result['available_amount']);
        $this->assertSame(10000, $result['day_end_balance']);

        $balanceReq = null;
        foreach ($this->getMockClient($gateway)->getHistory() as $req) {
            if (str_contains($req['url'], 'v1/reporting/balances')) {
                $balanceReq = $req;
            }
        }
        $this->assertNotNull($balanceReq);
        // as_of_time 由日期推导为当日 23:59:59Z（GET 查询串记录在 data 中）
        $this->assertSame('2026-08-15T23:59:59Z', $balanceReq['data']['as_of_time'] ?? '');
    }

    public function testQueryBalanceEmptyThrows(): void
    {
        $gateway = $this->createGateway([
            'v1/reporting/balances' => json_encode(['balances' => []]),
        ]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无返回数据');
        $gateway->queryBalance();
    }

    public function testGetName(): void
    {
        $this->assertSame('paypal', PaypalGateway::getName());
    }
}
