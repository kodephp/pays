<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Kuaishou;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 快手支付配置 DTO
 *
 * 封装快手支付（快手小程序、快手 App）所需的全部配置项。
 */
readonly class KuaishouConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $appId 快手应用 ID
     * @param string $appSecret 应用密钥
     * @param string $merchantId 商户号
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $appId,
        public string $appSecret,
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
            appId: $config['app_id'] ?? '',
            appSecret: $config['app_secret'] ?? '',
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
        return 'kuaishou';
    }
}
