<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wise\WiseGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Wise 网关单元测试（含余额查询能力）
 */
class WiseGatewayTest extends TestCase
{
    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应（按 URL 子串匹配）
     * @param array<string, mixed> $config 网关配置
     */
    private function createGateway(array $responses = [], array $config = []): WiseGateway
    {
        $config = array_merge([
            'api_key' => 'wise_key',
            'profile_id' => 'WISE_PROFILE',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new WiseGateway($config, $mock);
    }

    /**
     * 获取网关内部的 MockHttpClient
     */
    private function getMockClient(WiseGateway $gateway): MockHttpClient
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
     * 余额查询：GET /v4/profiles/{profile_id}/balances，取首个余额 amount
     */
    public function testQueryBalance(): void
    {
        $resp = json_encode([
            'balances' => [
                ['id' => 'bal_1', 'currency' => 'EUR', 'amount' => 123450, 'type' => 'STANDARD'],
                ['id' => 'bal_2', 'currency' => 'USD', 'amount' => 9900, 'type' => 'STANDARD'],
            ],
        ]);

        $gateway = $this->createGateway(['v4/profiles/' => $resp]);

        $result = $gateway->queryBalance();

        $this->assertSame('bal_1', $result['balance_id']);
        $this->assertSame(123450, $result['available_amount']);
        $this->assertSame(0, $result['pending_amount']);
        $this->assertSame('EUR', $result['currency']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v4/profiles/WISE_PROFILE/balances', $last['url']);
        $this->assertSame('Bearer wise_key', $last['headers']['Authorization'] ?? '');
    }

    /**
     * 日终余额：Wise 无按日期接口，抛「无此方法」
     */
    public function testQueryDayEndBalanceNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/无此方法|not supported|queryDayEndBalance/i');

        $gateway->queryDayEndBalance('2024-04-25');
    }

    /**
     * 网关标识
     */
    public function testGetName(): void
    {
        $this->assertSame('wise', WiseGateway::getName());
    }
}
