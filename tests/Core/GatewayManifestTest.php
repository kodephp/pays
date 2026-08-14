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
        // 支付宝、Stripe 亦支持实时余额查询
        $this->assertTrue(GatewayManifest::supports('alipay', GatewayManifest::CAP_BALANCE));
        $this->assertTrue(GatewayManifest::supports('stripe', GatewayManifest::CAP_BALANCE));

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

    /**
     * configSchema 返回每个内置平台的必填/可选配置字段契约
     */
    public function testConfigSchemaExposesRequiredAndOptional(): void
    {
        $wechat = GatewayManifest::configSchema('wechat');
        $this->assertSame(['app_id', 'mch_id', 'api_key'], $wechat['required']);
        $this->assertContains('cert_path', $wechat['optional']);
        $this->assertContains('sandbox', $wechat['optional']);

        $alipay = GatewayManifest::configSchema('alipay');
        $this->assertSame(['app_id', 'private_key', 'public_key'], $alipay['required']);
        $this->assertContains('app_auth_token', $alipay['optional']);

        // 聚合支付：channels 为必填
        $aggregate = GatewayManifest::configSchema('aggregate');
        $this->assertSame(['channels'], $aggregate['required']);
        $this->assertSame([], $aggregate['optional']);
    }

    /**
     * configSchema 对未登记平台通过反射 Config 构造函数自动推导
     */
    public function testConfigSchemaFallsBackToReflection(): void
    {
        // 自定义平台：手动登记一个仅声明 config_class 的清单
        GatewayManifest::register('__custom_test', [
            'label' => '自定义测试',
            'gateway_class' => \Kode\Pays\Gateway\Wechat\WechatPayGateway::class,
            'config_class' => \Kode\Pays\Config\WechatConfig::class,
        ]);

        $schema = GatewayManifest::configSchema('__custom_test');
        // WechatConfig 构造函数：appId/mchId/apiKey 无默认值 => 必填；其余有默认值 => 可选
        $this->assertSame(['app_id', 'mch_id', 'api_key'], $schema['required']);
        $this->assertContains('cert_path', $schema['optional']);
        $this->assertContains('sandbox', $schema['optional']);

        GatewayManifest::unregister('__custom_test');
    }

    /**
     * inspect 返回统一的接入响应结构（元信息 + 能力 + 操作 + 配置 + 缺失校验）
     */
    public function testInspectReturnsUnifiedResponse(): void
    {
        $info = GatewayManifest::inspect('wechat');

        // 元信息
        $this->assertSame('wechat', $info['name']);
        $this->assertSame('微信支付', $info['label']);
        $this->assertSame(GatewayManifest::REGION_DOMESTIC, $info['region']);
        $this->assertSame(GatewayManifest::SIGN_MD5, $info['signature']);

        // 能力开关（完整 bool 映射）
        $this->assertArrayHasKey(GatewayManifest::CAP_CREATE_ORDER, $info['capabilities']);
        $this->assertTrue($info['capabilities'][GatewayManifest::CAP_TRANSFER]);

        // 可调用操作：仅已开启能力，且含中文标签与方法名
        $this->assertArrayHasKey(GatewayManifest::CAP_TRANSFER, $info['operations']);
        $this->assertSame('企业付款/转账', $info['operations'][GatewayManifest::CAP_TRANSFER]['label']);
        $this->assertContains('singleTransfer', $info['operations'][GatewayManifest::CAP_TRANSFER]['methods']);

        // 配置字段契约
        $this->assertSame(['app_id', 'mch_id', 'api_key'], $info['config']['required']);

        // 缺失校验：未传配置时应报告缺漏且 valid=false
        $this->assertSame(['app_id', 'mch_id', 'api_key'], $info['missing']);
        $this->assertFalse($info['valid']);
    }

    /**
     * inspect 在配置齐全时 valid=true 且 missing 为空
     */
    public function testInspectValidWhenConfigProvided(): void
    {
        $info = GatewayManifest::inspect('wechat', [
            'app_id' => 'wx123',
            'mch_id' => '123',
            'api_key' => 'key',
        ]);

        $this->assertSame([], $info['missing']);
        $this->assertTrue($info['valid']);
    }

    /**
     * 空字符串视为缺失，仍纳入 missing 校验
     */
    public function testInspectTreatsEmptyStringAsMissing(): void
    {
        $info = GatewayManifest::inspect('wechat', [
            'app_id' => 'wx123',
            'mch_id' => '',
            'api_key' => 'key',
        ]);

        $this->assertSame(['mch_id'], $info['missing']);
        $this->assertFalse($info['valid']);
    }

    /**
     * capabilityLabel / capabilityOperations 映射正确
     */
    public function testCapabilityLabelAndOperations(): void
    {
        $this->assertSame('分账', GatewayManifest::capabilityLabel(GatewayManifest::CAP_PROFIT_SHARING));
        $this->assertContains(
            'createProfitSharing',
            GatewayManifest::capabilityOperations(GatewayManifest::CAP_PROFIT_SHARING)
        );

        // 未知能力返回原值 / 空数组，不抛异常
        $this->assertSame('__unknown__', GatewayManifest::capabilityLabel('__unknown__'));
        $this->assertSame([], GatewayManifest::capabilityOperations('__unknown__'));
    }

    /**
     * normalize 后清单数据携带 config_schema，且 get() 可读取
     */
    public function testEntryCarriesConfigSchema(): void
    {
        $entry = GatewayManifest::get('alipay');

        $this->assertArrayHasKey('config_schema', $entry);
        $this->assertSame(['app_id', 'private_key', 'public_key'], $entry['config_schema']['required']);
    }

    /**
     * Pay 门面 inspect 与 GatewayManifest 一致
     */
    public function testPayFacadeInspectDelegates(): void
    {
        $info = \Kode\Pays\Facade\Pay::inspect('alipay', [
            'app_id' => 'a',
            'private_key' => 'b',
            'public_key' => 'c',
        ]);

        $this->assertTrue($info['valid']);
        $this->assertArrayHasKey(GatewayManifest::CAP_QR, $info['capabilities']);
    }

    /**
     * validate 返回结构化校验结果：缺必填、无未知键
     */
    public function testValidateReportsMissingRequired(): void
    {
        $result = GatewayManifest::validate('wechat', []);

        $this->assertFalse($result['valid']);
        $this->assertSame(['app_id', 'mch_id', 'api_key'], $result['missing']);
        $this->assertSame([], $result['unknown']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('app_id', $result['errors'][0]);
    }

    /**
     * validate 检测到未知键（拼写错误），但必填齐全时 valid 仍为 true
     */
    public function testValidateDetectsUnknownKeys(): void
    {
        $result = GatewayManifest::validate('wechat', [
            'app_id' => 'wx123',
            'mch_id' => '123',
            'api_key' => 'key',
            'appid' => 'typo', // 常见拼写错误
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(['appid'], $result['unknown']);
    }

    /**
     * validate 在配置齐全且无未知键时全部通过
     */
    public function testValidatePassesWhenComplete(): void
    {
        $result = GatewayManifest::validate('wechat', [
            'app_id' => 'wx123',
            'mch_id' => '123',
            'api_key' => 'key',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['unknown']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * configSchema 包含每字段的元信息（类型/必填/默认值/说明），且由 Config 类反射得到
     */
    public function testConfigSchemaIncludesFieldMetadata(): void
    {
        $schema = GatewayManifest::configSchema('wechat');
        $this->assertArrayHasKey('fields', $schema);

        $appId = $schema['fields']['app_id'] ?? null;
        $this->assertNotNull($appId);
        $this->assertSame('string', $appId['type']);
        $this->assertTrue($appId['required']);
        $this->assertArrayHasKey('default', $appId);
        $this->assertNotEmpty($appId['description']); // 来自 WechatConfig 构造函数 @param

        // 可选字段：cert_path 非必填，默认值应为 null
        $cert = $schema['fields']['cert_path'] ?? null;
        $this->assertNotNull($cert);
        $this->assertFalse($cert['required']);
        $this->assertNull($cert['default']);
    }

    /**
     * inspect 包含未知键检测，并在 config 中附带字段元信息
     */
    public function testInspectIncludesUnknownAndFields(): void
    {
        $info = GatewayManifest::inspect('wechat', [
            'app_id' => 'wx123',
            'mch_id' => '123',
            'api_key' => 'key',
            'extra_field' => 'oops',
        ]);

        $this->assertTrue($info['valid']);
        $this->assertSame(['extra_field'], $info['unknown']);
        $this->assertArrayHasKey('fields', $info['config']);
        $this->assertArrayHasKey('app_id', $info['config']['fields']);
    }

    /**
     * Pay 门面 validate 与 GatewayManifest 一致
     */
    public function testPayFacadeValidateDelegates(): void
    {
        $result = \Kode\Pays\Facade\Pay::validate('wechat', [
            'app_id' => 'wx123',
            'mch_id' => '123',
            'api_key' => 'key',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing']);
    }

    /**
     * configExample 生成可拷贝模板：必填给占位、可选有默认值则用默认值
     */
    public function testConfigExampleGeneratesTemplate(): void
    {
        $example = GatewayManifest::configExample('wechat');

        // 必填字段给出类型占位（非真实值）
        $this->assertSame('<your_app_id>', $example['app_id']);
        $this->assertSame('<your_mch_id>', $example['mch_id']);
        $this->assertSame('<your_api_key>', $example['api_key']);
        $this->assertContains('api_key', array_keys($example));

        // 可选字段 sandbox 有默认值 false，应直接填入默认值
        if (array_key_exists('sandbox', $example)) {
            $this->assertFalse($example['sandbox']);
        }

        // 占位值均不应为空字符串（避免开发者误以为已填）
        foreach (['app_id', 'mch_id', 'api_key'] as $requiredKey) {
            $this->assertNotEmpty($example[$requiredKey]);
        }
    }

    /**
     * configExample 在无字段元信息的平台（聚合支付）回退为通用占位
     */
    public function testConfigExampleFallsBackForSchemaOnlyPlatform(): void
    {
        $example = GatewayManifest::configExample('aggregate');

        $this->assertArrayHasKey('channels', $example);
        $this->assertSame('<your_channels>', $example['channels']);
    }

    /**
     * 用 configExample 生成的模板补齐真实值后，validate 应全部通过
     */
    public function testConfigExampleThenValidateRoundTrip(): void
    {
        $example = GatewayManifest::configExample('wechat');
        $filled = [
            'app_id' => 'wx_real',
            'mch_id' => 'mch_real',
            'api_key' => 'key_real',
        ];
        $config = array_merge($example, $filled);

        $result = GatewayManifest::validate('wechat', $config);
        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing']);
    }

    /**
     * Pay 门面 configExample 与 GatewayManifest 一致
     */
    public function testPayFacadeConfigExampleDelegates(): void
    {
        $example = \Kode\Pays\Facade\Pay::configExample('alipay');

        $this->assertSame('<your_app_id>', $example['app_id']);
        $this->assertSame('<your_private_key>', $example['private_key']);
    }
}
