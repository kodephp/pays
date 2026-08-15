<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Paypal;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Contract\QrCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * PayPal 网关
 *
 * 支持 PayPal Checkout、订阅等支付场景
 */
class PaypalGateway extends AbstractGateway implements
    BalanceCapableInterface,
    SubscriptionCapableInterface,
    RefundCapableInterface,
    SettlementCapableInterface,
    PersonalReceiveCapableInterface,
    WebhookCapableInterface,
    QrCapableInterface
{
    /**
     * 沙箱环境基础 URL
     */
    protected const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api-m.paypal.com/';

    /**
     * 访问令牌
     */
    protected ?string $accessToken = null;

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['client_id', 'client_secret']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * 创建支付订单
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['intent', 'purchase_units']);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post('v2/checkout/orders', $params, $headers);
    }

    /**
     * 查询订单
     *
     * @param string $orderId PayPal 订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->get("v2/checkout/orders/{$orderId}", [], $headers);
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function refund(array $params): array
    {
        $this->validateRequired($params, ['capture_id']);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        $captureId = $params['capture_id'];
        unset($params['capture_id']);

        return $this->post("v2/payments/captures/{$captureId}/refund", $params, $headers);
    }

    /**
     * 验证异步通知签名（Webhook，运行时耦合版）
     *
     * PayPal 的签名校验依赖原始请求体、传输头与证书链，无法仅凭「已解析数组」完成，
     * 故此处诚实地返回 false（不可验证），真正的校验请走与运行时解耦的
     * {@see verifyWebhook()}（接收原始请求体 + 请求头 + webhook_id 配置）。
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // 历史遗留占位在 v2.7.0 移除：已解析数组缺请求头/证书链，无法做真实验签，
        // 返回 true 属「伪造通过」；统一改由 verifyWebhook 完成真实证书链校验。
        unset($data);

        return false;
    }

    /**
     * 验证 Webhook 原始请求签名（证书链校验，与运行时解耦）
     *
     * 对齐 PayPal 官方 Webhook 验签规范（证书方案，当前默认）：
     * - 取请求头 PAYPAL-AUTH-ALGO / PAYPAL-CERT-URL / PAYPAL-TRANSMISSION-ID /
     *   PAYPAL-TRANSMISSION-SIG / PAYPAL-TRANSMISSION-TIME；
     * - 签名原文 = transmissionId + "\n" + transmissionTime + "\n" + webhookId + "\n"
     *   （webhookId 来自配置 webhook_id，即你在 PayPal 后台创建 Webhook 时分配的 ID）；
     * - 用 PAYPAL-CERT-URL 指向的公钥证书（按 URL 内存缓存）以 RSA-SHA256 验签。
     * 另含 5 分钟防重放窗口。任何头缺失 / webhook_id 未配置 / 证书不可达均返回 false，
     * 不做「伪造通过」。
     *
     * @param string $payload 原始请求体（JSON 字符串，此处仅用于完整性透传，验签用头信息）
     * @param array<string, string> $headers 请求头（键名大小写不敏感）
     * @return bool 验签是否通过
     */
    #[\Override]
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        $authAlgo = $this->webhookHeader($headers, 'PAYPAL-AUTH-ALGO');
        $certUrl = $this->webhookHeader($headers, 'PAYPAL-CERT-URL');
        $transmissionId = $this->webhookHeader($headers, 'PAYPAL-TRANSMISSION-ID');
        $transmissionSig = $this->webhookHeader($headers, 'PAYPAL-TRANSMISSION-SIG');
        $transmissionTime = $this->webhookHeader($headers, 'PAYPAL-TRANSMISSION-TIME');

        if (
            $authAlgo === '' || $certUrl === '' || $transmissionId === ''
            || $transmissionSig === '' || $transmissionTime === ''
        ) {
            return false;
        }

        $webhookId = $this->getConfig('webhook_id', '');
        if ($webhookId === '') {
            return false;
        }

        // 防重放：签名时间距今超过 5 分钟则拒绝
        $ts = strtotime($transmissionTime);
        if ($ts !== false && abs(time() - $ts) > 300) {
            return false;
        }

        // 签名原文（PayPal 规范：三段以 \n 连接，尾部含 \n）
        $expected = $transmissionId . "\n" . $transmissionTime . "\n" . $webhookId . "\n";

        $pubKey = $this->loadPayPalCertificate($certUrl);
        if ($pubKey === false) {
            return false;
        }

        $signature = base64_decode($transmissionSig, true);
        if ($signature === false) {
            return false;
        }

        $ok = openssl_verify($expected, $signature, $pubKey, OPENSSL_ALGO_SHA256);

        return $ok === 1;
    }

    /**
     * 解析 Webhook 原始请求体为统一事件结构
     *
     * @param string $payload 原始请求体（JSON 字符串）
     * @return array<string, mixed> 统一事件结构
     * @throws PayException 报文非合法 JSON 时
     */
    #[\Override]
    public function parseWebhook(string $payload): array
    {
        $data = $this->decodeJson($payload);

        return [
            'gateway' => 'paypal',
            'event_id' => $data['id'] ?? null,
            'event_type' => $data['event_type'] ?? null,
            'data' => $data,
            'raw' => $payload,
        ];
    }

    /**
     * 按证书 URL 加载 PayPal 公钥证书（内存缓存，避免每次验签重复拉取）
     *
     * PayPal 通过 PAYPAL-CERT-URL 提供 x509 公钥证书（PEM），
     * 用 openssl_pkey_get_public 加载后即可参与 RSA-SHA256 验签。
     *
     * @param string $certUrl 证书地址（来自 PAYPAL-CERT-URL 头）
     * @return \OpenSSLAsymmetricKey|false 加载成功返回公钥资源，否则 false
     */
    protected function loadPayPalCertificate(string $certUrl): \OpenSSLAsymmetricKey|false
    {
        static $cache = [];

        if (isset($cache[$certUrl])) {
            return $cache[$certUrl];
        }

        try {
            $pem = $this->httpClient->get($certUrl);
        } catch (\Throwable $e) {
            return false;
        }

        if (!is_string($pem) || $pem === '') {
            return false;
        }

        $key = openssl_pkey_get_public($pem);
        if ($key !== false) {
            $cache[$certUrl] = $key;
        }

        return $key;
    }

    /**
     * 关闭订单
     *
     * @param string $orderId PayPal 订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post("v2/checkout/orders/{$orderId}/cancel", [], $headers);
    }

    /**
     * 创建订阅计划（PayPal Billing Plan）
     *
     * 先创建 Product，再基于 Product 创建 Plan，金额单位为最小货币单位（分）。
     *
     * @param array<string, mixed> $params 计划参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createPlan(array $params): array
    {
        $this->validateRequired($params, ['name', 'amount', 'currency', 'interval']);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        $product = $this->post('v1/catalogs/products', [
            'name' => $params['name'],
            'type' => 'DIGITAL',
        ], $headers);

        return $this->post('v1/billing/plans', [
            'product_id' => $product['id'],
            'name' => $params['name'],
            'billing_cycles' => [
                [
                    'frequency' => [
                        'interval_unit' => strtoupper($params['interval']),
                        'interval_count' => (int) ($params['interval_count'] ?? 1),
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => number_format($params['amount'] / 100, 2),
                            'currency_code' => strtoupper($params['currency']),
                        ],
                    ],
                ],
            ],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
            ],
        ], $headers);
    }

    /**
     * 创建订阅（PayPal Subscription）
     *
     * @param array<string, mixed> $params 订阅参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['plan_id']);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post('v1/billing/subscriptions', [
            'plan_id' => $params['plan_id'],
            'subscriber' => [
                'name' => [
                    'given_name' => $params['customer_name'] ?? 'Customer',
                ],
                'email_address' => $params['customer_email'] ?? '',
            ],
            'application_context' => [
                'return_url' => $params['return_url'] ?? '',
                'cancel_url' => $params['cancel_url'] ?? '',
            ],
        ], $headers);
    }

    /**
     * 取消订阅（PayPal Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post("v1/billing/subscriptions/{$subscriptionId}/cancel", [
            'reason' => '用户取消',
        ], $headers);
    }

    /**
     * 暂停订阅（PayPal Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post("v1/billing/subscriptions/{$subscriptionId}/suspend", [
            'reason' => '用户暂停',
        ], $headers);
    }

    /**
     * 恢复订阅（PayPal Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post("v1/billing/subscriptions/{$subscriptionId}/activate", [
            'reason' => '用户恢复',
        ], $headers);
    }

    /**
     * 查询订阅详情（PayPal Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getSubscription(string $subscriptionId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->get("v1/billing/subscriptions/{$subscriptionId}", [], $headers);
    }

    /* ==================== 退款能力（RefundCapableInterface） ==================== */

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */

    public function applyRefund(array $params): array
    {
        $captureId = $params['transaction_id'] ?? '';

        if ($captureId === '') {
            throw PayException::paramError('PayPal 退款需要提供 capture_id（transaction_id）');
        }

        $requestData = [
            'amount' => [
                'value' => number_format($params['refund_fee'] / 100, 2),
                'currency_code' => strtoupper($params['currency'] ?? 'USD'),
            ],
            'invoice_id' => $params['out_refund_no'],
            'note_to_payer' => $params['refund_desc'] ?? '',
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->post("v2/payments/captures/{$captureId}/refund", $requestData, $headers);
    }

    /**
     * 查询退款结果
     *
     * @return array<string, mixed>
     * @throws PayException
     */

    public function queryRefund(string $outRefundNo): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->get("v2/payments/refunds/{$outRefundNo}", [], $headers);
    }

    /**
     * 取消退款（PayPal 不支持，统一报「无此方法」）
     *
     * @throws PayException
     */

    public function cancelRefund(string $outRefundNo): array
    {
        throw PayException::methodNotSupported('paypal', 'cancelRefund');
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'paypal';
    }

    /**
     * 解析响应
     *
     * @param string $response JSON 响应字符串
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function parseResponse(string $response): array
    {
        $data = $this->decodeJson($response);

        if (!is_array($data)) {
            throw PayException::gatewayError('PayPal 响应格式异常');
        }

        // PayPal 错误响应包含 error 字段
        if (isset($data['error'])) {
            throw PayException::gatewayError(
                $data['error_description'] ?? 'PayPal 业务失败',
                $data['error'],
            );
        }

        // 业务错误
        if (isset($data['details']) && is_array($data['details'])) {
            $detail = $data['details'][0] ?? [];
            throw PayException::gatewayError(
                $detail['description'] ?? 'PayPal 业务失败',
                $detail['issue'] ?? '',
            );
        }

        return $data;
    }

    /**
     * 获取访问令牌
     *
     * @return string 访问令牌
     * @throws PayException
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $credentials = base64_encode($this->getConfig('client_id') . ':' . $this->getConfig('client_secret'));

        $headers = [
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        try {
            $response = $this->httpClient->post(
                $this->getBaseUrl() . 'v1/oauth2/token',
                ['grant_type' => 'client_credentials'],
                $headers,
            );
        } catch (\Throwable $e) {
            throw PayException::networkError('获取 PayPal 访问令牌失败：' . $e->getMessage(), $e);
        }

        $data = $this->decodeJson($response);

        if (!is_array($data) || !isset($data['access_token'])) {
            throw PayException::gatewayError('获取 PayPal 访问令牌响应异常');
        }

        $this->accessToken = $data['access_token'];

        return $this->accessToken;
    }

    /* ==================== 余额查询能力（BalanceCapableInterface） ==================== */

    /**
     * 查询账户实时余额（PayPal Reporting API）
     *
     * 对齐 PayPal 真实规范：`GET /v1/reporting/balances` 返回各币种余额列表，
     * 每个条目含 `total_balance` 与 `available_balance`（`value` 为十进制主单位字符串，如 "123.45"）。
     * 需将主单位换算为最小货币单位（分）：`(int) round((float) $value * 100)`。
     * 多币种时取首个余额作为可用余额，并保留完整列表于 `raw`。
     *
     * @param array<string, mixed> $params 可选参数：
     *        - currency_code：指定币种（默认不传，返回全部币种）
     * @return array<string, mixed> 含 available_amount（分）/ pending_amount / currency / raw
     * @throws PayException
     */
    #[\Override]
    public function queryBalance(array $params = []): array
    {
        $query = [];
        if (isset($params['currency_code'])) {
            $query['currency_code'] = strtoupper((string) $params['currency_code']);
        }

        $response = $this->get(
            'v1/reporting/balances',
            $query,
            ['Authorization' => 'Bearer ' . $this->getAccessToken()],
        );

        $balances = $response['balances'] ?? [];
        if ($balances === []) {
            throw PayException::gatewayError('PayPal 余额查询无返回数据', 'paypal');
        }

        $primary = $balances[0];
        $total = (float) ($primary['total_balance']['value'] ?? '0');
        $available = (float) ($primary['available_balance']['value'] ?? '0');

        return [
            'available_amount' => (int) round($available * 100),
            'pending_amount' => (int) round(($total - $available) * 100),
            'currency' => $primary['available_balance']['currency_code']
                ?? $primary['total_balance']['currency_code']
                ?? 'USD',
            'raw' => $response,
        ];
    }

    /**
     * 查询日终余额（PayPal Reporting API 支持 as_of_time 时间点）
     *
     * PayPal `/v1/reporting/balances` 支持 `as_of_time`（ISO8601 UTC）时间点快照，
     * 取当日 23:59:59Z 即等价于「日终余额」，与全 SDK `downloadFundFlow` 的 `bill_date` 约定互补。
     *
     * @param string $date 对账日期，格式 YYYY-MM-DD
     * @param array<string, mixed> $params 可选参数：
     *        - currency_code：指定币种（默认不传，返回全部币种）
     * @return array<string, mixed> 含 available_amount（分）/ pending_amount / currency / raw
     * @throws PayException
     */
    #[\Override]
    public function queryDayEndBalance(string $date, array $params = []): array
    {
        $query = ['as_of_time' => $date . 'T23:59:59Z'];
        if (isset($params['currency_code'])) {
            $query['currency_code'] = strtoupper((string) $params['currency_code']);
        }

        $response = $this->get(
            'v1/reporting/balances',
            $query,
            ['Authorization' => 'Bearer ' . $this->getAccessToken()],
        );

        $balances = $response['balances'] ?? [];
        if ($balances === []) {
            throw PayException::gatewayError('PayPal 日终余额查询无返回数据', 'paypal');
        }

        $primary = $balances[0];
        $total = (float) ($primary['total_balance']['value'] ?? '0');
        $available = (float) ($primary['available_balance']['value'] ?? '0');

        return [
            'available_amount' => (int) round($available * 100),
            'pending_amount' => (int) round(($total - $available) * 100),
            'currency' => $primary['available_balance']['currency_code']
                ?? $primary['total_balance']['currency_code']
                ?? 'USD',
            'raw' => $response,
            'day_end_balance' => (int) round($total * 100),
        ];
    }

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * PayPal 无平台内钱包余额划拨语义，调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToWallet(array $params): array
    {
        throw PayException::methodNotSupported('paypal', 'settleToWallet');
    }

    /**
     * PayPal 不支持直接结算到银行卡，调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToBankCard(array $params): array
    {
        throw PayException::methodNotSupported('paypal', 'settleToBankCard');
    }

    /**
     * 结算到 PayPal 收款账户（Payouts 批次）
     *
     * 金额入参统一为最小货币单位（分），此处换算为 PayPal 要求的两位小数金额。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        $outBizNo = (string) $params['out_biz_no'];
        $currency = strtoupper((string) ($params['currency'] ?? $this->getConfig('currency', 'USD')));

        return $this->post('v1/payments/payouts', [
            'sender_batch_header' => [
                'sender_batch_id' => $outBizNo,
                'email_subject' => 'Auto Settlement',
                'email_message' => $params['description'] ?? 'Your payment has been settled.',
            ],
            'items' => [
                [
                    'recipient_type' => $params['recipient_type'] ?? 'EMAIL',
                    'amount' => [
                        'value' => number_format((int) $params['amount'] / 100, 2, '.', ''),
                        'currency' => $currency,
                    ],
                    'receiver' => $params['account'],
                    'sender_item_id' => $outBizNo,
                    'note' => $params['description'] ?? 'Auto settlement',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 查询结算结果（按 Payouts 批次号查询）
     *
     * @param string $outBizNo PayPal 返回的 payout_batch_id
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function querySettlement(string $outBizNo): array
    {
        return $this->get("v1/payments/payouts/{$outBizNo}", [], [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ]);
    }

    /* ==================== 个人收款能力（PersonalReceiveCapableInterface） ==================== */

    /**
     * 生成个人收款二维码（Invoicing 发票二维码）
     *
     * 流程：创建发票草稿 → 发送发票（`auto_send` 为 false 时跳过）→ 生成二维码。
     * 金额入参统一为最小货币单位（分），内部换算为 PayPal 两位小数金额。
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $headers = $this->buildJsonAuthHeaders();
        $outTradeNo = (string) ($params['out_trade_no'] ?? 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999));
        $currency = strtoupper((string) ($params['currency'] ?? $this->getConfig('currency', 'USD')));
        $value = number_format((int) $params['amount'] / 100, 2, '.', '');

        $invoice = $this->post('v2/invoicing/invoices', [
            'detail' => [
                'invoice_number' => $outTradeNo,
                'currency_code' => $currency,
                'note' => (string) $params['description'],
                'reference' => $params['attach'] ?? $outTradeNo,
            ],
            'items' => [
                [
                    'name' => (string) $params['description'],
                    'quantity' => '1',
                    'unit_amount' => [
                        'currency_code' => $currency,
                        'value' => $value,
                    ],
                ],
            ],
        ], $headers);

        $invoiceId = (string) ($invoice['id'] ?? $this->extractInvoiceId($invoice));

        if ($invoiceId === '') {
            throw PayException::gatewayError('PayPal 创建发票未返回发票 ID');
        }

        if (($params['auto_send'] ?? true) !== false) {
            $this->post("v2/invoicing/invoices/{$invoiceId}/send", [
                'send_to_invoicer' => false,
            ], $headers);
        }

        $qr = $this->post("v2/invoicing/invoices/{$invoiceId}/generate-qr-code", [
            'width' => (int) ($params['width'] ?? 400),
            'height' => (int) ($params['height'] ?? 400),
        ], $headers);

        return [
            'out_trade_no' => $outTradeNo,
            'invoice_id' => $invoiceId,
            'qr_code' => $qr['image'] ?? ($qr['qr_code'] ?? ''),
            'amount' => (int) $params['amount'],
            'currency' => $currency,
            'description' => (string) $params['description'],
        ];
    }

    /**
     * 查询个人收款记录（Transaction Search）
     *
     * @param array<string, mixed> $params 查询参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRecords(array $params): array
    {
        $startTime = strtotime((string) ($params['start_time'] ?? '-30 days'));
        $endTime = strtotime((string) ($params['end_time'] ?? 'now'));

        if ($startTime === false || $endTime === false) {
            throw PayException::paramError('start_time / end_time 时间格式无法解析');
        }

        return $this->get('v1/reporting/transactions', [
            'start_date' => gmdate('Y-m-d\TH:i:s\Z', $startTime),
            'end_date' => gmdate('Y-m-d\TH:i:s\Z', $endTime),
            'fields' => $params['fields'] ?? 'all',
            'page_size' => (int) ($params['limit'] ?? 100),
            'page' => (int) ($params['page'] ?? 1),
        ], $this->buildJsonAuthHeaders());
    }

    /**
     * 提现到收款账户（Payouts 批次）
     *
     * PayPal 无「提现到银行卡」开放接口，提现语义为把余额付给指定收款账户
     * （默认按邮箱 EMAIL，可用 `recipient_type` 指定 PHONE / PAYPAL_ID）。
     *
     * @param array<string, mixed> $params 提现参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        $outBizNo = (string) $params['out_biz_no'];
        $currency = strtoupper((string) ($params['currency'] ?? $this->getConfig('currency', 'USD')));
        $note = (string) ($params['description'] ?? 'Personal withdraw');

        return $this->post('v1/payments/payouts', [
            'sender_batch_header' => [
                'sender_batch_id' => $outBizNo,
                'email_subject' => 'Withdraw',
                'email_message' => $note,
            ],
            'items' => [
                [
                    'recipient_type' => $params['recipient_type'] ?? 'EMAIL',
                    'amount' => [
                        'value' => number_format((int) $params['amount'] / 100, 2, '.', ''),
                        'currency' => $currency,
                    ],
                    'receiver' => $params['account'],
                    'sender_item_id' => $outBizNo,
                    'note' => $note,
                ],
            ],
        ], $this->buildJsonAuthHeaders());
    }

    /**
     * 查询提现结果
     *
     * PayPal 仅支持按批次 ID / 明细 ID 查询，不支持按商户单号反查：
     * - 默认按 `payout_batch_id` 查询批次；
     * - 传 `item:{payout_item_id}` 时按单笔明细查询。
     *
     * @param string $outBizNo PayPal 批次 ID，或 `item:` 前缀的明细 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryWithdraw(string $outBizNo): array
    {
        $headers = $this->buildJsonAuthHeaders();

        if (str_starts_with($outBizNo, 'item:')) {
            $itemId = substr($outBizNo, 5);

            if ($itemId === '') {
                throw PayException::paramError('item: 前缀后缺少 payout_item_id');
            }

            return $this->get("v1/payments/payouts-item/{$itemId}", [], $headers);
        }

        return $this->get("v1/payments/payouts/{$outBizNo}", [], $headers);
    }

    /**
     * 构造带 Bearer Token 的 JSON 请求头
     *
     * @return array<string, string>
     * @throws PayException
     */
    protected function buildJsonAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * 从发票创建响应中解析发票 ID
     *
     * PayPal 创建发票可能只返回 `href` 链接，需要从链接尾部提取 ID。
     *
     * @param array<string, mixed> $response
     */
    protected function extractInvoiceId(array $response): string
    {
        $href = '';

        if (isset($response['href']) && is_string($response['href'])) {
            $href = $response['href'];
        } elseif (isset($response['links'][0]['href']) && is_string($response['links'][0]['href'])) {
            $href = $response['links'][0]['href'];
        }

        if ($href === '') {
            return '';
        }

        $segments = explode('/', rtrim($href, '/'));

        return (string) end($segments);
    }
}
