<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\AlipayGlobal;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 支付宝国际版配置 DTO
 *
 * 封装支付宝国际版（Alipay+、Alipay Global）所需的全部配置项。
 */
readonly class AlipayGlobalConfig implements ConfigInterface
{
    /**
     * 构造函数
     *
     * @param string $appId 应用 ID
     * @param string $privateKey RSA 私钥
     * @param string $publicKey 支付宝公钥
     * @param string $gatewayUrl 网关地址
     * @param string $signType 签名类型：RSA2、RSA
     * @param bool $sandbox 是否沙箱环境
     */
    public function __construct(
        public string $appId,
        public string $privateKey,
        public string $publicKey,
        public string $gatewayUrl = 'https://globalmapi.alipay.com/gateway.do',
        public string $signType = 'RSA2',
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
            privateKey: $config['private_key'] ?? '',
            publicKey: $config['public_key'] ?? '',
            gatewayUrl: $config['gateway_url'] ?? 'https://globalmapi.alipay.com/gateway.do',
            signType: $config['sign_type'] ?? 'RSA2',
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
        return 'alipay_global';
    }
}
