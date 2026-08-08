<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Stripe 网关原生订阅能力单元测试
 */
class StripeSubscriptionTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): StripeGateway
    {
        $config = array_merge(['secret_key' => 'sk_test_123'], $config);

        $mock = new MockHttpClient($responses);

        return new StripeGateway($config, $mock);
    }

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

    public function testCreatePlan(): void
    {
        $gateway = $this->createGateway(['v1/prices' => json_encode(['id' => 'price_1'])]);

        $result = $gateway->createPlan([
            'name' => '月度会员',
            'amount' => 9900,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 3,
        ]);

        $this->assertSame('price_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/prices', $last['url']);
        $this->assertSame(9900, $last['data']['unit_amount'] ?? 0);
        $this->assertSame('usd', $last['data']['currency'] ?? '');
        $this->assertSame('month', $last['data']['recurring']['interval'] ?? '');
        $this->assertSame(3, $last['data']['recurring']['interval_count'] ?? 0);
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
    }

    public function testCreatePlanMissingName(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：name');

        $gateway->createPlan(['amount' => 9900, 'currency' => 'usd', 'interval' => 'month']);
    }

    public function testCreateSubscription(): void
    {
        $gateway = $this->createGateway(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->createSubscription([
            'customer_id' => 'cus_1',
            'plan_id' => 'price_1',
        ]);

        $this->assertSame('sub_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/subscriptions', $last['url']);
        $this->assertSame('cus_1', $last['data']['customer'] ?? '');
        $this->assertSame('price_1', $last['data']['items'][0]['price'] ?? '');
    }

    public function testCancelSubscription(): void
    {
        $gateway = $this->createGateway(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $gateway->cancelSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/subscriptions/sub_1', $last['url']);
        $this->assertSame(true, $last['data']['cancel_at_period_end'] ?? null);
    }

    public function testPauseSubscription(): void
    {
        $gateway = $this->createGateway(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $gateway->pauseSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/subscriptions/sub_1', $last['url']);
        $this->assertSame('mark_uncollectible', $last['data']['pause_collection']['behavior'] ?? '');
    }

    public function testResumeSubscription(): void
    {
        $gateway = $this->createGateway(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $gateway->resumeSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/subscriptions/sub_1', $last['url']);
        $this->assertArrayHasKey('pause_collection', $last['data']);
        $this->assertNull($last['data']['pause_collection']);
    }

    public function testGetSubscription(): void
    {
        $gateway = $this->createGateway(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $gateway->getSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v1/subscriptions/sub_1', $last['url']);
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('stripe', StripeGateway::getName());
    }
}
