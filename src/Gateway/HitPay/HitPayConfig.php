<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\HitPay;

use Kode\Pays\Contract\ConfigInterface;

/**
 * HitPay 配置 DTO
 *
 * HitPay 是新加坡本地支付聚合平台，支持东南亚多国的本地支付方式。
 * 覆盖新加坡、马来西亚、泰国、印度尼西亚等国家。
 *
 * @param string $apiKey API 密钥
 * @param string $webhookSecret Webhook 签名密钥
 * @param bool $sandbox 是否沙箱模式
 */
readonly class HitPayConfig implements ConfigInterface
{
    public function __construct(
        public string $apiKey,
        public string $webhookSecret = '',
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): static
    {
        return new static(
            apiKey: $config['api_key'] ?? '',
            webhookSecret: $config['webhook_secret'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'hitpay';
    }
}
