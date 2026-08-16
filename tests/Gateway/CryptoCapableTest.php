<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\CryptoCapableInterface;
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
 * CryptoCapableInterface 集中功能测试（v2.24.0 新增，与 Webhook/QR/Refund/Transfer/
 * ProfitSharing/Balance/Settlement/Subscription/RedPacket/Reconciliation/PersonalReceive 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 1 家真实实现 CryptoCapableInterface（coinbase），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 8 个接口方法（createOrder / createCryptoOrder /
 *   getPaymentAddresses / getConfirmations / getExchangeRate / queryOrder / refund /
 *   verifyNotify），验证其确实向 Coinbase Commerce 真实端点发起请求并返回解析后的响应；
 * - 诚实性：createCryptoOrder 不支持的币种统一抛 paramError，不伪造；
 * - verifyNotify 依赖 `$_SERVER` + `php://input` 超全局（仅可测 guard 路径），真实的
 *   HMAC-SHA256 验签逻辑由同网关解耦版 verifyWebhook 承载并在本测试中完整驱动。
 */
class CryptoCapableTest extends TestCase
{
    private function coinbase(array $responses = []): CoinbaseGateway
    {
        return new CoinbaseGateway(array_merge([
            'api_key' => 'test_api_key',
            'webhook_secret' => 'test_webhook_secret',
        ], []), new MockHttpClient($responses));
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
        ]);
    }

    // ---- 契约一致性断言 ----

    public function testCoinbaseImplementsCryptoCapableInterface(): void
    {
        $this->assertInstanceOf(CryptoCapableInterface::class, $this->coinbase());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new StripeGateway(['secret_key' => 'sk_test_123']));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new PaypalGateway([
            'client_id' => 'pp_cid', 'client_secret' => 'pp_sec', 'currency' => 'USD',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new AlipayGateway([
            'app_id' => 'alipay_app', 'private_key' => 'k', 'public_key' => 'k', 'notify_url' => 'https://example.com/notify',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new WechatPayV3Gateway([
            'app_id' => 'wx_app', 'mch_id' => 'mch_1', 'serial_no' => 's', 'private_key' => 'k', 'api_key' => 'ak',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new WechatPayGateway([
            'app_id' => 'wx_app', 'mch_id' => 'mch_1', 'api_key' => 'ak', 'serial_no' => 's', 'private_key' => 'k',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, $this->unionpay());
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new RevolutGateway([
            'api_key' => 'rev_api', 'merchant_id' => 'rev_mid', 'account_id' => 'rev_src', 'currency' => 'EUR',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new SquareGateway([
            'application_id' => 'sq_app', 'access_token' => 'sq_token', 'location_id' => 'sq_loc', 'currency' => 'USD',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new AdyenGateway([
            'api_key' => 'ady_key', 'merchant_account' => 'ady_ma', 'client_id' => 'ady_cid', 'client_secret' => 'ady_sec',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new JdGateway([
            'merchant_no' => 'jd_mno', 'des_key' => 'jd_des', 'md5_key' => 'jd_md5',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new MeituanGateway([
            'app_id' => 'mt_app', 'app_secret' => 'mt_sec', 'merchant_id' => 'mt_mid',
        ]));
        $this->assertNotInstanceOf(CryptoCapableInterface::class, new DouyinPayGateway([
            'app_id' => 'dy_app', 'merchant_id' => 'dy_mid', 'salt' => 'dy_salt',
        ]));
    }

    // ---- createOrder 真实功能验证 ----

    public function testCoinbaseCreateOrderReturnsCharge(): void
    {
        $gateway = $this->coinbase(['v2/charges' => json_encode([
            'data' => [
                'id' => 'chg_abc',
                'code' => 'code_xyz',
                'hosted_url' => 'https://commerce.coinbase.com/charges/chg_abc',
                'timeline' => [['status' => 'NEW']],
                'pricing' => [],
                'addresses' => [],
            ],
        ])]);

        $result = $gateway->createOrder([
            'out_trade_no' => 'O1',
            'total_amount' => 10000,
            'currency' => 'USD',
        ]);

        $this->assertSame('O1', $result['out_trade_no']);
        $this->assertSame('chg_abc', $result['charge_id']);
        $this->assertSame('https://commerce.coinbase.com/charges/chg_abc', $result['hosted_url']);
        $this->assertSame('NEW', $result['status']);
    }

    // ---- createCryptoOrder 真实功能验证（含诚实性校验） ----

    public function testCoinbaseCreateCryptoOrderReturnsCharge(): void
    {
        $gateway = $this->coinbase(['v2/charges' => json_encode([
            'data' => [
                'id' => 'chg_cr',
                'code' => 'code_cr',
                'hosted_url' => 'https://commerce.coinbase.com/charges/chg_cr',
                'timeline' => [['status' => 'NEW']],
                'pricing' => [],
                'addresses' => [],
            ],
        ])]);

        $result = $gateway->createCryptoOrder([
            'out_trade_no' => 'O2',
            'crypto_amount' => '50.00',
            'crypto_currency' => 'USDC',
        ]);

        $this->assertSame('O2', $result['out_trade_no']);
        $this->assertSame('chg_cr', $result['charge_id']);
    }

    public function testCoinbaseCreateCryptoOrderRejectsUnsupportedCurrency(): void
    {
        $this->expectException(PayException::class);

        $this->coinbase()->createCryptoOrder([
            'out_trade_no' => 'O2',
            'crypto_amount' => '1.0',
            'crypto_currency' => 'NOTREAL',
        ]);
    }

    // ---- getPaymentAddresses 真实功能验证 ----

    public function testCoinbaseGetPaymentAddressesReturnsAddresses(): void
    {
        $gateway = $this->coinbase(['v2/charges' => json_encode([
            'data' => [
                'addresses' => ['bitcoin' => 'bc1abc', 'ethereum' => '0xabc'],
                'pricing' => [],
            ],
        ])]);

        $result = $gateway->getPaymentAddresses('chg_1');

        $this->assertArrayHasKey('bitcoin', $result);
        $this->assertSame('bc1abc', $result['bitcoin']['address']);
        $this->assertSame('bitcoin', $result['bitcoin']['currency']);
        $this->assertStringStartsWith('bitcoin:', $result['bitcoin']['uri']);
    }

    // ---- getConfirmations 真实功能验证 ----

    public function testCoinbaseGetConfirmationsReturnsConfirmations(): void
    {
        $gateway = $this->coinbase(['v2/charges' => json_encode([
            'data' => [
                'payments' => [
                    [
                        'transaction_id' => 'tx1',
                        'status' => 'CONFIRMED',
                        'confirmations' => 6,
                        'confirmations_required' => 6,
                        'value' => ['crypto' => ['currency' => 'BTC']],
                    ],
                ],
            ],
        ])]);

        $result = $gateway->getConfirmations('chg_c');

        $this->assertArrayHasKey('BTC', $result);
        $this->assertSame('tx1', $result['BTC']['transaction_id']);
        $this->assertSame(6, $result['BTC']['confirmations']);
    }

    // ---- getExchangeRate 真实功能验证 ----

    public function testCoinbaseGetExchangeRateReturnsRate(): void
    {
        $gateway = $this->coinbase(['v2/exchange-rates' => json_encode([
            'data' => [
                'currency' => 'USD',
                'rates' => ['btc' => '60000'],
                'timestamp' => 1700000000,
            ],
        ])]);

        $result = $gateway->getExchangeRate('BTC', 'USD');

        $this->assertSame('BTC', $result['crypto_currency']);
        $this->assertSame('USD', $result['fiat_currency']);
        $this->assertSame('60000', $result['rate']);
    }

    // ---- queryOrder 真实功能验证 ----

    public function testCoinbaseQueryOrderReturnsParsedCharge(): void
    {
        $gateway = $this->coinbase(['v2/charges' => json_encode([
            'data' => [
                'id' => 'chg_q',
                'code' => 'c_q',
                'timeline' => [['status' => 'COMPLETED']],
                'pricing' => [],
                'payments' => [],
                'addresses' => [],
            ],
        ])]);

        $result = $gateway->queryOrder('chg_q');

        $this->assertSame('chg_q', $result['charge_id']);
        $this->assertSame('COMPLETED', $result['status']);
    }

    // ---- refund 真实功能验证 ----

    public function testCoinbaseRefundPostsToRefundEndpoint(): void
    {
        $gateway = $this->coinbase(['refund' => json_encode([
            'data' => ['id' => 'ref_1', 'status' => 'pending'],
        ])]);

        $result = $gateway->refund(['charge_id' => 'chg_r', 'refund_fee' => 500]);

        $this->assertSame('ref_1', $result['data']['id'] ?? '');
    }

    // ---- verifyNotify（guard 路径，依赖超全局） ----

    public function testCoinbaseVerifyNotifyRejectsMissingSignature(): void
    {
        $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] = '';

        $this->assertFalse($this->coinbase()->verifyNotify([]));

        unset($_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE']);
    }

    // ---- verifyWebhook（解耦版，真实 HMAC-SHA256 验签路径） ----

    public function testCoinbaseVerifyWebhookValidatesSignature(): void
    {
        $payload = '{"event":{"id":"evt_1","type":"charge:confirmed"}}';
        $secret = 'test_webhook_secret';
        $valid = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->coinbase()->verifyWebhook($payload, ['X-Cc-Webhook-Signature' => $valid]));
        $this->assertFalse($this->coinbase()->verifyWebhook($payload, ['X-Cc-Webhook-Signature' => 'deadbeef']));
    }
}
