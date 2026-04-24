<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Adyen;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Adyen 配置对象
 */
readonly class AdyenConfig implements ConfigInterface
{
    /**
     * @param string $apiKey API 密钥
     * @param string $merchantAccount 商户账户名
     * @param string|null $clientKey 客户端密钥（Web Drop-in 使用）
     * @param string $environment 环境（test/live）
     */
    public function __construct(
        public string $apiKey,
        public string $merchantAccount,
        public ?string $clientKey = null,
        public string $environment = 'test',
    ) {
    }

    /**
     * 从数组创建配置对象
     *
     * @param array<string, mixed> $config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            apiKey: $config['api_key'] ?? '',
            merchantAccount: $config['merchant_account'] ?? '',
            clientKey: $config['client_key'] ?? null,
            environment: $config['environment'] ?? 'test',
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'adyen';
    }
}
