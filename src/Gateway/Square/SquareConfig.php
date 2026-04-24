<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Square;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Square 配置对象
 */
readonly class SquareConfig implements ConfigInterface
{
    /**
     * @param string $applicationId 应用 ID
     * @param string $accessToken 访问令牌
     * @param string|null $environment 环境（sandbox/production）
     * @param string $apiVersion API 版本（默认 2024-05-15）
     */
    public function __construct(
        public string $applicationId,
        public string $accessToken,
        public ?string $environment = null,
        public string $apiVersion = '2024-05-15',
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
            applicationId: $config['application_id'] ?? '',
            accessToken: $config['access_token'] ?? '',
            environment: $config['environment'] ?? null,
            apiVersion: $config['api_version'] ?? '2024-05-15',
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'square';
    }
}
