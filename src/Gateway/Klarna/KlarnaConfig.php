<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Klarna;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Klarna 配置 DTO
 *
 * 封装 Klarna（先买后付）所需的全部配置项。
 */
readonly class KlarnaConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $username API 用户名
     * @param string $password API 密码
     * @param string $region 区域：eu、us、oc
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $username,
        public string $password,
        public string $region = 'eu',
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
            username: $config['username'] ?? '',
            password: $config['password'] ?? '',
            region: $config['region'] ?? 'eu',
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
        return 'klarna';
    }
}
