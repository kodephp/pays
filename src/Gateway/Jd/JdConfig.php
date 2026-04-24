<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Jd;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 京东支付配置 DTO
 *
 * 封装京东支付（京东钱包、京东白条）所需的全部配置项。
 */
readonly class JdConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $merchantNo 商户号
     * @param string $desKey DES 密钥
     * @param string $md5Key MD5 密钥
     * @param string $rsaPrivateKey RSA 私钥
     * @param string $rsaPublicKey RSA 公钥
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $merchantNo,
        public string $desKey,
        public string $md5Key,
        public string $rsaPrivateKey,
        public string $rsaPublicKey,
        public bool $sandbox = false,
    ) {
    }

    /**
     * 从数组创建配置实例
     *
     * @param array<string, mixed> $config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            merchantNo: $config['merchant_no'] ?? '',
            desKey: $config['des_key'] ?? '',
            md5Key: $config['md5_key'] ?? '',
            rsaPrivateKey: $config['rsa_private_key'] ?? '',
            rsaPublicKey: $config['rsa_public_key'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     *
     * @return string
     */
    public function getGateway(): string
    {
        return 'jd';
    }
}
