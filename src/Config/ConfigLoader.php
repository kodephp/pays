<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Core\PayException;

/**
 * 配置加载器
 *
 * 支持从数组、环境变量、配置文件等多种来源加载支付配置。
 * 便于在不同环境（开发、测试、生产）间快速切换配置。
 *
 * 使用示例：
 * ```php
 * // 从环境变量加载
 * $config = ConfigLoader::fromEnv('WECHAT_');
 *
 * // 从数组加载并转换
 * $config = ConfigLoader::fromArray([
 *     'app_id' => 'wx123',
 *     'mch_id' => '123',
 *     'api_key' => 'key',
 * ]);
 *
 * // 从 JSON 文件加载
 * $config = ConfigLoader::fromFile('/path/to/config.json');
 * ```
 */
class ConfigLoader
{
    /**
     * 从数组加载配置
     *
     * @param array<string, mixed> $config 配置数组
     * @return array<string, mixed>
     */
    public static function fromArray(array $config): array
    {
        return $config;
    }

    /**
     * 从环境变量加载配置
     *
     * 自动读取以指定前缀开头的环境变量，并去除前缀作为配置键。
     * 支持将值自动转换为 bool、int、float 类型。
     *
     * @param string $prefix 环境变量前缀，如 "WECHAT_"
     * @return array<string, mixed>
     */
    public static function fromEnv(string $prefix): array
    {
        $config = [];

        foreach ($_ENV as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $configKey = strtolower(str_replace($prefix, '', $key));
                $config[$configKey] = self::castValue($value);
            }
        }

        // 同时检查 getenv
        foreach (getenv() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $configKey = strtolower(str_replace($prefix, '', $key));
                if (!isset($config[$configKey])) {
                    $config[$configKey] = self::castValue($value);
                }
            }
        }

        return $config;
    }

    /**
     * 从 JSON 文件加载配置
     *
     * @param string $path 文件路径
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function fromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw PayException::configError("配置文件不存在：{$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw PayException::configError("无法读取配置文件：{$path}");
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw PayException::configError("配置文件格式错误：{$path}");
        }

        return $data;
    }

    /**
     * 从 PHP 文件加载配置（返回数组）
     *
     * @param string $path 文件路径
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function fromPhpFile(string $path): array
    {
        if (!file_exists($path)) {
            throw PayException::configError("配置文件不存在：{$path}");
        }

        $data = require $path;

        if (!is_array($data)) {
            throw PayException::configError("PHP 配置文件必须返回数组：{$path}");
        }

        return $data;
    }

    /**
     * 合并多个配置源
     *
     * 后面的配置会覆盖前面的同名键。
     *
     * @param array<string, mixed> ...$configs
     * @return array<string, mixed>
     */
    public static function merge(array ...$configs): array
    {
        return array_merge(...$configs);
    }

    /**
     * 加载多环境配置
     *
     * 根据当前环境变量 APP_ENV 自动选择对应配置。
     *
     * @param string $basePath 配置目录
     * @param string $gateway 网关标识
     * @param string|null $env 环境名称，默认从 APP_ENV 读取
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function loadForEnv(string $basePath, string $gateway, ?string $env = null): array
    {
        $env = $env ?? $_ENV['APP_ENV'] ?? 'production';

        // 基础配置
        $baseFile = rtrim($basePath, '/') . "/{$gateway}.php";
        $baseConfig = file_exists($baseFile) ? self::fromPhpFile($baseFile) : [];

        // 环境特定配置
        $envFile = rtrim($basePath, '/') . "/{$gateway}.{$env}.php";
        $envConfig = file_exists($envFile) ? self::fromPhpFile($envFile) : [];

        return self::merge($baseConfig, $envConfig);
    }

    /**
     * 自动转换环境变量值的类型
     *
     * @param string $value
     * @return mixed
     */
    protected static function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
