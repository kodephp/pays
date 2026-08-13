<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Contract\HttpClientInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\HttpClient;

/**
 * 微信小程序登录助手
 *
 * 小程序内通过 `wx.login()` 拿到临时登录凭证 code 后，需调用微信
 * `auth.code2Session` 接口换取用户的 openid、session_key（及绑定开放平台时的
 * unionid）。本助手封装该流程，与 {@see WechatOauth}（公众号网页授权）对称，
 * 统一降低接入成本。
 *
 * 开放平台关联说明：当小程序已绑定到同一微信开放平台账号时，响应会携带共享的
 * unionid，便于在公众号 / 小程序 / App 之间识别同一用户。
 *
 * 使用：
 * <code>
 * $mp = new WechatMiniProgram(['app_id' => 'wx...', 'app_secret' => '...']);
 * // 1) 小程序端 wx.login() 取得 code
 * // 2) 服务端用 $mp->code2Session($code) 换取 openid / session_key
 * </code>
 */
class WechatMiniProgram
{
    /**
     * code2Session 接口地址
     */
    private const CODE2SESSION_URL = 'https://api.weixin.qq.com/sns/jscode2session';

    /**
     * @param array<string, mixed> $config 需包含 app_id、app_secret
     * @param HttpClientInterface|null $httpClient 可选自定义 HTTP 客户端
     */
    public function __construct(
        private array $config,
        private ?HttpClientInterface $httpClient = null,
    ) {
        if (empty($config['app_id']) || empty($config['app_secret'])) {
            throw PayException::configError('WechatMiniProgram 需要配置 app_id 与 app_secret');
        }

        if (!$this->httpClient instanceof HttpClientInterface) {
            $this->httpClient = new HttpClient();
        }
    }

    /**
     * 用小程序登录 code 换取 openid / session_key
     *
     * 返回字段含 openid、session_key，若小程序已绑定开放平台还会包含 unionid。
     * session_key 用于服务端解密小程序 `wx.getUserInfo` 等敏感数据，应妥善保管、
     * 不可下发到前端。
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    public function code2Session(string $code): array
    {
        $query = [
            'appid' => $this->config['app_id'],
            'secret' => $this->config['app_secret'],
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ];

        $data = $this->request(self::CODE2SESSION_URL, $query);

        if (empty($data['openid']) || empty($data['session_key'])) {
            throw PayException::gatewayError('微信小程序登录未返回 openid 或 session_key：' . ($data['errmsg'] ?? '未知错误'));
        }

        return $data;
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
            throw PayException::gatewayError('微信小程序登录响应解析失败：' . mb_substr($body, 0, 200));
        }

        if (isset($data['errcode']) && (int) $data['errcode'] !== 0) {
            throw PayException::gatewayError(
                '微信小程序登录失败（' . $data['errcode'] . '）：' . ($data['errmsg'] ?? '未知错误'),
            );
        }

        return $data;
    }
}
