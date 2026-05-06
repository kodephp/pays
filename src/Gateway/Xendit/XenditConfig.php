<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Xendit;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Xendit 配置 DTO
 *
 * Xendit 是东南亚支付聚合平台，支持印度尼西亚、菲律宾、马来西亚、
 * 泰国、越南等国家的多种本地支付方式。
 *
 * @param string $secretKey API 密钥（私钥）
 * @param string $publicKey 公钥（可选，用于前端）
 * @param string $callbackToken Webhook 验证令牌
 * @param bool $sandbox 是否沙箱模式
 */
readonly class XenditConfig implements ConfigInterface
{
    public function __construct(
        public string $secretKey,
        public string $publicKey = '',
        public string $callbackToken = '',
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            secretKey: $config['secret_key'] ?? '',
            publicKey: $config['public_key'] ?? '',
            callbackToken: $config['callback_token'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'xendit';
    }
}
