<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

/**
 * 日期时间工具类
 *
 * 提供支付场景中常用的日期时间操作，如格式化、时区转换等。
 */
class DateUtil
{
    /**
     * 获取当前时间戳（秒）
     *
     * @return int
     */
    public static function now(): int
    {
        return time();
    }

    /**
     * 获取当前时间戳（毫秒）
     *
     * @return int
     */
    public static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * 格式化日期时间为网关常用格式
     *
     * @param string $format 格式
     * @param int|null $timestamp 时间戳，null 表示当前时间
     * @return string
     */
    public static function format(string $format = 'Y-m-d H:i:s', ?int $timestamp = null): string
    {
        return date($format, $timestamp ?? time());
    }

    /**
     * 格式化为微信支付时间格式（YmdHis）
     *
     * @param int|null $timestamp
     * @return string
     */
    public static function wechatFormat(?int $timestamp = null): string
    {
        return date('YmdHis', $timestamp ?? time());
    }

    /**
     * 格式化为支付宝时间格式（Y-m-d H:i:s）
     *
     * @param int|null $timestamp
     * @return string
     */
    public static function alipayFormat(?int $timestamp = null): string
    {
        return date('Y-m-d H:i:s', $timestamp ?? time());
    }

    /**
     * 格式化为 ISO 8601 格式
     *
     * @param int|null $timestamp
     * @return string
     */
    public static function iso8601(?int $timestamp = null): string
    {
        return date('c', $timestamp ?? time());
    }

    /**
     * 计算订单过期时间
     *
     * @param int $minutes 有效分钟数
     * @param int|null $fromTimestamp 起始时间戳，null 表示当前时间
     * @return int 过期时间戳
     */
    public static function expireAt(int $minutes, ?int $fromTimestamp = null): int
    {
        return ($fromTimestamp ?? time()) + $minutes * 60;
    }

    /**
     * 检查是否已过期
     *
     * @param int $expireTimestamp 过期时间戳
     * @return bool
     */
    public static function isExpired(int $expireTimestamp): bool
    {
        return time() > $expireTimestamp;
    }

    /**
     * 获取对账日期（默认前一天）
     *
     * @param int $daysAgo 前几天
     * @param string $format 输出格式
     * @return string
     */
    public static function billDate(int $daysAgo = 1, string $format = 'Ymd'): string
    {
        return date($format, strtotime("-{$daysAgo} days"));
    }

    /**
     * 解析时间字符串为时间戳
     *
     * @param string $time 时间字符串
     * @return int|false
     */
    public static function parse(string $time): int|false
    {
        return strtotime($time);
    }

    /**
     * 获取两个时间戳之间的时间差（秒）
     *
     * @param int $start 开始时间戳
     * @param int $end 结束时间戳
     * @return int
     */
    public static function diff(int $start, int $end): int
    {
        return $end - $start;
    }

    /**
     * 获取友好的时间差描述
     *
     * @param int $seconds 秒数
     * @return string
     */
    public static function friendlyDiff(int $seconds): string
    {
        $seconds = abs($seconds);

        if ($seconds < 60) {
            return $seconds . '秒';
        }

        if ($seconds < 3600) {
            return floor($seconds / 60) . '分钟';
        }

        if ($seconds < 86400) {
            return floor($seconds / 3600) . '小时';
        }

        return floor($seconds / 86400) . '天';
    }
}
