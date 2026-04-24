<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use Kode\Pays\Core\PayException;

/**
 * 加密工具类
 *
 * 提供支付场景中常用的加密/解密能力，包括 AES、RSA、3DES 等算法。
 * 所有方法均为静态方法，便于直接调用。
 */
class Encryptor
{
    /**
     * AES-256-GCM 加密
     *
     * @param string $plaintext 明文
     * @param string $key 密钥（32字节）
     * @param string|null $aad 附加认证数据（可选）
     * @return array{ ciphertext: string, nonce: string, tag: string } 密文、随机数、认证标签
     * @throws PayException
     */
    public static function aesGcmEncrypt(string $plaintext, string $key, ?string $aad = null): array
    {
        if (strlen($key) !== 32) {
            throw PayException::paramError('AES-256-GCM 密钥必须为 32 字节');
        }

        $nonce = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad ?? '',
            16,
        );

        if ($ciphertext === false) {
            throw PayException::paramError('AES-GCM 加密失败');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * AES-256-GCM 解密
     *
     * @param string $ciphertext 密文（base64）
     * @param string $key 密钥（32字节）
     * @param string $nonce 随机数（base64）
     * @param string $tag 认证标签（base64）
     * @param string|null $aad 附加认证数据（可选）
     * @return string 明文
     * @throws PayException
     */
    public static function aesGcmDecrypt(string $ciphertext, string $key, string $nonce, string $tag, ?string $aad = null): string
    {
        if (strlen($key) !== 32) {
            throw PayException::paramError('AES-256-GCM 密钥必须为 32 字节');
        }

        $plaintext = openssl_decrypt(
            base64_decode($ciphertext),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($nonce),
            base64_decode($tag),
            $aad ?? '',
        );

        if ($plaintext === false) {
            throw PayException::paramError('AES-GCM 解密失败，数据可能已被篡改');
        }

        return $plaintext;
    }

    /**
     * AES-256-ECB 加密（PKCS7 填充，用于微信支付等场景）
     *
     * @param string $plaintext 明文
     * @param string $key 密钥（32字节）
     * @return string 密文（base64）
     * @throws PayException
     */
    public static function aesEcbEncrypt(string $plaintext, string $key): string
    {
        if (strlen($key) !== 32) {
            throw PayException::paramError('AES-256-ECB 密钥必须为 32 字节');
        }

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-ecb', $key, OPENSSL_RAW_DATA);

        if ($ciphertext === false) {
            throw PayException::paramError('AES-ECB 加密失败');
        }

        return base64_encode($ciphertext);
    }

    /**
     * AES-256-ECB 解密（PKCS7 填充）
     *
     * @param string $ciphertext 密文（base64）
     * @param string $key 密钥（32字节）
     * @return string 明文
     * @throws PayException
     */
    public static function aesEcbDecrypt(string $ciphertext, string $key): string
    {
        if (strlen($key) !== 32) {
            throw PayException::paramError('AES-256-ECB 密钥必须为 32 字节');
        }

        $plaintext = openssl_decrypt(base64_decode($ciphertext), 'aes-256-ecb', $key, OPENSSL_RAW_DATA);

        if ($plaintext === false) {
            throw PayException::paramError('AES-ECB 解密失败');
        }

        return $plaintext;
    }

    /**
     * AES-256-CBC 加密
     *
     * @param string $plaintext 明文
     * @param string $key 密钥（32字节）
     * @param string $iv 初始化向量（16字节）
     * @return string 密文（base64）
     * @throws PayException
     */
    public static function aesCbcEncrypt(string $plaintext, string $key, string $iv): string
    {
        if (strlen($key) !== 32) {
            throw PayException::paramError('AES-256-CBC 密钥必须为 32 字节');
        }

        if (strlen($iv) !== 16) {
            throw PayException::paramError('AES-256-CBC IV 必须为 16 字节');
        }

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw PayException::paramError('AES-CBC 加密失败');
        }

        return base64_encode($ciphertext);
    }

    /**
     * AES-256-CBC 解密
     *
     * @param string $ciphertext 密文（base64）
     * @param string $key 密钥（32字节）
     * @param string $iv 初始化向量（16字节）
     * @return string 明文
     * @throws PayException
     */
    public static function aesCbcDecrypt(string $ciphertext, string $key, string $iv): string
    {
        if (strlen($key) !== 32) {
            throw PayException::paramError('AES-256-CBC 密钥必须为 32 字节');
        }

        if (strlen($iv) !== 16) {
            throw PayException::paramError('AES-256-CBC IV 必须为 16 字节');
        }

        $plaintext = openssl_decrypt(base64_decode($ciphertext), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw PayException::paramError('AES-CBC 解密失败');
        }

        return $plaintext;
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
        $success = openssl_public_encrypt($plaintext, $ciphertext, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$success) {
            throw PayException::paramError('RSA 公钥加密失败');
        }

        return base64_encode($ciphertext);
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
        $success = openssl_private_decrypt(base64_decode($ciphertext), $plaintext, $privateKey, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$success) {
            throw PayException::paramError('RSA 私钥解密失败');
        }

        return $plaintext;
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
        $algo = self::getOpensslAlgorithm($algorithm);

        $success = openssl_sign($data, $signature, $privateKey, $algo);

        if (!$success) {
            throw PayException::paramError('RSA 签名失败');
        }

        return base64_encode($signature);
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
        $algo = self::getOpensslAlgorithm($algorithm);

        return openssl_verify($data, base64_decode($signature), $publicKey, $algo) === 1;
    }

    /**
     * 3DES-ECB 加密（部分银行/ legacy 系统使用）
     *
     * @param string $plaintext 明文
     * @param string $key 密钥（24字节）
     * @return string 密文（base64）
     * @throws PayException
     */
    public static function des3Encrypt(string $plaintext, string $key): string
    {
        if (strlen($key) !== 24) {
            throw PayException::paramError('3DES 密钥必须为 24 字节');
        }

        $ciphertext = openssl_encrypt($plaintext, 'des-ede3', $key, OPENSSL_RAW_DATA);

        if ($ciphertext === false) {
            throw PayException::paramError('3DES 加密失败');
        }

        return base64_encode($ciphertext);
    }

    /**
     * 3DES-ECB 解密
     *
     * @param string $ciphertext 密文（base64）
     * @param string $key 密钥（24字节）
     * @return string 明文
     * @throws PayException
     */
    public static function des3Decrypt(string $ciphertext, string $key): string
    {
        if (strlen($key) !== 24) {
            throw PayException::paramError('3DES 密钥必须为 24 字节');
        }

        $plaintext = openssl_decrypt(base64_decode($ciphertext), 'des-ede3', $key, OPENSSL_RAW_DATA);

        if ($plaintext === false) {
            throw PayException::paramError('3DES 解密失败');
        }

        return $plaintext;
    }

    /**
     * 获取 OpenSSL 算法常量
     *
     * @param string $algorithm 算法名称
     * @return int
     * @throws PayException
     */
    protected static function getOpensslAlgorithm(string $algorithm): int
    {
        return match ($algorithm) {
            'sha256' => OPENSSL_ALGO_SHA256,
            'sha1' => OPENSSL_ALGO_SHA1,
            'md5' => OPENSSL_ALGO_MD5,
            default => throw PayException::paramError("不支持的签名算法：{$algorithm}"),
        };
    }
}
