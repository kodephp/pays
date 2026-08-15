<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\HitPay\HitPayGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Gateway\Wise\WiseGateway;
use Kode\Pays\Gateway\Xendit\XenditGateway;
use Kode\Pays\Gateway\Payoneer\PayoneerGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * WebhookCapableInterface 种子实现测试（v2.4.0 起，v2.5.0 扩展）
 *
 * 覆盖 Stripe / Coinbase / HitPay / Xendit（v2.4.0）以及微信支付 / 支付宝（v2.5.0）
 * 共六个已有真实验签逻辑的网关，验证 verifyWebhook（与运行时解耦的签名校验）与
 * parseWebhook（统一事件结构）行为。
 */
class WebhookCapableTest extends TestCase
{
    private function stripe(array $config = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
            'webhook_secret' => 'whsec_test',
        ], $config));
    }

    private function coinbase(array $config = []): CoinbaseGateway
    {
        return new CoinbaseGateway(array_merge([
            'api_key' => 'coinbase_api_key',
            'webhook_secret' => 'coinbase_whsec',
        ], $config));
    }

    private function hitpay(array $config = []): HitPayGateway
    {
        return new HitPayGateway(array_merge([
            'api_key' => 'hitpay_api_key',
            'webhook_secret' => 'hitpay_whsec',
        ], $config));
    }

    private function xendit(array $config = []): XenditGateway
    {
        return new XenditGateway(array_merge([
            'secret_key' => 'xnd_secret',
            'callback_token' => 'xendit_callback_token',
        ], $config));
    }

    private function wechat(array $config = []): WechatPayGateway
    {
        return new WechatPayGateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'wechat_api_key',
        ], $config));
    }

    private function alipay(array $config = []): AlipayGateway
    {
        return new AlipayGateway(array_merge([
            'app_id' => 'alipay_app',
            'private_key' => $config['private_key'] ?? 'alipay_priv',
            'public_key' => $config['public_key'] ?? 'alipay_pub',
        ], $config));
    }

    private function unionpay(array $config = []): UnionPayGateway
    {
        // 生成自签名证书：私钥（cert_path）用于签名、公钥证书（verify_cert_path）用于验签
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
        ], $config));
    }

    private function adyen(array $config = []): AdyenGateway
    {
        return new AdyenGateway(array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'hmac_key' => bin2hex(random_bytes(16)),
        ], $config));
    }

    private function wise(array $config = []): WiseGateway
    {
        return new WiseGateway(array_merge([
            'api_key' => 'wise_key',
            'profile_id' => 'wise_profile',
        ], $config));
    }

    private function payoneer(array $config = []): PayoneerGateway
    {
        return new PayoneerGateway(array_merge([
            'api_key' => 'po_key',
            'api_secret' => 'po_secret',
            'program_id' => 'PO_PROGRAM',
        ], $config));
    }

    private function revolut(array $config = []): RevolutGateway
    {
        return new RevolutGateway(array_merge([
            'api_key' => 'revolut_key',
            'merchant_id' => 'rev_merchant',
        ], $config));
    }

    private function paypal(array $config = [], ?MockHttpClient $http = null): PaypalGateway
    {
        $gateway = new PaypalGateway(array_merge([
            'client_id' => 'pp_client',
            'client_secret' => 'pp_secret',
            'webhook_id' => 'WH-PAYPAL-WEBHOOK-ID',
        ], $config), $http);

        return $gateway;
    }

    private function buildWechatXml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $value) {
            $xml .= is_numeric($value) ? "<{$key}>{$value}</{$key}>" : "<{$key}><![CDATA[{$value}]]></{$key}>";
        }

        return $xml . '</xml>';
    }

    public function testGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->coinbase());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->hitpay());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->xendit());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->unionpay());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->adyen());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->wise());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->payoneer());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->paypal());
    }

    public function testStripeVerifyAndParse(): void
    {
        $gateway = $this->stripe();
        $payload = (string) json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded']);
        $timestamp = (string) time();
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');
        $headers = ['Stripe-Signature' => "t={$timestamp},v1={$sig}"];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        // 错误密钥应校验失败
        $this->assertFalse($gateway->verifyWebhook($payload, ['Stripe-Signature' => "t={$timestamp},v1=bad"]));
        // 缺头应校验失败
        $this->assertFalse($gateway->verifyWebhook($payload, []));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('stripe', $event['gateway']);
        $this->assertSame('evt_1', $event['event_id']);
        $this->assertSame('payment_intent.succeeded', $event['event_type']);
        $this->assertSame($payload, $event['raw']);
        $this->assertSame('evt_1', $event['data']['id']);
    }

    public function testCoinbaseVerifyAndParse(): void
    {
        $gateway = $this->coinbase();
        $payload = (string) json_encode(['event' => ['id' => 'evt_c', 'type' => 'charge:confirmed']]);
        $sig = hash_hmac('sha256', $payload, 'coinbase_whsec');
        $headers = ['X-Cc-Webhook-Signature' => $sig];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        $this->assertFalse($gateway->verifyWebhook($payload, ['X-Cc-Webhook-Signature' => 'bad']));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('coinbase', $event['gateway']);
        $this->assertSame('evt_c', $event['event_id']);
        $this->assertSame('charge:confirmed', $event['event_type']);
    }

    public function testHitPayVerifyAndParse(): void
    {
        $gateway = $this->hitpay();
        $payload = (string) json_encode(['id' => 'evt_h', 'type' => 'payment.notify']);
        $sig = hash_hmac('sha256', $payload, 'hitpay_whsec');
        $headers = ['X-Hitpay-Signature' => $sig];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        $this->assertFalse($gateway->verifyWebhook($payload, ['X-Hitpay-Signature' => 'bad']));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('hitpay', $event['gateway']);
        $this->assertSame('evt_h', $event['event_id']);
        $this->assertSame('payment.notify', $event['event_type']);
    }

    public function testXenditVerifyAndParse(): void
    {
        $gateway = $this->xendit();
        $payload = (string) json_encode(['id' => 'evt_x', 'event_type' => 'invoice.paid']);
        $headers = ['X-Callback-Token' => 'xendit_callback_token'];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        $this->assertFalse($gateway->verifyWebhook($payload, ['X-Callback-Token' => 'wrong']));
        $this->assertFalse($gateway->verifyWebhook($payload, []));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('xendit', $event['gateway']);
        $this->assertSame('evt_x', $event['event_id']);
        $this->assertSame('invoice.paid', $event['event_type']);
    }

    public function testWechatVerifyAndParse(): void
    {
        $gateway = $this->wechat();
        $data = [
            'appid' => 'wx_app',
            'mch_id' => 'mch_1',
            'out_trade_no' => 'order_1',
            'transaction_id' => 'txn_1',
            'result_code' => 'SUCCESS',
            'return_code' => 'SUCCESS',
            'total_fee' => '100',
        ];
        $data['sign'] = Signer::md5($data, 'wechat_api_key');
        $payload = $this->buildWechatXml($data);

        $this->assertTrue($gateway->verifyWebhook($payload));

        // 篡改金额后 MD5 验签应失败（沿用原 sign）
        $tampered = $this->buildWechatXml(array_merge($data, ['total_fee' => '999']));
        $this->assertFalse($gateway->verifyWebhook($tampered));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('wechat', $event['gateway']);
        $this->assertSame('txn_1', $event['event_id']);
        $this->assertSame('pay_success', $event['event_type']);
        $this->assertSame($payload, $event['raw']);
        $this->assertSame('txn_1', $event['data']['transaction_id']);
    }

    public function testAlipayVerifyAndParse(): void
    {
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            $this->markTestSkipped('当前环境不支持 openssl_pkey_new');
        }
        openssl_pkey_export($res, $privatePem);
        $publicPem = (string) openssl_pkey_get_details($res)['key'];

        $gateway = $this->alipay(['private_key' => $privatePem, 'public_key' => $publicPem]);
        $data = [
            'app_id' => 'alipay_app',
            'trade_no' => 'tn_1',
            'out_trade_no' => 'o_1',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '9.00',
        ];
        $data['sign'] = Signer::rsa2($data, $privatePem);
        $data['sign_type'] = 'RSA2';
        $payload = http_build_query($data);

        $this->assertTrue($gateway->verifyWebhook($payload));

        // 错误签名应失败
        $bad = $data;
        $bad['sign'] = 'invalid-signature';
        $this->assertFalse($gateway->verifyWebhook(http_build_query($bad)));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('alipay', $event['gateway']);
        $this->assertSame('tn_1', $event['event_id']);
        $this->assertSame('TRADE_SUCCESS', $event['event_type']);
        $this->assertSame('tn_1', $event['data']['trade_no']);
    }

    public function testAdyenVerifyAndParse(): void
    {
        $gateway = $this->adyen(['hmac_key' => '0123456789abcdef0123456789abcdef']);
        $inner = ['pspReference' => 'psp_1', 'notificationItems' => [['eventCode' => 'AUTHORISATION']]];
        $payloadField = base64_encode((string) json_encode($inner));
        $sig = hash_hmac('sha256', $payloadField, hex2bin('0123456789abcdef0123456789abcdef'));
        $payload = http_build_query(['payload' => $payloadField, 'hmacSignature' => $sig]);

        $this->assertTrue($gateway->verifyWebhook($payload));

        // 篡改 payload 后签名应失败
        $bad = base64_encode((string) json_encode(['pspReference' => 'tampered']));
        $this->assertFalse($gateway->verifyWebhook(http_build_query(['payload' => $bad, 'hmacSignature' => $sig])));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('adyen', $event['gateway']);
        $this->assertSame('psp_1', $event['event_id']);
        $this->assertSame('AUTHORISATION', $event['event_type']);
    }

    public function testWiseVerifyAndParse(): void
    {
        $gateway = $this->wise(['api_key' => 'wise_secret']);
        $data = ['id' => 'evt_w', 'event_type' => 'transfer.state_change'];
        $payload = (string) json_encode($data);
        $sig = hash_hmac('sha256', $payload, 'wise_secret');
        $headers = ['X-Signature-SHA256' => $sig];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        $this->assertFalse($gateway->verifyWebhook($payload, ['X-Signature-SHA256' => 'bad']));
        $this->assertFalse($gateway->verifyWebhook($payload, []));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('wise', $event['gateway']);
        $this->assertSame('evt_w', $event['event_id']);
        $this->assertSame('transfer.state_change', $event['event_type']);
    }

    public function testPayoneerVerifyAndParse(): void
    {
        $gateway = $this->payoneer(['api_secret' => 'po_secret']);
        $data = ['event_id' => 'evt_p', 'event_type' => 'payment.success'];
        $payload = (string) json_encode($data);
        $sig = hash_hmac('sha256', $payload, 'po_secret');
        $headers = ['X-Payoneer-Signature' => $sig];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        $this->assertFalse($gateway->verifyWebhook($payload, ['X-Payoneer-Signature' => 'bad']));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('payoneer', $event['gateway']);
        $this->assertSame('evt_p', $event['event_id']);
        $this->assertSame('payment.success', $event['event_type']);
    }

    public function testRevolutVerifyAndParse(): void
    {
        $gateway = $this->revolut(['api_key' => 'revolut_secret']);
        $data = ['event_id' => 'evt_r', 'event' => 'transaction.created'];
        $payload = (string) json_encode($data);
        $sig = hash_hmac('sha256', $payload, 'revolut_secret');
        $headers = ['X-Signature' => $sig];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));
        $this->assertFalse($gateway->verifyWebhook($payload, ['X-Signature' => 'bad']));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('revolut', $event['gateway']);
        $this->assertSame('evt_r', $event['event_id']);
        $this->assertSame('transaction.created', $event['event_type']);
    }

    public function testParseWebhookThrowsOnInvalidJson(): void
    {
        $this->expectException(PayException::class);

        $this->stripe()->parseWebhook('not-json');
    }

    public function testPaypalVerifyAndParse(): void
    {
        // 生成密钥对：私钥用于签名、公钥证书（PEM）用于验签
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'bits' => 2048]);
        $this->assertNotFalse($res, '环境不支持 openssl_pkey_new');
        openssl_pkey_export($res, $privPem);
        $pubPem = (string) (openssl_pkey_get_details($res)['key'] ?? '');
        $this->assertNotEmpty($pubPem);

        // 用 MockHttpClient 把 PAYPAL-CERT-URL 子串映射到上述公钥 PEM
        $http = new MockHttpClient();
        $http->addResponse('notifications/certs', $pubPem);

        $gateway = $this->paypal([], $http);

        $payload = (string) json_encode(['id' => 'evt_pp', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED']);
        $transmissionId = 'trans_id_1';
        $transmissionTime = gmdate('Y-m-d\TH:i:s\Z');
        $webhookId = 'WH-PAYPAL-WEBHOOK-ID';

        // 签名原文：transmissionId \n transmissionTime \n webhookId \n（PayPal 规范）
        $expected = $transmissionId . "\n" . $transmissionTime . "\n" . $webhookId . "\n";
        openssl_sign($expected, $sig, $res, OPENSSL_ALGO_SHA256);
        $transmissionSig = base64_encode($sig);

        $headers = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.paypal.com/v1/notifications/certs/CERT123',
            'PAYPAL-TRANSMISSION-ID' => $transmissionId,
            'PAYPAL-TRANSMISSION-SIG' => $transmissionSig,
            'PAYPAL-TRANSMISSION-TIME' => $transmissionTime,
        ];

        $this->assertTrue($gateway->verifyWebhook($payload, $headers));

        // 篡改签名应校验失败
        $badHeaders = $headers;
        $badHeaders['PAYPAL-TRANSMISSION-SIG'] = base64_encode('forged');
        $this->assertFalse($gateway->verifyWebhook($payload, $badHeaders));

        // 缺头应校验失败
        $this->assertFalse($gateway->verifyWebhook($payload, []));

        // 未配置 webhook_id 应校验失败（诚实拒绝，而非伪造通过）
        $this->assertFalse($this->paypal(['webhook_id' => ''], $http)->verifyWebhook($payload, $headers));

        $event = $gateway->parseWebhook($payload);
        $this->assertSame('paypal', $event['gateway']);
        $this->assertSame('evt_pp', $event['event_id']);
        $this->assertSame('PAYMENT.CAPTURE.COMPLETED', $event['event_type']);
        $this->assertSame($payload, $event['raw']);
        $this->assertSame('evt_pp', $event['data']['id']);
    }
}
