<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 支付宝网关「周期扣款订阅」原生方法单元测试
 *
 * 验证签约 / 解约 / 查询 / 代扣均复用 buildRequestParams 标准 RSA2 签名，
 * 且周期规则（period_rule_params）按支付宝规范组装。
 */
class AlipaySubscriptionTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): AlipayGateway
    {
        $privateKey = $this->generateRsaPrivateKey();

        $config = array_merge([
            'app_id' => '2021000000000000',
            'private_key' => $privateKey,
            'public_key' => $privateKey,
        ], $config);

        $mock = new MockHttpClient($responses);

        return new AlipayGateway($config, $mock);
    }

    private function generateRsaPrivateKey(): string
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
            'bits' => 2048,
        ]);

        if ($res === false) {
            $this->markTestSkipped('当前环境不支持 openssl 生成 RSA 私钥');
        }

        $exported = '';
        openssl_pkey_export($res, $exported);

        return $exported;
    }

    private function getMockClient(AlipayGateway $gateway): MockHttpClient
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

    private function okJson(string $method, array $extra = []): string
    {
        return json_encode([
            "{$method}_response" => array_merge([
                'code' => '10000',
                'msg' => 'Success',
            ], $extra),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 解析请求中的 biz_content
     */
    private function bizContent(array $last): array
    {
        $raw = $last['data']['biz_content'] ?? '{}';

        return json_decode((string) $raw, true);
    }

    public function testCreatePlanBuildsPeriodRuleLocally(): void
    {
        $gateway = $this->createGateway();

        $plan = $gateway->createPlan([
            'name' => '月度会员',
            'amount' => 19.9,
            'currency' => 'CNY',
            'interval' => 'month',
            'interval_count' => 1,
            'execute_time' => '2026-09-01',
            'total_payments' => 12,
        ]);

        $this->assertStringStartsWith('alipay_plan_', $plan['plan_id']);
        $this->assertSame('MONTH', $plan['period_rule_params']['period_type']);
        $this->assertSame(1, $plan['period_rule_params']['period']);
        $this->assertSame('2026-09-01', $plan['period_rule_params']['execute_time']);
        $this->assertSame(19.9, $plan['period_rule_params']['single_amount']);
        $this->assertSame(12, $plan['period_rule_params']['total_payments']);

        // 计划为本地组装，不应产生网络请求
        $this->assertNull($this->getMockClient($gateway)->getLastRequest());
    }

    public function testCreatePlanRejectsForeignCurrency(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('支付宝周期扣款仅支持 CNY');

        $gateway->createPlan([
            'name' => '月度会员',
            'amount' => 19.9,
            'currency' => 'USD',
            'interval' => 'month',
        ]);
    }

    public function testCreatePlanRejectsUnsupportedInterval(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('支付宝周期扣款的 interval 仅支持 day / month');

        $gateway->createPlan([
            'name' => '周会员',
            'amount' => 9.9,
            'currency' => 'CNY',
            'interval' => 'week',
        ]);
    }

    public function testCreateSubscriptionReturnsSignedSignUrl(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->createSubscription([
            'customer_id' => 'AGREEMENT_001',
            'plan_id' => 'alipay_plan_x',
            'amount' => 19.9,
            'interval' => 'month',
            'notify_url' => 'https://example.com/sign-notify',
        ]);

        $this->assertSame('GET', $result['method']);
        $this->assertSame('AGREEMENT_001', $result['external_agreement_no']);

        $query = [];
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        $this->assertSame('alipay.user.agreement.page.sign', $query['method']);
        $this->assertSame('RSA2', $query['sign_type']);
        $this->assertNotEmpty($query['sign'], '签约链接应带 RSA2 签名');

        $biz = json_decode((string) $query['biz_content'], true);
        $this->assertSame('CYCLE_PAY_AUTH_P', $biz['personal_product_code']);
        $this->assertSame('INDUSTRY|DEFAULT_SCENE', $biz['sign_scene']);
        $this->assertSame('AGREEMENT_001', $biz['external_agreement_no']);
        $this->assertSame('MONTH', $biz['period_rule_params']['period_type']);
        $this->assertSame('https://example.com/sign-notify', $biz['sign_notify_url']);
    }

    public function testCreateSubscriptionAcceptsExplicitPeriodRule(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->createSubscription([
            'customer_id' => 'AGREEMENT_002',
            'plan_id' => 'alipay_plan_y',
            'period_rule_params' => [
                'period_type' => 'DAY',
                'period' => 7,
                'execute_time' => '2026-09-10',
                'single_amount' => 5.0,
            ],
        ]);

        $query = [];
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);
        $biz = json_decode((string) $query['biz_content'], true);

        $this->assertSame('DAY', $biz['period_rule_params']['period_type']);
        $this->assertSame(7, $biz['period_rule_params']['period']);
    }

    public function testCreateSubscriptionRequiresCustomerId(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：customer_id');

        $gateway->createSubscription(['plan_id' => 'alipay_plan_x']);
    }

    public function testCancelSubscriptionUnsignsByAgreementNo(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay_user_agreement_unsign'),
        ]);

        $gateway->cancelSubscription('20260810000000000001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.user.agreement.unsign', $last['data']['method']);
        $this->assertNotEmpty($last['data']['sign'], '解约请求应带 RSA2 签名');
        $this->assertSame('20260810000000000001', $this->bizContent($last)['agreement_no']);
    }

    public function testCancelSubscriptionSupportsExternalAgreementNo(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay_user_agreement_unsign'),
        ]);

        $gateway->cancelSubscription('ext:AGREEMENT_001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);

        $biz = $this->bizContent($last);
        $this->assertSame('AGREEMENT_001', $biz['external_agreement_no']);
        $this->assertSame('CYCLE_PAY_AUTH_P', $biz['personal_product_code']);
        $this->assertArrayNotHasKey('agreement_no', $biz);
    }

    public function testGetSubscriptionQueriesAgreement(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay_user_agreement_query', ['status' => 'NORMAL']),
        ]);

        $result = $gateway->getSubscription('20260810000000000001');

        $this->assertSame('NORMAL', $result['status']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.user.agreement.query', $last['data']['method']);
        $this->assertNotEmpty($last['data']['sign']);
    }

    public function testPauseAndResumeNotSupported(): void
    {
        $gateway = $this->createGateway();

        try {
            $gateway->pauseSubscription('20260810000000000001');
            $this->fail('暂停订阅应抛出「无此方法」');
        } catch (PayException $e) {
            $this->assertStringContainsString('pauseSubscription', $e->getMessage());
        }

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/resumeSubscription/');
        $gateway->resumeSubscription('20260810000000000001');
    }

    public function testPayWithAgreementSignsTradePay(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay_trade_pay', ['trade_no' => '2026081022001']),
        ]);

        $result = $gateway->payWithAgreement([
            'out_trade_no' => 'SUB_202608_001',
            'total_amount' => 19.9,
            'subject' => '月度会员续费',
            'agreement_no' => '20260810000000000001',
        ]);

        $this->assertSame('2026081022001', $result['trade_no']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.trade.pay', $last['data']['method']);
        $this->assertNotEmpty($last['data']['sign'], '代扣请求应带 RSA2 签名');

        $biz = $this->bizContent($last);
        $this->assertSame('CYCLE_PAY_AUTH', $biz['product_code']);
        $this->assertSame('20260810000000000001', $biz['agreement_params']['agreement_no']);
    }

    public function testModifyExecutionPlanSignsRequest(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay_user_agreement_executionplan_modify'),
        ]);

        $gateway->modifyExecutionPlan([
            'agreement_no' => '20260810000000000001',
            'deduct_time' => '2026-09-20',
            'memo' => '用户申请延期',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.user.agreement.executionplan.modify', $last['data']['method']);
        $this->assertSame('2026-09-20', $this->bizContent($last)['deduct_time']);
    }
}
