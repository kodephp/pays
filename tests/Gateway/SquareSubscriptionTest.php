<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Square 网关「订阅」原生方法单元测试
 *
 * 验证 Catalog 订阅计划与 Subscriptions API 的端点、请求体与 Bearer 认证头。
 */
class SquareSubscriptionTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): SquareGateway
    {
        $config = array_merge([
            'application_id' => 'app_1',
            'access_token' => 'token_1',
            'location_id' => 'L1',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new SquareGateway($config, $mock);
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

    public function testCreatePlanBuildsCatalogObject(): void
    {
        $gateway = $this->createGateway([
            'v2/catalog/object' => json_encode(['catalog_object' => ['id' => 'PLAN_1']]),
        ]);

        $result = $gateway->createPlan([
            'name' => 'Gold Membership',
            'amount' => 2999,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 3,
        ]);

        $this->assertSame('PLAN_1', $result['catalog_object']['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v2/catalog/object', $last['url']);
        $this->assertSame('Bearer token_1', $last['headers']['Authorization'] ?? '');

        $object = $last['data']['object'];
        $this->assertSame('SUBSCRIPTION_PLAN', $object['type']);
        $this->assertSame('Gold Membership', $object['subscription_plan_data']['name']);

        $phase = $object['subscription_plan_data']['subscription_plan_variations'][0]
            ['subscription_plan_variation_data']['phases'][0];
        $this->assertSame('QUARTERLY', $phase['cadence']);
        $this->assertSame(2999, $phase['pricing']['price_money']['amount']);
        $this->assertSame('USD', $phase['pricing']['price_money']['currency']);
    }

    public function testCreatePlanRejectsUnsupportedCadence(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('Square 不支持的订阅周期：5 month');

        $gateway->createPlan([
            'name' => 'Odd Plan',
            'amount' => 100,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 5,
        ]);
    }

    public function testCreateSubscriptionUsesConfiguredLocation(): void
    {
        $gateway = $this->createGateway([
            'v2/subscriptions' => json_encode(['subscription' => ['id' => 'SUB_1']]),
        ]);

        $result = $gateway->createSubscription([
            'customer_id' => 'CUST_1',
            'plan_id' => 'VAR_1',
            'card_id' => 'CARD_1',
        ]);

        $this->assertSame('SUB_1', $result['subscription']['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v2/subscriptions', $last['url']);
        $this->assertSame('L1', $last['data']['location_id']);
        $this->assertSame('CUST_1', $last['data']['customer_id']);
        $this->assertSame('VAR_1', $last['data']['plan_variation_id']);
        $this->assertSame('CARD_1', $last['data']['card_id']);
        $this->assertNotEmpty($last['data']['idempotency_key']);
    }

    public function testCreateSubscriptionRequiresLocation(): void
    {
        $gateway = $this->createGateway([], ['location_id' => null]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：location_id');

        $gateway->createSubscription(['customer_id' => 'CUST_1', 'plan_id' => 'VAR_1']);
    }

    public function testCancelPauseResumeHitDedicatedEndpoints(): void
    {
        $gateway = $this->createGateway([
            'v2/subscriptions' => json_encode(['subscription' => ['id' => 'SUB_1']]),
        ]);
        $client = $this->getMockClient($gateway);

        $gateway->cancelSubscription('SUB_1');
        $this->assertStringContainsString('v2/subscriptions/SUB_1/cancel', $client->getLastRequest()['url']);

        $gateway->pauseSubscription('SUB_1');
        $this->assertStringContainsString('v2/subscriptions/SUB_1/pause', $client->getLastRequest()['url']);

        $gateway->resumeSubscription('SUB_1');
        $this->assertStringContainsString('v2/subscriptions/SUB_1/resume', $client->getLastRequest()['url']);
    }

    public function testGetSubscriptionUsesGet(): void
    {
        $gateway = $this->createGateway([
            'v2/subscriptions' => json_encode(['subscription' => ['id' => 'SUB_1']]),
        ]);

        $gateway->getSubscription('SUB_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v2/subscriptions/SUB_1', $last['url']);
    }
}
