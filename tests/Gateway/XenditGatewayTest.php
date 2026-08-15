<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Xendit\XenditGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Xendit 网关单元测试（余额查询能力）
 *
 * 余额对齐 Xendit 真实规范：`GET /balance` 返回账户当前余额（整数，已为账户币种最小单位）。
 */
class XenditGatewayTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): XenditGateway
    {
        $config = array_merge([
            'secret_key' => 'xendit_secret',
            'currency' => 'IDR',
        ], $config);

        $responses = $responses === []
            ? ['balance' => json_encode(['balance' => 123456])]
            : $responses;

        return new XenditGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(XenditGateway $gateway): MockHttpClient
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
        $gateway = $this->createGateway(['balance' => json_encode(['balance' => 123456])]);
        $result = $gateway->queryBalance();

        $this->assertSame(123456, $result['available_amount']);
        $this->assertSame(0, $result['pending_amount']);
        $this->assertSame('IDR', $result['currency']);
        $this->assertSame(123456, $result['raw']['balance']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('/balance', $last['url']);
        $decoded = base64_decode(
            (string) preg_replace('/^Basic /', '', $last['headers']['Authorization'] ?? '')
        );
        $this->assertStringContainsString('xendit_secret', $decoded);
    }

    public function testQueryDayEndBalanceNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');
        $gateway->queryDayEndBalance('2026-08-15');
    }

    public function testGetName(): void
    {
        $this->assertSame('xendit', XenditGateway::getName());
    }
}
