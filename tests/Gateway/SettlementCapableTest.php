<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Jd\JdGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
use Kode\Pays\Gateway\Meituan\MeituanGateway;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Gateway\Qq\QqGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * SettlementCapableInterface 集中功能测试（v2.19.0 新增，与 WebhookCapableTest /
 * QrCapableTest / RefundCapableTest / TransferCapableTest / ProfitSharingCapableTest /
 * BalanceCapableTest 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 9 家真实实现 SettlementCapableInterface（stripe / wechat_v3 / wechat_v2 /
 *   adyen / jd / meituan / revolut / alipay / paypal），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 4 个结算方法（settleToWallet / settleToBankCard /
 *   settleToPayout / querySettlement），验证其确实向平台端点发起请求并返回解析后的
 *   响应，而非占位 / 空响应。结算方法大量委托到 transfer / withdraw / queryTransfer，
 *   本测试按各自真实委托链路分别 mock 并断言；
 * - 无平台内钱包 / 银行卡 / 外部 Payout 语义的网关，对应方法诚实抛
 *   methodNotSupported（「无此方法」），不伪造结算逻辑。
 */
class SettlementCapableTest extends TestCase
{
    // ---- 9 家结算网关工厂 ----

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], []), new MockHttpClient($responses));
    }

    private function revolut(array $responses = []): RevolutGateway
    {
        return new RevolutGateway(array_merge([
            'api_key' => 'rev_api',
            'merchant_id' => 'rev_mid',
            'account_id' => 'rev_src',
        ], []), new MockHttpClient($responses));
    }

    private function adyen(array $responses = []): AdyenGateway
    {
        return new AdyenGateway(array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'environment' => 'test',
            'balance_account_id' => 'BA_TEST',
        ], []), new MockHttpClient($responses));
    }

    private function jd(array $responses = []): JdGateway
    {
        return new JdGateway(array_merge([
            'merchant_no' => 'jd_mno',
            'des_key' => 'jd_des',
            'md5_key' => 'jd_md5',
        ], []), new MockHttpClient($responses));
    }

    private function meituan(array $responses = []): MeituanGateway
    {
        return new MeituanGateway(array_merge([
            'app_id' => 'mt_app',
            'app_secret' => 'mt_sec',
            'merchant_id' => 'mt_mid',
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

    private function wechat(array $responses = []): WechatPayGateway
    {
        return new WechatPayGateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'wechat_api_key',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
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

    private function paypal(array $responses = []): PaypalGateway
    {
        $responses = $responses === []
            ? [
                'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
                'v1/payments/payouts' => json_encode(['batch_header' => ['payout_batch_id' => 'P1']]),
            ]
            : $responses;

        return new PaypalGateway(array_merge([
            'client_id' => 'pp_cid',
            'client_secret' => 'pp_sec',
            'sandbox' => true,
        ], []), new MockHttpClient($responses));
    }

    /**
     * 临时生成合法 RSA 私钥（对齐 TransferCapableTest / BalanceCapableTest 做法）
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
     * 微信 V2 成功响应 XML（仅要求 return_code=SUCCESS；result_code / sign 均可选）
     */
    private function wxXml(array $extra = []): string
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<mch_id><![CDATA[m1]]></mch_id>';

        foreach ($extra as $k => $v) {
            $xml .= "<{$k}><![CDATA[{$v}]]></{$k}>";
        }

        return $xml . '</xml>';
    }

    /**
     * 统一结算参数（金额 < 2000 元阈值，规避微信 V3 platform_certificate 依赖）
     */
    private function settleParams(array $overrides = []): array
    {
        return array_merge([
            'out_biz_no' => 'S_001',
            'amount' => 5000,
            'account' => 'ACC123',
            'bank_card_no' => '6228480000000000',
            'real_name' => '张三',
        ], $overrides);
    }

    // ---- 契约一致性断言 ----

    public function testAllNineSettlementGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->wechatV3());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->adyen());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->jd());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->meituan());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(SettlementCapableInterface::class, $this->paypal());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']));
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new SquareGateway(['application_id' => 'a', 'access_token' => 't']));
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new QqGateway([
            'app_id' => 'qq_app', 'mch_id' => 'qq_mch', 'api_key' => 'k',
            'serial_no' => 's', 'private_key' => $this->rsaPrivateKey(),
        ]));
        $this->assertNotInstanceOf(SettlementCapableInterface::class, new UnionPayGateway([
            'mer_id' => 'm1', 'cert_path' => '/tmp/c', 'verify_cert_path' => '/tmp/v', 'cert_pwd' => '123456',
        ]));
    }

    // ---- stripe：仅 settleToPayout / querySettlement 真实（委托 transfers） ----

    public function testStripeSettleToPayoutReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/transfers' => json_encode(['id' => 'tr_1'])]);

        $result = $gateway->settleToPayout($this->settleParams());

        $this->assertSame('tr_1', $result['id']);
    }

    public function testStripeQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/transfers' => json_encode(['id' => 'tr_q1'])]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('tr_q1', $result['id']);
    }

    // ---- wechat_v3：仅 settleToWallet / querySettlement 真实（委托 V3 transfer） ----

    public function testWechatV3SettleToWalletReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/batches' => json_encode(['out_batch_no' => 'B1']),
        ]);

        // 不含 name：金额 < 2000 元不触发 platform_certificate 依赖
        $result = $gateway->settleToWallet([
            'out_biz_no' => 'S_001',
            'amount' => 5000,
            'account' => 'ACC123',
        ]);

        $this->assertSame('B1', $result['out_batch_no']);
    }

    public function testWechatV3QuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/batches/out-batch-no' => json_encode(['out_batch_no' => 'B1']),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('B1', $result['out_batch_no']);
    }

    // ---- wechat_v2：settleToWallet / settleToBankCard / querySettlement 真实 ----

    public function testWechatV2SettleToWalletReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/promotion/transfers' => $this->wxXml(['payment_no' => 'P1']),
        ]);

        $result = $gateway->settleToWallet($this->settleParams());

        $this->assertSame('P1', $result['payment_no']);
    }

    public function testWechatV2SettleToBankCardReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/pay_bank' => $this->wxXml(['payment_no' => 'PB1']),
        ]);

        $result = $gateway->settleToBankCard($this->settleParams());

        $this->assertSame('PB1', $result['payment_no']);
    }

    public function testWechatV2QuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'v3/transfer/batches' => json_encode(['out_batch_no' => 'B1']),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('B1', $result['out_batch_no']);
    }

    // ---- adyen：settleToBankCard / settleToPayout / querySettlement 真实（委托 Transfer） ----

    public function testAdyenSettleToBankCardReturnsParsedResponse(): void
    {
        $gateway = $this->adyen([
            'pal/servlet/Transfer/v68/transfer' => json_encode(['id' => 'A1', 'status' => 'received']),
        ]);

        $result = $gateway->settleToBankCard($this->settleParams());

        $this->assertSame('A1', $result['id']);
    }

    public function testAdyenSettleToPayoutReturnsParsedResponse(): void
    {
        $gateway = $this->adyen([
            'pal/servlet/Transfer/v68/transfer' => json_encode(['id' => 'A2', 'status' => 'received']),
        ]);

        $result = $gateway->settleToPayout($this->settleParams());

        $this->assertSame('A2', $result['id']);
    }

    public function testAdyenQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->adyen([
            'pal/servlet/Transfer/v68/transfer' => json_encode(['id' => 'AQ1', 'status' => 'received']),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('AQ1', $result['id']);
    }

    // ---- jd：settleToWallet / settleToBankCard / querySettlement 真实 ----

    public function testJdSettleToWalletReturnsParsedResponse(): void
    {
        $gateway = $this->jd([
            'api/transfer/' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JS1']),
        ]);

        $result = $gateway->settleToWallet($this->settleParams());

        $this->assertSame('JS1', $result['outBizNo']);
    }

    public function testJdSettleToBankCardReturnsParsedResponse(): void
    {
        $gateway = $this->jd([
            'api/settle/bankcard' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JSB1']),
        ]);

        $result = $gateway->settleToBankCard($this->settleParams());

        $this->assertSame('JSB1', $result['outBizNo']);
    }

    public function testJdQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->jd([
            'api/settle/query' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JSQ1']),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('JSQ1', $result['outBizNo']);
    }

    // ---- meituan：settleToWallet / settleToBankCard / querySettlement 真实 ----

    public function testMeituanSettleToWalletReturnsParsedResponse(): void
    {
        $gateway = $this->meituan([
            'api/transfer/' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MS1']),
        ]);

        $result = $gateway->settleToWallet($this->settleParams());

        $this->assertSame('MS1', $result['out_biz_no']);
    }

    public function testMeituanSettleToBankCardReturnsParsedResponse(): void
    {
        $gateway = $this->meituan([
            'api/settle/bankcard' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MSB1']),
        ]);

        $result = $gateway->settleToBankCard($this->settleParams());

        $this->assertSame('MSB1', $result['out_biz_no']);
    }

    public function testMeituanQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->meituan([
            'api/settle/query' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MSQ1']),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('MSQ1', $result['out_biz_no']);
    }

    // ---- revolut：4 个方法均真实（委托 /api/1.0/pay 与 /api/1.0/transactions） ----

    public function testRevolutSettleToWalletReturnsParsedResponse(): void
    {
        $gateway = $this->revolut([
            'api/1.0/pay' => json_encode(['id' => 'R1']),
            'api/1.0/transactions' => json_encode(['id' => 'RQ1']),
        ]);

        $result = $gateway->settleToWallet($this->settleParams());

        $this->assertSame('R1', $result['id']);
    }

    public function testRevolutSettleToBankCardReturnsParsedResponse(): void
    {
        $gateway = $this->revolut([
            'api/1.0/pay' => json_encode(['id' => 'R2']),
            'api/1.0/transactions' => json_encode(['id' => 'RQ1']),
        ]);

        $result = $gateway->settleToBankCard($this->settleParams());

        $this->assertSame('R2', $result['id']);
    }

    public function testRevolutSettleToPayoutReturnsParsedResponse(): void
    {
        $gateway = $this->revolut([
            'api/1.0/pay' => json_encode(['id' => 'R3']),
            'api/1.0/transactions' => json_encode(['id' => 'RQ1']),
        ]);

        $result = $gateway->settleToPayout($this->settleParams());

        $this->assertSame('R3', $result['id']);
    }

    public function testRevolutQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->revolut([
            'api/1.0/pay' => json_encode(['id' => 'R1']),
            'api/1.0/transactions' => json_encode(['id' => 'RQ1']),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('RQ1', $result['id']);
    }

    // ---- alipay：settleToWallet / settleToBankCard / querySettlement 真实（逐方法独立网关） ----

    public function testAlipaySettleToWalletReturnsParsedResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_fund_trans_uni_transfer_response' => ['code' => '10000', 'out_biz_no' => 'A_S1'],
            ]),
        ]);

        $result = $gateway->settleToWallet($this->settleParams());

        $this->assertSame('A_S1', $result['out_biz_no']);
    }

    public function testAlipaySettleToBankCardReturnsParsedResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_fund_trans_uni_transfer_response' => ['code' => '10000', 'out_biz_no' => 'A_B1'],
            ]),
        ]);

        $result = $gateway->settleToBankCard($this->settleParams());

        $this->assertSame('A_B1', $result['out_biz_no']);
    }

    public function testAlipayQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_fund_trans_common_query_response' => ['code' => '10000', 'out_biz_no' => 'A_Q1'],
            ]),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('A_Q1', $result['out_biz_no']);
    }

    // ---- paypal：仅 settleToPayout / querySettlement 真实（Payouts 批次） ----

    public function testPaypalSettleToPayoutReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/payments/payouts' => json_encode(['batch_header' => ['payout_batch_id' => 'P1']]),
        ]);

        $result = $gateway->settleToPayout($this->settleParams());

        $this->assertSame('P1', $result['batch_header']['payout_batch_id']);
    }

    public function testPaypalQuerySettlementReturnsParsedResponse(): void
    {
        $gateway = $this->paypal([
            'v1/oauth2/token' => json_encode(['access_token' => 'tok_1', 'token_type' => 'Bearer']),
            'v1/payments/payouts' => json_encode(['batch_header' => ['payout_batch_id' => 'PQ1']]),
        ]);

        $result = $gateway->querySettlement('S_001');

        $this->assertSame('PQ1', $result['batch_header']['payout_batch_id']);
    }

    /**
     * 无平台内钱包 / 银行卡 / 外部 Payout 语义的网关，对应方法诚实抛「无此方法」
     * （stripe / wechat_v3 / wechat_v2 / adyen / jd / meituan / alipay / paypal 共 13 处）
     */
    public function testSettleMethodsNotSupportedForGatewaysWithoutSemantics(): void
    {
        $cases = [
            'stripe.settleToWallet' => [$this->stripe(), 'settleToWallet'],
            'stripe.settleToBankCard' => [$this->stripe(), 'settleToBankCard'],
            'wechat_v3.settleToBankCard' => [$this->wechatV3(), 'settleToBankCard'],
            'wechat_v3.settleToPayout' => [$this->wechatV3(), 'settleToPayout'],
            'wechat_v2.settleToPayout' => [$this->wechat(), 'settleToPayout'],
            'adyen.settleToWallet' => [$this->adyen(), 'settleToWallet'],
            'jd.settleToPayout' => [$this->jd(), 'settleToPayout'],
            'meituan.settleToPayout' => [$this->meituan(), 'settleToPayout'],
            'alipay.settleToPayout' => [$this->alipay(), 'settleToPayout'],
            'paypal.settleToWallet' => [$this->paypal(), 'settleToWallet'],
            'paypal.settleToBankCard' => [$this->paypal(), 'settleToBankCard'],
        ];

        foreach ($cases as $name => [$gateway, $method]) {
            try {
                $gateway->$method($this->settleParams());
                $this->fail("{$name} 应当抛 methodNotSupported，但未抛异常");
            } catch (PayException $e) {
                $this->assertStringContainsString('无此方法', $e->getMessage(), "{$name} 的结算异常信息不符");
            }
        }
    }
}
