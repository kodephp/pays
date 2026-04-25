<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Revolut;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Revolut 配置 DTO
 *
 * 封装 Revolut 商户支付所需的全部配置项。
 */
readonly class RevolutConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $apiKey API 密钥
     * @param string $merchantId 商户 ID
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $apiKey,
        public string $merchantId,
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
            apiKey: $config['api_key'] ?? '',
            merchantId: $config['merchant_id'] ?? '',
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
        return 'revolut';
    }
}
