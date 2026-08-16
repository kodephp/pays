<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
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
 * PersonalReceiveCapableInterface 集中功能测试（v2.23.0 新增，与 Webhook/QR/Refund/
 * Transfer/ProfitSharing/Balance/Settlement/Subscription/RedPacket/Reconciliation 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 8 家真实实现 PersonalReceiveCapableInterface（unionpay / stripe / paypal / square /
 *   alipay / revolut / wechat_v3 / wechat_v2），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 createQrCode / queryRecords / withdraw / queryWithdraw，
 *   验证其确实向平台真实端点发起请求并返回解析后的响应，而非占位 / 空响应；
 * - 无对应语义的方法诚实抛「无此方法」（square 的 withdraw 无主动提现接口），不伪造；
 * - PayPal 每方法均需先取访问令牌（v1/oauth2/token），已按 buildJsonAuthHeaders 依赖 mock。
 */
class PersonalReceiveCapableTest extends TestCase
{
    // ---- 8 家个人收款网关工厂（复用既有 *CapableTest 同构配置） ----

    private function unionpay(array $responses = []): UnionPayGateway
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'up-test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($cert, $certPem);

        $certFile = tempnam(sys_get_temp_dir(), 'up_');
        $verifyFile = tempnam(sys_get_temp_dir(), 'upv_');
        file_put_contents($certFile, $privatePem);
        file_put_contents($verifyFile, $certPem);
        register_shutdown_function(static function () use ($certFile, $verifyFile): void {
            @unlink($certFile);
            @unlink($verifyFile);
        });

        return new UnionPayGateway([
            'mer_id' => 'm1',
            'cert_path' => $certFile,
            'verify_cert_path' => $verifyFile,
            'cert_pwd' => '123456',
        ], new MockHttpClient($responses));
    }

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], []), new MockHttpClient($responses));
    }

    private function paypal(array $responses = []): PaypalGateway
    {
        $responses = array_merge([
            'v1/oauth2/token' => json_encode(['access_token' => 'TOK', 'token_type' => 'Bearer']),
        ], $responses);

        return new PaypalGateway(array_merge([
            'client_id' => 'pp_cid',
            'client_secret' => 'pp_sec',
            'currency' => 'USD',
        ], []), new MockHttpClient($responses));
    }

    private function square(array $responses = []): SquareGateway
    {
        return new SquareGateway(array_merge([
            'application_id' => 'sq_app',
            'access_token' => 'sq_token',
            'location_id' => 'sq_loc',
            'currency' => 'USD',
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

    private function revolut(array $responses = []): RevolutGateway
    {
        $responses = $responses === []
            ? ['merchant.revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])]
            : $responses;

        return new RevolutGateway(array_merge([
            'api_key' => 'rev_api',
            'merchant_id' => 'rev_mid',
            'account_id' => 'rev_src',
            'currency' => 'EUR',
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
     * 微信 V2 成功响应 XML（不带 sign，跳过验签，确保 libxml 解析稳定）
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

    /**
     * 微信风格对账单 CSV（数据行 transaction_id 落索引 5）
     */
    private function wechatCsvSample(): string
    {
        return "col1,col2,col3,col4,col5,col6,col7,col8,col9,col10,col11,col12\n"
            . "`2026-08-16 10:00:00`,wx,mch,,,txn1,out1,open1,trade,,cny";
    }

    // ---- 契约一致性断言 ----

    public function testAllEightPersonalReceiveGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->unionpay());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->paypal());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->square());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->wechatV3());
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $this->wechat());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']));
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new JdGateway([
            'merchant_no' => 'jd_mno', 'des_key' => 'jd_des', 'md5_key' => 'jd_md5',
        ]));
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new MeituanGateway([
            'app_id' => 'mt_app', 'app_secret' => 'mt_sec', 'merchant_id' => 'mt_mid',
        ]));
        $this->assertNotInstanceOf(PersonalReceiveCapableInterface::class, new DouyinPayGateway([
            'app_id' => 'dy_app', 'merchant_id' => 'dy_mid', 'salt' => 'dy_salt',
        ]));
    }

    // ---- createQrCode 真实功能验证（MockHttpClient 驱动） ----

    public function testUnionPayCreateQrCodeReturnsQrCode(): void
    {
        $gateway = $this->unionpay(['gateway/api/backTransReq.do' => json_encode([
            'respCode' => '00', 'qrCode' => 'https://qr/Q1', 'queryId' => 'Q1',
        ])]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('https://qr/Q1', $result['qr_code']);
    }

    public function testStripeCreateQrCodeReturnsPaymentLink(): void
    {
        $gateway = $this->stripe([
            'v1/prices' => json_encode(['id' => 'price_1']),
            'v1/payment_links' => json_encode(['id' => 'pl_1', 'url' => 'https://pay/pl_1', 'metadata' => ['out_trade_no' => 'PERSONAL_X']]),
        ]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('https://pay/pl_1', $result['payment_link']);
        $this->assertSame('PERSONAL_X', $result['out_trade_no']);
    }

    public function testPaypalCreateQrCodeReturnsInvoiceQr(): void
    {
        $gateway = $this->paypal([
            '/generate-qr-code' => json_encode(['image' => 'data:image/png;base64,ABC']),
            '/send' => json_encode(['id' => 'INV1', 'status' => 'SENT']),
            'v2/invoicing/invoices' => json_encode(['id' => 'INV1']),
        ]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('INV1', $result['invoice_id']);
        $this->assertSame('data:image/png;base64,ABC', $result['qr_code']);
    }

    public function testSquareCreateQrCodeReturnsPaymentLink(): void
    {
        $gateway = $this->square([
            'v2/online-checkout/payment-links' => json_encode(['payment_link' => ['id' => 'pl1', 'url' => 'https://sq/pl1']]),
        ]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('https://sq/pl1', $result['qr_code']);
    }

    public function testAlipayCreateQrCodeReturnsQrCode(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_trade_precreate_response' => ['code' => '10000', 'qr_code' => 'https://qr/a'],
            ]),
        ]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('https://qr/a', $result['qr_code']);
    }

    public function testRevolutCreateQrCodeReturnsCheckoutUrl(): void
    {
        $gateway = $this->revolut(['api/1.0/orders' => json_encode([
            'id' => 'ord1', 'checkout_url' => 'https://rev/ord1',
        ])]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('ord1', $result['order_id']);
        $this->assertSame('https://rev/ord1', $result['qr_code']);
    }

    public function testWechatV3CreateQrCodeReturnsCodeUrl(): void
    {
        $gateway = $this->wechatV3([
            'pay/transactions/native' => json_encode(['code_url' => 'weixin://wxpay/biz/xxx']),
        ]);

        $result = $gateway->createQrCode([
            'amount' => 100, 'description' => '收款', 'notify_url' => 'https://example.com/notify',
        ]);

        $this->assertSame('weixin://wxpay/biz/xxx', $result['code_url']);
    }

    public function testWechatV2CreateQrCodeReturnsCodeUrl(): void
    {
        $gateway = $this->wechat([
            'pay/unifiedorder' => $this->okXml(['code_url' => 'weixin://wxpay/x']),
        ]);

        $result = $gateway->createQrCode(['amount' => 100, 'description' => '收款']);

        $this->assertSame('weixin://wxpay/x', $result['code_url']);
    }

    // ---- queryRecords 真实功能验证 ----

    public function testUnionPayQueryRecordsReturnsResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/queryTrans.do' => json_encode([
            'respCode' => '00', 'queryId' => 'Q1',
        ])]);

        $result = $gateway->queryRecords(['out_trade_no' => 'PR1']);

        $this->assertSame('Q1', $result['queryId']);
    }

    public function testStripeQueryRecordsReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/payment_intents' => json_encode([
            'data' => [['id' => 'pi_1', 'amount' => 100]],
        ])]);

        $result = $gateway->queryRecords([]);

        $this->assertSame('pi_1', $result['data'][0]['id']);
    }

    public function testPaypalQueryRecordsReturnsResponse(): void
    {
        $gateway = $this->paypal([
            'v1/reporting/transactions' => json_encode(['transaction_details' => [['id' => 't1']]]),
        ]);

        $result = $gateway->queryRecords([]);

        $this->assertSame('t1', $result['transaction_details'][0]['id']);
    }

    public function testSquareQueryRecordsReturnsResponse(): void
    {
        $gateway = $this->square([
            'v2/payments' => json_encode(['payments' => [['id' => 'p1']]]),
        ]);

        $result = $gateway->queryRecords([]);

        $this->assertSame('p1', $result['payments'][0]['id']);
    }

    public function testAlipayQueryRecordsReturnsResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_trade_query_response' => ['code' => '10000', 'trade_no' => 'T1'],
            ]),
        ]);

        $result = $gateway->queryRecords([]);

        $this->assertSame('T1', $result['trade_no']);
    }

    public function testRevolutQueryRecordsReturnsResponse(): void
    {
        $gateway = $this->revolut(['api/1.0/orders' => json_encode([
            'items' => [['id' => 'o1']],
        ])]);

        $result = $gateway->queryRecords([]);

        $this->assertSame('o1', $result['items'][0]['id']);
    }

    public function testWechatV3QueryRecordsReturnsBillMeta(): void
    {
        $gateway = $this->wechatV3([
            'bill/tradebill' => json_encode(['download_url' => '', 'hash_value' => '']),
        ]);

        $result = $gateway->queryRecords([]);

        $this->assertSame('', $result['download_url']);
        $this->assertSame([], $result['records']);
    }

    public function testWechatV2QueryRecordsReturnsParsedRecords(): void
    {
        $gateway = $this->wechat([
            'pay/downloadbill' => $this->wechatCsvSample(),
        ]);

        $result = $gateway->queryRecords([]);

        $this->assertCount(1, $result['records']);
        $this->assertSame('txn1', $result['records'][0]['transaction_id']);
    }

    // ---- withdraw 真实功能验证（square 诚实抛「无此方法」） ----

    public function testUnionPayWithdrawReturnsResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/backTransReq.do' => json_encode([
            'respCode' => '00', 'queryId' => 'W1',
        ])]);

        $result = $gateway->withdraw([
            'out_biz_no' => 'W1', 'amount' => 100, 'bank_card_no' => '622', 'real_name' => '张三',
        ]);

        $this->assertSame('W1', $result['queryId']);
    }

    public function testStripeWithdrawReturnsResponse(): void
    {
        $gateway = $this->stripe(['v1/payouts' => json_encode(['id' => 'po_1'])]);

        $result = $gateway->withdraw(['out_biz_no' => 'W1', 'amount' => 100]);

        $this->assertSame('po_1', $result['id']);
    }

    public function testPaypalWithdrawReturnsResponse(): void
    {
        $gateway = $this->paypal([
            'v1/payments/payouts' => json_encode(['batch_header' => ['payout_batch_id' => 'P1']]),
        ]);

        $result = $gateway->withdraw(['out_biz_no' => 'W1', 'amount' => 100, 'account' => 'dev@example.com']);

        $this->assertSame('P1', $result['batch_header']['payout_batch_id']);
    }

    public function testSquareWithdrawNotSupported(): void
    {
        $gateway = $this->square();

        try {
            $gateway->withdraw(['out_biz_no' => 'W1', 'amount' => 100]);
            $this->fail('square 应当抛 methodNotSupported，但未抛异常');
        } catch (PayException $e) {
            $this->assertStringContainsString('无此方法', $e->getMessage());
        }
    }

    public function testAlipayWithdrawReturnsResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_fund_trans_uni_transfer_response' => ['code' => '10000', 'out_biz_no' => 'W1'],
            ]),
        ]);

        $result = $gateway->withdraw([
            'out_biz_no' => 'W1', 'amount' => 100, 'bank_card_no' => '622', 'real_name' => '张三',
        ]);

        $this->assertSame('W1', $result['out_biz_no']);
    }

    public function testRevolutWithdrawReturnsResponse(): void
    {
        $gateway = $this->revolut(['api/1.0/pay' => json_encode(['id' => 'pay1'])]);

        $result = $gateway->withdraw([
            'out_biz_no' => 'W1', 'amount' => 100, 'account' => 'ACC123',
        ]);

        $this->assertSame('pay1', $result['id']);
    }

    public function testWechatV3WithdrawReturnsResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/batches' => json_encode(['out_batch_no' => 'B1']),
        ]);

        $result = $gateway->withdraw([
            'out_biz_no' => 'W1', 'amount' => 100, 'account' => 'ACC123',
        ]);

        $this->assertSame('B1', $result['out_batch_no']);
    }

    public function testWechatV2WithdrawReturnsResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/pay_bank' => $this->okXml(['partner_trade_no' => 'W1']),
        ]);

        $result = $gateway->withdraw([
            'out_biz_no' => 'W1', 'amount' => 100, 'bank_card_no' => '622', 'real_name' => '张三',
        ]);

        $this->assertSame('W1', $result['partner_trade_no']);
    }

    // ---- queryWithdraw 真实功能验证 ----

    public function testUnionPayQueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/queryTrans.do' => json_encode([
            'respCode' => '00', 'queryId' => 'W1',
        ])]);

        $result = $gateway->queryWithdraw('W1');

        $this->assertSame('W1', $result['queryId']);
    }

    public function testStripeQueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->stripe(['v1/payouts/po_q1' => json_encode(['id' => 'po_q1'])]);

        $result = $gateway->queryWithdraw('po_q1');

        $this->assertSame('po_q1', $result['id']);
    }

    public function testPaypalQueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->paypal([
            'v1/payments/payouts/P1' => json_encode(['id' => 'P1']),
        ]);

        $result = $gateway->queryWithdraw('P1');

        $this->assertSame('P1', $result['id']);
    }

    public function testSquareQueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->square([
            'v2/payouts/po1' => json_encode(['id' => 'po1']),
        ]);

        $result = $gateway->queryWithdraw('po1');

        $this->assertSame('po1', $result['id']);
    }

    public function testAlipayQueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_fund_trans_common_query_response' => ['code' => '10000', 'out_biz_no' => 'W1'],
            ]),
        ]);

        $result = $gateway->queryWithdraw('W1');

        $this->assertSame('W1', $result['out_biz_no']);
    }

    public function testRevolutQueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->revolut(['api/1.0/transactions' => json_encode([
            'data' => [['id' => 't1']],
        ])]);

        $result = $gateway->queryWithdraw('W1');

        $this->assertSame('t1', $result['data'][0]['id']);
    }

    public function testWechatV3QueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/batches/out-batch-no' => json_encode(['out_batch_no' => 'B1']),
        ]);

        $result = $gateway->queryWithdraw('W1');

        $this->assertSame('B1', $result['out_batch_no']);
    }

    public function testWechatV2QueryWithdrawReturnsResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/query_bank' => $this->okXml(['partner_trade_no' => 'W1']),
        ]);

        $result = $gateway->queryWithdraw('W1');

        $this->assertSame('W1', $result['partner_trade_no']);
    }
}
