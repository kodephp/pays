<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

/**
 * 沙箱环境管理器
 *
 * 统一管理各支付网关的沙箱/生产环境切换，支持全局覆盖和按网关单独配置。
 * 沙箱模式下所有支付请求指向测试环境，不会扣真实资金。
 *
 * 使用示例：
 * ```php
 * use Kode\Pays\Core\SandboxManager;
 *
 * // 全局开启沙箱（所有网关）
 * SandboxManager::enableGlobal();
 *
 * // 仅对微信开启沙箱
 * SandboxManager::enable('wechat');
 *
 * // 检查当前环境
 * if (SandboxManager::isSandbox('wechat')) {
 *     // 沙箱模式逻辑
 * }
 * ```
 */
class SandboxManager
{
    /**
     * 全局沙箱开关
     */
    protected static bool $globalSandbox = false;

    /**
     * 各网关沙箱状态
     *
     * @var array<string, bool>
     */
    protected static array $gatewaySandbox = [];

    /**
     * 沙箱环境 URL 映射表
     *
     * @var array<string, array{prod: string, sandbox: string}>
     */
    protected static array $urlMap = [
        'wechat' => [
            'prod' => 'https://api.mch.weixin.qq.com/',
            'sandbox' => 'https://api.mch.weixin.qq.com/sandboxnew/',
        ],
        'alipay' => [
            'prod' => 'https://openapi.alipay.com/gateway.do',
            'sandbox' => 'https://openapi.alipaydev.com/gateway.do',
        ],
        'unionpay' => [
            'prod' => 'https://gateway.95516.com/',
            'sandbox' => 'https://gateway.test.95516.com/',
        ],
        'douyin' => [
            'prod' => 'https://developer.toutiao.com/',
            'sandbox' => 'https://developer-sandbox.toutiao.com/',
        ],
        'paypal' => [
            'prod' => 'https://api-m.paypal.com/',
            'sandbox' => 'https://api-m.sandbox.paypal.com/',
        ],
        'meituan' => [
            'prod' => 'https://open-api.meituan.com/',
            'sandbox' => 'https://open-api-test.meituan.com/',
        ],
        'jd' => [
            'prod' => 'https://wg.jd.com/',
            'sandbox' => 'https://uat-wg.jd.com/',
        ],
        'kuaishou' => [
            'prod' => 'https://pay-api.gifshow.com/',
            'sandbox' => 'https://pay-api-test.gifshow.com/',
        ],
        'apple' => [
            'prod' => 'https://apple-pay-gateway.apple.com/',
            'sandbox' => 'https://apple-pay-gateway-cert.apple.com/',
        ],
        'google' => [
            'prod' => 'https://payments.googleapis.com/',
            'sandbox' => 'https://payments.googleapis.com/',
        ],
        'amazon' => [
            'prod' => 'https://pay-api.amazon.com/',
            'sandbox' => 'https://pay-api.amazon.com/',
        ],
        'klarna' => [
            'prod' => 'https://api.klarna.com/',
            'sandbox' => 'https://api.playground.klarna.com/',
        ],
        'alipay_global' => [
            'prod' => 'https://globalmapi.alipay.com/',
            'sandbox' => 'https://globalmapi.alipay.com/',
        ],
    ];

    /**
     * 全局开启沙箱模式
     */
    public static function enableGlobal(): void
    {
        self::$globalSandbox = true;
    }

    /**
     * 全局关闭沙箱模式
     */
    public static function disableGlobal(): void
    {
        self::$globalSandbox = false;
    }

    /**
     * 对指定网关开启沙箱模式
     *
     * @param string $gateway 网关标识
     */
    public static function enable(string $gateway): void
    {
        self::$gatewaySandbox[$gateway] = true;
    }

    /**
     * 对指定网关关闭沙箱模式
     *
     * @param string $gateway 网关标识
     */
    public static function disable(string $gateway): void
    {
        self::$gatewaySandbox[$gateway] = false;
    }

    /**
     * 判断指定网关是否处于沙箱模式
     *
     * 优先级：网关单独设置 > 全局设置
     *
     * @param string $gateway 网关标识
     * @return bool
     */
    public static function isSandbox(string $gateway): bool
    {
        // 如果网关有单独设置，优先使用
        if (isset(self::$gatewaySandbox[$gateway])) {
            return self::$gatewaySandbox[$gateway];
        }

        // 否则使用全局设置
        return self::$globalSandbox;
    }

    /**
     * 获取指定网关的当前环境基础 URL
     *
     * @param string $gateway 网关标识
     * @return string|null 基础 URL，未配置时返回 null
     */
    public static function getBaseUrl(string $gateway): ?string
    {
        if (!isset(self::$urlMap[$gateway])) {
            return null;
        }

        $mode = self::isSandbox($gateway) ? 'sandbox' : 'prod';

        return self::$urlMap[$gateway][$mode];
    }

    /**
     * 注册网关的沙箱 URL 映射
     *
     * @param string $gateway 网关标识
     * @param string $prodUrl 生产环境 URL
     * @param string $sandboxUrl 沙箱环境 URL
     */
    public static function registerUrl(string $gateway, string $prodUrl, string $sandboxUrl): void
    {
        self::$urlMap[$gateway] = [
            'prod' => $prodUrl,
            'sandbox' => $sandboxUrl,
        ];
    }

    /**
     * 重置所有沙箱设置
     */
    public static function reset(): void
    {
        self::$globalSandbox = false;
        self::$gatewaySandbox = [];
    }

    /**
     * 获取当前处于沙箱模式的所有网关
     *
     * @return string[] 网关标识列表
     */
    public static function getSandboxGateways(): array
    {
        $result = [];

        foreach (array_keys(self::$urlMap) as $gateway) {
            if (self::isSandbox($gateway)) {
                $result[] = $gateway;
            }
        }

        return $result;
    }
}
