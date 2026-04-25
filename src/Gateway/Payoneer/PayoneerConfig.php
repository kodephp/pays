<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Payoneer;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Payoneer 配置 DTO
 *
 * 封装 Payoneer 跨境支付所需的全部配置项。
 */
readonly class PayoneerConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $apiKey API 密钥
     * @param string $apiSecret API 密钥密码
     * @param string $programId 项目 ID
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $apiKey,
        public string $apiSecret,
        public string $programId,
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
            apiSecret: $config['api_secret'] ?? '',
            programId: $config['program_id'] ?? '',
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
        return 'payoneer';
    }
}
