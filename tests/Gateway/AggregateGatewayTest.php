<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Aggregate\AggregateGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 聚合支付网关单元测试
 */
class AggregateGatewayTest extends TestCase
{
    /**
     * 注册测试用 stub 网关，供 AggregateGateway 路由使用
     */
    protected function setUp(): void
    {
        parent::setUp();

        GatewayFactory::register('stub_success', StubSuccessGateway::class);
        GatewayFactory::register('stub_failure', StubFailureGateway::class);
    }

    /**
     * 清理 stub 网关注册
     */
    protected function tearDown(): void
    {
        GatewayFactory::unregister('stub_success');
        GatewayFactory::unregister('stub_failure');

        parent::tearDown();
    }

    /**
     * 测试按优先级路由：高优先级渠道优先被使用
     */
    public function testCreateOrderRoutesByPriority(): void
    {
        $agg = new AggregateGateway([
            'channels' => [
                [
                    'gateway' => 'stub_success',
                    'priority' => 2,
                    'config' => ['tag' => 'low-priority'],
                ],
                [
                    'gateway' => 'stub_success',
                    'priority' => 1,
                    'config' => ['tag' => 'high-priority'],
                ],
            ],
        ]);

        $result = $agg->createOrder(['out_trade_no' => 'O1']);

        $this->assertSame('stub_success', $result['_channel']);
        $this->assertSame('high-priority', $result['_tag']);
    }

    /**
     * 测试失败降级：高优先级渠道失败时切换到低优先级渠道
     */
    public function testCreateOrderFallbackOnFailure(): void
    {
        $agg = new AggregateGateway([
            'channels' => [
                [
                    'gateway' => 'stub_failure',
                    'priority' => 1,
                    'config' => [],
                ],
                [
                    'gateway' => 'stub_success',
                    'priority' => 2,
                    'config' => ['tag' => 'fallback'],
                ],
            ],
        ]);

        $result = $agg->createOrder(['out_trade_no' => 'O1']);

        $this->assertSame('stub_success', $result['_channel']);
        $this->assertSame('fallback', $result['_tag']);
    }

    /**
     * 测试所有渠道均失败时抛聚合异常
     */
    public function testAllChannelsFailedThrowsException(): void
    {
        $agg = new AggregateGateway([
            'channels' => [
                ['gateway' => 'stub_failure', 'priority' => 1, 'config' => []],
                ['gateway' => 'stub_failure', 'priority' => 2, 'config' => []],
            ],
        ]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('聚合支付所有渠道均失败');

        $agg->createOrder(['out_trade_no' => 'O1']);
    }

    /**
     * 测试空渠道列表抛异常
     */
    public function testEmptyChannelsThrowsException(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('聚合支付必须配置 channels');

        new AggregateGateway(['channels' => []]);
    }

    /**
     * 测试渠道配置缺 gateway 键抛异常
     */
    public function testMissingGatewayKeyThrowsException(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('聚合支付渠道必须配置 gateway 标识');

        new AggregateGateway([
            'channels' => [
                ['priority' => 1, 'config' => []],
            ],
        ]);
    }

    /**
     * 测试 channels 键缺失抛异常
     */
    public function testMissingChannelsKeyThrowsException(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('聚合支付必须配置 channels');

        new AggregateGateway([]);
    }

    /**
     * 测试获取网关标识
     */
    public function testGetName(): void
    {
        $this->assertSame('aggregate', AggregateGateway::getName());
    }

    /**
     * 测试渠道按 priority 排序：通过反射验证内部 $channels 顺序
     */
    public function testChannelsSortedByPriority(): void
    {
        $agg = new AggregateGateway([
            'channels' => [
                ['gateway' => 'stub_success', 'priority' => 5, 'config' => []],
                ['gateway' => 'stub_success', 'priority' => 1, 'config' => []],
                ['gateway' => 'stub_success', 'priority' => 3, 'config' => []],
            ],
        ]);

        $ref = new \ReflectionClass($agg);
        $prop = $ref->getProperty('channels');
        $prop->setAccessible(true);
        $channels = $prop->getValue($agg);

        $priorities = array_map(static fn (array $c) => $c['priority'], $channels);

        $this->assertSame([1, 3, 5], $priorities);
    }
}

/**
 * 测试用 stub 网关：总是成功
 *
 * 用于 AggregateGateway 的路由测试，避免真实 HTTP 请求。
 * 待 AggregateGateway src bug 修复后启用。
 */
class StubSuccessGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        // 跳过父类的必填字段校验
    }

    protected function getBaseUrl(): string
    {
        return 'https://stub.example.com/';
    }

    protected function parseResponse(string $response): array
    {
        return ['status' => 'ok'];
    }

    public function createOrder(array $params): array
    {
        return array_merge(['status' => 'success'], $params, [
            '_tag' => $this->getConfig('tag', ''),
        ]);
    }

    public function queryOrder(string $orderId): array
    {
        return ['status' => 'success'];
    }

    public function refund(array $params): array
    {
        return ['status' => 'success'];
    }

    public function queryRefund(string $refundId): array
    {
        return ['status' => 'success'];
    }

    public function verifyNotify(array $data): bool
    {
        return true;
    }

    public function closeOrder(string $orderId): array
    {
        return ['status' => 'success'];
    }

    public static function getName(): string
    {
        return 'stub_success';
    }
}

/**
 * 测试用 stub 网关：总是抛 PayException
 *
 * 用于测试 AggregateGateway 的失败降级逻辑。
 * 待 AggregateGateway src bug 修复后启用。
 */
class StubFailureGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        // 跳过父类的必填字段校验
    }

    protected function getBaseUrl(): string
    {
        return 'https://stub.example.com/';
    }

    protected function parseResponse(string $response): array
    {
        return [];
    }

    public function createOrder(array $params): array
    {
        throw PayException::gatewayError('stub failure gateway');
    }

    public function queryOrder(string $orderId): array
    {
        throw PayException::gatewayError('stub failure gateway');
    }

    public function refund(array $params): array
    {
        throw PayException::gatewayError('stub failure gateway');
    }

    public function queryRefund(string $refundId): array
    {
        throw PayException::gatewayError('stub failure gateway');
    }

    public function verifyNotify(array $data): bool
    {
        return false;
    }

    public function closeOrder(string $orderId): array
    {
        throw PayException::gatewayError('stub failure gateway');
    }

    public static function getName(): string
    {
        return 'stub_failure';
    }
}
