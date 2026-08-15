<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Payoneer\PayoneerGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Payoneer 网关单元测试（含余额查询能力）
 *
 * 余额对齐 Payoneer 真实规范：`GET /v2/programs/{programId}/balance`（Basic 认证）。
 * 金额以主币种单位返回（如 "19669.36"），网关换算为最小货币单位（分）。
 */
class PayoneerGatewayTest extends TestCase
{
    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应（按 URL 子串匹配）
     * @param array<string, mixed> $config 网关配置
     */
    private function createGateway(array $responses = [], array $config = []): PayoneerGateway
    {
        $config = array_merge([
            'api_key' => 'po_key',
            'api_secret' => 'po_secret',
            'program_id' => 'PO_PROGRAM',
        ], $config);

        return new PayoneerGateway($config, new MockHttpClient($responses));
    }

    /**
     * 获取网关内部的 MockHttpClient
     */
    private function getMockClient(PayoneerGateway $gateway): MockHttpClient
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
     * 余额查询（主币种数值形态）：GET /v2/programs/{programId}/balance
     */
    public function testQueryBalanceNumeric(): void
    {
        $resp = json_encode(['balance' => 19669.36, 'currency' => 'USD']);

        $gateway = $this->createGateway(['balance' => $resp]);
        $result = $gateway->queryBalance();

        $this->assertSame(1966936, $result['available_amount']);
        $this->assertSame(0, $result['pending_amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame(19669.36, $result['raw']['balance']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('/v2/programs/PO_PROGRAM/balance', $last['url']);
        $decoded = base64_decode(
            (string) preg_replace('/^Basic /', '', $last['headers']['Authorization'] ?? '')
        );
        $this->assertStringContainsString('po_key:po_secret', $decoded);
    }

    /**
     * 余额查询（available_balance 字符串形态，官方 /v4 账户余额风格）
     */
    public function testQueryBalanceAvailableString(): void
    {
        $resp = json_encode(['available_balance' => '20.00', 'currency' => 'GBP', 'id' => 'bal_gbp']);

        $gateway = $this->createGateway(['balance' => $resp]);
        $result = $gateway->queryBalance();

        $this->assertSame('bal_gbp', $result['balance_id']);
        $this->assertSame(2000, $result['available_amount']);
        $this->assertSame('GBP', $result['currency']);
    }

    /**
     * 日终余额：Payoneer 无按日期接口，抛「无此方法」
     */
    public function testQueryDayEndBalanceNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/无此方法|not supported|queryDayEndBalance/i');

        $gateway->queryDayEndBalance('2026-08-15');
    }

    /**
     * 网关标识
     */
    public function testGetName(): void
    {
        $this->assertSame('payoneer', PayoneerGateway::getName());
    }
}
