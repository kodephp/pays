<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\QrCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Aggregate\AggregateGateway;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Douyin\DouyinPayGateway;
use Kode\Pays\Gateway\HitPay\HitPayGateway;
use Kode\Pays\Gateway\Jd\JdGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
use Kode\Pays\Gateway\Kuaishou\KuaishouGateway;
use Kode\Pays\Gateway\Meituan\MeituanGateway;
use Kode\Pays\Gateway\Payoneer\PayoneerGateway;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Gateway\Qq\QqGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Gateway\Wise\WiseGateway;
use Kode\Pays\Gateway\Xendit\XenditGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * QrCapableInterface 种子实现测试（v2.11.0 契约化，v2.13.0 补功能验证）
 *
 * 与 WebhookCapableTest 对等：
 * - 断言 8 家真实实现 createQrCode 的网关（wechat / alipay / unionpay / stripe /
 *   revolut / wechat_v3 / paypal / square）均 implements QrCapableInterface；
 * - 断言 v2.11.0 诚实移除虚报的 5 家（douyin / jd / qq / hitpay / aggregate）不实现该接口；
 * - 用 MockHttpClient 真实驱动 createQrCode，验证其确实产出可扫码的二维码载体
 *   （code_url / qr_code / payment_link / image），而非占位/空响应。
 */
class QrCapableTest extends TestCase
{
    // ---- 8 家 QR 网关工厂 ----

    private function wechat(array $config = [], ?MockHttpClient $mock = null): WechatPayGateway
    {
        $mock ??= new MockHttpClient();

        return new WechatPayGateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'wechat_api_key',
        ], $config), $mock);
    }

    private function alipay(array $config = [], ?MockHttpClient $mock = null): AlipayGateway
    {
        $mock ??= new MockHttpClient();
        $key = $config['private_key'] ?? $this->rsaPrivateKey();

        return new AlipayGateway(array_merge([
            'app_id' => 'alipay_app',
            'private_key' => $key,
            'public_key' => $key,
            'notify_url' => 'https://example.com/notify',
        ], $config), $mock);
    }

    private function unionpay(array $config = [], ?MockHttpClient $mock = null): UnionPayGateway
    {
        $mock ??= new MockHttpClient();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'unionpay-test'], $key, ['digest_alg' => 'sha256']);
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

        return new UnionPayGateway(array_merge([
            'mer_id' => 'm1',
            'cert_path' => $certFile,
            'verify_cert_path' => $verifyFile,
            'cert_pwd' => '123456',
        ], $config), $mock);
    }

    private function stripe(array $config = [], ?MockHttpClient $mock = null): StripeGateway
    {
        $mock ??= new MockHttpClient();

        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
            'webhook_secret' => 'whsec_test',
        ], $config), $mock);
    }

    private function revolut(array $config = [], ?MockHttpClient $mock = null): RevolutGateway
    {
        $mock ??= new MockHttpClient();

        return new RevolutGateway(array_merge([
            'api_key' => 'rev_api',
            'merchant_id' => 'rev_mid',
        ], $config), $mock);
    }

    private function wechatV3(array $config = [], ?MockHttpClient $mock = null): WechatPayV3Gateway
    {
        $mock ??= new MockHttpClient();

        return new WechatPayV3Gateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
            'api_key' => 'wechat_api_key',
        ], $config), $mock);
    }

    private function paypal(array $config = [], ?MockHttpClient $mock = null): PaypalGateway
    {
        $mock ??= new MockHttpClient();

        return new PaypalGateway(array_merge([
            'client_id' => 'pp_cid',
            'client_secret' => 'pp_sec',
        ], $config), $mock);
    }

    private function square(array $config = [], ?MockHttpClient $mock = null): SquareGateway
    {
        $mock ??= new MockHttpClient();

        return new SquareGateway(array_merge([
            'application_id' => 'sq_app',
            'access_token' => 'sq_token',
        ], $config), $mock);
    }

    // ---- 5 家虚报移除网关工厂（用于断言不实现接口） ----

    private function douyin(): DouyinPayGateway
    {
        return new DouyinPayGateway([
            'app_id' => 'dy_app',
            'merchant_id' => 'dy_mid',
            'salt' => 'dy_salt',
        ]);
    }

    private function jd(): JdGateway
    {
        return new JdGateway([
            'merchant_no' => 'jd_no',
            'des_key' => 'jd_des',
            'md5_key' => 'jd_md5',
            'rsa_private_key' => $this->rsaPrivateKey(),
            'rsa_public_key' => $this->rsaPrivateKey(),
        ]);
    }

    private function qq(): QqGateway
    {
        return new QqGateway([
            'app_id' => 'qq_app',
            'mch_id' => 'qq_mch',
            'api_key' => 'qq_api_key',
            'serial_no' => 'qq_serial',
            'private_key' => $this->rsaPrivateKey(),
        ]);
    }

    private function hitpay(): HitPayGateway
    {
        return new HitPayGateway([
            'api_key' => 'hitpay_api_key',
            'webhook_secret' => 'hitpay_whsec',
        ]);
    }

    private function aggregate(): AggregateGateway
    {
        return new AggregateGateway(['channels' => [['gateway' => 'wechat']]]);
    }

    /**
     * 临时生成合法 RSA 私钥（对齐 AlipayPersonalReceiveTest 做法，避免依赖外部文件）
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

    public function testAllEightQrGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(QrCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(QrCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(QrCapableInterface::class, $this->unionpay());
        $this->assertInstanceOf(QrCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(QrCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(QrCapableInterface::class, $this->wechatV3());
        $this->assertInstanceOf(QrCapableInterface::class, $this->paypal());
        $this->assertInstanceOf(QrCapableInterface::class, $this->square());
    }

    public function testOverclaimedGatewaysDoNotImplementInterface(): void
    {
        // v2.11.0 诚实移除 CAP_QR 虚报的 5 家：SDK 无 createQrCode 实现
        $this->assertNotInstanceOf(QrCapableInterface::class, $this->douyin());
        $this->assertNotInstanceOf(QrCapableInterface::class, $this->jd());
        $this->assertNotInstanceOf(QrCapableInterface::class, $this->qq());
        $this->assertNotInstanceOf(QrCapableInterface::class, $this->hitpay());
        $this->assertNotInstanceOf(QrCapableInterface::class, $this->aggregate());

        // 明确无关网关也不应实现（避免未来误接）
        $this->assertNotInstanceOf(QrCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new AmazonGateway(['merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new AdyenGateway(['api_key' => 'k', 'merchant_account' => 'a', 'hmac_key' => bin2hex(random_bytes(16))]));
        $this->assertNotInstanceOf(QrCapableInterface::class, new WiseGateway(['api_key' => 'k', 'profile_id' => 'p']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new PayoneerGateway(['api_key' => 'k', 'api_secret' => 's', 'program_id' => 'p']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new XenditGateway(['secret_key' => 's', 'public_key' => 'p', 'callback_token' => 't']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new KuaishouGateway(['app_id' => 'a', 'app_secret' => 's', 'merchant_id' => 'm']));
        $this->assertNotInstanceOf(QrCapableInterface::class, new MeituanGateway(['app_id' => 'a', 'app_secret' => 's', 'merchant_id' => 'm']));
    }

    // ---- 真实 createQrCode 功能验证（MockHttpClient 驱动） ----

    public function testWechatV2CreateQrCodeReturnsCodeUrl(): void
    {
        $mock = new MockHttpClient([
            'pay/unifiedorder' => '<xml>'
                . '<return_code>SUCCESS</return_code>'
                . '<result_code>SUCCESS</result_code>'
                . '<code_url>weixin://wxpay/bizpayurl?pr=abc123</code_url>'
                . '</xml>',
        ]);
        $gateway = $this->wechat([], $mock);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame('weixin://wxpay/bizpayurl?pr=abc123', $result['code_url']);
        $this->assertSame(100, $result['amount']);
    }

    public function testWechatV3CreateQrCodeReturnsCodeUrl(): void
    {
        $mock = new MockHttpClient([
            'pay/transactions' => (string) json_encode(['code_url' => 'weixin://wxpay/native?m=abc']),
        ]);
        $gateway = $this->wechatV3([], $mock);

        $result = $gateway->createQrCode([
            'out_trade_no' => 'NAT_1',
            'amount' => 100,
            'description' => '商品付款',
            'notify_url' => 'https://example.com/notify',
        ]);

        $this->assertSame('weixin://wxpay/native?m=abc', $result['code_url']);
        $this->assertSame('NAT_1', $result['out_trade_no']);
    }

    public function testUnionPayCreateQrCodeReturnsQrCode(): void
    {
        $mock = new MockHttpClient([
            'backTransReq' => (string) json_encode([
                'respCode' => '00',
                'respMsg' => 'Success',
                'qrCode' => 'https://qr.unionpay.com/xyz',
                'queryId' => 'Q123',
            ]),
        ]);
        $gateway = $this->unionpay([], $mock);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame('https://qr.unionpay.com/xyz', $result['qr_code']);
        $this->assertSame('Q123', $result['query_id']);
    }

    public function testPaypalCreateQrCodeReturnsQrImage(): void
    {
        $mock = new MockHttpClient([
            'oauth2/token' => (string) json_encode(['access_token' => 'TOK_1', 'token_type' => 'Bearer']),
            'generate-qr-code' => (string) json_encode(['image' => 'data:image/png;base64,AAA']),
            'send' => '{}',
            'invoicing/invoices' => (string) json_encode(['id' => 'INV_1']),
        ]);
        $gateway = $this->paypal([], $mock);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame('INV_1', $result['invoice_id']);
        $this->assertSame('data:image/png;base64,AAA', $result['qr_code']);
    }

    public function testRevolutCreateQrCodeReturnsCheckoutUrl(): void
    {
        $mock = new MockHttpClient([
            'api/1.0/orders' => (string) json_encode([
                'id' => 'ORD_1',
                'checkout_url' => 'https://checkout.revolut.com/pay/ORD_1',
            ]),
        ]);
        $gateway = $this->revolut([], $mock);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame('ORD_1', $result['order_id']);
        $this->assertSame('https://checkout.revolut.com/pay/ORD_1', $result['qr_code']);
    }

    public function testSquareCreateQrCodeReturnsPaymentLinkUrl(): void
    {
        $mock = new MockHttpClient([
            'online-checkout/payment-links' => (string) json_encode([
                'payment_link' => ['id' => 'pl_1', 'url' => 'https://square.site/pl_1'],
            ]),
        ]);
        $gateway = $this->square([], $mock);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame('pl_1', $result['payment_link_id']);
        $this->assertSame('https://square.site/pl_1', $result['qr_code']);
    }

    public function testMissingRequiredParamsThrows(): void
    {
        $this->expectException(PayException::class);

        $this->wechat()->createQrCode(['amount' => 100]);
    }
}
