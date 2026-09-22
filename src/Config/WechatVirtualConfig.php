<?php

declare(strict_types=1);

namespace Kode\Pays\Config;

use Kode\Pays\Contract\ConfigInterface;

/**
 * 微信小程序虚拟支付配置对象
 *
 * 对应网关 `wechat_virtual`，对接微信开放平台 xpay 服务端 API
 * （`https://api.weixin.qq.com/xpay/*`），与商户平台微信支付（`wechat` / `wechat_v3`）
 * 的凭据体系完全独立：本配置使用小程序 `app_id` + `app_secret` 换取 `access_token`，
 * 用虚拟支付 AppKey 做支付签名，用用户 `session_key` 做用户态签名。
 *
 * AppKey 按环境二选一（0 现网 / 1 沙箱），故分别声明 `app_key` 与 `sandbox_app_key`。
 *
 * @phpstan-consistent-constructor
 */
readonly class WechatVirtualConfig implements ConfigInterface
{
    /**
     * @param string $appId 小程序 APPID
     * @param string $appSecret 小程序 AppSecret（用于换取 access_token）
     * @param string $offerId 虚拟支付 OfferId（MP 后台「虚拟支付 → 基础配置」）
     * @param string $appKey 现网 AppKey（env=0 时用于支付签名）
     * @param string|null $sandboxAppKey 沙箱 AppKey（env=1 时用于支付签名）
     * @param int $env 环境：0-现网，1-沙箱
     * @param string|null $sessionKey 用户 session_key（code2session 获得），
     *        用于计算用户态签名 signature；下单时也可在请求级覆盖
     * @param string|null $openid 默认用户 openid（查询/退款/代币类接口需要）
     * @param string|null $userIp 默认用户 IP（代币类接口需要）
     * @param string|null $messageToken 消息推送 Token，用于验签发货/退款推送；
     *        未配置时 verifyNotify() 诚实返回 false，引导调用方以 query_order 兜底回查
     * @param string|null $accessToken 显式注入的 access_token；配置后跳过自动换取
     * @param string $baseUrl xpay 基础域名
     */
    public function __construct(
        public string $appId,
        public string $appSecret,
        public string $offerId,
        public string $appKey,
        public ?string $sandboxAppKey = null,
        public int $env = 0,
        public ?string $sessionKey = null,
        public ?string $openid = null,
        public ?string $userIp = null,
        public ?string $messageToken = null,
        public ?string $accessToken = null,
        public string $baseUrl = 'https://api.weixin.qq.com',
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
            appId: (string) ($config['app_id'] ?? ''),
            appSecret: (string) ($config['app_secret'] ?? ''),
            offerId: (string) ($config['offer_id'] ?? ''),
            appKey: (string) ($config['app_key'] ?? ''),
            sandboxAppKey: $config['sandbox_app_key'] ?? null,
            env: (int) ($config['env'] ?? 0),
            sessionKey: $config['session_key'] ?? null,
            openid: $config['openid'] ?? null,
            userIp: $config['user_ip'] ?? null,
            messageToken: $config['message_token'] ?? null,
            accessToken: $config['access_token'] ?? null,
            baseUrl: (string) ($config['base_url'] ?? 'https://api.weixin.qq.com'),
        );
    }

    /**
     * 获取网关标识
     */
    public function getGateway(): string
    {
        return 'wechat_virtual';
    }
}
