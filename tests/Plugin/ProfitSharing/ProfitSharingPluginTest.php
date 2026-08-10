<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin\ProfitSharing;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Plugin\ProfitSharingPlugin;
use Kode\Pays\Support\HttpClient;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\TestCase;

/**
 * 分账插件单元测试（使用内存假网关，不发起真实 HTTP）
 *
 * 收敛后插件仅做「参数校验 + 类型安全转发」，平台组装逻辑已下沉到网关原生方法，
 * 因此本测试聚焦于：转发到网关原生方法、参数归一化、以及未实现能力接口 / 可选方法时抛异常。
 * 平台组装细节（端点、金额换算、鉴权头）由各网关单元测试覆盖。
 */

abstract class FakeGateway implements
    GatewayInterface,
    HttpCapableInterface
{
    /** @var array<int, array{method: string, data: mixed}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $config = ['secret_key' => 'sk_test_123'];

    public function post(string $endpoint, array $data = [], array $headers = []): array
    {
        return ['status' => 'SUCCESS', 'endpoint' => $endpoint];
    }

    public function postRaw(string $endpoint, string $body, array $headers = []): array
    {
        return ['status' => 'SUCCESS'];
    }

    public function get(string $endpoint, array $query = [], array $headers = []): array
    {
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

/**
 * 微信假网关：实现分账能力接口（含可选方法），记录原生分账方法调用
 */
class WechatFakeGateway extends FakeGateway implements ProfitSharingCapableInterface
{
    public static function getName(): string { return 'wechat'; }

    public function createProfitSharing(array $params): array
    {
        $this->calls[] = ['method' => 'createProfitSharing', 'data' => $params];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $this->calls[] = [
            'method' => 'queryProfitSharing',
            'data' => ['out_order_no' => $outOrderNo, 'transaction_id' => $transactionId],
        ];
        return ['status' => 'SUCCESS'];
    }

    public function returnProfitSharing(array $params): array
    {
        $this->calls[] = ['method' => 'returnProfitSharing', 'data' => $params];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $this->calls[] = ['method' => 'queryProfitSharingReturn', 'data' => ['out_return_no' => $outReturnNo]];
        return ['status' => 'SUCCESS'];
    }

    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $this->calls[] = ['method' => 'unfreezeProfitSharing', 'data' => ['transaction_id' => $transactionId, 'out_order_no' => $outOrderNo]];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharingConfig(string $outOrderNo, ?string $transactionId = null): array
    {
        $this->calls[] = ['method' => 'queryProfitSharingConfig', 'data' => ['out_order_no' => $outOrderNo, 'transaction_id' => $transactionId]];
        return ['status' => 'SUCCESS'];
    }

    public function addProfitSharingReceiver(array $receiver): array
    {
        $this->calls[] = ['method' => 'addProfitSharingReceiver', 'data' => $receiver];
        return ['status' => 'SUCCESS'];
    }

    public function removeProfitSharingReceiver(array $receiver): array
    {
        $this->calls[] = ['method' => 'removeProfitSharingReceiver', 'data' => $receiver];
        return ['status' => 'SUCCESS'];
    }
}

/**
 * 支付宝假网关：实现分账能力接口（不含可选方法 queryProfitSharingConfig）
 */
class AlipayFakeGateway extends FakeGateway implements ProfitSharingCapableInterface
{
    public static function getName(): string { return 'alipay'; }

    public function createProfitSharing(array $params): array
    {
        $this->calls[] = ['method' => 'createProfitSharing', 'data' => $params];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $this->calls[] = [
            'method' => 'queryProfitSharing',
            'data' => ['out_order_no' => $outOrderNo, 'transaction_id' => $transactionId],
        ];
        return ['status' => 'SUCCESS'];
    }

    public function returnProfitSharing(array $params): array
    {
        $this->calls[] = ['method' => 'returnProfitSharing', 'data' => $params];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $this->calls[] = ['method' => 'queryProfitSharingReturn', 'data' => ['out_return_no' => $outReturnNo]];
        return ['status' => 'SUCCESS'];
    }

    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $this->calls[] = ['method' => 'unfreezeProfitSharing', 'data' => ['transaction_id' => $transactionId, 'out_order_no' => $outOrderNo]];
        return ['status' => 'SUCCESS'];
    }

    public function addProfitSharingReceiver(array $receiver): array
    {
        $this->calls[] = ['method' => 'addProfitSharingReceiver', 'data' => $receiver];
        return ['status' => 'SUCCESS'];
    }

    public function removeProfitSharingReceiver(array $receiver): array
    {
        $this->calls[] = ['method' => 'removeProfitSharingReceiver', 'data' => $receiver];
        return ['status' => 'SUCCESS'];
    }
}

/**
 * Stripe 假网关：实现分账能力接口，但缺少可选方法（add/remove/config）
 */
class StripeFakeGateway extends FakeGateway implements ProfitSharingCapableInterface
{
    public static function getName(): string { return 'stripe'; }

    public function createProfitSharing(array $params): array
    {
        $this->calls[] = ['method' => 'createProfitSharing', 'data' => $params];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $this->calls[] = [
            'method' => 'queryProfitSharing',
            'data' => ['out_order_no' => $outOrderNo, 'transaction_id' => $transactionId],
        ];
        return ['status' => 'SUCCESS'];
    }

    public function returnProfitSharing(array $params): array
    {
        $this->calls[] = ['method' => 'returnProfitSharing', 'data' => $params];
        return ['status' => 'SUCCESS'];
    }

    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $this->calls[] = ['method' => 'queryProfitSharingReturn', 'data' => ['out_return_no' => $outReturnNo]];
        return ['status' => 'SUCCESS'];
    }

    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $this->calls[] = ['method' => 'unfreezeProfitSharing', 'data' => ['transaction_id' => $transactionId, 'out_order_no' => $outOrderNo]];
        return ['status' => 'SUCCESS'];
    }
}

/**
 * 不支持分账的网关（未实现 ProfitSharingCapableInterface）
 */
class UnsupportedFakeGateway extends FakeGateway
{
    public static function getName(): string { return 'paypal'; }
}

class ProfitSharingPluginTest extends TestCase
{
    /**
     * 微信分账：插件转发到网关原生 createProfitSharing，Receiver DTO 原样透传
     */
    public function testWechatCreateForwardsToGatewayNativeMethod(): void
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

        $this->assertSame('createProfitSharing', $gateway->calls[0]['method']);
        $this->assertInstanceOf(Receiver::class, $gateway->calls[0]['data']['receivers'][0]);
        $this->assertSame(100, $gateway->calls[0]['data']['receivers'][0]->amount->getMinorAmount());
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
            'receivers' => [['type' => 'MERCHANT_ID', 'account' => '123', 'amount' => 200]],
        ]);

        $this->assertSame('createProfitSharing', $gateway->calls[0]['method']);
        $this->assertSame(200, $gateway->calls[0]['data']['receivers'][0]['amount']);
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
     * 微信分账配置查询：转发到 queryProfitSharingConfig
     */
    public function testWechatQueryConfigForwards(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->queryConfig('SHARE_1', 'T100');

        $this->assertSame('queryProfitSharingConfig', $gateway->calls[0]['method']);
        $this->assertSame('SHARE_1', $gateway->calls[0]['data']['out_order_no']);
        $this->assertSame('T100', $gateway->calls[0]['data']['transaction_id']);
    }

    /**
     * 分账查询：transaction_id 透传至网关（微信必填，其余平台忽略）
     */
    public function testQueryForwardsTransactionId(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->query('SHARE_1', 'T100');

        $this->assertSame('queryProfitSharing', $gateway->calls[0]['method']);
        $this->assertSame('SHARE_1', $gateway->calls[0]['data']['out_order_no']);
        $this->assertSame('T100', $gateway->calls[0]['data']['transaction_id']);
    }

    /**
     * 分账查询：省略 transaction_id 时不透传（微信网关内部据此决定是否携带字段）
     */
    public function testQueryOmitsTransactionId(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->query('SHARE_1');

        $this->assertSame('SHARE_1', $gateway->calls[0]['data']['out_order_no']);
        $this->assertNull($gateway->calls[0]['data']['transaction_id']);
    }

    /**
     * 不支持分账配置查询的网关（网关未实现该可选方法）抛「无此方法」
     */
    public function testQueryConfigUnsupportedThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');
        (new ProfitSharingPlugin(new AlipayFakeGateway()))->queryConfig('SHARE_1');
    }

    /**
     * 解冻支持显式 out_order_no
     */
    public function testWechatUnfreezeForwardsWithProvidedOrderNo(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->unfreeze('T100', 'FINISH_9');

        $this->assertSame('unfreezeProfitSharing', $gateway->calls[0]['method']);
        $this->assertSame('FINISH_9', $gateway->calls[0]['data']['out_order_no']);
    }

    /**
     * 添加接收方（微信）转发到 addProfitSharingReceiver
     */
    public function testWechatAddReceiverForwards(): void
    {
        $gateway = new WechatFakeGateway();
        $plugin = new ProfitSharingPlugin($gateway);

        $plugin->addReceiver(['type' => 'MERCHANT_ID', 'account' => '123', 'name' => '供应商']);

        $this->assertSame('addProfitSharingReceiver', $gateway->calls[0]['method']);
    }

    /**
     * Stripe 删除接收方：网关未实现该可选方法，抛「无此方法」
     */
    public function testStripeRemoveReceiverUnsupportedThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');
        (new ProfitSharingPlugin(new StripeFakeGateway()))->removeReceiver(['type' => 'x', 'account' => '1']);
    }

    /**
     * 不支持分账的网关在 create 时抛「未实现分账能力接口」
     */
    public function testCreateUnsupportedGatewayThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('未实现分账能力接口');
        (new ProfitSharingPlugin(new UnsupportedFakeGateway()))->create([
            'transaction_id' => 'T',
            'out_order_no' => 'O',
            'receivers' => [['type' => 'x', 'account' => '1', 'amount' => 1]],
        ]);
    }

    /**
     * 支付宝分账转发到网关原生 createProfitSharing
     */
    public function testAlipayCreateForwardsToGatewayNativeMethod(): void
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

        $this->assertSame('createProfitSharing', $gateway->calls[0]['method']);
    }

    /**
     * Stripe 分账转发到网关原生 createProfitSharing
     */
    public function testStripeCreateForwardsToGatewayNativeMethod(): void
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

        $this->assertSame('createProfitSharing', $gateway->calls[0]['method']);
    }
}
