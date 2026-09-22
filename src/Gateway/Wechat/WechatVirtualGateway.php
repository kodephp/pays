<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Contract\VirtualPayCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Signer;

/**
 * 微信小程序虚拟支付网关
 *
 * 对接微信开放平台 xpay 服务端 API（`https://api.weixin.qq.com/xpay/*`），
 * 用于小程序内的虚拟商品交易（会员、课程、道具、代币等），
 * 与商户平台常规支付（{@see WechatPayGateway} / {@see WechatPayV3Gateway}）分属两套独立凭据体系。
 *
 * 协议要点（均经官方文档核对）：
 * - 端点：`POST https://api.weixin.qq.com/xpay/{api}`，Query 携带 `access_token` 与签名，Body 为 JSON
 * - 支付签名 `pay_sig = hex(hmac_sha256(appKey, uri . '&' . requestBody))`，uri 不含 query string
 * - 用户态签名 `signature = hex(hmac_sha256(sessionKey, requestBody))`
 * - **requestBody 必须与请求体逐字节一致**，故本类先编码 JSON 再对该字符串签名，
 *   再将该字符串原样作为请求体发送（{@see AbstractGateway::postRaw()}）
 * - 签名需求分三档：
 *   - 仅 `access_token`：`present_currency` / `notify_provide_goods`
 *   - `access_token` + `pay_sig`：`query_order` / `refund_order` / `download_bill`
 *   - `access_token` + `signature` + `pay_sig`：`query_user_balance` / `currency_pay` / `cancel_currency_pay`
 * - AppKey 按环境二选一：`env=0` 用 `app_key`，`env=1` 用 `sandbox_app_key`
 *
 * 下单说明：微信明确「下单与拉起支付由客户端 `wx.requestVirtualPayment` 完成」，
 * 服务端职责是产出该接口所需的 `signData` 与两套签名，故 {@see self::createOrder()}
 * 返回前端参数而非调用微信支付统一下单。
 */
class WechatVirtualGateway extends AbstractGateway implements VirtualPayCapableInterface
{
    /**
     * 支付模式：道具直购
     */
    public const MODE_GOODS = 'short_series_goods';

    /**
     * 支付模式：代币充值
     */
    public const MODE_COIN = 'short_series_coin';

    /**
     * 客户端接口名，用作支付签名的 uri
     *
     * 官方规定：wx.requestVirtualPayment 场景下 uri 固定为此字符串（不含 `/` 前缀）
     */
    public const CLIENT_SIGN_URI = 'requestVirtualPayment';

    /**
     * 签名需求档：仅 access_token
     */
    public const SIGN_NONE = 'none';

    /**
     * 签名需求档：access_token + pay_sig
     */
    public const SIGN_PAY = 'pay';

    /**
     * 签名需求档：access_token + signature + pay_sig
     */
    public const SIGN_BOTH = 'both';

    /**
     * 退款原因枚举（官方仅支持 0-5）
     *
     * @phpstan-var array<int|string, string>
     */
    public const REFUND_REASONS = [
        '0' => '暂无描述',
        '1' => '产品问题，影响使用或效果不佳',
        '2' => '售后问题，无法满足需求',
        '3' => '意愿问题，用户主动退款',
        '4' => '价格问题',
        '5' => '其他原因',
    ];

    /**
     * 退款来源枚举（官方仅支持 1-3）
     *
     * @phpstan-var array<int|string, string>
     */
    public const REFUND_SOURCES = [
        '1' => '人工客服退款',
        '2' => '用户自己发起退款',
        '3' => '其它',
    ];

    /**
     * 订单状态枚举（Res.order.status）
     *
     * @phpstan-var array<int|string, string>
     */
    public const ORDER_STATUSES = [
        '0' => '订单初始化（未创建成功，不可用于支付）',
        '1' => '订单创建成功',
        '2' => '订单已支付，待发货',
        '3' => '订单发货中',
        '4' => '订单已发货',
        '5' => '订单已经退款',
        '6' => '订单已经关闭（不可再使用）',
        '7' => '订单退款失败',
        '8' => '用户退款完成',
        '9' => '回收广告金完成',
        '10' => '分账回退完成',
    ];

    /**
     * 订单类型枚举（Res.order.order_type）
     *
     * @phpstan-var array<int|string, string>
     */
    public const ORDER_TYPES = [
        '0' => '普通虚拟支付',
        '1' => '普通退款',
        '7' => '苹果iOS支付',
        '8' => '苹果iOS退款',
    ];

    /**
     * 商户订单号合法字符集（8-32 位，不能以下划线开头）
     */
    protected const TRADE_NO_PATTERN = '/^[A-Za-z0-9*|@][A-Za-z0-9_\-*|@]{7,31}$/';

    /**
     * 退款单号合法字符集（8-32 位）
     */
    protected const REFUND_NO_PATTERN = '/^[A-Za-z0-9_\-]{8,31}$/';

    public function createOrder(array $params): array
    {
        $mode = (string) ($params['mode'] ?? self::MODE_GOODS);

        if (!in_array($mode, [self::MODE_GOODS, self::MODE_COIN], true)) {
            throw PayException::paramError(
                "不支持的虚拟支付模式：{$mode}（仅支持 " . self::MODE_GOODS . ' / ' . self::MODE_COIN . '）'
            );
        }

        // 允许调用方自行组装 signData（如代币充值模式），SDK 只负责按官方规则签名
        if (isset($params['sign_data']) && is_string($params['sign_data']) && $params['sign_data'] !== '') {
            $signData = $params['sign_data'];
        } else {
            $signData = json_encode(
                $this->buildSignData($params, $mode),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($signData === false) {
                throw PayException::paramError('signData 序列化失败');
            }
        }

        $sessionKey = $this->resolveSessionKey($params);
        $env = $this->xpayEnv($params['env'] ?? null);

        return [
            'signData' => $signData,
            'paySig' => Signer::hmacSha256Raw(
                self::CLIENT_SIGN_URI . '&' . $signData,
                // 必须与本次下单的 env 取同一把 AppKey，否则微信侧验签失败
                $this->effectiveAppKey($env)
            ),
            'signature' => Signer::hmacSha256Raw($signData, $sessionKey),
            'mode' => $mode,
            'env' => $env,
            'offerId' => (string) ($params['offer_id'] ?? $this->getConfig('offer_id')),
        ];
    }

    public function queryVirtualOrder(string $orderId, array $context = []): array
    {
        if ($orderId === '') {
            throw PayException::paramError('订单号不能为空');
        }

        $payload = [
            'openid' => $this->resolveOpenid($context),
            'env' => $this->xpayEnv($context['env'] ?? null),
        ];

        if (!empty($context['wx_order_id'])) {
            $payload['wx_order_id'] = (string) $context['wx_order_id'];
        } else {
            $payload['order_id'] = $orderId;
        }

        return $this->requestXpay('/xpay/query_order', $payload, self::SIGN_PAY);
    }

    public function refundVirtualOrder(array $params): array
    {
        $refundOrderId = $this->requireString($params, 'refund_order_id');
        if (preg_match(self::REFUND_NO_PATTERN, $refundOrderId) !== 1) {
            throw PayException::paramError(
                'refund_order_id 格式非法：需 8-32 位字母、数字、下划线或连字符'
            );
        }

        if (empty($params['order_id']) && empty($params['wx_order_id'])) {
            throw PayException::paramError('order_id 与 wx_order_id 需至少提供一个（原支付单号）');
        }

        $refundFee = (int) ($params['refund_fee'] ?? 0);
        $leftFee = (int) ($params['left_fee'] ?? -1);

        if ($refundFee < 1) {
            throw PayException::paramError('refund_fee 必须大于 0（单位：分）');
        }

        if ($leftFee < $refundFee) {
            throw PayException::paramError(
                "left_fee 必须不小于 refund_fee（当前 left_fee={$leftFee}, refund_fee={$refundFee}）；"
                . '请通过 query_order 确认剩余可退金额'
            );
        }

        $reason = (string) ($params['refund_reason'] ?? '');
        if (!isset(self::REFUND_REASONS[$reason])) {
            throw PayException::paramError(
                'refund_reason 非法：仅支持 ' . implode(',', array_keys(self::REFUND_REASONS))
            );
        }

        $reqFrom = (string) ($params['req_from'] ?? '');
        if (!isset(self::REFUND_SOURCES[$reqFrom])) {
            throw PayException::paramError(
                'req_from 非法：仅支持 ' . implode(',', array_keys(self::REFUND_SOURCES))
            );
        }

        $payload = [
            'openid' => $this->resolveOpenid($params),
            'order_id' => (string) ($params['order_id'] ?? ''),
            'wx_order_id' => (string) ($params['wx_order_id'] ?? ''),
            'refund_order_id' => $refundOrderId,
            'left_fee' => $leftFee,
            'refund_fee' => $refundFee,
            'biz_meta' => (string) ($params['biz_meta'] ?? ''),
            'refund_reason' => $reason,
            'req_from' => $reqFrom,
            'env' => $this->xpayEnv($params['env'] ?? null),
        ];

        return $this->requestXpay('/xpay/refund_order', $payload, self::SIGN_PAY);
    }

    public function notifyProvideGoods(array $params): array
    {
        if (empty($params['order_id']) && empty($params['wx_order_id'])) {
            throw PayException::paramError('order_id 与 wx_order_id 需至少提供一个');
        }

        $payload = [
            'order_id' => (string) ($params['order_id'] ?? ''),
            'wx_order_id' => (string) ($params['wx_order_id'] ?? ''),
            'env' => $this->xpayEnv($params['env'] ?? null),
        ];

        return $this->requestXpay('/xpay/notify_provide_goods', $payload, self::SIGN_NONE);
    }

    public function queryTokenBalance(string $openid, array $context = []): array
    {
        return $this->requestXpay('/xpay/query_user_balance', [
            'openid' => $openid !== '' ? $openid : $this->resolveOpenid($context),
            'env' => $this->xpayEnv($context['env'] ?? null),
            'user_ip' => (string) ($context['user_ip'] ?? $this->getConfig('user_ip', '')),
        ], self::SIGN_BOTH);
    }

    public function deductTokens(array $params): array
    {
        $amount = (int) ($params['amount'] ?? 0);
        if ($amount < 1) {
            throw PayException::paramError('amount 必须为正整数（代币个数，非金额）');
        }

        if (empty($params['order_id'])) {
            throw PayException::paramError('order_id 必填（本次扣款订单号）');
        }

        return $this->requestXpay('/xpay/currency_pay', [
            'openid' => $this->resolveOpenid($params),
            'env' => $this->xpayEnv($params['env'] ?? null),
            'user_ip' => (string) ($params['user_ip'] ?? $this->getConfig('user_ip', '')),
            'amount' => $amount,
            'order_id' => (string) $params['order_id'],
            'payitem' => $this->encodePayitem($params['payitem'] ?? null),
            'remark' => (string) ($params['remark'] ?? ''),
        ], self::SIGN_BOTH);
    }

    public function refundTokens(array $params): array
    {
        $amount = (int) ($params['amount'] ?? 0);
        if ($amount < 1) {
            throw PayException::paramError('amount 必须为正整数（退款代币个数）');
        }

        if (empty($params['pay_order_id'])) {
            throw PayException::paramError('pay_order_id 必填（原 currency_pay 时传入的 order_id）');
        }

        if (empty($params['order_id'])) {
            throw PayException::paramError('order_id 必填（本次退款单号）');
        }

        return $this->requestXpay('/xpay/cancel_currency_pay', [
            'openid' => $this->resolveOpenid($params),
            'env' => $this->xpayEnv($params['env'] ?? null),
            'user_ip' => (string) ($params['user_ip'] ?? $this->getConfig('user_ip', '')),
            'pay_order_id' => (string) $params['pay_order_id'],
            'order_id' => (string) $params['order_id'],
            'amount' => $amount,
        ], self::SIGN_BOTH);
    }

    public function giftTokens(array $params): array
    {
        $amount = (int) ($params['amount'] ?? 0);
        if ($amount < 1) {
            throw PayException::paramError('amount 必须为正整数（赠送代币个数）');
        }

        if (empty($params['order_id'])) {
            throw PayException::paramError('order_id 必填（赠送单号）');
        }

        return $this->requestXpay('/xpay/present_currency', [
            'openid' => $this->resolveOpenid($params),
            'env' => $this->xpayEnv($params['env'] ?? null),
            'order_id' => (string) $params['order_id'],
            'amount' => $amount,
        ], self::SIGN_NONE);
    }

    public function downloadVirtualBill(array $params): array
    {
        $beginDs = (int) ($params['begin_ds'] ?? 0);
        $endDs = (int) ($params['end_ds'] ?? 0);

        foreach (['begin_ds' => $beginDs, 'end_ds' => $endDs] as $field => $value) {
            if (!preg_match('/^\d{8}$/', (string) $value) || $value < 10000000 || $value > 99999999) {
                throw PayException::paramError("{$field} 格式非法：需 8 位日期整数，形如 20230801");
            }
        }

        if ($beginDs > $endDs) {
            throw PayException::paramError('begin_ds 不能晚于 end_ds');
        }

        return $this->requestXpay('/xpay/download_bill', [
            'begin_ds' => $beginDs,
            'end_ds' => $endDs,
            'env' => $this->xpayEnv($params['env'] ?? null),
        ], self::SIGN_PAY);
    }

    /**
     * 基础契约入口：查询订单（委托 {@see self::queryVirtualOrder()}）
     */
    #[\Override]
    public function queryOrder(string $orderId): array
    {
        return $this->queryVirtualOrder($orderId);
    }

    /**
     * 基础契约入口：申请退款（委托 {@see self::refundVirtualOrder()}）
     */
    #[\Override]
    public function refund(array $params): array
    {
        return $this->refundVirtualOrder($params);
    }

    /**
     * 基础契约入口：查询退款状态
     *
     * 官方明确 `refund_order` 仅启动退款任务，退款最终状态需经 `query_order` 追踪，
     * 且退款单在 xpay 侧同样是 order_type=1 的订单，故此处以退款单号查单。
     */
    #[\Override]
    public function queryRefund(string $refundId): array
    {
        return $this->queryVirtualOrder($refundId);
    }

    /**
     * 基础契约入口：关闭订单
     *
     * 微信虚拟支付**不提供关单接口**（订单关闭由平台按超时/风控自动处理），
     * 故诚实抛「无此方法」，避免伪造成功。
     */
    #[\Override]
    public function closeOrder(string $orderId): array
    {
        throw PayException::methodNotSupported(self::getName(), 'closeOrder');
    }

    /**
     * 验证异步通知签名
     *
     * xpay 推送（`xpay_goods_deliver_notify` / `xpay_coin_pay_notify` /
     * `xpay_refund_notify` / `xpay_complaint_notify`）复用小程序消息推送通道，
     * 其签名经 URL Query 下发（`signature` / `msg_signature` / `timestamp` / `nonce`）。
     *
     * - 明文推送：`signature = sha1(sort([token, timestamp, nonce]))`
     * - AES 推送：`msg_signature = sha1(sort([token, timestamp, nonce, encrypt]))`
     *
     * 未配置 `message_token` 时无法做密码学验签，本方法**诚实返回 false**，
     * 引导调用方以 {@see self::queryVirtualOrder()} 做业务回查兜底，不伪造通过。
     */
    #[\Override]
    public function verifyNotify(array $data): bool
    {
        $token = $this->getConfig('message_token');

        if (!is_string($token) || $token === '') {
            return false;
        }

        $timestamp = (string) ($data['timestamp'] ?? '');
        $nonce = (string) ($data['nonce'] ?? '');

        $encrypt = (string) ($data['encrypt'] ?? '');
        $msgSignature = (string) ($data['msg_signature'] ?? '');

        if ($msgSignature !== '' && $encrypt !== '') {
            $candidate = $msgSignature;
            $parts = [$token, $timestamp, $nonce, $encrypt];
        } else {
            $candidate = (string) ($data['signature'] ?? '');
            $parts = [$token, $timestamp, $nonce];
        }

        if ($candidate === '') {
            return false;
        }

        // 微信消息推送规则：按**字典序**排序后拼接（索引数组必须用 sort，
        // ksort 对数字键不做排序，会静默产出错误签名）
        sort($parts);

        return hash_equals(hash('sha1', implode('', $parts)), $candidate);
    }

    public static function getName(): string
    {
        return 'wechat_virtual';
    }

    protected function getBaseUrl(): string
    {
        return rtrim((string) $this->getConfig('base_url', 'https://api.weixin.qq.com'), '/');
    }

    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw PayException::gatewayError('微信虚拟支付响应解析失败：' . $response);
        }

        $errcode = (int) ($data['errcode'] ?? 0);

        if ($errcode !== 0) {
            throw PayException::gatewayError(
                '微信虚拟支付接口错误 [' . $errcode . ']：' . (string) ($data['errmsg'] ?? '未知错误'),
                (string) $errcode,
                (string) ($data['errmsg'] ?? ''),
            );
        }

        return $data;
    }

    /**
     * 发起 xpay 请求：先编码 JSON、再按原始字节签名、最后原样发送
     *
     * 微信要求 `pay_sig` / `signature` 的参与字节与实际请求体**逐字节一致**，
     * 任何序列化差异都会导致验签失败，故此处统一走 raw body 通道。
     *
     * @param string $endpoint 接口路径，如 `/xpay/query_order`
     * @param array<string, mixed> $payload 请求体字段
     * @param string $signing 签名档：SIGN_NONE / SIGN_PAY / SIGN_BOTH
     * @return array<string, mixed> 响应（errcode 恒为 0）
     */
    protected function requestXpay(string $endpoint, array $payload, string $signing): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw PayException::paramError('请求体序列化失败：' . json_last_error_msg());
        }

        $query = ['access_token' => $this->accessToken()];

        if ($signing === self::SIGN_BOTH) {
            $query['signature'] = Signer::hmacSha256Raw($body, $this->resolveSessionKey($payload));
        }

        if ($signing === self::SIGN_PAY || $signing === self::SIGN_BOTH) {
            $query['pay_sig'] = Signer::hmacSha256Raw(
                $endpoint . '&' . $body,
                // 与请求体内的 env 取同一把 AppKey，否则微信侧验签失败
                $this->effectiveAppKey((int) ($payload['env'] ?? $this->xpayEnv()))
            );
        }

        return $this->postRaw(
            $endpoint . '?' . http_build_query($query),
            $body,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * 获取小程序 access_token
     *
     * 优先使用显式注入的 `access_token`（避免有效期内反复换取触发接口频控）；
     * 否则调用 `/cgi-bin/token` 换取。
     */
    protected function accessToken(): string
    {
        $injected = $this->getConfig('access_token');

        if (is_string($injected) && $injected !== '') {
            return $injected;
        }

        $url = $this->getBaseUrl() . '/cgi-bin/token?' . http_build_query([
            'grant_type' => 'client_credentials',
            'appid' => (string) $this->getConfig('app_id', ''),
            'secret' => (string) $this->getConfig('app_secret', ''),
        ]);

        try {
            $response = $this->httpClient->get($url);
        } catch (\Throwable $e) {
            throw PayException::networkError('获取小程序 access_token 失败：' . $e->getMessage(), $e);
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['access_token']) || (string) $data['access_token'] === '') {
            throw PayException::gatewayError('获取小程序 access_token 失败：' . $response);
        }

        return (string) $data['access_token'];
    }

    /**
     * 按环境取支付签名密钥
     *
     * `env=1`（沙箱）优先取 `sandbox_app_key`，缺省时回落 `app_key`；
     * `env=0`（现网）取 `app_key`。
     */
    protected function effectiveAppKey(?int $env = null): string
    {
        $env = $env ?? $this->xpayEnv();

        if ($env === 1) {
            $sandbox = $this->getConfig('sandbox_app_key');

            if (is_string($sandbox) && $sandbox !== '') {
                return $sandbox;
            }
        }

        $key = $this->getConfig('app_key');

        if (!is_string($key) || $key === '') {
            throw PayException::configError(
                'wechat_virtual 缺少支付签名密钥 app_key'
                . ($env === 1 ? '（沙箱环境建议同时配置 sandbox_app_key）' : '')
            );
        }

        return $key;
    }

    /**
     * 解析 xpay 环境值（0 现网 / 1 沙箱）
     *
     * 优先级：请求级覆盖 > 全局 sandbox 开关 > 配置 env > 默认 0
     */
    protected function xpayEnv(mixed $override = null): int
    {
        if ($override !== null && (is_int($override) || is_bool($override) || is_numeric($override))) {
            return ((int) $override) === 1 ? 1 : 0;
        }

        if ($this->sandbox) {
            return 1;
        }

        $env = $this->getConfig('env', 0);

        return is_numeric($env) && (int) $env === 1 ? 1 : 0;
    }

    /**
     * 解析用户 openid
     *
     * @param array<string, mixed> $context 请求上下文（含可选 openid）
     */
    protected function resolveOpenid(array $context): string
    {
        $value = $context['openid'] ?? $this->getConfig('openid');

        if (!is_string($value) || $value === '') {
            throw PayException::paramError('openid 必填（可通过请求参数或配置 openid 提供）');
        }

        return $value;
    }

    /**
     * 解析用户 session_key（用户态签名密钥）
     *
     * @param array<string, mixed> $context 请求上下文（含可选 session_key）
     */
    protected function resolveSessionKey(array $context): string
    {
        $value = $context['session_key'] ?? $this->getConfig('session_key');

        if (!is_string($value) || $value === '') {
            throw PayException::configError(
                'wechat_virtual 缺少用户态签名密钥 session_key（经 auth.code2Session 获取后配置）'
            );
        }

        return $value;
    }

    /**
     * 组装 wx.requestVirtualPayment 的 signData 字段
     *
     * 字段顺序与官方示例一致（offerId / buyQuantity / env / currencyType /
     * productId / goodsPrice / outTradeNo / attach），以保证 JSON 序列化结果可复现。
     *
     * @param array<string, mixed> $params 下单参数
     * @return array<string, mixed>
     */
    private function buildSignData(array $params, string $mode): array
    {
        $offerId = $this->requireString(
            ['offer_id' => $params['offer_id'] ?? $this->getConfig('offer_id')],
            'offer_id'
        );

        $outTradeNo = $this->requireString(
            ['out_trade_no' => $params['out_trade_no'] ?? $params['outTradeNo'] ?? ''],
            'out_trade_no'
        );

        if (preg_match(self::TRADE_NO_PATTERN, $outTradeNo) !== 1) {
            throw PayException::paramError(
                'out_trade_no 格式非法：需 8-32 位，仅数字、大小写字母、_ - | * @，且不能以下划线开头'
            );
        }

        $currencyType = (string) ($params['currency_type'] ?? 'CNY');

        if ($currencyType !== 'CNY') {
            throw PayException::paramError("虚拟支付当前仅支持币种 CNY，收到：{$currencyType}");
        }

        $buyQuantity = (int) ($params['buy_quantity'] ?? 1);

        if ($buyQuantity < 1) {
            throw PayException::paramError('buy_quantity 必须 >= 1');
        }

        $signData = [
            'offerId' => $offerId,
            'buyQuantity' => $buyQuantity,
            'env' => $this->xpayEnv($params['env'] ?? null),
            'currencyType' => $currencyType,
        ];

        if ($mode === self::MODE_GOODS) {
            $productId = $this->requireString(
                ['product_id' => $params['product_id'] ?? $params['productId'] ?? ''],
                'product_id'
            );

            $goodsPrice = (int) ($params['goods_price'] ?? 0);

            if ($goodsPrice < 1) {
                throw PayException::paramError('goods_price 必填且必须为正整数（单位：分）');
            }

            $signData['productId'] = $productId;
            $signData['goodsPrice'] = $goodsPrice;

            if (isset($params['activity_selling_price'])) {
                $discount = (int) $params['activity_selling_price'];

                if ($discount < 1 || $discount > $goodsPrice) {
                    throw PayException::paramError(
                        'activity_selling_price 需为 [1, goods_price] 区间内的整数（单位：分）'
                    );
                }

                $signData['activitySellingPrice'] = $discount;
            }
        } else {
            throw PayException::paramError(
                '代币充值模式（' . self::MODE_COIN . '）的 signData 结构需按 MP 后台道具配置自行组装，'
                . '请传入 sign_data 字段，SDK 仅负责按官方规则计算 pay_sig / signature'
            );
        }

        $signData['outTradeNo'] = $outTradeNo;
        $signData['attach'] = (string) ($params['attach'] ?? '');

        return $signData;
    }

    /**
     * 校验必填字符串字段
     *
     * @param array<string, mixed> $params 参数集合
     */
    private function requireString(array $params, string $field): string
    {
        $value = $params[$field] ?? '';

        if (!is_string($value) || $value === '') {
            throw PayException::paramError("{$field} 必填");
        }

        return $value;
    }

    /**
     * 归一化 currency_pay 的 payitem 字段
     *
     * 官方约定其值为 JSON 字符串（形如 `[{"productid":"x","unit_price":1,"quantity":1}]`）。
     * 传入数组时统一编码，传入字符串时原样透传。
     */
    private function encodePayitem(mixed $payitem): string
    {
        if ($payitem === null || $payitem === '') {
            return '';
        }

        if (is_string($payitem)) {
            return $payitem;
        }

        if (is_array($payitem)) {
            $encoded = json_encode($payitem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                throw PayException::paramError('payitem 序列化失败：' . json_last_error_msg());
            }

            return $encoded;
        }

        throw PayException::paramError('payitem 需为数组或 JSON 字符串');
    }
}
