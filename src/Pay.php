<?php

declare(strict_types=1);

namespace Kode\Pays;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\HttpClient;

/**
 * SDK 统一入口类
 *
 * 提供简洁的静态方法快速创建支付网关实例
 *
 * 示例：
 * ```php
 * $gateway = Pay::create('wechat', [
 *     'app_id' => 'wx123456',
 *     'mch_id' => '1234567890',
 *     'api_key' => 'your-api-key',
 * ]);
 *
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_202404240001',
 *     'total_fee' => 100,
 *     'body' => '商品描述',
 * ]);
 * ```
 */
class Pay
{
    /**
     * 创建支付网关实例
     *
     * @param string $gateway 网关标识，如 wechat、alipay、paypal
     * @param array<string, mixed> $config 网关配置
     * @param HttpClient|null $httpClient 自定义 HTTP 客户端
     * @return GatewayInterface 网关实例
     * @throws PayException
     */
    public static function create(string $gateway, array $config, ?HttpClient $httpClient = null): GatewayInterface
    {
        return GatewayFactory::create($gateway, $config, $httpClient);
    }

    /**
     * 注册自定义网关
     *
     * @param string $name 网关标识
     * @param class-string<GatewayInterface> $class 网关类全限定名
     * @throws PayException
     */
    public static function register(string $name, string $class): void
    {
        GatewayFactory::register($name, $class);
    }

    /**
     * 获取所有支持的网关标识
     *
     * @return string[]
     */
    public static function getGateways(): array
    {
        return GatewayFactory::getNames();
    }

    /**
     * 判断是否支持某网关
     *
     * @param string $gateway 网关标识
     * @return bool
     */
    public static function has(string $gateway): bool
    {
        return GatewayFactory::has($gateway);
    }
}
