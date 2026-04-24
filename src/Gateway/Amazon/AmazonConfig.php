<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Amazon;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Amazon Pay 配置 DTO
 *
 * 封装 Amazon Pay 所需的全部配置项。
 */
readonly class AmazonConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $merchantId 商户 ID
     * @param string $accessKey 访问密钥 ID
     * @param string $secretKey 密钥
     * @param string $clientId 客户端 ID
     * @param string $region 区域：na、eu、jp
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $merchantId,
        public string $accessKey,
        public string $secretKey,
        public string $clientId,
        public string $region = 'na',
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
            merchantId: $config['merchant_id'] ?? '',
            accessKey: $config['access_key'] ?? '',
            secretKey: $config['secret_key'] ?? '',
            clientId: $config['client_id'] ?? '',
            region: $config['region'] ?? 'na',
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
        return 'amazon';
    }
}
