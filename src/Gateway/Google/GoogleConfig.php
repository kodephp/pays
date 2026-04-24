<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Google;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Google Pay 配置 DTO
 *
 * 封装 Google Pay 所需的全部配置项。
 */
readonly class GoogleConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $merchantId 商户 ID
     * @param string $merchantName 商户名称
     * @param string $gatewayMerchantId 网关商户 ID
     * @param string $environment 环境：TEST 或 PRODUCTION
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $merchantId,
        public string $merchantName,
        public string $gatewayMerchantId,
        public string $environment = 'TEST',
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
            merchantId: $config['merchant_id'] ?? '',
            merchantName: $config['merchant_name'] ?? '',
            gatewayMerchantId: $config['gateway_merchant_id'] ?? '',
            environment: $config['environment'] ?? 'TEST',
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
        return 'google';
    }
}
