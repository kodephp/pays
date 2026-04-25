<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wise;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Wise 配置 DTO
 *
 * 封装 Wise（原 TransferWise）跨境汇款所需的全部配置项。
 */
readonly class WiseConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $apiKey API 密钥
     * @param string $profileId 个人/企业资料 ID
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $apiKey,
        public string $profileId,
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
            apiKey: $config['api_key'] ?? '',
            profileId: $config['profile_id'] ?? '',
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
        return 'wise';
    }
}
