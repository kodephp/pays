<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Core\PayException;

/**
 * kode/tools 加解密集成适配器
 *
 * 当项目中安装了 kode/tools 时，使用其提供的加解密能力，
 * 否则降级到 SDK 内置的 Encryptor 工具类。
 */
class KodeToolsCryptoAdapter
{
    /**
     * AES-256-GCM 加密
     *
     * @param string $plaintext 明文
     * @param string $key 密钥（32字节）
     * @param string|null $aad 附加认证数据
     * @return array{ ciphertext: string, nonce: string, tag: string }
     * @throws PayException
     */
    public static function aesGcmEncrypt(string $plaintext, string $key, ?string $aad = null): array
    {
        if (class_exists(\Kode\Tools\Crypto\Aes::class)) {
            return \Kode\Tools\Crypto\Aes::gcmEncrypt($plaintext, $key, $aad);
        }

        return \Kode\Pays\Support\Encryptor::aesGcmEncrypt($plaintext, $key, $aad);
    }

    /**
     * AES-256-GCM 解密
     *
     * @param string $ciphertext 密文（base64）
     * @param string $key 密钥（32字节）
     * @param string $nonce 随机数（base64）
     * @param string $tag 认证标签（base64）
     * @param string|null $aad 附加认证数据
     * @return string 明文
     * @throws PayException
     */
    public static function aesGcmDecrypt(string $ciphertext, string $key, string $nonce, string $tag, ?string $aad = null): string
    {
        if (class_exists(\Kode\Tools\Crypto\Aes::class)) {
            return \Kode\Tools\Crypto\Aes::gcmDecrypt($ciphertext, $key, $nonce, $tag, $aad);
        }

        return \Kode\Pays\Support\Encryptor::aesGcmDecrypt($ciphertext, $key, $nonce, $tag, $aad);
    }

    /**
     * AES-256-ECB 加密
     *
     * @param string $plaintext 明文
     * @param string $key 密钥（32字节）
     * @return string 密文（base64）
     * @throws PayException
     */
    public static function aesEcbEncrypt(string $plaintext, string $key): string
    {
        if (class_exists(\Kode\Tools\Crypto\Aes::class)) {
            return \Kode\Tools\Crypto\Aes::ecbEncrypt($plaintext, $key);
        }

        return \Kode\Pays\Support\Encryptor::aesEcbEncrypt($plaintext, $key);
    }

    /**
     * AES-256-ECB 解密
     *
     * @param string $ciphertext 密文（base64）
     * @param string $key 密钥（32字节）
     * @return string 明文
     * @throws PayException
     */
    public static function aesEcbDecrypt(string $ciphertext, string $key): string
    {
        if (class_exists(\Kode\Tools\Crypto\Aes::class)) {
            return \Kode\Tools\Crypto\Aes::ecbDecrypt($ciphertext, $key);
        }

        return \Kode\Pays\Support\Encryptor::aesEcbDecrypt($ciphertext, $key);
    }

    /**
     * RSA 公钥加密
     *
     * @param string $plaintext 明文
     * @param string $publicKey PEM 格式公钥
     * @return string 密文（base64）
     * @throws PayException
     */
    public static function rsaEncrypt(string $plaintext, string $publicKey): string
    {
        if (class_exists(\Kode\Tools\Crypto\Rsa::class)) {
            return \Kode\Tools\Crypto\Rsa::encrypt($plaintext, $publicKey);
        }

        return \Kode\Pays\Support\Encryptor::rsaEncrypt($plaintext, $publicKey);
    }

    /**
     * RSA 私钥解密
     *
     * @param string $ciphertext 密文（base64）
     * @param string $privateKey PEM 格式私钥
     * @return string 明文
     * @throws PayException
     */
    public static function rsaDecrypt(string $ciphertext, string $privateKey): string
    {
        if (class_exists(\Kode\Tools\Crypto\Rsa::class)) {
            return \Kode\Tools\Crypto\Rsa::decrypt($ciphertext, $privateKey);
        }

        return \Kode\Pays\Support\Encryptor::rsaDecrypt($ciphertext, $privateKey);
    }

    /**
     * RSA 私钥签名
     *
     * @param string $data 待签名数据
     * @param string $privateKey PEM 格式私钥
     * @param string $algorithm 签名算法（sha256/sha1/md5）
     * @return string 签名值（base64）
     * @throws PayException
     */
    public static function rsaSign(string $data, string $privateKey, string $algorithm = 'sha256'): string
    {
        if (class_exists(\Kode\Tools\Crypto\Rsa::class)) {
            return \Kode\Tools\Crypto\Rsa::sign($data, $privateKey, $algorithm);
        }

        return \Kode\Pays\Support\Encryptor::rsaSign($data, $privateKey, $algorithm);
    }

    /**
     * RSA 公钥验签
     *
     * @param string $data 原始数据
     * @param string $signature 签名值（base64）
     * @param string $publicKey PEM 格式公钥
     * @param string $algorithm 签名算法（sha256/sha1/md5）
     * @return bool
     * @throws PayException
     */
    public static function rsaVerify(string $data, string $signature, string $publicKey, string $algorithm = 'sha256'): bool
    {
        if (class_exists(\Kode\Tools\Crypto\Rsa::class)) {
            return \Kode\Tools\Crypto\Rsa::verify($data, $signature, $publicKey, $algorithm);
        }

        return \Kode\Pays\Support\Encryptor::rsaVerify($data, $signature, $publicKey, $algorithm);
    }

    /**
     * 生成随机密钥
     *
     * @param int $length 密钥长度（字节）
     * @return string
     */
    public static function randomKey(int $length = 32): string
    {
        if (class_exists(\Kode\Tools\Crypto\Crypto::class)) {
            return \Kode\Tools\Crypto\Crypto::randomBytes($length);
        }

        return random_bytes($length);
    }

    /**
     * 判断是否支持 kode/tools 加解密
     */
    public static function isSupported(): bool
    {
        return class_exists(\Kode\Tools\Crypto\Aes::class)
            || class_exists(\Kode\Tools\Crypto\Rsa::class);
    }
}
