<?php

declare(strict_types=1);

namespace Kode\Pays\Facade;

use Kode\Pays\Contract\ConfigInterface;
use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\PayException;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Support\HttpClient;

/**
 * Kode Pays SDK 门面类
 *
 * 提供静态方法快速访问 SDK 核心能力，是开发者最常用的入口。
 * 支持链式配置、全局事件监听注册、配置缓存等高级特性。
 *
 * 示例：
 * ```php
 * use Kode\Pays\Facade\Pay;
 *
 * // 快速创建网关
 * $wechat = Pay::wechat([
 *     'app_id' => 'wx123456',
 *     'mch_id' => '123456',
 *     'api_key' => 'your-key',
 * ]);
 *
 * // 使用配置 DTO 创建
 * $config = WechatConfig::fromArray([...]);
 * $wechat = Pay::createWithConfig('wechat', $config);
 *
 * // 注册全局事件监听
 * Pay::on('pay.payment.success', function ($payload) {
 *     // 发送通知
 * });
 *
 * // 预注册配置，后续快速创建
 * Pay::registerConfig('wechat', $configArray);
 * $wechat = Pay::wechat(); // 无需再传配置
 * ```
 */
class Pay
{
    /**
     * 全局事件分发器实例
     */
    protected static ?EventDispatcher $dispatcher = null;

    /**
     * 全局默认 HTTP 客户端
     */
    protected static ?HttpClient $httpClient = null;

    /**
     * 预注册的配置缓存
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $configCache = [];

    /**
     * 网关实例缓存
     *
     * @var array<string, GatewayInterface>
     */
    protected static array $gatewayCache = [];

    /**
     * 魔术方法：通过门面快速创建网关
     *
     * 支持：Pay::wechat($config)、Pay::alipay($config) 等
     * 如果已预注册配置，可省略参数：Pay::wechat()
     *
     * @param string $name 网关标识
     * @param array<int, mixed> $arguments 参数列表，第一个为配置数组（可选）
     * @return GatewayInterface
     * @throws PayException
     */
    public static function __callStatic(string $name, array $arguments): GatewayInterface
    {
        $cacheKey = $name;

        // 检查实例缓存
        if (isset(self::$gatewayCache[$cacheKey])) {
            return self::$gatewayCache[$cacheKey];
        }

        // 获取配置
        if (!empty($arguments)) {
            $config = $arguments[0];
        } elseif (isset(self::$configCache[$name])) {
            $config = self::$configCache[$name];
        } else {
            throw PayException::configError("创建 {$name} 网关时必须传入配置参数，或先调用 Pay::registerConfig('{$name}', ...) 预注册配置");
        }

        $gateway = GatewayFactory::create($name, $config, self::$httpClient);

        // 自动注入全局事件分发器
        if (self::$dispatcher !== null && method_exists($gateway, 'setDispatcher')) {
            $gateway->setDispatcher(self::$dispatcher);
        }

        // 缓存实例
        self::$gatewayCache[$cacheKey] = $gateway;

        return $gateway;
    }

    /**
     * 通用创建网关方法
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $config 配置数组
     * @return GatewayInterface
     * @throws PayException
     */
    public static function create(string $gateway, array $config): GatewayInterface
    {
        return GatewayFactory::create($gateway, $config, self::$httpClient);
    }

    /**
     * 使用配置 DTO 创建网关
     *
     * @param string $gateway 网关标识
     * @param ConfigInterface $config 配置 DTO
     * @return GatewayInterface
     * @throws PayException
     */
    public static function createWithConfig(string $gateway, ConfigInterface $config): GatewayInterface
    {
        return GatewayFactory::createWithConfig($gateway, $config, self::$httpClient);
    }

    /**
     * 自动配置 DTO 转换后创建网关
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $config 原始配置数组
     * @return GatewayInterface
     * @throws PayException
     */
    public static function createAutoConfig(string $gateway, array $config): GatewayInterface
    {
        return GatewayFactory::createAutoConfig($gateway, $config, self::$httpClient);
    }

    /**
     * 预注册网关配置
     *
     * 注册后可通过 Pay::wechat() 无参快速创建网关
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $config 配置数组
     */
    public static function registerConfig(string $gateway, array $config): void
    {
        self::$configCache[$gateway] = $config;
    }

    /**
     * 获取预注册的配置
     *
     * @param string $gateway 网关标识
     * @return array<string, mixed>|null
     */
    public static function getConfig(string $gateway): ?array
    {
        return self::$configCache[$gateway] ?? null;
    }

    /**
     * 清除网关实例缓存
     *
     * @param string|null $gateway 指定网关标识，null 表示清除所有
     */
    public static function clearCache(?string $gateway = null): void
    {
        if ($gateway === null) {
            self::$gatewayCache = [];
        } else {
            unset(self::$gatewayCache[$gateway]);
        }
    }

    /**
     * 注册全局事件监听器
     *
     * @param string $eventName 事件名称，可使用 Kode\Pays\Event\Events 常量
     * @param callable $listener 监听器回调
     * @param int $priority 优先级（数值越大越先执行）
     */
    public static function on(string $eventName, callable $listener, int $priority = 0): void
    {
        self::getDispatcher()->listen($eventName, $listener, $priority);
    }

    /**
     * 触发全局事件
     *
     * @param string $eventName 事件名称
     * @param mixed $payload 事件载荷
     * @return mixed
     */
    public static function emit(string $eventName, mixed $payload = null): mixed
    {
        return self::getDispatcher()->dispatch($eventName, $payload);
    }

    /**
     * 设置全局默认 HTTP 客户端
     *
     * @param HttpClient $httpClient
     */
    public static function setHttpClient(HttpClient $httpClient): void
    {
        self::$httpClient = $httpClient;
    }

    /**
     * 设置全局事件分发器
     *
     * @param EventDispatcher $dispatcher
     */
    public static function setDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * 获取全局事件分发器
     */
    public static function getDispatcher(): EventDispatcher
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = new EventDispatcher();
        }

        return self::$dispatcher;
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
