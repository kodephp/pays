<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\UnifiedQrRouter;
use Kode\Pays\Tests\TestCase;

/**
 * 统一收款码路由器单元测试
 *
 * 覆盖入口创建、路由下单、状态流转、幂等保护、错误边界等场景。
 */
class UnifiedQrRouterTest extends TestCase
{
    /**
     * 可测试的路由器子类
     *
     * 重写 createGateway 注入 mock 网关，避免依赖真实网关与 HttpClient。
     */
    private function createRouter(array $gateways = []): UnifiedQrRouter
    {
        return new class ($gateways) extends UnifiedQrRouter {
            /**
             * @param array<string, GatewayInterface> $gateways 通道标识 → mock 网关
             */
            public function __construct(array $gateways)
            {
                parent::__construct(
                    gatewayConfigs: array_fill_keys(array_keys($gateways), []),
                    httpClient: null,
                    entryUrlPrefix: 'https://test.kodephp.com/r/',
                );
                $this->gateways = $gateways;
            }

            /** @var array<string, GatewayInterface> */
            protected array $gateways = [];

            protected function createGateway(string $channel): GatewayInterface
            {
                if (!isset($this->gateways[$channel])) {
                    throw PayException::configError("通道 {$channel} 未配置 mock");
                }

                return $this->gateways[$channel];
            }
        };
    }

    /**
     * 构造一个 mock 网关
     *
     * @param array<string, mixed>|null $createResult createOrder 返回值（null 抛异常）
     * @param bool $verifyResult verifyNotify 返回值
     */
    private function createMockGateway(?array $createResult = null, bool $verifyResult = true): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);

        if ($createResult === null) {
            $gateway->method('createOrder')
                ->willThrowException(PayException::gatewayError('下单失败'));
        } else {
            $gateway->method('createOrder')->willReturn($createResult);
        }

        $gateway->method('verifyNotify')->willReturn($verifyResult);
        $gateway->method('queryOrder')->willReturn(['trade_state' => 'SUCCESS']);

        return $gateway;
    }

    /**
     * 测试创建入口：返回 router_id、entry_url、qr_content 等
     */
    public function testCreateEntry(): void
    {
        $router = $this->createRouter([
            'wechat' => $this->createMockGateway(),
        ]);

        $entry = $router->createEntry(['wechat'], 100, '商品付款');

        $this->assertNotEmpty($entry['router_id']);
        $this->assertSame(100, $entry['amount']);
        $this->assertSame(['wechat'], $entry['channels']);
        $this->assertSame($entry['entry_url'], $entry['qr_content']);
        $this->assertStringStartsWith('https://test.kodephp.com/r/', $entry['entry_url']);
        $this->assertStringContainsString($entry['router_id'], $entry['entry_url']);
    }

    /**
     * 测试创建入口：channels 为空抛异常
     */
    public function testCreateEntryWithEmptyChannelsThrows(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('channels 不能为空');

        $router->createEntry([], 100, '商品');
    }

    /**
     * 测试创建入口：金额非正抛异常
     */
    public function testCreateEntryWithZeroAmountThrows(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('amount 必须大于 0');

        $router->createEntry(['wechat'], 0, '商品');
    }

    /**
     * 测试创建入口：通道未配置抛异常
     */
    public function testCreateEntryWithUnknownChannelThrows(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('通道 alipay 未在 gatewayConfigs 中配置');

        $router->createEntry(['alipay'], 100, '商品');
    }

    /**
     * 测试路由下单成功：返回动态订单码与微信 code_url
     */
    public function testRouteSuccess(): void
    {
        $gateway = $this->createMockGateway(['code_url' => 'weixin://wxpay/bizpayurl?pr=xxx']);

        $router = $this->createRouter(['wechat' => $gateway]);

        $entry = $router->createEntry(['wechat'], 100, '商品');
        $order = $router->route($entry['router_id'], 'wechat');

        $this->assertSame('wechat', $order['channel']);
        $this->assertSame(100, $order['amount']);
        $this->assertStringContainsString('weixin://wxpay/bizpayurl', $order['pay_url']);
        $this->assertSame($order['pay_url'], $order['code_url']);
        $this->assertNotEmpty($order['out_trade_no']);
        $this->assertStringStartsWith('UO', $order['out_trade_no']);
    }

    /**
     * 测试路由下单：兼容支付宝 qr_code 字段
     */
    public function testRouteSupportsAlipayQrCode(): void
    {
        $gateway = $this->createMockGateway(['qr_code' => 'https://qr.alipay.com/bax00000']);

        $router = $this->createRouter(['alipay' => $gateway]);

        $entry = $router->createEntry(['alipay'], 200, '商品');
        $order = $router->route($entry['router_id'], 'alipay');

        $this->assertSame('https://qr.alipay.com/bax00000', $order['pay_url']);
    }

    /**
     * 测试路由下单：兼容 Stripe payment_link 字段
     */
    public function testRouteSupportsStripePaymentLink(): void
    {
        $gateway = $this->createMockGateway(['payment_link' => 'https://stripe.com/pl_123']);

        $router = $this->createRouter(['stripe' => $gateway]);

        $entry = $router->createEntry(['stripe'], 500, '商品');
        $order = $router->route($entry['router_id'], 'stripe');

        $this->assertSame('https://stripe.com/pl_123', $order['pay_url']);
    }

    /**
     * 测试路由下单：入口不存在抛异常
     */
    public function testRouteWithUnknownRouterIdThrows(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('统一收款入口不存在');

        $router->route('UNKNOWN_ROUTER', 'wechat');
    }

    /**
     * 测试路由下单：通道不在允许列表抛异常
     */
    public function testRouteWithDisallowedChannelThrows(): void
    {
        $router = $this->createRouter([
            'wechat' => $this->createMockGateway(),
            'alipay' => $this->createMockGateway(),
        ]);

        $entry = $router->createEntry(['wechat'], 100, '商品');

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('通道 alipay 不在该入口允许列表中');

        $router->route($entry['router_id'], 'alipay');
    }

    /**
     * 测试路由下单：已下单的入口再次调用返回相同订单（幂等）
     */
    public function testRouteIsIdempotentWhenOrdered(): void
    {
        $gateway = $this->createMockGateway(['code_url' => 'weixin://wxpay/bizpayurl?pr=xxx']);

        $router = $this->createRouter(['wechat' => $gateway]);

        $entry = $router->createEntry(['wechat'], 100, '商品');

        $first = $router->route($entry['router_id'], 'wechat');
        $second = $router->route($entry['router_id'], 'wechat');

        // 第二次直接返回缓存，不会再调用 createOrder
        $this->assertSame($first['out_trade_no'], $second['out_trade_no']);
        $this->assertSame($first['pay_url'], $second['pay_url']);
        $this->assertSame($first['channel'], $second['channel']);
        $this->assertSame($first['amount'], $second['amount']);
    }

    /**
     * 测试路由下单：已支付的入口再次调用抛异常
     */
    public function testRouteThrowsWhenAlreadyPaid(): void
    {
        $gateway = $this->createMockGateway(['code_url' => 'weixin://wxpay/bizpayurl']);

        $router = $this->createRouter(['wechat' => $gateway]);

        $entry = $router->createEntry(['wechat'], 100, '商品');
        $router->route($entry['router_id'], 'wechat');
        $router->markPaid($entry['router_id'], ['transaction_id' => 'T1']);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('入口已支付完成，无法重复下单');

        $router->route($entry['router_id'], 'wechat');
    }

    /**
     * 测试 markPaid：状态变更并写入 paid_at 与 payment_data
     */
    public function testMarkPaid(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $entry = $router->createEntry(['wechat'], 100, '商品');

        $ok = $router->markPaid($entry['router_id'], ['transaction_id' => 'T1']);

        $this->assertTrue($ok);

        $status = $router->getStatus($entry['router_id']);
        $this->assertSame(UnifiedQrRouter::STATUS_PAID, $status['status']);
        $this->assertNotNull($status['paid_at']);
        $this->assertSame(['transaction_id' => 'T1'], $status['payment_data']);
    }

    /**
     * 测试 markPaid：入口不存在返回 false
     */
    public function testMarkPaidReturnsFalseWhenNotFound(): void
    {
        $router = $this->createRouter([]);

        $this->assertFalse($router->markPaid('UNKNOWN', []));
    }

    /**
     * 测试 markClosed：状态变为 closed
     */
    public function testMarkClosed(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $entry = $router->createEntry(['wechat'], 100, '商品');

        $this->assertTrue($router->markClosed($entry['router_id']));

        $status = $router->getStatus($entry['router_id']);
        $this->assertSame(UnifiedQrRouter::STATUS_CLOSED, $status['status']);
    }

    /**
     * 测试 getPendingEntries：排除已支付与已关闭的入口
     */
    public function testGetPendingEntries(): void
    {
        $router = $this->createRouter([
            'wechat' => $this->createMockGateway(['code_url' => 'w']),
            'alipay' => $this->createMockGateway(['qr_code' => 'a']),
        ]);

        $e1 = $router->createEntry(['wechat'], 100, 'A');
        $e2 = $router->createEntry(['alipay'], 200, 'B');
        $e3 = $router->createEntry(['wechat'], 300, 'C');

        $router->markPaid($e1['router_id']);
        $router->markClosed($e2['router_id']);

        $pending = $router->getPendingEntries();

        $this->assertCount(1, $pending);
        $this->assertArrayHasKey($e3['router_id'], $pending);
    }

    /**
     * 测试 getEntry：不存在的入口返回 null
     */
    public function testGetEntryReturnsNullWhenNotFound(): void
    {
        $router = $this->createRouter([]);

        $this->assertNull($router->getEntry('UNKNOWN'));
    }

    /**
     * 测试 createEntry：attach 字段原样保存
     */
    public function testCreateEntryStoresAttach(): void
    {
        $router = $this->createRouter(['wechat' => $this->createMockGateway()]);

        $attach = ['user_id' => 123, 'shop_id' => 'S1'];
        $entry = $router->createEntry(['wechat'], 100, '商品', $attach);

        $status = $router->getStatus($entry['router_id']);
        $this->assertSame($attach, $status['attach']);
    }

    /**
     * 测试 createEntry：支持多个通道
     */
    public function testCreateEntryWithMultipleChannels(): void
    {
        $router = $this->createRouter([
            'wechat' => $this->createMockGateway(),
            'alipay' => $this->createMockGateway(),
        ]);

        $entry = $router->createEntry(['wechat', 'alipay'], 100, '商品');

        $this->assertSame(['wechat', 'alipay'], $entry['channels']);
    }
}
