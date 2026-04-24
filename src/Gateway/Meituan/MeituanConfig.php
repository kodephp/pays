<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Meituan;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 美团支付配置 DTO
 *
 * 封装美团支付所需的全部配置项，使用 readonly 属性确保不可变性。
 */
readonly class MeituanConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $appId 美团应用 ID
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
        return 'meituan';
    }
}
