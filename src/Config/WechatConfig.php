<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 微信支付配置对象
 *
 * 使用 readonly 属性确保配置不可变，增强类型安全。
 */
readonly class WechatConfig implements ConfigInterface
{
    /**
     * @param string $appId 微信公众号/小程序/APP 的 APPID
     * @param string $mchId 微信支付商户号
     * @param string $apiKey API 密钥（v2 接口使用）
     * @param string|null $apiV3Key APIv3 密钥（可选，v3 接口使用）
     * @param string|null $certPath 商户证书路径（退款/转账等敏感操作需要）
     * @param string|null $keyPath 商户证书私钥路径
     * @param string|null $platformCertPath 微信支付平台证书路径（v3 验签使用）
     * @param bool $sandbox 是否使用沙箱环境
     */
    public function __construct(
        public string $appId,
        public string $mchId,
        public string $apiKey,
        public ?string $apiV3Key = null,
        public ?string $certPath = null,
        public ?string $keyPath = null,
        public ?string $platformCertPath = null,
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
            mchId: $config['mch_id'] ?? '',
            apiKey: $config['api_key'] ?? '',
            apiV3Key: $config['api_v3_key'] ?? null,
            certPath: $config['cert_path'] ?? null,
            keyPath: $config['key_path'] ?? null,
            platformCertPath: $config['platform_cert_path'] ?? null,
            sandbox: $config['sandbox'] ?? false,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'wechat';
    }
}
