<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 支付宝配置对象
 */
readonly class AlipayConfig implements ConfigInterface
{
    /**
     * @param string $appId 支付宝应用 ID
     * @param string $privateKey 应用私钥（RSA 或 RSA2）
     * @param string $publicKey 支付宝公钥
     * @param string|null $appAuthToken 应用授权令牌（第三方授权时使用）
     * @param bool $sandbox 是否使用沙箱环境
     */
    public function __construct(
        public string $appId,
        public string $privateKey,
        public string $publicKey,
        public ?string $appAuthToken = null,
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
            appId: $config['app_id'] ?? '',
            privateKey: $config['private_key'] ?? '',
            publicKey: $config['public_key'] ?? '',
            appAuthToken: $config['app_auth_token'] ?? null,
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'alipay';
    }
}
