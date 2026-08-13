<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Contract\HttpClientInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\HttpClient;

/**
 * 微信公众平台网页授权（OAuth2）助手
 *
 * JSAPI / 公众号支付必须传入「对应该 app_id 的 openid」，而 openid 需通过
 * 微信网页授权（snsapi_base）获取。本助手封装「引导授权 → 用 code 换 openid」
 * 的标准流程，降低接入成本。
 *
 * 开放平台关联说明：当公众号 / 小程序已绑定到同一微信开放平台账号时，
 * 通过 snsapi_userinfo 授权可额外拿到共享的 unionid，便于跨账号识别同一用户。
 *
 * 使用：
 * <code>
 * $oauth = new WechatOauth(['app_id' => 'wx...', 'app_secret' => '...']);
 * // 1) 引导用户访问 $oauth->buildAuthorizeUrl($redirectUri)
 * // 2) 回调中用 $oauth->getOpenId($code) 换取 openid
 * </code>
 */
class WechatOauth
{
    /**
     * 网页授权引导地址
     */
    private const AUTHORIZE_URL = 'https://open.weixin.qq.com/connect/oauth2/authorize';

    /**
     * 用 code 换取 access_token / openid 的接口
     */
    private const ACCESS_TOKEN_URL = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    /**
     * 拉取用户信息的接口
     */
    private const USERINFO_URL = 'https://api.weixin.qq.com/sns/userinfo';

    /**
     * @param array<string, mixed> $config 需包含 app_id、app_secret
     * @param HttpClientInterface|null $httpClient 可选自定义 HTTP 客户端
     */
    public function __construct(
        private array $config,
        private ?HttpClientInterface $httpClient = null,
    ) {
        if (empty($config['app_id']) || empty($config['app_secret'])) {
            throw PayException::configError('WechatOauth 需要配置 app_id 与 app_secret');
        }

        if (!$this->httpClient instanceof HttpClientInterface) {
            $this->httpClient = new HttpClient();
        }
    }

    /**
     * 构建网页授权引导 URL
     *
     * 将用户重定向至此地址，微信会在用户同意后回跳 $redirectUri 并携带 code。
     *
     * @param string $redirectUri 回调地址（需 URL encode 前的原始地址）
     * @param string $scope snsapi_base（仅 openid）或 snsapi_userinfo（含用户信息）
     * @param string $state 原样透传的状态值，可用于防 CSRF
     */
    public function buildAuthorizeUrl(string $redirectUri, string $scope = 'snsapi_base', string $state = ''): string
    {
        $query = http_build_query([
            'appid' => $this->config['app_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ]);

        return self::AUTHORIZE_URL . '?' . $query . '#wechat_redirect';
    }

    /**
     * 用授权 code 换取 openid（及 access_token 等）
     *
     * 返回字段含 openid、access_token、refresh_token、scope，
     * 若公众号已绑定开放平台且 scope=snsapi_userinfo，还会包含 unionid。
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getOpenId(string $code): array
    {
        $query = [
            'appid' => $this->config['app_id'],
            'secret' => $this->config['app_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];

        $data = $this->request(self::ACCESS_TOKEN_URL, $query);

        if (empty($data['openid'])) {
            throw PayException::gatewayError('微信网页授权未返回 openid：' . ($data['errmsg'] ?? '未知错误'));
        }

        return $data;
    }

    /**
     * 拉取用户信息（需 snsapi_userinfo 授权得到的 access_token）
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getUserInfo(string $accessToken, string $openId, string $lang = 'zh_CN'): array
    {
        return $this->request(self::USERINFO_URL, [
            'access_token' => $accessToken,
            'openid' => $openId,
            'lang' => $lang,
        ]);
    }

    /**
     * 调用微信接口并解码 JSON 响应，遇错误码或非法 JSON 抛异常
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws PayException
     */
    private function request(string $url, array $query): array
    {
        $client = $this->httpClient instanceof HttpClientInterface ? $this->httpClient : new HttpClient();
        $body = $client->get($url, $query);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw PayException::gatewayError('微信网页授权响应解析失败：' . mb_substr($body, 0, 200));
        }

        if (isset($data['errcode']) && (int) $data['errcode'] !== 0) {
            throw PayException::gatewayError(
                '微信网页授权失败（' . $data['errcode'] . '）：' . ($data['errmsg'] ?? '未知错误'),
            );
        }

        return $data;
    }
}
