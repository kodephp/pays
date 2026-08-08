<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Facade;

use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Core\PayException;
use Kode\Pays\Facade\Pay;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 统一入口（Pay 门面 call / gateway / extend / verify）单元测试
 */
class PayDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GatewayFactory::register('fakechan', FakeGateway::class);
        Pay::registerConfig('fakechan', []);
    }

    protected function tearDown(): void
    {
        Pay::clearCache();
        GatewayFactory::unregister('fakechan');
        GatewayFactory::unregister('samplegw');
        GatewayManifest::unregister('samplegw');

        parent::tearDown();
    }

    /**
     * 统一入口 call 可调用任意已接入平台的标准方法
     */
    public function testCallDispatchesStandardMethod(): void
    {
        $result = Pay::call('fakechan', 'createOrder', ['out_trade_no' => 'T1']);

        $this->assertArrayHasKey('code_url', $result);
        $this->assertStringContainsString('T1', $result['code_url']);
    }

    /**
     * 语义化快捷方法 createOrder 等效于 call
     */
    public function testCreateOrderHelper(): void
    {
        $result = Pay::createOrder('fakechan', ['out_trade_no' => 'T2']);

        $this->assertStringContainsString('T2', $result['code_url']);
    }

    /**
     * 统一入口可调用各平台「特色方法」（接口之外的方法）
     */
    public function testCallReachesPlatformSpecificMethod(): void
    {
        $name = Pay::call('fakechan', 'getName');

        $this->assertSame('fakechan', $name);
    }

    /**
     * gateway() 返回强类型实例，可继续调用特色方法
     */
    public function testGatewayReturnsInstance(): void
    {
        $gateway = Pay::gateway('fakechan');

        $this->assertInstanceOf(FakeGateway::class, $gateway);
        $this->assertSame('fakechan', $gateway->getName());
    }

    /**
     * 调用不存在的方法应抛出参数异常
     */
    public function testCallUnknownMethodThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('不支持方法');

        Pay::call('fakechan', 'noSuchMethod');
    }

    /**
     * 安全入口 verify：先过 NotifyGuard，再走平台级验签
     */
    public function testVerifyPassesWithSign(): void
    {
        $this->assertTrue(Pay::verify('fakechan', ['sign' => 'x']));
    }

    /**
     * 安全入口 verify：缺少签名字段即拦截
     */
    public function testVerifyBlocksMissingSign(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少签名字段');

        Pay::verify('fakechan', []);
    }

    /**
     * 一次登记新平台后，统一入口与清单查询均可用
     */
    public function testExtendRegistersPlatform(): void
    {
        Pay::extend(
            'samplegw',
            [
                'label' => 'Sample Gateway',
                'region' => GatewayManifest::REGION_DOMESTIC,
                'signature' => GatewayManifest::SIGN_MD5,
                'capabilities' => [GatewayManifest::CAP_PROFIT_SHARING => true],
            ],
            FakeGateway::class,
        );

        $this->assertTrue(Pay::has('samplegw'));
        $this->assertTrue(GatewayManifest::supports('samplegw', GatewayManifest::CAP_PROFIT_SHARING));
        $this->assertSame('Sample Gateway', GatewayManifest::get('samplegw')['label']);

        // 统一入口可立即调用（需先登记配置）
        Pay::registerConfig('samplegw', []);
        $result = Pay::call('samplegw', 'createOrder', ['out_trade_no' => 'S1']);
        $this->assertStringContainsString('S1', $result['code_url']);
    }
}
