<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Config;

use Kode\Pays\Config\AlipayConfig;
use Kode\Pays\Config\DouyinConfig;
use Kode\Pays\Config\PaypalConfig;
use Kode\Pays\Config\UnionPayConfig;
use Kode\Pays\Config\WechatConfig;
use Kode\Pays\Config\WechatV3Config;
use Kode\Pays\Gateway\Stripe\StripeConfig;
use Kode\Pays\Tests\TestCase;

/**
 * 配置 DTO 单元测试
 *
 * 验证各网关配置对象的 fromArray() 工厂方法、字段映射、getGateway() 标识
 * 以及缺省字段使用默认值的行为。
 */
class ConfigDtoTest extends TestCase
{
    /**
     * 测试微信支付配置：fromArray 与字段映射
     */
    public function testWechatConfigFromArray(): void
    {
        $config = WechatConfig::fromArray([
            'app_id' => 'wx123',
            'mch_id' => 'm1',
            'api_key' => 'k1',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(WechatConfig::class, $config);
        $this->assertSame('wx123', $config->appId);
        $this->assertSame('m1', $config->mchId);
        $this->assertSame('k1', $config->apiKey);
        $this->assertTrue($config->sandbox);
        // 缺省字段使用默认值
        $this->assertNull($config->apiV3Key);
        $this->assertNull($config->certPath);
        $this->assertNull($config->keyPath);
        $this->assertNull($config->platformCertPath);
    }

    /**
     * 测试微信支付配置：getGateway 标识
     */
    public function testWechatConfigGetGateway(): void
    {
        $config = WechatConfig::fromArray([]);

        $this->assertSame('wechat', $config->getGateway());
    }

    /**
     * 测试微信支付配置：空数组 fromArray 不报错
     */
    public function testWechatConfigEmptyArray(): void
    {
        $config = WechatConfig::fromArray([]);

        $this->assertInstanceOf(WechatConfig::class, $config);
        $this->assertSame('', $config->appId);
        $this->assertSame('', $config->mchId);
        $this->assertSame('', $config->apiKey);
        $this->assertFalse($config->sandbox);
    }

    /**
     * 测试支付宝配置：fromArray 与字段映射
     */
    public function testAlipayConfigFromArray(): void
    {
        $config = AlipayConfig::fromArray([
            'app_id' => 'alipay123',
            'private_key' => 'priv',
            'public_key' => 'pub',
            'app_auth_token' => 'token',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(AlipayConfig::class, $config);
        $this->assertSame('alipay123', $config->appId);
        $this->assertSame('priv', $config->privateKey);
        $this->assertSame('pub', $config->publicKey);
        $this->assertSame('token', $config->appAuthToken);
        $this->assertTrue($config->sandbox);
    }

    /**
     * 测试支付宝配置：getGateway 标识
     */
    public function testAlipayConfigGetGateway(): void
    {
        $config = AlipayConfig::fromArray([]);

        $this->assertSame('alipay', $config->getGateway());
    }

    /**
     * 测试支付宝配置：空数组 fromArray 不报错
     */
    public function testAlipayConfigEmptyArray(): void
    {
        $config = AlipayConfig::fromArray([]);

        $this->assertInstanceOf(AlipayConfig::class, $config);
        $this->assertSame('', $config->appId);
        $this->assertNull($config->appAuthToken);
        $this->assertFalse($config->sandbox);
    }

    /**
     * 测试微信支付 V3 配置：fromArray 与字段映射
     */
    public function testWechatV3ConfigFromArray(): void
    {
        $config = WechatV3Config::fromArray([
            'mch_id' => 'm1',
            'serial_no' => 's1',
            'private_key' => 'pk',
            'api_key' => 'ak',
            'app_id' => 'wx123',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(WechatV3Config::class, $config);
        $this->assertSame('m1', $config->mchId);
        $this->assertSame('s1', $config->serialNo);
        $this->assertSame('pk', $config->privateKey);
        $this->assertSame('ak', $config->apiKey);
        $this->assertSame('wx123', $config->appId);
        $this->assertTrue($config->sandbox);
    }

    /**
     * 测试微信支付 V3 配置：getGateway 标识
     */
    public function testWechatV3ConfigGetGateway(): void
    {
        $config = WechatV3Config::fromArray([]);

        $this->assertSame('wechat_v3', $config->getGateway());
    }

    /**
     * 测试微信支付 V3 配置：空数组 fromArray 不报错
     */
    public function testWechatV3ConfigEmptyArray(): void
    {
        $config = WechatV3Config::fromArray([]);

        $this->assertInstanceOf(WechatV3Config::class, $config);
        $this->assertSame('', $config->mchId);
        $this->assertNull($config->appId);
        $this->assertFalse($config->sandbox);
    }

    /**
     * 测试抖音支付配置：fromArray 与字段映射
     */
    public function testDouyinConfigFromArray(): void
    {
        $config = DouyinConfig::fromArray([
            'app_id' => 'dy123',
            'merchant_id' => 'mid',
            'salt' => 'salt1',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(DouyinConfig::class, $config);
        $this->assertSame('dy123', $config->appId);
        $this->assertSame('mid', $config->merchantId);
        $this->assertSame('salt1', $config->salt);
        $this->assertTrue($config->sandbox);
    }

    /**
     * 测试抖音支付配置：getGateway 标识
     */
    public function testDouyinConfigGetGateway(): void
    {
        $config = DouyinConfig::fromArray([]);

        $this->assertSame('douyin', $config->getGateway());
    }

    /**
     * 测试抖音支付配置：空数组 fromArray 不报错
     */
    public function testDouyinConfigEmptyArray(): void
    {
        $config = DouyinConfig::fromArray([]);

        $this->assertInstanceOf(DouyinConfig::class, $config);
        $this->assertSame('', $config->appId);
        $this->assertFalse($config->sandbox);
    }

    /**
     * 测试 PayPal 配置：fromArray 与字段映射
     */
    public function testPaypalConfigFromArray(): void
    {
        $config = PaypalConfig::fromArray([
            'client_id' => 'cid',
            'client_secret' => 'cs',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(PaypalConfig::class, $config);
        $this->assertSame('cid', $config->clientId);
        $this->assertSame('cs', $config->clientSecret);
        $this->assertTrue($config->sandbox);
    }

    /**
     * 测试 PayPal 配置：getGateway 标识
     */
    public function testPaypalConfigGetGateway(): void
    {
        $config = PaypalConfig::fromArray([]);

        $this->assertSame('paypal', $config->getGateway());
    }

    /**
     * 测试 PayPal 配置：空数组 fromArray 不报错
     */
    public function testPaypalConfigEmptyArray(): void
    {
        $config = PaypalConfig::fromArray([]);

        $this->assertInstanceOf(PaypalConfig::class, $config);
        $this->assertSame('', $config->clientId);
        $this->assertSame('', $config->clientSecret);
        $this->assertFalse($config->sandbox);
    }

    /**
     * 测试云闪付配置：fromArray 与字段映射
     */
    public function testUnionPayConfigFromArray(): void
    {
        $config = UnionPayConfig::fromArray([
            'mer_id' => 'm1',
            'cert_path' => '/path/cert',
            'cert_pwd' => 'pwd',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(UnionPayConfig::class, $config);
        $this->assertSame('m1', $config->merId);
        $this->assertSame('/path/cert', $config->certPath);
        $this->assertSame('pwd', $config->certPwd);
        $this->assertTrue($config->sandbox);
    }

    /**
     * 测试云闪付配置：getGateway 标识
     */
    public function testUnionPayConfigGetGateway(): void
    {
        $config = UnionPayConfig::fromArray([]);

        $this->assertSame('unionpay', $config->getGateway());
    }

    /**
     * 测试云闪付配置：空数组 fromArray 不报错
     */
    public function testUnionPayConfigEmptyArray(): void
    {
        $config = UnionPayConfig::fromArray([]);

        $this->assertInstanceOf(UnionPayConfig::class, $config);
        $this->assertSame('', $config->merId);
        $this->assertFalse($config->sandbox);
    }

    /**
     * 测试 Stripe 配置：fromArray 与字段映射
     */
    public function testStripeConfigFromArray(): void
    {
        $config = StripeConfig::fromArray([
            'secret_key' => 'sk_test_xxx',
            'publishable_key' => 'pk_test_xxx',
            'webhook_secret' => 'whsec_xxx',
            'api_version' => '2024-06-20',
            'sandbox' => true,
        ]);

        $this->assertInstanceOf(StripeConfig::class, $config);
        $this->assertSame('sk_test_xxx', $config->secretKey);
        $this->assertSame('pk_test_xxx', $config->publishableKey);
        $this->assertSame('whsec_xxx', $config->webhookSecret);
        $this->assertSame('2024-06-20', $config->apiVersion);
        $this->assertTrue($config->sandbox);
    }

    /**
     * 测试 Stripe 配置：getGateway 标识
     */
    public function testStripeConfigGetGateway(): void
    {
        $config = StripeConfig::fromArray([]);

        $this->assertSame('stripe', $config->getGateway());
    }

    /**
     * 测试 Stripe 配置：空数组 fromArray 不报错（使用默认值）
     */
    public function testStripeConfigEmptyArray(): void
    {
        $config = StripeConfig::fromArray([]);

        $this->assertInstanceOf(StripeConfig::class, $config);
        $this->assertSame('', $config->secretKey);
        $this->assertNull($config->publishableKey);
        $this->assertNull($config->webhookSecret);
        // 默认 API 版本
        $this->assertSame('2024-06-20', $config->apiVersion);
        $this->assertFalse($config->sandbox);
    }
}
