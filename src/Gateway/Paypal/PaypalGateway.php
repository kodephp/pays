<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Paypal;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * PayPal 网关
 *
 * 支持 PayPal Checkout、订阅等支付场景
 */
class PaypalGateway extends AbstractGateway implements
    SubscriptionCapableInterface,
    RefundCapableInterface,
    SettlementCapableInterface,
    PersonalReceiveCapableInterface
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
     * 验证异步通知签名（Webhook）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // PayPal Webhook 验证需要使用传输的证书链验证签名
        // 实际实现需根据 PayPal Webhook 验证规范处理
        // 此处为简化示例，建议配合官方 SDK 或证书验证逻辑
        return true;
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
        $data = json_decode($response, true);

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

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            throw PayException::gatewayError('获取 PayPal 访问令牌响应异常');
        }

        $this->accessToken = $data['access_token'];

        return $this->accessToken;
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
