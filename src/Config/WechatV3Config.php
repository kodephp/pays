<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 微信支付 V3 配置对象
 *
 * @phpstan-consistent-constructor
 */
readonly class WechatV3Config implements ConfigInterface
{
    /**
     * @param string $mchId 商户号
     * @param string $serialNo API 证书序列号
     * @param string $privateKey API 证书私钥（PEM 格式）
     * @param string $apiKey APIv3 密钥（用于加密敏感数据）
     * @param string|null $appId 应用 ID（JSAPI/小程序支付需要）
     * @param bool $sandbox 是否使用沙箱环境
     * @param string|null $jsapiAppId JSAPI / 小程序支付场景的绑定 appid（可选）。
     *        同一商户可同时绑定多个 appid（公众号 / 小程序 / App），而 JSAPI 下单的 appid
     *        必须与 openid 来源一致。设置后作为 JSAPI 场景的默认 appid（仍可被请求级 app_id 覆盖）。
     */
    public function __construct(
        public string $mchId,
        public string $serialNo,
        public string $privateKey,
        public string $apiKey,
        public ?string $appId = null,
        public bool $sandbox = false,
        public ?string $jsapiAppId = null,
    ) {
    }

    /**
     * 从数组创建配置对象
     *
     * @param array<string, mixed> $config
     * @return static
     */
    public static function fromArray(array $config): static
    {
        return new static(
            mchId: $config['mch_id'] ?? '',
            serialNo: $config['serial_no'] ?? '',
            privateKey: $config['private_key'] ?? '',
            apiKey: $config['api_key'] ?? '',
            appId: $config['app_id'] ?? null,
            sandbox: $config['sandbox'] ?? false,
            jsapiAppId: $config['jsapi_app_id'] ?? null,
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'wechat_v3';
    }
}
