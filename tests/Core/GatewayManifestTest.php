<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 平台统一清单（GatewayManifest）单元测试
 */
class GatewayManifestTest extends TestCase
{
    /**
     * 内置平台自动登记后，可查询到常见平台标识
     */
    public function testBuiltinsRegistered(): void
    {
        $names = GatewayManifest::names();

        $this->assertContains('wechat', $names);
        $this->assertContains('alipay', $names);
        $this->assertContains('unionpay', $names);
        $this->assertContains('paypal', $names);
        $this->assertContains('stripe', $names);
    }

    /**
     * 域名通过网关类常量反射回退解析（内置平台未显式声明 base_url 时）
     */
    public function testBaseUrlResolvesFromGatewayConstants(): void
    {
        $reflection = new \ReflectionClass(WechatPayGateway::class);
        $prod = (string) $reflection->getConstant('PROD_BASE_URL');
        $sandbox = (string) $reflection->getConstant('SANDBOX_BASE_URL');

        $this->assertSame($prod, GatewayManifest::baseUrl('wechat'));
        $this->assertSame($sandbox, GatewayManifest::baseUrl('wechat', true));
    }

    /**
     * baseUrl 反射结果应回写缓存，避免重复反射
     */
    public function testBaseUrlMemoizesReflectionResult(): void
    {
        // 预热，清除可能已缓存的值
        $refClass = new \ReflectionClass(GatewayManifest::class);
        $entries = $refClass->getStaticPropertyValue('entries');
        unset($entries['wechat']['base_url'], $entries['wechat']['sandbox_url']);
        $refClass->setStaticPropertyValue('entries', $entries);

        $first = GatewayManifest::baseUrl('wechat');
        $this->assertNotEmpty($first);

        // 再次访问应直接命中缓存，值一致
        $this->assertSame($first, GatewayManifest::baseUrl('wechat'));

        $refreshed = $refClass->getStaticPropertyValue('entries');
        $this->assertSame($first, $refreshed['wechat']['base_url'] ?? null, '反射结果应已回写缓存');
    }

    /**
     * 显式声明的域名优先于反射回退
     */
    public function testExplicitBaseUrlWins(): void
    {
        GatewayManifest::register('explicitgw', [
            'label' => 'Explicit',
            'base_url' => 'https://api.example.com/',
            'sandbox_url' => 'https://sandbox.example.com/',
            'gateway_class' => WechatPayGateway::class,
        ]);

        $this->assertSame('https://api.example.com/', GatewayManifest::baseUrl('explicitgw'));
        $this->assertSame('https://sandbox.example.com/', GatewayManifest::baseUrl('explicitgw', true));

        GatewayManifest::unregister('explicitgw');
    }

    /**
     * 能力开关：内置平台按元数据声明，标准方法默认开启
     */
    public function testCapabilitiesAndSupports(): void
    {
        $this->assertTrue(GatewayManifest::supports('wechat', GatewayManifest::CAP_PROFIT_SHARING));
        $this->assertTrue(GatewayManifest::supports('wechat', GatewayManifest::CAP_CREATE_ORDER));
        // 增值能力默认关闭：快手未声明订阅
        $this->assertFalse(GatewayManifest::supports('kuaishou', GatewayManifest::CAP_SUBSCRIPTION));

        // 委托代扣 / 周期扣款：微信 V2、支付宝、Square、Adyen 均已支持
        $this->assertTrue(GatewayManifest::supports('wechat', GatewayManifest::CAP_SUBSCRIPTION));
        $this->assertTrue(GatewayManifest::supports('alipay', GatewayManifest::CAP_SUBSCRIPTION));
        $this->assertTrue(GatewayManifest::supports('square', GatewayManifest::CAP_SUBSCRIPTION));
        $this->assertTrue(GatewayManifest::supports('adyen', GatewayManifest::CAP_SUBSCRIPTION));
        $this->assertTrue(GatewayManifest::supports('stripe', GatewayManifest::CAP_SUBSCRIPTION));
        $this->assertTrue(GatewayManifest::supports('alipay', GatewayManifest::CAP_TRANSFER));

        // 微信 V3 支持余额查询，微信 V2 不支持
        $this->assertTrue(GatewayManifest::supports('wechat_v3', GatewayManifest::CAP_BALANCE));
        $this->assertFalse(GatewayManifest::supports('wechat', GatewayManifest::CAP_BALANCE));

        $caps = GatewayManifest::capabilities('wechat');
        $this->assertArrayHasKey(GatewayManifest::CAP_QR, $caps);
    }

    /**
     * 区域与签名方案查询
     */
    public function testRegionAndSignature(): void
    {
        $this->assertSame(GatewayManifest::REGION_DOMESTIC, GatewayManifest::region('wechat'));
        $this->assertSame(GatewayManifest::SIGN_MD5, GatewayManifest::signatureScheme('wechat'));

        $this->assertSame(GatewayManifest::REGION_INTERNATIONAL, GatewayManifest::region('paypal'));
        $this->assertSame(GatewayManifest::SIGN_NONE, GatewayManifest::signatureScheme('paypal'));
    }

    /**
     * 查询未注册平台抛出异常
     */
    public function testGetUnknownThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('未注册的平台');

        GatewayManifest::get('no-such-gateway');
    }
}
