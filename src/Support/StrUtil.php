<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

/**
 * 字符串工具类
 *
 * 提供支付场景中常用的字符串操作，如随机字符串生成、掩码处理等。
 */
class StrUtil
{
    /**
     * 生成随机字符串
     *
     * @param int $length 长度
     * @param string $chars 可用字符集
     * @return string
     */
    public static function random(int $length = 16, string $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string
    {
        $result = '';
        $maxIndex = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $maxIndex)];
        }

        return $result;
    }

    /**
     * 生成随机数字字符串
     *
     * @param int $length 长度
     * @return string
     */
    public static function randomNumeric(int $length = 6): string
    {
        return self::random($length, '0123456789');
    }

    /**
     * 对敏感信息进行掩码处理
     *
     * @param string $value 原始值
     * @param int $showPrefix 前缀保留位数
     * @param int $showSuffix 后缀保留位数
     * @param string $mask 掩码字符
     * @return string
     */
    public static function mask(string $value, int $showPrefix = 4, int $showSuffix = 4, string $mask = '*'): string
    {
        $length = strlen($value);

        if ($length <= $showPrefix + $showSuffix) {
            return str_repeat($mask, $length);
        }

        $prefix = substr($value, 0, $showPrefix);
        $suffix = substr($value, -$showSuffix);
        $middleLength = $length - $showPrefix - $showSuffix;

        return $prefix . str_repeat($mask, $middleLength) . $suffix;
    }

    /**
     * 生成唯一订单号
     *
     * 格式：日期(14位) + 随机数(6位) + 微秒(6位)
     *
     * @param string $prefix 前缀
     * @return string
     */
    public static function generateOrderNo(string $prefix = ''): string
    {
        $date = date('YmdHis');
        $random = self::randomNumeric(6);
        $micro = substr((string) microtime(true), -6);

        return $prefix . $date . $random . $micro;
    }

    /**
     * 生成退款单号
     *
     * @param string $originalOrderNo 原订单号
     * @return string
     */
    public static function generateRefundNo(string $originalOrderNo): string
    {
        return $originalOrderNo . 'R' . self::randomNumeric(4);
    }

    /**
     * 检查字符串是否以指定前缀开头
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    /**
     * 检查字符串是否以指定后缀结尾
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    /**
     * 将金额从元转换为分
     *
     * @param float|string $amount 金额（元）
     * @return int 金额（分）
     */
    public static function yuanToFen(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * 将金额从分转换为元
     *
     * @param int $amount 金额（分）
     * @return string 金额（元），保留两位小数
     */
    public static function fenToYuan(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * 生成 UUID v4
     *
     * @return string
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
