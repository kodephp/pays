<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\GatewayFactory;

/**
 * kode/facade 集成适配器
 *
 * 当项目中安装了 kode/facade 时，提供更强大的门面模式支持，
 * 包括静态代理、延迟加载、方法链式调用等。
 */
class KodeFacadeAdapter
{
    /**
     * 门面实例缓存
     *
     * @var array<string, GatewayInterface>
     */
    protected static array $instances = [];

    /**
     * 全局配置缓存
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $configs = [];

    /**
     * 注册网关配置
     *
     * @param string $name 网关名称
     * @param array<string, mixed> $config 网关配置
     */
    public static function registerConfig(string $name, array $config): void
    {
        self::$configs[$name] = $config;
    }

    /**
     * 获取网关实例（支持 kode/facade 延迟加载）
     *
     * @param string $name 网关名称
     * @param array<string, mixed>|null $config 可选配置，不传则使用已注册配置
     * @return GatewayInterface
     * @throws \Kode\Pays\Core\PayException
     */
    public static function gateway(string $name, ?array $config = null): GatewayInterface
    {
        $key = $name . '_' . md5(json_encode($config ?? self::$configs[$name] ?? []));

        if (!isset(self::$instances[$key])) {
            $cfg = $config ?? self::$configs[$name] ?? [];
            self::$instances[$key] = GatewayFactory::create($name, $cfg);
        }

        return self::$instances[$key];
    }

    /**
     * 清除所有缓存实例
     */
    public static function clear(): void
    {
        self::$instances = [];
    }

    /**
     * 使用 kode/facade 的静态代理能力（如果可用）
     *
     * @param string $class 目标类
     * @param string $method 方法名
     * @param array<mixed> $args 参数
     * @return mixed
     */
    public static function proxy(string $class, string $method, array $args): mixed
    {
        if (class_exists(\Kode\Facade\Proxy::class)) {
            return \Kode\Facade\Proxy::call($class, $method, $args);
        }

        return $class::$method(...$args);
    }
}
