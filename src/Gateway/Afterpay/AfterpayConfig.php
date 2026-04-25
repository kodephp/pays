<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Afterpay;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Afterpay/Clearpay 配置 DTO
 *
 * @param string $merchantId 商户 ID
 * @param string $secretKey API Secret Key
 * @param string $region 区域（AU/US/UK/EU）
 * @param bool $sandbox 是否沙箱模式
 */
readonly class AfterpayConfig implements ConfigInterface
{
    public function __construct(
        public string $merchantId,
        public string $secretKey,
        public string $region = 'US',
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            merchantId: $config['merchant_id'] ?? '',
            secretKey: $config['secret_key'] ?? '',
            region: $config['region'] ?? 'US',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'afterpay';
    }
}
