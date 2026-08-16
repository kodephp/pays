<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\ProfitSharingCapableInterface;
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
 * ProfitSharingCapableInterface 集中功能测试（v2.17.0 新增，与 WebhookCapableTest /
 * QrCapableTest / RefundCapableTest / TransferCapableTest 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 8 家真实实现 ProfitSharingCapableInterface（douyin / alipay / wechat_v3 /
 *   wechat_v2 / unionpay / jd / meituan / stripe），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 createProfitSharing / queryProfitSharing /
 *   returnProfitSharing / queryProfitSharingReturn / unfreezeProfitSharing，
 *   验证其确实向平台端点发起请求并返回解析后的响应，而非占位 / 空响应；
 * - 抖音的 returnProfitSharing / queryProfitSharingReturn 经退款接口触发、unfreezeProfitSharing
 *   复用 settle 完结，均为真实能力；
 * - unfreezeProfitSharing 仅 6 家（douyin / wechat_v3 / wechat_v2 / unionpay / jd / meituan）
 *   提供真实能力，alipay / stripe 因平台无独立解冻概念诚实抛 methodNotSupported（「无此方法」），
 *   不伪造解冻逻辑。
 */
class ProfitSharingCapableTest extends TestCase
{
    // ---- 8 家分账网关工厂 ----

    private function douyin(array $responses = []): DouyinPayGateway
    {
        return new DouyinPayGateway(array_merge([
            'app_id' => 'dy_app',
            'merchant_id' => 'dy_mid',
            'salt' => 'dy_salt',
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

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], []), new MockHttpClient($responses));
    }

    private function unionpay(array $responses = []): UnionPayGateway
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
            'bits' => 2048,
        ]);
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

    /**
     * 临时生成合法 RSA 私钥（对齐 AlipayRefundTest / WechatPayV3CapabilityTest / TransferCapableTest 做法）
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
     * 微信 V2 分账成功响应 XML（对齐 TransferCapableTest::okXml，确保 libxml 解析稳定）
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

    public function testAllEightProfitSharingGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->douyin());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->wechatV3());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->unionpay());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->jd());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->meituan());
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $this->stripe());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new RevolutGateway([
            'api_key' => 'rev_api', 'merchant_id' => 'rev_mid', 'account_id' => 'rev_src',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new AdyenGateway([
            'api_key' => 'adyen_key', 'merchant_account' => 'AdyenMerchant', 'environment' => 'test',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new KlarnaGateway([
            'username' => 'u', 'password' => 'p',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new AfterpayGateway([
            'merchant_id' => 'm', 'secret_key' => 's',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new CoinbaseGateway([
            'api_key' => 'k', 'webhook_secret' => 'w',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new SquareGateway([
            'application_id' => 'a', 'access_token' => 't',
        ]));
        $this->assertNotInstanceOf(ProfitSharingCapableInterface::class, new QqGateway([
            'app_id' => 'qq_app', 'mch_id' => 'qq_mch', 'api_key' => 'k',
            'serial_no' => 's', 'private_key' => $this->rsaPrivateKey(),
        ]));
    }

    // ---- createProfitSharing 真实功能验证（MockHttpClient 驱动） ----

    public function testDouyinCreateProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->douyin(['api/apps/ecpay/v1/settle' => json_encode([
            'err_no' => 0, 'out_settle_no' => 'DPS1',
        ])]);

        $result = $gateway->createProfitSharing([
            'out_order_no' => 'PS_001',
            'transaction_id' => 'T001',
            'receivers' => [['type' => 'MERCHANT_ID', 'account' => 'ACC1', 'amount' => 100]],
        ]);

        $this->assertSame('DPS1', $result['out_settle_no']);
    }

    public function testAlipayCreateProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_trade_order_settle_response' => ['code' => '10000', 'out_request_no' => 'APS1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->createProfitSharing([
            'out_order_no' => 'PS_001',
            'transaction_id' => 'T001',
            'receivers' => [['trans_in' => '2088', 'amount' => 1.00, 'desc' => '分账']],
        ]);

        $this->assertSame('APS1', $result['out_request_no']);
    }

    public function testWechatV3CreateProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3(['profitsharing/orders' => json_encode(['out_order_no' => 'WPS1'])]);

        $result = $gateway->createProfitSharing([
            'transaction_id' => 'T001',
            'out_order_no' => 'PS_001',
            'receivers' => [['type' => 'MERCHANT_ID', 'account' => 'ACC1', 'amount' => 100]],
        ]);

        $this->assertSame('WPS1', $result['out_order_no']);
    }

    public function testWechatV2CreateProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['secapi/pay/profitsharing' => $this->okXml(['out_order_no' => 'WPS_V2'])]);

        $result = $gateway->createProfitSharing([
            'transaction_id' => 'T001',
            'out_order_no' => 'PS_001',
            'receivers' => [['type' => 'MERCHANT_ID', 'account' => 'ACC1', 'amount' => 100]],
        ]);

        $this->assertSame('WPS_V2', $result['out_order_no']);
    }

    public function testUnionPayCreateProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/backTransReq.do' => json_encode([
            'respCode' => '00', 'orderId' => 'UPS1',
        ])]);

        $result = $gateway->createProfitSharing([
            'out_order_no' => 'PS_001',
            'transaction_id' => 'T001',
            'receivers' => [['type' => 'MERCHANT_ID', 'account' => 'ACC1', 'amount' => 100]],
        ]);

        $this->assertSame('UPS1', $result['orderId']);
    }

    public function testJdCreateProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/create' => json_encode(['resultCode' => '000000', 'outOrderNo' => 'JPS1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->createProfitSharing([
            'out_order_no' => 'PS_001',
            'transaction_id' => 'T001',
            'receivers' => [['account' => 'ACC1', 'amount' => 100]],
        ]);

        $this->assertSame('JPS1', $result['outOrderNo']);
    }

    public function testMeituanCreateProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/create' => json_encode(['status' => 'SUCCESS', 'out_order_no' => 'MPS1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->createProfitSharing([
            'out_order_no' => 'PS_001',
            'transaction_id' => 'T001',
            'receivers' => [['account' => 'ACC1', 'amount' => 100]],
        ]);

        $this->assertSame('MPS1', $result['out_order_no']);
    }

    public function testStripeCreateProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/transfers' => json_encode(['id' => 'tr_1', 'object' => 'transfer'])]);

        $result = $gateway->createProfitSharing([
            'out_order_no' => 'PS_001',
            'receivers' => [['account' => 'acc_1', 'amount' => 100, 'currency' => 'usd']],
        ]);

        $this->assertSame('PS_001', $result['out_order_no']);
        $this->assertSame(1, $result['count']);
    }

    // ---- queryProfitSharing 真实功能验证（MockHttpClient 驱动） ----

    public function testDouyinQueryProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->douyin(['api/apps/ecpay/v1/query_settle' => json_encode([
            'err_no' => 0, 'out_settle_no' => 'DPS_Q1',
        ])]);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('DPS_Q1', $result['out_settle_no']);
    }

    public function testAlipayQueryProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_trade_order_settle_query_response' => ['code' => '10000', 'out_request_no' => 'APS_Q1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('APS_Q1', $result['out_request_no']);
    }

    public function testWechatV3QueryProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3(['profitsharing/orders' => json_encode(['out_order_no' => 'WPS_Q1'])]);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('WPS_Q1', $result['out_order_no']);
    }

    public function testWechatV2QueryProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['pay/profitsharingquery' => $this->okXml(['out_order_no' => 'WPS_V2_Q'])]);

        $result = $gateway->queryProfitSharing('PS_001', 'T001');

        $this->assertSame('WPS_V2_Q', $result['out_order_no']);
    }

    public function testUnionPayQueryProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/queryTrans.do' => json_encode([
            'respCode' => '00', 'orderId' => 'UPS_Q1',
        ])]);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('UPS_Q1', $result['orderId']);
    }

    public function testJdQueryProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/query' => json_encode(['resultCode' => '000000', 'outOrderNo' => 'JPS_Q1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('JPS_Q1', $result['outOrderNo']);
    }

    public function testMeituanQueryProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/query' => json_encode(['status' => 'SUCCESS', 'out_order_no' => 'MPS_Q1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('MPS_Q1', $result['out_order_no']);
    }

    public function testStripeQueryProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/transfers' => json_encode(['id' => 'tr_q1', 'object' => 'transfer'])]);

        $result = $gateway->queryProfitSharing('PS_001');

        $this->assertSame('tr_q1', $result['id']);
    }

    // ---- returnProfitSharing 真实功能验证（MockHttpClient 驱动） ----

    public function testDouyinReturnProfitSharingReturnsParsedResponse(): void
    {
        // 抖音分账回退经退款接口触发
        $gateway = $this->douyin(['api/apps/ecpay/v1/create_refund' => json_encode([
            'err_no' => 0, 'out_refund_no' => 'DR1',
        ])]);

        $result = $gateway->returnProfitSharing([
            'out_order_no' => 'PS_001',
            'out_return_no' => 'DR1',
            'return_amount' => 100,
        ]);

        $this->assertSame('DR1', $result['out_refund_no']);
    }

    public function testAlipayReturnProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_trade_refund_response' => ['code' => '10000', 'out_request_no' => 'AR1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->returnProfitSharing([
            'out_return_no' => 'AR1',
            'transaction_id' => 'T001',
            'return_amount' => 1.00,
        ]);

        $this->assertSame('AR1', $result['out_request_no']);
    }

    public function testWechatV3ReturnProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3(['profitsharing/return-orders' => json_encode(['out_return_no' => 'WR1'])]);

        $result = $gateway->returnProfitSharing([
            'out_order_no' => 'PS_001',
            'out_return_no' => 'WR1',
            'return_amount' => 100,
        ]);

        $this->assertSame('WR1', $result['out_return_no']);
    }

    public function testWechatV2ReturnProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['secapi/pay/profitsharingreturn' => $this->okXml(['out_return_no' => 'WR_V2'])]);

        $result = $gateway->returnProfitSharing([
            'out_order_no' => 'PS_001',
            'out_return_no' => 'WR_V2',
            'return_amount' => 100,
        ]);

        $this->assertSame('WR_V2', $result['out_return_no']);
    }

    public function testUnionPayReturnProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/backTransReq.do' => json_encode([
            'respCode' => '00', 'orderId' => 'UR1',
        ])]);

        $result = $gateway->returnProfitSharing([
            'out_order_no' => 'PS_001',
            'out_return_no' => 'UR1',
            'return_amount' => 100,
        ]);

        $this->assertSame('UR1', $result['orderId']);
    }

    public function testJdReturnProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/return' => json_encode(['resultCode' => '000000', 'outReturnNo' => 'JR1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->returnProfitSharing([
            'out_order_no' => 'PS_001',
            'out_return_no' => 'JR1',
            'return_amount' => 100,
        ]);

        $this->assertSame('JR1', $result['outReturnNo']);
    }

    public function testMeituanReturnProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/return' => json_encode(['status' => 'SUCCESS', 'out_return_no' => 'MR1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->returnProfitSharing([
            'out_order_no' => 'PS_001',
            'out_return_no' => 'MR1',
            'return_amount' => 100,
        ]);

        $this->assertSame('MR1', $result['out_return_no']);
    }

    public function testStripeReturnProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['reversals' => json_encode(['id' => 'rev_1', 'object' => 'transfer_reversal'])]);

        $result = $gateway->returnProfitSharing([
            'transfer_id' => 'tr_1',
            'out_return_no' => 'RR1',
            'return_amount' => 100,
        ]);

        $this->assertSame('rev_1', $result['id']);
    }

    // ---- queryProfitSharingReturn 真实功能验证（MockHttpClient 驱动） ----

    public function testDouyinQueryProfitSharingReturnReturnsParsedResponse(): void
    {
        // 抖音分账回退结果查询经退款查询接口触发
        $gateway = $this->douyin(['api/apps/ecpay/v1/query_refund' => json_encode([
            'err_no' => 0, 'out_refund_no' => 'DR_Q1',
        ])]);

        $result = $gateway->queryProfitSharingReturn('DR_Q1');

        $this->assertSame('DR_Q1', $result['out_refund_no']);
    }

    public function testAlipayQueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_trade_fastpay_refund_query_response' => ['code' => '10000', 'out_request_no' => 'AR_Q1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->queryProfitSharingReturn('AR_Q1');

        $this->assertSame('AR_Q1', $result['out_request_no']);
    }

    public function testWechatV3QueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3(['profitsharing/return-orders' => json_encode(['out_return_no' => 'WR_Q1'])]);

        $result = $gateway->queryProfitSharingReturn('WR_Q1');

        $this->assertSame('WR_Q1', $result['out_return_no']);
    }

    public function testWechatV2QueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['pay/profitsharingreturnquery' => $this->okXml(['out_return_no' => 'WR_V2_Q'])]);

        $result = $gateway->queryProfitSharingReturn('WR_V2');

        $this->assertSame('WR_V2_Q', $result['out_return_no']);
    }

    public function testUnionPayQueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/queryTrans.do' => json_encode([
            'respCode' => '00', 'orderId' => 'UR_Q1',
        ])]);

        $result = $gateway->queryProfitSharingReturn('UR_Q1');

        $this->assertSame('UR_Q1', $result['orderId']);
    }

    public function testJdQueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/return/query' => json_encode(['resultCode' => '000000', 'outReturnNo' => 'JR_Q1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->queryProfitSharingReturn('JR_Q1');

        $this->assertSame('JR_Q1', $result['outReturnNo']);
    }

    public function testMeituanQueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/return/query' => json_encode(['status' => 'SUCCESS', 'out_return_no' => 'MR_Q1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->queryProfitSharingReturn('MR_Q1');

        $this->assertSame('MR_Q1', $result['out_return_no']);
    }

    public function testStripeQueryProfitSharingReturnReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['transfer_reversals' => json_encode(['id' => 'rev_q1', 'object' => 'transfer_reversal'])]);

        $result = $gateway->queryProfitSharingReturn('RR_Q1');

        $this->assertSame('rev_q1', $result['id']);
    }

    // ---- unfreezeProfitSharing 真实功能验证（仅 6 家真实，2 家诚实抛「无此方法」） ----

    public function testDouyinUnfreezeProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->douyin(['api/apps/ecpay/v1/settle' => json_encode([
            'err_no' => 0, 'out_settle_no' => 'DU1',
        ])]);

        $result = $gateway->unfreezeProfitSharing('T001');

        $this->assertSame('DU1', $result['out_settle_no']);
    }

    public function testWechatV3UnfreezeProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3(['profitsharing/orders' => json_encode(['out_order_no' => 'WU1'])]);

        $result = $gateway->unfreezeProfitSharing('T001');

        $this->assertSame('WU1', $result['out_order_no']);
    }

    public function testWechatV2UnfreezeProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['secapi/pay/profitsharingfinish' => $this->okXml(['out_order_no' => 'WU_V2'])]);

        $result = $gateway->unfreezeProfitSharing('T001');

        $this->assertSame('WU_V2', $result['out_order_no']);
    }

    public function testUnionPayUnfreezeProfitSharingReturnsParsedResponse(): void
    {
        $gateway = $this->unionpay(['gateway/api/backTransReq.do' => json_encode([
            'respCode' => '00', 'orderId' => 'UU1',
        ])]);

        $result = $gateway->unfreezeProfitSharing('T001');

        $this->assertSame('UU1', $result['orderId']);
    }

    public function testJdUnfreezeProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/finish' => json_encode(['resultCode' => '000000', 'outOrderNo' => 'JU1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->unfreezeProfitSharing('T001');

        $this->assertSame('JU1', $result['outOrderNo']);
    }

    public function testMeituanUnfreezeProfitSharingReturnsParsedResponse(): void
    {
        $mock = ['api/profitsharing/finish' => json_encode(['status' => 'SUCCESS', 'out_order_no' => 'MU1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->unfreezeProfitSharing('T001');

        $this->assertSame('MU1', $result['out_order_no']);
    }

    /**
     * alipay / stripe 平台无独立「解冻」概念，调用即报「无此方法」（诚实不伪造）
     */
    public function testUnfreezeProfitSharingNotSupportedForTwoGateways(): void
    {
        $gateways = [
            'alipay' => $this->alipay(),
            'stripe' => $this->stripe(),
        ];

        foreach ($gateways as $name => $gateway) {
            try {
                $gateway->unfreezeProfitSharing('T001');
                $this->fail("{$name} 应当抛 methodNotSupported，但未抛异常");
            } catch (PayException $e) {
                $this->assertStringContainsString('无此方法', $e->getMessage(), "{$name} 的解冻异常信息不符");
            }
        }
    }
}
