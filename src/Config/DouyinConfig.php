<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 抖音支付配置对象
 */
readonly class DouyinConfig implements ConfigInterface
{
    /**
     * @param string $appId 应用 ID
     * @param string $merchantId 商户号
     * @param string $salt 签名盐值
     * @param bool $sandbox 是否使用沙箱环境
     */
    public function __construct(
        public string $appId,
        public string $merchantId,
        public string $salt,
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
            merchantId: $config['merchant_id'] ?? '',
            salt: $config['salt'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'douyin';
    }
}
