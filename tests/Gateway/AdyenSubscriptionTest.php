<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Adyen 网关「Recurring 订阅」原生方法单元测试
 *
 * 验证令牌化首期支付、后续期次 ContAuth 扣款、令牌禁用与查询的端点与请求体。
 */
class AdyenSubscriptionTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): AdyenGateway
    {
        $config = array_merge([
            'api_key' => 'key_1',
            'merchant_account' => 'MERCHANT_1',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new AdyenGateway($config, $mock);
    }

    private function getMockClient(AdyenGateway $gateway): MockHttpClient
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

    public function testCreatePlanNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/createPlan/');

        $gateway->createPlan(['name' => 'Gold', 'amount' => 100, 'currency' => 'EUR', 'interval' => 'month']);
    }

    public function testCreateSubscriptionStoresPaymentMethod(): void
    {
        $gateway = $this->createGateway([
            'checkout/v70/payments' => json_encode([
                'resultCode' => 'Authorised',
                'additionalData' => ['recurring.recurringDetailReference' => 'TOKEN_1'],
            ]),
        ]);

        $result = $gateway->createSubscription([
            'customer_id' => 'SHOPPER_1',
            'plan_id' => 'PLAN_REF_1',
            'amount' => 2999,
            'currency' => 'eur',
            'payment_method' => ['type' => 'scheme', 'encryptedCardNumber' => 'test_1'],
        ]);

        $this->assertSame('Authorised', $result['resultCode']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('checkout/v70/payments', $last['url']);
        $this->assertSame('key_1', $last['headers']['X-API-Key'] ?? '');
        $this->assertSame('MERCHANT_1', $last['data']['merchantAccount']);
        $this->assertSame('PLAN_REF_1', $last['data']['reference']);
        $this->assertSame('SHOPPER_1', $last['data']['shopperReference']);
        $this->assertSame('Ecommerce', $last['data']['shopperInteraction']);
        $this->assertSame('Subscription', $last['data']['recurringProcessingModel']);
        $this->assertTrue($last['data']['storePaymentMethod']);
        $this->assertSame(2999, $last['data']['amount']['value']);
        $this->assertSame('EUR', $last['data']['amount']['currency']);
    }

    public function testCreateSubscriptionRequiresPlanId(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：plan_id');

        $gateway->createSubscription(['customer_id' => 'SHOPPER_1']);
    }

    public function testCancelSubscriptionDisablesToken(): void
    {
        $gateway = $this->createGateway([
            'Recurring/v68/disable' => json_encode(['response' => '[detail-successfully-disabled]']),
        ], ['shopper_reference' => 'SHOPPER_1']);

        $gateway->cancelSubscription('TOKEN_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pal/servlet/Recurring/v68/disable', $last['url']);
        $this->assertSame('TOKEN_1', $last['data']['recurringDetailReference']);
        $this->assertSame('SHOPPER_1', $last['data']['shopperReference']);
    }

    public function testCancelSubscriptionDisablesAllTokensOfShopper(): void
    {
        $gateway = $this->createGateway([
            'Recurring/v68/disable' => json_encode(['response' => '[all-details-successfully-disabled]']),
        ]);

        $gateway->cancelSubscription('shopper:SHOPPER_2');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('SHOPPER_2', $last['data']['shopperReference']);
        $this->assertArrayNotHasKey('recurringDetailReference', $last['data']);
    }

    public function testGetSubscriptionListsRecurringDetails(): void
    {
        $gateway = $this->createGateway([
            'Recurring/v68/listRecurringDetails' => json_encode(['details' => []]),
        ]);

        $gateway->getSubscription('SHOPPER_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pal/servlet/Recurring/v68/listRecurringDetails', $last['url']);
        $this->assertSame('SHOPPER_1', $last['data']['shopperReference']);
    }

    public function testGetSubscriptionByTokenRequiresConfiguredShopper(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('Adyen 查询令牌需配置 shopper_reference');

        $gateway->getSubscription('token:TOKEN_1');
    }

    public function testPauseAndResumeNotSupported(): void
    {
        $gateway = $this->createGateway();

        try {
            $gateway->pauseSubscription('TOKEN_1');
            $this->fail('暂停订阅应抛出「无此方法」');
        } catch (PayException $e) {
            $this->assertStringContainsString('pauseSubscription', $e->getMessage());
        }

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/resumeSubscription/');
        $gateway->resumeSubscription('TOKEN_1');
    }

    public function testChargeRecurringUsesContAuth(): void
    {
        $gateway = $this->createGateway([
            'checkout/v70/payments' => json_encode(['resultCode' => 'Authorised']),
        ]);

        $gateway->chargeRecurring([
            'reference' => 'SUB_202608_001',
            'amount' => 2999,
            'currency' => 'eur',
            'customer_id' => 'SHOPPER_1',
            'token' => 'TOKEN_1',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('ContAuth', $last['data']['shopperInteraction']);
        $this->assertSame('Subscription', $last['data']['recurringProcessingModel']);
        $this->assertSame('scheme', $last['data']['paymentMethod']['type']);
        $this->assertSame('TOKEN_1', $last['data']['paymentMethod']['storedPaymentMethodId']);
    }
}
