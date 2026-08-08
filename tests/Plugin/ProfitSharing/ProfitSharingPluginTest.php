<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin\ProfitSharing;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Plugin\ProfitSharingPlugin;
use Kode\Pays\Support\HttpClient;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\TestCase;

/**
 * 分账插件单元测试（使用内存假网关，不发起真实 HTTP）
 */

abstract class FakeGateway implements
    GatewayInterface,
    HttpCapableInterface
{
    /** @var array<int, array{endpoint: string, data: array, headers: array}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $config = ['secret_key' => 'sk_test_123'];

    public function post(string $endpoint, array $data = [], array $headers = []): array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'data' => $data, 'headers' => $headers];

        return ['status' => 'SUCCESS', 'endpoint' => $endpoint, 'data' => $data];
    }

    public function postRaw(string $endpoint, string $body, array $headers = []): array
    {
        return ['status' => 'SUCCESS'];
    }

    public function get(string $endpoint, array $query = [], array $headers = []): array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'data' => $query, 'headers' => $headers];

        return ['status' => 'SUCCESS'];
    }

    public function put(string $endpoint, array $data = [], array $headers = []): array
    {
        return ['status' => 'SUCCESS'];
    }

    public function delete(string $endpoint, array $query = [], array $headers = []): array
    {
        return ['status' => 'SUCCESS'];
    }

    public function createOrder(array $params): array { return []; }

    public function queryOrder(string $orderId): array { return []; }

    public function refund(array $params): array { return []; }

    public function queryRefund(string $refundId): array { return []; }

    public function verifyNotify(array $data): bool { return true; }

    public function closeOrder(string $orderId): array { return []; }

    public function setDispatcher(EventDispatcher $dispatcher): void {}

    public function setHttpClient(HttpClient $httpClient): void {}
}

class WechatFakeGateway extends FakeGateway
{
    public static function getName(): string { return 'wechat'; }
}

class AlipayFakeGateway extends FakeGateway
{
    public static function getName(): string { return 'alipay'; }
}

class StripeFakeGateway extends FakeGateway
{
    public static function getName(): string { return 'stripe'; }
}

class UnsupportedFakeGateway extends FakeGateway
{
    public static function getName(): string { return 'paypal'; }
}

class ProfitSharingPluginTest extends TestCase
{
    /**
     * 微信分账使用 Receiver DTO 时，金额按分上报且端点正确
     */
    public function testWechatCreateWithReceiverDto(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->create([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $this->assertSame('secapi/pay/profitsharing', $gateway->calls[0]['endpoint']);
        $receivers = json_decode($gateway->calls[0]['data']['receivers'], true);
        $this->assertSame(100, $receivers[0]['amount']);
        $this->assertSame('MERCHANT_ID', $receivers[0]['type']);
    }

    /**
     * 数组形式（旧用法）仍向后兼容
     */
    public function testWechatCreateWithArrayBackwardCompatible(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->create([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_2',
            'receivers' => [
                ['type' => 'MERCHANT_ID', 'account' => '123', 'amount' => 200],
            ],
        ]);

        $receivers = json_decode($gateway->calls[0]['data']['receivers'], true);
        $this->assertSame(200, $receivers[0]['amount']);
    }

    /**
     * 数组金额 <= 0 时抛异常
     */
    public function testCreateRejectsNonPositiveArrayAmount(): void
    {
        $this->expectException(PayException::class);
        (new ProfitSharingPlugin(new WechatFakeGateway()))->create([
            'transaction_id' => 'T',
            'out_order_no' => 'O',
            'receivers' => [['type' => 'MERCHANT_ID', 'account' => '1', 'amount' => 0]],
        ]);
    }

    /**
     * Receiver DTO 金额为 0 时抛异常
     */
    public function testCreateRejectsNonPositiveReceiverMoney(): void
    {
        $this->expectException(PayException::class);
        (new ProfitSharingPlugin(new WechatFakeGateway()))->create([
            'transaction_id' => 'T',
            'out_order_no' => 'O',
            'receivers' => [
                new Receiver('MERCHANT_ID', '1', null, Money::fromMinor(0, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);
    }

    /**
     * 微信分账配置查询调用正确端点
     */
    public function testWechatQueryConfig(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->queryConfig('SHARE_1', 'T100');

        $this->assertSame('pay/profitsharingconfigquery', $gateway->calls[0]['endpoint']);
        $this->assertSame('SHARE_1', $gateway->calls[0]['data']['out_order_no']);
        $this->assertSame('T100', $gateway->calls[0]['data']['transaction_id']);
    }

    /**
     * 不支持分账配置查询的网关抛异常
     */
    public function testQueryConfigUnsupportedThrows(): void
    {
        $this->expectException(PayException::class);
        (new ProfitSharingPlugin(new AlipayFakeGateway()))->queryConfig('SHARE_1');
    }

    /**
     * 解冻支持显式 out_order_no
     */
    public function testWechatUnfreezeUsesProvidedOrderNo(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->unfreeze('T100', 'FINISH_9');

        $this->assertSame('secapi/pay/profitsharingfinish', $gateway->calls[0]['endpoint']);
        $this->assertSame('FINISH_9', $gateway->calls[0]['data']['out_order_no']);
    }

    /**
     * 添加接收方（微信）调用正确端点
     */
    public function testWechatAddReceiver(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->addReceiver(['type' => 'MERCHANT_ID', 'account' => '123', 'name' => '供应商']);

        $this->assertSame('pay/profitsharingaddreceiver', $gateway->calls[0]['endpoint']);
    }

    /**
     * 不支持分账的网关在 create 时抛异常
     */
    public function testCreateUnsupportedGatewayThrows(): void
    {
        $this->expectException(PayException::class);
        (new ProfitSharingPlugin(new UnsupportedFakeGateway()))->create([
            'transaction_id' => 'T',
            'out_order_no' => 'O',
            'receivers' => [['type' => 'x', 'account' => '1', 'amount' => 1]],
        ]);
    }

    /**
     * 支付宝使用 Receiver DTO 时 amount 转为主单位元
     */
    public function testAlipayCreateWithReceiverDto(): void
    {
        $gateway = new AlipayFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->create([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $biz = json_decode($gateway->calls[0]['data']['biz_content'], true);
        $this->assertSame('alipay.trade.order.settle', $gateway->calls[0]['data']['method']);
        $this->assertSame('1.00', $biz['royalty_parameters'][0]['amount']);
    }

    /**
     * Stripe 使用 Receiver DTO 时按 Transfer 拆分
     */
    public function testStripeCreateWithReceiverDto(): void
    {
        $gateway = new StripeFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->create([
            'transaction_id' => 'pi_1',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', 'acct_1', null, Money::fromMinor(300, 'USD'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $this->assertSame('v1/transfers', $gateway->calls[0]['endpoint']);
        $this->assertSame(300, $gateway->calls[0]['data']['amount']);
        $this->assertSame('usd', $gateway->calls[0]['data']['currency']);
        $this->assertSame('pi_1', $gateway->calls[0]['data']['source_transaction']);
        $this->assertSame('Bearer sk_test_123', $gateway->calls[0]['headers']['Authorization']);
    }
}
