<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Stripe;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Stripe 配置对象
 */
readonly class StripeConfig implements ConfigInterface
{
    /**
     * @param string $secretKey Stripe 密钥（sk_test_xxx 或 sk_live_xxx）
     * @param string|null $publishableKey 可发布密钥（客户端使用，可选）
     * @param string|null $webhookSecret Webhook 签名密钥（可选）
     * @param string $apiVersion API 版本（默认 2024-06-20）
     * @param bool $sandbox 是否使用测试环境
     */
    public function __construct(
        public string $secretKey,
        public ?string $publishableKey = null,
        public ?string $webhookSecret = null,
        public string $apiVersion = '2024-06-20',
        public bool $sandbox = false,
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
            secretKey: $config['secret_key'] ?? '',
            publishableKey: $config['publishable_key'] ?? null,
            webhookSecret: $config['webhook_secret'] ?? null,
            apiVersion: $config['api_version'] ?? '2024-06-20',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'stripe';
    }
}
