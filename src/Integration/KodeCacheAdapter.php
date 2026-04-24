<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

/**
 * kode/cache 集成适配器
 *
 * 当项目中安装了 kode/cache 时，自动启用其缓存和分布式锁能力，
 * 用于支付订单防重、证书缓存、配置缓存等场景。
 * 未安装时完全回退到原生实现，零依赖侵入。
 *
 * 使用示例：
 * ```php
 * // 缓存支付证书
 * KodeCacheAdapter::set('wechat_cert_' . $mchId, $certContent, 3600);
 *
 * // 获取缓存
 * $cert = KodeCacheAdapter::get('wechat_cert_' . $mchId);
 *
 * // 分布式锁防止重复支付
 * if (KodeCacheAdapter::lock('pay_' . $orderId, 10)) {
 *     // 执行业务逻辑
 *     KodeCacheAdapter::unlock('pay_' . $orderId);
 * }
 * ```
 */
class KodeCacheAdapter
{
    /**
     * 内存缓存回退（未安装 kode/cache 时使用）
     *
     * @var array<string, array{value: mixed, expire: int}>
     */
    protected static array $fallbackCache = [];

    /**
     * 内存锁回退
     *
     * @var array<string, bool>
     */
    protected static array $fallbackLocks = [];

    /**
     * 写入缓存
     *
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间（秒），0 表示永不过期
     * @return bool
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (class_exists(\Kode\Cache\Cache::class)) {
            return \Kode\Cache\Cache::set($key, $value, $ttl);
        }

        self::$fallbackCache[$key] = [
            'value' => $value,
            'expire' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return true;
    }

    /**
     * 读取缓存
     *
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (class_exists(\Kode\Cache\Cache::class)) {
            return \Kode\Cache\Cache::get($key, $default);
        }

        if (!isset(self::$fallbackCache[$key])) {
            return $default;
        }

        $item = self::$fallbackCache[$key];

        if ($item['expire'] > 0 && time() > $item['expire']) {
            unset(self::$fallbackCache[$key]);

            return $default;
        }

        return $item['value'];
    }

    /**
     * 判断缓存是否存在
     *
     * @param string $key 缓存键
     * @return bool
     */
    public static function has(string $key): bool
    {
        if (class_exists(\Kode\Cache\Cache::class)) {
            return \Kode\Cache\Cache::has($key);
        }

        if (!isset(self::$fallbackCache[$key])) {
            return false;
        }

        $item = self::$fallbackCache[$key];

        if ($item['expire'] > 0 && time() > $item['expire']) {
            unset(self::$fallbackCache[$key]);

            return false;
        }

        return true;
    }

    /**
     * 删除缓存
     *
     * @param string $key 缓存键
     * @return bool
     */
    public static function delete(string $key): bool
    {
        if (class_exists(\Kode\Cache\Cache::class)) {
            return \Kode\Cache\Cache::delete($key);
        }

        unset(self::$fallbackCache[$key]);

        return true;
    }

    /**
     * 获取分布式锁
     *
     * @param string $key 锁标识
     * @param int $ttl 锁过期时间（秒）
     * @return bool 是否获取成功
     */
    public static function lock(string $key, int $ttl = 60): bool
    {
        if (class_exists(\Kode\Cache\Lock::class)) {
            return \Kode\Cache\Lock::acquire($key, $ttl);
        }

        if (isset(self::$fallbackLocks[$key])) {
            return false;
        }

        self::$fallbackLocks[$key] = true;

        return true;
    }

    /**
     * 释放分布式锁
     *
     * @param string $key 锁标识
     * @return bool
     */
    public static function unlock(string $key): bool
    {
        if (class_exists(\Kode\Cache\Lock::class)) {
            return \Kode\Cache\Lock::release($key);
        }

        unset(self::$fallbackLocks[$key]);

        return true;
    }

    /**
     * 判断 kode/cache 是否可用
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Kode\Cache\Cache::class);
    }
}
