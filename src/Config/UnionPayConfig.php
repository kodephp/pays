<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 云闪付配置对象
 */
readonly class UnionPayConfig implements ConfigInterface
{
    /**
     * @param string $merId 商户号
     * @param string $certPath 证书路径
     * @param string $certPwd 证书密码
     * @param bool $sandbox 是否使用沙箱环境
     */
    public function __construct(
        public string $merId,
        public string $certPath,
        public string $certPwd,
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
            merId: $config['mer_id'] ?? '',
            certPath: $config['cert_path'] ?? '',
            certPwd: $config['cert_pwd'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'unionpay';
    }
}
