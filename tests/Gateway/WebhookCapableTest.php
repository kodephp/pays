<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\HitPay\HitPayGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\Xendit\XenditGateway;
use Kode\Pays\Tests\TestCase;

/**
 * WebhookCapableInterface 种子实现测试（v2.4.0）
 *
 * 覆盖 Stripe / Coinbase / HitPay / Xendit 四个已有真实验签逻辑的网关，
 * 验证 verifyWebhook（与运行时解耦的签名校验）与 parseWebhook（统一事件结构）行为。
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

    public function testGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->coinbase());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->hitpay());
        $this->assertInstanceOf(WebhookCapableInterface::class, $this->xendit());
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

    public function testParseWebhookThrowsOnInvalidJson(): void
    {
        $this->expectException(PayException::class);

        $this->stripe()->parseWebhook('not-json');
    }
}
