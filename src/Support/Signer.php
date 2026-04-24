<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use Kode\Pays\Core\PayException;

/**
 * 签名工具类
 *
 * 提供支付场景常用的 MD5、RSA、RSA2、HMAC-SHA256 签名与验签方法
 */
class Signer
{
    /**
     * 使用 MD5 签名
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $key 密钥
     * @param bool $excludeEmpty 是否排除空值
     * @return string 签名结果
     */
    public static function md5(array $params, string $key, bool $excludeEmpty = true): string
    {
        $string = self::buildQueryString($params, $excludeEmpty) . '&key=' . $key;

        return strtoupper(md5($string));
    }

    /**
     * 验证 MD5 签名
     *
     * @param array<string, mixed> $params 待验证参数（需包含 sign 字段）
     * @param string $key 密钥
     * @param string $signField 签名字段名
     * @param bool $excludeEmpty 是否排除空值
     * @return bool 验证结果
     */
    public static function verifyMd5(array $params, string $key, string $signField = 'sign', bool $excludeEmpty = true): bool
    {
        if (!isset($params[$signField])) {
            return false;
        }

        $sign = $params[$signField];
        unset($params[$signField]);

        return self::md5($params, $key, $excludeEmpty) === $sign;
    }

    /**
     * 使用 RSA 签名（SHA1）
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $privateKey 私钥内容或路径
     * @param bool $isFile 是否为文件路径
     * @return string 签名结果（Base64）
     * @throws PayException
     */
    public static function rsa(array $params, string $privateKey, bool $isFile = false): string
    {
        $string = self::buildQueryString($params);
        $key = self::loadPrivateKey($privateKey, $isFile);

        openssl_sign($string, $signature, $key, OPENSSL_ALGO_SHA1);

        return base64_encode($signature);
    }

    /**
     * 使用 RSA2 签名（SHA256）
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $privateKey 私钥内容或路径
     * @param bool $isFile 是否为文件路径
     * @return string 签名结果（Base64）
     * @throws PayException
     */
    public static function rsa2(array $params, string $privateKey, bool $isFile = false): string
    {
        $string = self::buildQueryString($params);
        $key = self::loadPrivateKey($privateKey, $isFile);

        openssl_sign($string, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 验证 RSA 签名
     *
     * @param array<string, mixed> $params 待验证参数
     * @param string $publicKey 公钥内容或路径
     * @param string $sign 签名值
     * @param bool $isFile 是否为文件路径
     * @param string $algo 算法 SHA1 或 SHA256
     * @return bool 验证结果
     * @throws PayException
     */
    public static function verifyRsa(
        array $params,
        string $publicKey,
        string $sign,
        bool $isFile = false,
        string $algo = 'SHA256',
    ): bool {
        $string = self::buildQueryString($params);
        $key = self::loadPublicKey($publicKey, $isFile);
        $algorithm = $algo === 'SHA1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;

        return openssl_verify($string, base64_decode($sign), $key, $algorithm) === 1;
    }

    /**
     * 使用 HMAC-SHA256 签名
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $key 密钥
     * @param bool $excludeEmpty 是否排除空值
     * @return string 签名结果
     */
    public static function hmacSha256(array $params, string $key, bool $excludeEmpty = true): string
    {
        $string = self::buildQueryString($params, $excludeEmpty) . '&key=' . $key;

        return strtoupper(hash_hmac('sha256', $string, $key));
    }

    /**
     * 构建待签名字符串
     *
     * 规则：参数按 key 升序排序，拼接为 key1=value1&key2=value2 格式
     *
     * @param array<string, mixed> $params 参数数组
     * @param bool $excludeEmpty 是否排除空值
     * @param string[] $excludeFields 不参与签名的字段
     * @return string 查询字符串
     */
    public static function buildQueryString(array $params, bool $excludeEmpty = true, array $excludeFields = ['sign']): string
    {
        ksort($params);

        $pairs = [];

        foreach ($params as $key => $value) {
            if (in_array($key, $excludeFields, true)) {
                continue;
            }

            if ($excludeEmpty && ($value === '' || $value === null)) {
                continue;
            }

            $pairs[] = $key . '=' . $value;
        }

        return implode('&', $pairs);
    }

    /**
     * 加载私钥
     *
     * @param string $privateKey 私钥内容或路径
     * @param bool $isFile 是否为文件路径
     * @return resource 私钥资源
     * @throws PayException
     */
    protected static function loadPrivateKey(string $privateKey, bool $isFile)
    {
        if ($isFile) {
            if (!file_exists($privateKey)) {
                throw PayException::configError('私钥文件不存在：' . $privateKey);
            }
            $privateKey = file_get_contents($privateKey);
        }

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw PayException::configError('私钥格式错误，无法加载');
        }

        return $key;
    }

    /**
     * 加载公钥
     *
     * @param string $publicKey 公钥内容或路径
     * @param bool $isFile 是否为文件路径
     * @return resource 公钥资源
     * @throws PayException
     */
    protected static function loadPublicKey(string $publicKey, bool $isFile)
    {
        if ($isFile) {
            if (!file_exists($publicKey)) {
                throw PayException::configError('公钥文件不存在：' . $publicKey);
            }
            $publicKey = file_get_contents($publicKey);
        }

        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            throw PayException::configError('公钥格式错误，无法加载');
        }

        return $key;
    }
}
