<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

/**
 * 数组工具类
 *
 * 提供支付场景中常用的数组操作，如排序、过滤、树形转换等。
 */
class ArrayUtil
{
    /**
     * 按键名升序排序（用于签名前参数排序）
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    public static function ksort(array $array): array
    {
        ksort($array);

        return $array;
    }

    /**
     * 过滤空值（null、空字符串）
     *
     * @param array<string, mixed> $array
     * @param bool $strict 是否严格过滤（false 和 0 也过滤）
     * @return array<string, mixed>
     */
    public static function filterEmpty(array $array, bool $strict = false): array
    {
        return array_filter($array, function ($value) use ($strict) {
            if ($strict) {
                return $value !== null && $value !== '';
            }

            return $value !== null && $value !== '' && $value !== false;
        });
    }

    /**
     * 将数组键名从驼峰转为下划线
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    public static function camelToSnakeKeys(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = self::camelToSnake((string) $key);
            $result[$newKey] = is_array($value) ? self::camelToSnakeKeys($value) : $value;
        }

        return $result;
    }

    /**
     * 将数组键名从下划线转为驼峰
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    public static function snakeToCamelKeys(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = self::snakeToCamel((string) $key);
            $result[$newKey] = is_array($value) ? self::snakeToCamelKeys($value) : $value;
        }

        return $result;
    }

    /**
     * 根据路径获取数组中的值
     *
     * @param array<string, mixed> $array
     * @param string $path 路径，如 "user.name"
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(array $array, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);

        foreach ($keys as $key) {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                return $default;
            }

            $array = $array[$key];
        }

        return $array;
    }

    /**
     * 根据路径设置数组中的值
     *
     * @param array<string, mixed> $array
     * @param string $path 路径
     * @param mixed $value 值
     * @return array<string, mixed>
     */
    public static function set(array $array, string $path, mixed $value): array
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }

        $current = $value;

        return $array;
    }

    /**
     * 检查数组是否包含所有指定键
     *
     * @param array<string, mixed> $array
     * @param string[] $keys
     * @return bool
     */
    public static function hasKeys(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 驼峰转下划线
     *
     * @param string $input
     * @return string
     */
    protected static function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
    }

    /**
     * 下划线转驼峰
     *
     * @param string $input
     * @return string
     */
    protected static function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }
}
