<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Config\WechatConfig;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 网关工厂单元测试
 */
class GatewayFactoryTest extends TestCase
{
    /**
     * 测试创建已知网关
     */
    public function testCreateKnownGateway(): void
    {
        $gateway = GatewayFactory::create('wechat', [
            'app_id' => 'wx123456',
            'mch_id' => '123456',
            'api_key' => 'test-key',
        ]);

        $this->assertInstanceOf(WechatPayGateway::class, $gateway);
    }

    /**
     * 测试创建未知网关抛出异常
     */
    public function testCreateUnknownGatewayThrowsException(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('不支持的支付网关');

        GatewayFactory::create('unknown', []);
    }

    /**
     * 测试使用配置 DTO 创建网关
     */
    public function testCreateWithConfigDto(): void
    {
        $config = new WechatConfig(
            appId: 'wx123456',
            mchId: '123456',
            apiKey: 'test-key',
        );

        $gateway = GatewayFactory::createWithConfig('wechat', $config);

        $this->assertInstanceOf(WechatPayGateway::class, $gateway);
    }

    /**
     * 测试自动配置转换
     */
    public function testCreateAutoConfig(): void
    {
        $gateway = GatewayFactory::createAutoConfig('wechat', [
            'app_id' => 'wx123456',
            'mch_id' => '123456',
            'api_key' => 'test-key',
        ]);

        $this->assertInstanceOf(WechatPayGateway::class, $gateway);
    }

    /**
     * 测试注册自定义网关
     */
    public function testRegisterCustomGateway(): void
    {
        GatewayFactory::register('custom', WechatPayGateway::class);

        $this->assertTrue(GatewayFactory::has('custom'));

        $gateway = GatewayFactory::create('custom', [
            'app_id' => 'wx123456',
            'mch_id' => '123456',
            'api_key' => 'test-key',
        ]);

        $this->assertInstanceOf(WechatPayGateway::class, $gateway);

        // 清理
        GatewayFactory::unregister('custom');
    }

    /**
     * 测试获取所有网关标识
     */
    public function testGetNames(): void
    {
        $names = GatewayFactory::getNames();

        $this->assertContains('wechat', $names);
        $this->assertContains('alipay', $names);
        $this->assertContains('unionpay', $names);
        $this->assertContains('douyin', $names);
        $this->assertContains('paypal', $names);
        $this->assertContains('aggregate', $names);
    }

    /**
     * 测试获取配置 DTO 类名
     */
    public function testGetConfigClass(): void
    {
        $this->assertSame(WechatConfig::class, GatewayFactory::getConfigClass('wechat'));
        $this->assertNull(GatewayFactory::getConfigClass('nonexistent'));
    }
}
