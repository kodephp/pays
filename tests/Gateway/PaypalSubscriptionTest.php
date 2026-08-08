<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * PayPal 网关原生订阅能力单元测试
 */
class PaypalSubscriptionTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): PaypalGateway
    {
        $config = array_merge([
            'client_id' => 'cid_test',
            'client_secret' => 'csec_test',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new PaypalGateway($config, $mock);
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

    public function testCreatePlan(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/catalogs/products' => json_encode(['id' => 'prod_1']),
            'v1/billing/plans' => json_encode(['id' => 'plan_1']),
        ]);

        $result = $gateway->createPlan([
            'name' => '月度会员',
            'amount' => 9900,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 3,
        ]);

        $this->assertSame('plan_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/billing/plans', $last['url']);
        $this->assertSame('prod_1', $last['data']['product_id'] ?? '');
        $this->assertSame('MONTH', $last['data']['billing_cycles'][0]['frequency']['interval_unit'] ?? '');
        $this->assertSame(3, $last['data']['billing_cycles'][0]['frequency']['interval_count'] ?? 0);
        $this->assertSame('99.00', $last['data']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] ?? '');
        $this->assertSame('USD', $last['data']['billing_cycles'][0]['pricing_scheme']['fixed_price']['currency_code'] ?? '');
        $this->assertSame('Bearer pp_token', $last['headers']['Authorization'] ?? '');
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
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $result = $gateway->createSubscription([
            'plan_id' => 'plan_1',
            'customer_email' => 'user@example.com',
        ]);

        $this->assertSame('sub_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/billing/subscriptions', $last['url']);
        $this->assertSame('plan_1', $last['data']['plan_id'] ?? '');
        $this->assertSame('user@example.com', $last['data']['subscriber']['email_address'] ?? '');
    }

    public function testCancelSubscription(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $gateway->cancelSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/billing/subscriptions/sub_1/cancel', $last['url']);
        $this->assertSame('用户取消', $last['data']['reason'] ?? '');
    }

    public function testPauseSubscription(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $gateway->pauseSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/billing/subscriptions/sub_1/suspend', $last['url']);
        $this->assertSame('用户暂停', $last['data']['reason'] ?? '');
    }

    public function testResumeSubscription(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $gateway->resumeSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/billing/subscriptions/sub_1/activate', $last['url']);
        $this->assertSame('用户恢复', $last['data']['reason'] ?? '');
    }

    public function testGetSubscription(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $gateway->getSubscription('sub_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v1/billing/subscriptions/sub_1', $last['url']);
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('paypal', PaypalGateway::getName());
    }
}
