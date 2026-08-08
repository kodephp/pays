<?php

declare(strict_types=1);

namespace Kode\Pays;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\HttpClient;

/**
 * SDK 统一入口类（根命名空间别名）
 *
 * 直接继承 {@see \Kode\Pays\Facade\Pay}，因此同时具备门面类的全部能力
 * （统一入口 call / 平台清单 manifest / 安全校验 verify / 一次扩展 extend 等），
 * 调用方无论引用 `Kode\Pays\Pay` 还是 `Kode\Pays\Facade\Pay` 都指向同一套统一入口。
 *
 * 示例：
 * ```php
 * // 统一入口：一个方法调用任意已接入平台
 * $result = Pay::call('wechat', 'createOrder', [
 *     'out_trade_no' => 'ORDER_202404240001',
 *     'total_fee' => 100,
 *     'body' => '商品描述',
 * ]);
 *
 * // 或拿到强类型实例，调用平台特色方法
 * $wechat = Pay::gateway('wechat', $config);
 * $result = $wechat->createOrder([...]);
 * ```
 */
class Pay extends \Kode\Pays\Facade\Pay
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
