<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * PayPal 配置对象
 */
readonly class PaypalConfig implements ConfigInterface
{
    /**
     * @param string $clientId PayPal 客户端 ID
     * @param string $clientSecret PayPal 客户端密钥
     * @param bool $sandbox 是否使用沙箱环境
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
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
            clientId: $config['client_id'] ?? '',
            clientSecret: $config['client_secret'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'paypal';
    }
}
