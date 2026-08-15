<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
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
 * RefundCapableInterface 集中功能测试（v2.14.0 契约化，v2.15.0 补功能对等验证）
 *
 * 与 WebhookCapableTest / QrCapableTest 同定位——从「能力视角」一次性锁定：
 * - 恰好 7 家真实实现 RefundCapableInterface（wechat / alipay / wechat_v3 / paypal / stripe / revolut / adyen），
 *   其余仅具备基础 queryRefund（形参 $refundId）但不实现高级退款接口的网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 applyRefund / queryRefund，验证其确实向平台端点发起请求并返回解析后的响应，
 *   而非占位 / 空响应；
 * - cancelRefund 仅 Stripe 提供真实取消能力，其余 6 家（wechat / alipay / wechat_v3 / paypal / revolut / adyen）
 *   诚实抛 methodNotSupported（「无此方法」），不伪造取消逻辑。
 */
class RefundCapableTest extends TestCase
{
    // ---- 7 家退款网关工厂 ----

    private function wechat(array $responses = [], array $config = []): WechatPayGateway
    {
        return new WechatPayGateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'wechat_api_key',
        ], $config), new MockHttpClient($responses));
    }

    private function alipay(array $responses = [], array $config = []): AlipayGateway
    {
        $key = $config['private_key'] ?? $this->rsaPrivateKey();

        return new AlipayGateway(array_merge([
            'app_id' => 'alipay_app',
            'private_key' => $key,
            'public_key' => $key,
            'notify_url' => 'https://example.com/notify',
        ], $config), new MockHttpClient($responses));
    }

    private function wechatV3(array $responses = [], array $config = []): WechatPayV3Gateway
    {
        return new WechatPayV3Gateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
            'api_key' => 'wechat_api_key',
        ], $config), new MockHttpClient($responses));
    }

    private function paypal(array $responses = [], array $config = []): PaypalGateway
    {
        return new PaypalGateway(array_merge([
            'client_id' => 'pp_cid',
            'client_secret' => 'pp_sec',
        ], $config), new MockHttpClient($responses));
    }

    private function stripe(array $responses = [], array $config = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], $config), new MockHttpClient($responses));
    }

    private function revolut(array $responses = [], array $config = []): RevolutGateway
    {
        $responses = $responses === []
            ? ['merchant.revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])]
            : $responses;

        return new RevolutGateway(array_merge([
            'api_key' => 'rev_api',
            'merchant_id' => 'rev_mid',
            'account_id' => 'rev_src',
        ], $config), new MockHttpClient($responses));
    }

    private function adyen(array $responses = [], array $config = []): AdyenGateway
    {
        $responses = $responses === []
            ? ['adyen.com' => json_encode(['id' => 'X', 'status' => 'received'])]
            : $responses;

        return new AdyenGateway(array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'environment' => 'test',
        ], $config), new MockHttpClient($responses));
    }

    /**
     * 临时生成合法 RSA 私钥（对齐 AlipayRefundTest / WechatPayV3CapabilityTest 做法）
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
     * 微信退款成功响应 XML（对齐 WechatPayRefundTest::okXml，确保 libxml 解析稳定）
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

    public function testAllSevenRefundGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(RefundCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(RefundCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(RefundCapableInterface::class, $this->wechatV3());
        $this->assertInstanceOf(RefundCapableInterface::class, $this->paypal());
        $this->assertInstanceOf(RefundCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(RefundCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(RefundCapableInterface::class, $this->adyen());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        // 这些网关仅有基础 queryRefund（形参 $refundId），未实现 RefundCapableInterface 三件套
        $klarna = new KlarnaGateway(['username' => 'u', 'password' => 'p']);
        $this->assertNotInstanceOf(RefundCapableInterface::class, $klarna);

        $amazon = new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a',
            'secret_key' => 's', 'client_id' => 'c',
        ]);
        $this->assertNotInstanceOf(RefundCapableInterface::class, $amazon);

        $afterpay = new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']);
        $this->assertNotInstanceOf(RefundCapableInterface::class, $afterpay);

        $coinbase = new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']);
        $this->assertNotInstanceOf(RefundCapableInterface::class, $coinbase);

        $square = new SquareGateway(['application_id' => 'a', 'access_token' => 't']);
        $this->assertNotInstanceOf(RefundCapableInterface::class, $square);

        $this->assertNotInstanceOf(RefundCapableInterface::class, new QqGateway([
            'app_id' => 'qq_app', 'mch_id' => 'qq_mch', 'api_key' => 'k',
            'serial_no' => 's', 'private_key' => $this->rsaPrivateKey(),
        ]));
        $this->assertNotInstanceOf(RefundCapableInterface::class, $this->unionpay());
    }

    private function unionpay(): UnionPayGateway
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
        ], new MockHttpClient());
    }

    // ---- applyRefund 真实功能验证（MockHttpClient 驱动） ----

    public function testWechatApplyRefundReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['secapi/pay/refund' => $this->okXml(['refund_id' => 'REF_1'])]);

        $result = $gateway->applyRefund([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 50,
            'total_fee' => 100,
        ]);

        $this->assertSame('REF_1', $result['refund_id']);
    }

    public function testAlipayApplyRefundReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_trade_refund_response' => ['code' => '10000', 'msg' => 'Success', 'out_request_no' => 'REFUND_001'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->applyRefund([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
        ]);

        $this->assertSame('REFUND_001', $result['out_request_no']);
    }

    public function testWechatV3ApplyRefundReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'refund/domestic/refunds' => json_encode(['refund_id' => 'R1', 'status' => 'PROCESSING']),
        ]);

        $result = $gateway->applyRefund([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 500,
            'total_fee' => 1000,
        ]);

        $this->assertSame('R1', $result['refund_id']);
    }

    public function testPaypalApplyRefundReturnsParsedResponse(): void
    {
        $mock = [
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v2/payments/captures' => json_encode(['id' => 'ref_1', 'status' => 'COMPLETED']),
        ];
        $gateway = $this->paypal($mock);

        $result = $gateway->applyRefund([
            'transaction_id' => 'CAP_1',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
            'currency' => 'USD',
        ]);

        $this->assertSame('ref_1', $result['id']);
    }

    public function testStripeApplyRefundReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/refunds' => json_encode(['id' => 're_1', 'status' => 'succeeded'])]);

        $result = $gateway->applyRefund([
            'transaction_id' => 'pi_1',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 5000,
        ]);

        $this->assertSame('re_1', $result['id']);
    }

    public function testRevolutApplyRefundReturnsParsedResponse(): void
    {
        $gateway = $this->revolut();

        $result = $gateway->applyRefund([
            'out_refund_no' => 'R_REV_1',
            'refund_fee' => 10000,
            'transaction_id' => 'ORD_5512',
        ]);

        $this->assertSame('X', $result['id']);
    }

    public function testAdyenApplyRefundReturnsParsedResponse(): void
    {
        $gateway = $this->adyen();

        $result = $gateway->applyRefund([
            'out_refund_no' => 'R_ADYEN_1',
            'refund_fee' => 5000,
            'transaction_id' => 'PSP_882211',
        ]);

        $this->assertSame('X', $result['id']);
    }

    // ---- queryRefund 真实功能验证（MockHttpClient 驱动） ----

    public function testWechatQueryRefundReturnsParsedResponse(): void
    {
        $gateway = $this->wechat(['pay/refundquery' => $this->okXml(['refund_id' => 'REF_1'])]);

        $result = $gateway->queryRefund('REFUND_001');

        $this->assertSame('REF_1', $result['refund_id']);
    }

    public function testAlipayQueryRefundReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_trade_fastpay_refund_query_response' => ['code' => '10000', 'out_request_no' => 'REFUND_001'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->queryRefund('REFUND_001');

        $this->assertSame('REFUND_001', $result['out_request_no']);
    }

    public function testWechatV3QueryRefundReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3(['refund/domestic/refunds/REFUND_001' => json_encode(['refund_id' => 'R1'])]);

        $result = $gateway->queryRefund('REFUND_001');

        $this->assertSame('R1', $result['refund_id']);
    }

    public function testPaypalQueryRefundReturnsParsedResponse(): void
    {
        $mock = [
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v2/payments/refunds' => json_encode(['id' => 'ref_1', 'status' => 'COMPLETED']),
        ];
        $gateway = $this->paypal($mock);

        $result = $gateway->queryRefund('REFUND_001');

        $this->assertSame('ref_1', $result['id']);
    }

    public function testStripeQueryRefundReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/refunds' => json_encode(['data' => [['id' => 're_1']]])]);

        $result = $gateway->queryRefund('REFUND_001');

        $this->assertSame('re_1', $result['data'][0]['id']);
    }

    public function testRevolutQueryRefundReturnsParsedResponse(): void
    {
        $gateway = $this->revolut();

        $result = $gateway->queryRefund('REF_ORD_7722');

        $this->assertSame('X', $result['id']);
    }

    public function testAdyenQueryRefundReturnsParsedResponse(): void
    {
        $gateway = $this->adyen();

        $result = $gateway->queryRefund('PSP_882211');

        $this->assertSame('X', $result['id']);
    }

    // ---- cancelRefund 真实功能验证（仅 Stripe） ----

    public function testStripeCancelRefundReturnsCanceled(): void
    {
        $mock = [
            'v1/refunds/re_1/cancel' => json_encode(['id' => 're_1', 'status' => 'canceled']),
            'v1/refunds' => json_encode(['data' => [['id' => 're_1']]]),
        ];
        $gateway = $this->stripe($mock);

        $result = $gateway->cancelRefund('REFUND_001');

        $this->assertSame('canceled', $result['status']);
    }

    /**
     * 其余 6 家未提供取消退款能力，统一报「无此方法」（诚实不伪造）
     */
    public function testCancelRefundNotSupportedForSixGateways(): void
    {
        $gateways = [
            'wechat' => $this->wechat(),
            'alipay' => $this->alipay(),
            'wechat_v3' => $this->wechatV3(),
            'paypal' => $this->paypal(),
            'revolut' => $this->revolut(),
            'adyen' => $this->adyen(),
        ];

        foreach ($gateways as $name => $gateway) {
            try {
                $gateway->cancelRefund('REFUND_001');
                $this->fail("{$name} 应当抛 methodNotSupported，但未抛异常");
            } catch (PayException $e) {
                $this->assertStringContainsString('无此方法', $e->getMessage(), "{$name} 的取消退款异常信息不符");
            }
        }
    }
}
