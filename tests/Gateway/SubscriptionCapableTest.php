<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Douyin\DouyinPayGateway;
use Kode\Pays\Gateway\Jd\JdGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
use Kode\Pays\Gateway\Meituan\MeituanGateway;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * SubscriptionCapableInterface 集中功能测试（v2.20.0 新增，与 WebhookCapableTest /
 * QrCapableTest / RefundCapableTest / TransferCapableTest / ProfitSharingCapableTest /
 * BalanceCapableTest / SettlementCapableTest 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 6 家真实实现 SubscriptionCapableInterface（stripe / square / paypal /
 *   alipay / wechat_v2 / adyen），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 6 个订阅方法（createPlan / createSubscription /
 *   cancelSubscription / pauseSubscription / resumeSubscription / getSubscription），
 *   验证其确实向平台端点发起请求并返回解析后的响应，而非占位 / 空响应；
 * - 诚实区分三类实现：
 *   1) 全真实（stripe / square / paypal）：6 方法均向真实端点发请求；
 *   2) 本地返回（alipay.createPlan / alipay.createSubscription / wechat_v2.createSubscription）：
 *      签约类接口本身返回可跳转链接 / 计划描述，不发起同步 HTTP 调用；
 *   3) 诚实抛「无此方法」（alipay.pause/resume、wechat_v2.createPlan/pause/resume、
 *      adyen.createPlan/pause/resume）：对应平台无该语义端点，不伪造逻辑。
 */
class SubscriptionCapableTest extends TestCase
{
    // ---- 6 家订阅网关工厂 ----

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], []), new MockHttpClient($responses));
    }

    private function square(array $responses = []): SquareGateway
    {
        return new SquareGateway(array_merge([
            'application_id' => 'sq_app',
            'access_token' => 'sq_token',
            'location_id' => 'sq_loc',
        ], []), new MockHttpClient($responses));
    }

    private function paypal(array $responses = []): PaypalGateway
    {
        $responses = $responses === []
            ? ['v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer'])]
            : $responses;

        return new PaypalGateway(array_merge([
            'client_id' => 'pp_cid',
            'client_secret' => 'pp_sec',
            'sandbox' => true,
        ], []), new MockHttpClient($responses));
    }

    private function adyen(array $responses = []): AdyenGateway
    {
        $responses = $responses === []
            ? ['adyen.com' => json_encode(['id' => 'X', 'status' => 'received'])]
            : $responses;

        return new AdyenGateway(array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'environment' => 'test',
        ], []), new MockHttpClient($responses));
    }

    private function alipay(array $responses = []): AlipayGateway
    {
        $key = $this->rsaPrivateKey();

        return new AlipayGateway(array_merge([
            'app_id' => 'alipay_app',
            'private_key' => $key,
            'public_key' => $key,
            'notify_url' => 'https://example.com/notify',
        ], []), new MockHttpClient($responses));
    }

    private function wechatV2(array $responses = []): WechatPayGateway
    {
        return new WechatPayGateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'wechat_api_key',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
        ], []), new MockHttpClient($responses));
    }

    /**
     * 临时生成合法 RSA 私钥（对齐 TransferCapableTest / ProfitSharingCapableTest 做法）
     */
    private function rsaPrivateKey(): string
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

    /**
     * 微信 V2 成功响应 XML（对齐 TransferCapableTest::okXml，确保 libxml 解析稳定）
     */
    private function okXml(array $extra = []): string
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<mch_id><![CDATA[m1]]></mch_id>';

        foreach ($extra as $k => $v) {
            $xml .= "<{$k}><![CDATA[{$v}]]></{$k}>";
        }

        return $xml . '</xml>';
    }

    // ---- 契约一致性断言 ----

    public function testAllSixSubscriptionGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(SubscriptionCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(SubscriptionCapableInterface::class, $this->square());
        $this->assertInstanceOf(SubscriptionCapableInterface::class, $this->paypal());
        $this->assertInstanceOf(SubscriptionCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(SubscriptionCapableInterface::class, $this->wechatV2());
        $this->assertInstanceOf(SubscriptionCapableInterface::class, $this->adyen());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']));
        // 微信 V3（APIv3）未登记 CAP_SUBSCRIPTION，亦不实现该接口
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new WechatPayV3Gateway([
            'app_id' => 'wx_app', 'mch_id' => 'mch_1', 'serial_no' => 's',
            'private_key' => $this->rsaPrivateKey(), 'api_key' => 'k',
        ]));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new RevolutGateway([
            'api_key' => 'r', 'merchant_id' => 'm', 'account_id' => 'a',
        ]));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new JdGateway([
            'merchant_no' => 'n', 'des_key' => 'd', 'md5_key' => 'm',
        ]));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new MeituanGateway([
            'app_id' => 'a', 'app_secret' => 's', 'merchant_id' => 'm',
        ]));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new DouyinPayGateway([
            'app_id' => 'a', 'merchant_id' => 'm', 'salt' => 's',
        ]));
        $this->assertNotInstanceOf(SubscriptionCapableInterface::class, new UnionPayGateway([
            'mer_id' => 'm1', 'cert_path' => '/tmp/c', 'verify_cert_path' => '/tmp/v', 'cert_pwd' => '123456',
        ]));
    }

    // ---- Stripe：6 方法全真实（MockHttpClient 驱动） ----

    public function testStripeCreatePlanReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/prices' => json_encode(['id' => 'price_1'])]);

        $result = $gateway->createPlan([
            'name' => '月度会员', 'amount' => 9900, 'currency' => 'cny', 'interval' => 'month',
        ]);

        $this->assertSame('price_1', $result['id']);
    }

    public function testStripeCreateSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->createSubscription(['customer_id' => 'cus_1', 'plan_id' => 'price_1']);

        $this->assertSame('sub_1', $result['id']);
    }

    public function testStripeCancelSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->cancelSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testStripePauseSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->pauseSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testStripeResumeSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->resumeSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testStripeGetSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/subscriptions' => json_encode(['id' => 'sub_1', 'status' => 'active'])]);

        $result = $gateway->getSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
        $this->assertSame('active', $result['status']);
    }

    // ---- Square：6 方法全真实（MockHttpClient 驱动） ----

    public function testSquareCreatePlanReturnsParsedResponse(): void
    {
        $gateway = $this->square(['v2/catalog/object' => json_encode(['id' => 'cat_1'])]);

        $result = $gateway->createPlan([
            'name' => '月度会员', 'amount' => 9900, 'currency' => 'usd', 'interval' => 'month',
        ]);

        $this->assertSame('cat_1', $result['id']);
    }

    public function testSquareCreateSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->square(['v2/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->createSubscription(['customer_id' => 'cus_1', 'plan_id' => 'plan_1']);

        $this->assertSame('sub_1', $result['id']);
    }

    public function testSquareCancelSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->square(['v2/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->cancelSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testSquarePauseSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->square(['v2/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->pauseSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testSquareResumeSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->square(['v2/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->resumeSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testSquareGetSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->square(['v2/subscriptions' => json_encode(['id' => 'sub_1'])]);

        $result = $gateway->getSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    // ---- PayPal：6 方法全真实（每次调用先取令牌，需 mock v1/oauth2/token） ----

    public function testPaypalCreatePlanReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/catalogs/products' => json_encode(['id' => 'PROD1']),
            'v1/billing/plans' => json_encode(['id' => 'PLAN1']),
        ]);

        $result = $gateway->createPlan([
            'name' => '月度会员', 'amount' => 9900, 'currency' => 'usd', 'interval' => 'month',
        ]);

        $this->assertSame('PLAN1', $result['id']);
    }

    public function testPaypalCreateSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $result = $gateway->createSubscription(['plan_id' => 'PLAN1']);

        $this->assertSame('sub_1', $result['id']);
    }

    public function testPaypalCancelSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $result = $gateway->cancelSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testPaypalPauseSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $result = $gateway->pauseSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testPaypalResumeSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1']),
        ]);

        $result = $gateway->resumeSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    public function testPaypalGetSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/billing/subscriptions' => json_encode(['id' => 'sub_1', 'status' => 'ACTIVE']),
        ]);

        $result = $gateway->getSubscription('sub_1');

        $this->assertSame('sub_1', $result['id']);
    }

    // ---- Adyen：createPlan / pause / resume 诚实抛「无此方法」，其余 3 方法真实 ----

    public function testAdyenCreatePlanThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->adyen()->createPlan([
            'name' => '月度会员', 'amount' => 9900, 'currency' => 'eur', 'interval' => 'month',
        ]);
    }

    public function testAdyenCreateSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->adyen(['checkout/v70/payments' => json_encode(['id' => 'pay_1', 'resultCode' => 'Authorised'])]);

        $result = $gateway->createSubscription(['customer_id' => 'shopper_1', 'plan_id' => 'ref_1']);

        $this->assertSame('pay_1', $result['id']);
    }

    public function testAdyenCancelSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->adyen(['pal/servlet/Recurring/v68/disable' => json_encode(['response' => '[cancel-recurring-success]'])]);

        $result = $gateway->cancelSubscription('token_1');

        $this->assertArrayHasKey('response', $result);
    }

    public function testAdyenPauseSubscriptionThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->adyen()->pauseSubscription('token_1');
    }

    public function testAdyenResumeSubscriptionThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->adyen()->resumeSubscription('token_1');
    }

    public function testAdyenGetSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->adyen(['pal/servlet/Recurring/v68/listRecurringDetails' => json_encode(['details' => []])]);

        $result = $gateway->getSubscription('shopper_1');

        $this->assertArrayHasKey('details', $result);
    }

    // ---- Alipay：createPlan / createSubscription 为本地返回，pause / resume 诚实抛 ----

    public function testAlipayCreatePlanReturnsLocalArray(): void
    {
        // 纯本地生成计划描述，不发起 HTTP 请求（周期扣款计划需用户在收银台签约）
        $result = $this->alipay()->createPlan([
            'name' => '月度会员', 'amount' => 9900, 'currency' => 'CNY', 'interval' => 'month',
        ]);

        $this->assertArrayHasKey('plan_id', $result);
        $this->assertSame('CNY', $result['currency']);
    }

    public function testAlipayCreateSubscriptionReturnsLocalRedirectArray(): void
    {
        // 返回可跳转的签约链接，不发起同步 HTTP 调用
        $result = $this->alipay()->createSubscription([
            'customer_id' => 'ext_1',
            'plan_id' => 'plan_1',
            'period_rule_params' => ['period_type' => 'MONTH', 'period_value' => 1, 'execute_time' => '20300101000000'],
        ]);

        $this->assertArrayHasKey('url', $result);
        $this->assertStringContainsString('alipay.user.agreement.page.sign', $result['url']);
    }

    public function testAlipayCancelSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_user_agreement_unsign_response' => ['code' => '10000', 'agreement_no' => 'A1'],
            ]),
        ]);

        $result = $gateway->cancelSubscription('A1');

        $this->assertSame('A1', $result['agreement_no']);
    }

    public function testAlipayPauseSubscriptionThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->alipay()->pauseSubscription('A1');
    }

    public function testAlipayResumeSubscriptionThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->alipay()->resumeSubscription('A1');
    }

    public function testAlipayGetSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_user_agreement_query_response' => ['code' => '10000', 'agreement_no' => 'A1'],
            ]),
        ]);

        $result = $gateway->getSubscription('A1');

        $this->assertSame('A1', $result['agreement_no']);
    }

    // ---- WechatV2：createPlan / pause / resume 诚实抛，createSubscription 本地返回，cancel / get 走 papay 端点 ----

    public function testWechatV2CreatePlanThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->wechatV2()->createPlan([
            'name' => '月度会员', 'amount' => 9900, 'currency' => 'cny', 'interval' => 'month',
        ]);
    }

    public function testWechatV2CreateSubscriptionReturnsLocalRedirectArray(): void
    {
        // 返回可跳转的公众号纯签约链接，不发起同步 HTTP 调用
        $result = $this->wechatV2()->createSubscription([
            'customer_id' => 'contract_1',
            'plan_id' => 'tpl_1',
            'notify_url' => 'https://example.com/notify',
        ]);

        $this->assertArrayHasKey('url', $result);
        $this->assertStringContainsString('papay/entrustweb', $result['url']);
    }

    public function testWechatV2CancelSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV2(['papay/deletecontract' => $this->okXml(['contract_id' => 'C1'])]);

        $result = $gateway->cancelSubscription('C1');

        $this->assertArrayHasKey('return_code', $result);
        $this->assertSame('SUCCESS', $result['return_code']);
    }

    public function testWechatV2PauseSubscriptionThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->wechatV2()->pauseSubscription('C1');
    }

    public function testWechatV2ResumeSubscriptionThrowsNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $this->wechatV2()->resumeSubscription('C1');
    }

    public function testWechatV2GetSubscriptionReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV2(['papay/querycontract' => $this->okXml(['contract_id' => 'C1'])]);

        $result = $gateway->getSubscription('C1');

        $this->assertArrayHasKey('return_code', $result);
        $this->assertSame('SUCCESS', $result['return_code']);
    }
}
