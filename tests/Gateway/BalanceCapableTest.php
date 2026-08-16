<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
use Kode\Pays\Gateway\Payoneer\PayoneerGateway;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Gateway\Wise\WiseGateway;
use Kode\Pays\Gateway\Qq\QqGateway;
use Kode\Pays\Gateway\Xendit\XenditGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * BalanceCapableInterface 集中功能测试（v2.18.0 新增，与 WebhookCapableTest /
 * QrCapableTest / RefundCapableTest / TransferCapableTest / ProfitSharingCapableTest 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 9 家真实实现 BalanceCapableInterface（stripe / wise / xendit / paypal /
 *   revolut / adyen / alipay / wechat_v3 / payoneer），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 queryBalance（9 家），验证其确实向各平台余额端点发起
 *   请求并返回按「分」归一化的解析响应，而非占位 / 空响应；
 * - queryDayEndBalance 仅 2 家（paypal / wechat_v3）提供真实日终余额能力，其余 7 家
 *   （stripe / wise / xendit / revolut / adyen / alipay / payoneer）诚实抛
 *   methodNotSupported（「无此方法」），不伪造日终余额逻辑。
 */
class BalanceCapableTest extends TestCase
{
    // ---- 9 家余额网关工厂 ----

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], []), new MockHttpClient($responses));
    }

    private function wise(array $responses = []): WiseGateway
    {
        return new WiseGateway(array_merge([
            'profile_id' => 'profile_1',
            'api_key' => 'wise_key',
        ], []), new MockHttpClient($responses));
    }

    private function xendit(array $responses = []): XenditGateway
    {
        return new XenditGateway(array_merge([
            'secret_key' => 'xendit_key',
            'currency' => 'IDR',
        ], []), new MockHttpClient($responses));
    }

    private function paypal(array $responses = []): PaypalGateway
    {
        $responses = $responses === []
            ? [
                'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
                'v1/reporting/balances' => json_encode(['balances' => []]),
            ]
            : $responses;

        return new PaypalGateway(array_merge([
            'client_id' => 'pp_cid',
            'client_secret' => 'pp_sec',
            'sandbox' => true,
        ], []), new MockHttpClient($responses));
    }

    private function revolut(array $responses = []): RevolutGateway
    {
        $responses = $responses === []
            ? ['merchant.revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])]
            : $responses;

        return new RevolutGateway(array_merge([
            'api_key' => 'rev_api',
            'merchant_id' => 'rev_mid',
            'account_id' => 'rev_src',
        ], []), new MockHttpClient($responses));
    }

    private function adyen(array $responses = []): AdyenGateway
    {
        $responses = $responses === []
            ? [
                'adyen.com' => json_encode(['id' => 'X', 'status' => 'received']),
                'balancePlatform/balanceAccounts' => json_encode(['data' => []]),
            ]
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

    private function wechatV3(array $responses = []): WechatPayV3Gateway
    {
        return new WechatPayV3Gateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
            'api_key' => 'wechat_api_key',
        ], []), new MockHttpClient($responses));
    }

    private function payoneer(array $responses = []): PayoneerGateway
    {
        return new PayoneerGateway(array_merge([
            'api_key' => 'po_key',
            'api_secret' => 'po_sec',
            'program_id' => 'prog_1',
            'sandbox' => true,
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

    // ---- 契约一致性断言 ----

    public function testAllNineBalanceGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->wise());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->xendit());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->paypal());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->adyen());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->wechatV3());
        $this->assertInstanceOf(BalanceCapableInterface::class, $this->payoneer());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']));
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new SquareGateway(['application_id' => 'a', 'access_token' => 't']));
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new QqGateway([
            'app_id' => 'qq_app', 'mch_id' => 'qq_mch', 'api_key' => 'k',
            'serial_no' => 's', 'private_key' => $this->rsaPrivateKey(),
        ]));
        $this->assertNotInstanceOf(BalanceCapableInterface::class, new UnionPayGateway([
            'mer_id' => 'm1', 'cert_path' => '/tmp/c', 'verify_cert_path' => '/tmp/v', 'cert_pwd' => '123456',
        ]));
    }

    // ---- queryBalance 真实功能验证（MockHttpClient 驱动，9 家全覆盖） ----

    public function testStripeQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/balance' => json_encode([
            'available' => [['amount' => 1000, 'currency' => 'cny']],
            'pending' => [['amount' => 200, 'currency' => 'cny']],
        ])]);

        $result = $gateway->queryBalance();

        $this->assertSame(1000, $result['available_amount']);
        $this->assertSame(200, $result['pending_amount']);
        $this->assertSame('cny', $result['currency']);
    }

    public function testWiseQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->wise(['v4/profiles/' => json_encode([
            'balances' => [['id' => 'B1', 'amount' => 1000, 'currency' => 'EUR']],
        ])]);

        $result = $gateway->queryBalance();

        $this->assertSame('B1', $result['balance_id']);
        $this->assertSame(1000, $result['available_amount']);
        $this->assertSame('EUR', $result['currency']);
    }

    public function testXenditQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->xendit(['balance' => json_encode(['balance' => 50000])]);

        $result = $gateway->queryBalance();

        $this->assertSame(50000, $result['available_amount']);
        $this->assertSame('IDR', $result['currency']);
    }

    public function testPaypalQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/reporting/balances' => json_encode(['balances' => [[
                'available_balance' => ['value' => '123.45', 'currency_code' => 'USD'],
                'total_balance' => ['value' => '200.00', 'currency_code' => 'USD'],
            ]]]),
        ]);

        $result = $gateway->queryBalance();

        // 主单位「元」换算为「分」：123.45 * 100 = 12345；待结算 = (200 - 123.45) * 100 = 7655
        $this->assertSame(12345, $result['available_amount']);
        $this->assertSame(7655, $result['pending_amount']);
        $this->assertSame('USD', $result['currency']);
    }

    public function testRevolutQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->revolut(['api/1.0/accounts' => json_encode([
            'accounts' => [['state' => 'active', 'id' => 'A1', 'balance' => 1000, 'currency' => 'EUR']],
        ])]);

        $result = $gateway->queryBalance();

        $this->assertSame('A1', $result['account_id']);
        $this->assertSame(1000, $result['available_amount']);
        $this->assertSame('EUR', $result['currency']);
    }

    public function testAdyenQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->adyen([
            'balancePlatform/balanceAccounts' => json_encode(['data' => [[
                'balanceAccountId' => 'BA1',
                'balance' => ['value' => 1000, 'currency' => 'EUR'],
                'pendingBalance' => ['value' => 200, 'currency' => 'EUR'],
            ]]]),
        ]);

        $result = $gateway->queryBalance(['balance_account_id' => 'BA1']);

        $this->assertSame('BA1', $result['balance_account_id']);
        $this->assertSame(1000, $result['available_amount']);
        $this->assertSame(200, $result['pending_amount']);
        $this->assertSame('EUR', $result['currency']);
    }

    public function testAlipayQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_fund_account_query_response' => [
                    'code' => '10000',
                    'available_amount' => '12.34',
                    'freeze_amount' => '1.00',
                    'total_amount' => '13.34',
                ],
            ]),
        ]);

        $result = $gateway->queryBalance();

        // 支付宝返回「元」，统一换算为「分」
        $this->assertSame(1234, $result['available_amount']);
        $this->assertSame(100, $result['freeze_amount']);
        $this->assertSame(1334, $result['total_amount']);
        $this->assertSame('CNY', $result['currency']);
    }

    public function testWechatV3QueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'merchant/fund/balance' => json_encode([
                'available_amount' => 1000, 'pending_amount' => 200, 'currency' => 'CNY',
            ]),
        ]);

        $result = $gateway->queryBalance();

        $this->assertSame(1000, $result['available_amount']);
        $this->assertSame(200, $result['pending_amount']);
        $this->assertSame('CNY', $result['currency']);
    }

    public function testPayoneerQueryBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->payoneer([
            '/v2/programs/' => json_encode(['balance' => ['amount' => '12.34', 'currency' => 'USD']]),
        ]);

        $result = $gateway->queryBalance();

        $this->assertSame(1234, $result['available_amount']);
        $this->assertSame('USD', $result['currency']);
    }

    // ---- queryDayEndBalance 真实功能验证（仅 paypal / wechat_v3 两家） ----

    public function testPaypalQueryDayEndBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/reporting/balances' => json_encode(['balances' => [[
                'available_balance' => ['value' => '123.45', 'currency_code' => 'USD'],
                'total_balance' => ['value' => '200.00', 'currency_code' => 'USD'],
            ]]]),
        ]);

        $result = $gateway->queryDayEndBalance('2026-08-16');

        $this->assertSame(12345, $result['available_amount']);
        $this->assertSame(20000, $result['day_end_balance']);
        $this->assertSame('USD', $result['currency']);
    }

    public function testWechatV3QueryDayEndBalanceReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'merchant/fund/dayendbalance' => json_encode([
                'available_amount' => 1000, 'pending_amount' => 200, 'day_end_balance' => 3000, 'currency' => 'CNY',
            ]),
        ]);

        $result = $gateway->queryDayEndBalance('2026-08-16');

        $this->assertSame(3000, $result['day_end_balance']);
        $this->assertSame('CNY', $result['currency']);
    }

    /**
     * stripe / wise / xendit / revolut / adyen / alipay / payoneer 不提供日终余额能力，
     * 调用即报「无此方法」（诚实不伪造）
     */
    public function testQueryDayEndBalanceNotSupportedForSevenGateways(): void
    {
        $gateways = [
            'stripe' => $this->stripe(),
            'wise' => $this->wise(),
            'xendit' => $this->xendit(),
            'revolut' => $this->revolut(),
            'adyen' => $this->adyen(),
            'alipay' => $this->alipay(),
            'payoneer' => $this->payoneer(),
        ];

        foreach ($gateways as $name => $gateway) {
            try {
                $gateway->queryDayEndBalance('2026-08-16');
                $this->fail("{$name} 应当抛 methodNotSupported，但未抛异常");
            } catch (PayException $e) {
                $this->assertStringContainsString('无此方法', $e->getMessage(), "{$name} 的日终余额异常信息不符");
            }
        }
    }
}
