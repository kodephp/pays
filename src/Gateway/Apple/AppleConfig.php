<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Apple;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Apple Pay 配置 DTO
 *
 * 封装 Apple Pay 所需的全部配置项。
 */
readonly class AppleConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $merchantIdentifier 商户标识符
     * @param string $merchantCertificate 商户证书（PEM 格式）
     * @param string $merchantCertificateKey 商户证书私钥
     * @param string $applePayMerchantId 苹果分配的商户 ID
     * @param string $domainName 域名
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $merchantIdentifier,
        public string $merchantCertificate,
        public string $merchantCertificateKey,
        public string $applePayMerchantId,
        public string $domainName,
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
            merchantIdentifier: $config['merchant_identifier'] ?? '',
            merchantCertificate: $config['merchant_certificate'] ?? '',
            merchantCertificateKey: $config['merchant_certificate_key'] ?? '',
            applePayMerchantId: $config['apple_pay_merchant_id'] ?? '',
            domainName: $config['domain_name'] ?? '',
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
        return 'apple';
    }
}
