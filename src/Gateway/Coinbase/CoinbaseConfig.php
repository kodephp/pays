<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Coinbase;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Coinbase Commerce 配置 DTO
 *
 * @param string $apiKey Coinbase Commerce API Key
 * @param string $webhookSecret Webhook 签名密钥
 * @param bool $sandbox 是否沙箱模式
 */
readonly class CoinbaseConfig implements ConfigInterface
{
    public function __construct(
        public string $apiKey,
        public string $webhookSecret = '',
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            apiKey: $config['api_key'] ?? '',
            webhookSecret: $config['webhook_secret'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'coinbase';
    }
}
