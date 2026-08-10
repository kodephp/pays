<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Stripe;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ProfitSharing\Receiver;

/**
 * Stripe 网关
 *
 * 支持 Stripe Checkout、PaymentIntent、订阅等支付场景。
 * 覆盖全球 40+ 个国家/地区，支持 135+ 种货币。
 */
class StripeGateway extends AbstractGateway implements TransferCapableInterface, SubscriptionCapableInterface, PersonalReceiveCapableInterface, ReconciliationCapableInterface, RefundCapableInterface, ProfitSharingCapableInterface, SettlementCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://api.stripe.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api.stripe.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['secret_key']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * 创建支付订单（PaymentIntent）
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['amount', 'currency']);

        $requestData = [
            'amount' => $params['amount'],
            'currency' => strtolower($params['currency']),
            'automatic_payment_methods[enabled]' => 'true',
            'metadata[out_trade_no]' => $params['out_trade_no'] ?? '',
        ];

        if (isset($params['description'])) {
            $requestData['description'] = $params['description'];
        }

        if (isset($params['customer'])) {
            $requestData['customer'] = $params['customer'];
        }

        if (isset($params['receipt_email'])) {
            $requestData['receipt_email'] = $params['receipt_email'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('v1/payment_intents', $requestData, $headers);
    }

    /**
     * 查询订单（PaymentIntent）
     *
     * @param string $orderId PaymentIntent ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->get("v1/payment_intents/{$orderId}", [], $headers);
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
        $this->validateRequired($params, ['payment_intent']);

        $requestData = [
            'payment_intent' => $params['payment_intent'],
        ];

        if (isset($params['amount'])) {
            $requestData['amount'] = $params['amount'];
        }

        if (isset($params['reason'])) {
            $requestData['reason'] = $params['reason'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('v1/refunds', $requestData, $headers);
    }

    /**
     * 验证异步通知签名（Webhook）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Stripe webhook 头格式：Stripe-Signature: t=xxx,v1=yyy[,v1=zzz]
        $sigHeader = $data['sig_header'] ?? '';
        $payload = $data['payload'] ?? '';
        $secret = $this->getConfig('webhook_secret', $this->getConfig('secret_key', ''));
        if ($sigHeader === '' || $payload === '' || $secret === '') {
            return false;
        }

        // 解析签名头中的 t（时间戳）和 v1（签名）元素
        $elements = explode(',', $sigHeader);
        $timestamp = null;
        $signatures = [];
        foreach ($elements as $element) {
            $parts = explode('=', $element, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't') {
                $timestamp = $parts[1];
            } elseif ($parts[0] === 'v1') {
                $signatures[] = $parts[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }

        // 时间戳容差 5 分钟，防止重放攻击
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        // 计算期望签名：HMAC-SHA256("{timestamp}.{payload}", secret)
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 关闭订单（取消 PaymentIntent）
     *
     * @param string $orderId PaymentIntent ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post("v1/payment_intents/{$orderId}/cancel", [], $headers);
    }

    /**
     * 创建 Checkout 会话
     *
     * @param array<string, mixed> $params 会话参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createCheckoutSession(array $params): array
    {
        $this->validateRequired($params, ['line_items', 'success_url', 'cancel_url']);

        $requestData = [
            'mode' => $params['mode'] ?? 'payment',
            'success_url' => $params['success_url'],
            'cancel_url' => $params['cancel_url'],
        ];

        // 处理 line_items
        foreach ($params['line_items'] as $index => $item) {
            $requestData["line_items[{$index}][price_data][currency]"] = $item['currency'] ?? 'usd';
            $requestData["line_items[{$index}][price_data][unit_amount]"] = $item['amount'];
            $requestData["line_items[{$index}][price_data][product_data][name]"] = $item['name'];
            $requestData["line_items[{$index}][quantity]"] = $item['quantity'] ?? 1;
        }

        if (isset($params['client_reference_id'])) {
            $requestData['client_reference_id'] = $params['client_reference_id'];
        }

        if (isset($params['customer_email'])) {
            $requestData['customer_email'] = $params['customer_email'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('v1/checkout/sessions', $requestData, $headers);
    }

    /**
     * 单笔 Payout（企业付款到 Connect 账户）
     *
     * 复用网关 {@see buildAuthHeaders()} 携带 Bearer Token，金额单位为最小货币单位。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function singleTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['account']);

        return $this->post('v1/payouts', [
            'amount' => (int) $params['amount'],
            'currency' => strtolower($params['currency'] ?? 'usd'),
            'destination' => $recipient['account'],
            'description' => $params['description'] ?? '',
            'metadata' => [
                'out_biz_no' => $params['out_biz_no'],
            ],
        ], $this->buildAuthHeaders());
    }

    /**
     * 批量 Payout
     *
     * Stripe 无原生批量 Payout，逐笔调用 {@see singleTransfer} 后聚合返回。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function batchTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        $list = $params['transfer_detail_list'];
        if (!is_array($list) || empty($list)) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        $results = [];

        foreach ($list as $item) {
            $results[] = $this->singleTransfer([
                'out_biz_no' => $item['out_detail_no'],
                'amount' => $item['amount'],
                'currency' => $item['currency'] ?? 'usd',
                'recipient' => $item['recipient'],
                'description' => $item['remark'] ?? '',
            ]);
        }

        return [
            'out_biz_no' => $params['out_biz_no'],
            'payouts' => $results,
            'count' => count($results),
        ];
    }

    /**
     * 查询 Payout
     *
     * @return array<string, mixed>
     */
    public function queryTransfer(string $outBizNo): array
    {
        return $this->get('v1/payouts', [
            'metadata[out_biz_no]' => $outBizNo,
        ], $this->buildAuthHeaders());
    }

    /**
     * 查询转账电子回单
     *
     * Stripe 不提供电子回单能力，调用即报「无此方法」。
     *
     * @throws PayException
     */
    public function transferReceipt(string $outBizNo): array
    {
        throw PayException::methodNotSupported('stripe', 'transferReceipt');
    }

    /**
     * 创建订阅计划（Stripe Price）
     *
     * 复用网关 {@see buildAuthHeaders()} 携带 Bearer Token，金额单位为最小货币单位。
     *
     * @param array<string, mixed> $params 计划参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createPlan(array $params): array
    {
        $this->validateRequired($params, ['name', 'amount', 'currency', 'interval']);

        return $this->post('v1/prices', [
            'unit_amount' => (int) $params['amount'],
            'currency' => $params['currency'],
            'recurring' => [
                'interval' => $params['interval'],
                'interval_count' => (int) ($params['interval_count'] ?? 1),
            ],
            'product_data' => [
                'name' => $params['name'],
            ],
        ], $this->buildAuthHeaders());
    }

    /**
     * 创建订阅（Stripe Subscription）
     *
     * @param array<string, mixed> $params 订阅参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['customer_id', 'plan_id']);

        return $this->post('v1/subscriptions', [
            'customer' => $params['customer_id'],
            'items' => [
                ['price' => $params['plan_id']],
            ],
            'metadata' => $params['metadata'] ?? [],
        ], $this->buildAuthHeaders());
    }

    /**
     * 取消订阅（Stripe Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->post("v1/subscriptions/{$subscriptionId}", [
            'cancel_at_period_end' => true,
        ], $this->buildAuthHeaders());
    }

    /**
     * 暂停订阅（Stripe Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->post("v1/subscriptions/{$subscriptionId}", [
            'pause_collection' => ['behavior' => 'mark_uncollectible'],
        ], $this->buildAuthHeaders());
    }

    /**
     * 恢复订阅（Stripe Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->post("v1/subscriptions/{$subscriptionId}", [
            'pause_collection' => null,
        ], $this->buildAuthHeaders());
    }

    /**
     * 查询订阅详情（Stripe Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->get("v1/subscriptions/{$subscriptionId}", [], $this->buildAuthHeaders());
    }

    /**
     * 创建个人收款 Payment Link
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $price = $this->post('v1/prices', [
            'unit_amount' => (int) $params['amount'],
            'currency' => strtolower($params['currency'] ?? 'usd'),
            'product_data' => [
                'name' => $params['description'],
            ],
        ], $this->buildAuthHeaders());

        $link = $this->post('v1/payment_links', [
            'line_items' => [
                ['price' => $price['id'], 'quantity' => 1],
            ],
            'metadata' => array_merge(
                $params['attach'] ?? [],
                ['out_trade_no' => 'PERSONAL_' . date('YmdHis')]
            ),
        ], $this->buildAuthHeaders());

        return [
            'out_trade_no' => $link['metadata']['out_trade_no'] ?? '',
            'payment_link' => $link['url'] ?? '',
            'amount' => $params['amount'],
            'description' => $params['description'],
        ];
    }

    /**
     * 查询个人收款记录
     *
     * @param array<string, mixed> $params 查询参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRecords(array $params): array
    {
        $startTime = strtotime($params['start_time'] ?? '-30 days');
        $endTime = strtotime($params['end_time'] ?? 'now');

        return $this->get('v1/payment_intents', [
            'created[gte]' => $startTime,
            'created[lte]' => $endTime,
            'limit' => $params['limit'] ?? 100,
        ], $this->buildAuthHeaders());
    }

    /**
     * 提现到关联银行账户（Stripe Payouts）
     *
     * Stripe 只能把余额打到「已关联到本账户的外部账户」，不接受任意银行卡号；
     * 需指定外部账户时用 `destination` 传 Stripe 的 `ba_xxx` / `card_xxx` ID，
     * 不传则由 Stripe 使用默认外部账户。金额单位为最小货币单位（分）。
     *
     * @param array<string, mixed> $params 提现参数（out_biz_no / amount 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount']);

        $requestData = [
            'amount' => (int) $params['amount'],
            'currency' => strtolower((string) ($params['currency'] ?? $this->getConfig('currency', 'usd'))),
            'metadata' => ['out_biz_no' => (string) $params['out_biz_no']],
        ];

        if (isset($params['destination'])) {
            $requestData['destination'] = (string) $params['destination'];
        }

        if (isset($params['description'])) {
            $requestData['description'] = (string) $params['description'];
        }

        if (isset($params['method'])) {
            $requestData['method'] = (string) $params['method'];
        }

        return $this->post('v1/payouts', $requestData, $this->buildAuthHeaders());
    }

    /**
     * 查询提现结果
     *
     * Stripe 不支持按商户单号反查，默认按 Stripe 打款单号（`po_xxx`）查询；
     * 传 `meta:{out_biz_no}` 时改用列表接口按 metadata 过滤。
     *
     * @param string $outBizNo Stripe payout ID，或 `meta:` 前缀的商户提现单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryWithdraw(string $outBizNo): array
    {
        if (str_starts_with($outBizNo, 'meta:')) {
            $bizNo = substr($outBizNo, 5);

            if ($bizNo === '') {
                throw PayException::paramError('meta: 前缀后缺少商户提现单号');
            }

            return $this->get('v1/payouts', [
                'limit' => 100,
                'metadata[out_biz_no]' => $bizNo,
            ], $this->buildAuthHeaders());
        }

        return $this->get("v1/payouts/{$outBizNo}", [], $this->buildAuthHeaders());
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
        $requestData = [
            'payment_intent' => $params['transaction_id'] ?? '',
            'amount' => (int) $params['refund_fee'],
            'reason' => $this->mapStripeRefundReason($params['refund_desc'] ?? ''),
            'metadata' => [
                'out_refund_no' => $params['out_refund_no'],
            ],
        ];

        return $this->post('v1/refunds', $requestData, [
            'Authorization' => 'Bearer ' . $this->getConfig('secret_key'),
        ]);
    }

    /**
     * 查询退款结果
     *
     * @return array<string, mixed>
     * @throws PayException
     */

    public function queryRefund(string $outRefundNo): array
    {
        return $this->get('v1/refunds', [
            'metadata[out_refund_no]' => $outRefundNo,
        ], [
            'Authorization' => 'Bearer ' . $this->getConfig('secret_key'),
        ]);
    }

    /**
     * 取消退款（仅 Stripe 支持）
     *
     * 先按商户退款单号定位 Stripe refund id，再发起取消。
     *
     * @return array<string, mixed>
     * @throws PayException
     */

    public function cancelRefund(string $outRefundNo): array
    {
        $refunds = $this->queryRefund($outRefundNo);
        $refundId = $refunds['data'][0]['id'] ?? '';

        if ($refundId === '') {
            throw PayException::paramError('未找到对应的 Stripe 退款记录');
        }

        return $this->post("v1/refunds/{$refundId}/cancel", [], [
            'Authorization' => 'Bearer ' . $this->getConfig('secret_key'),
        ]);
    }

    /**
     * 映射 Stripe 退款原因
     */
    protected function mapStripeRefundReason(string $desc): string
    {
        return match (true) {
            str_contains($desc, '欺诈') || str_contains($desc, 'fraud') => 'fraudulent',
            str_contains($desc, '重复') || str_contains($desc, 'duplicate') => 'duplicate',
            str_contains($desc, '请求') || str_contains($desc, 'request') => 'requested_by_customer',
            default => 'requested_by_customer',
        };
    }

    /**
     * 下载 Stripe 交易对账单（Balance Transaction 列表）
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填，格式 YYYYMMDD）
     * @return array<string, mixed> Balance Transaction 列表
     * @throws PayException
     */
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $startTime = strtotime($params['bill_date'] . ' 00:00:00');
        $endTime = strtotime($params['bill_date'] . ' 23:59:59');

        return $this->get('v1/balance_transactions', [
            'created[gte]' => $startTime,
            'created[lte]' => $endTime,
            'limit' => $params['limit'] ?? 100,
        ], [
            'Authorization' => 'Bearer ' . $this->getConfig('secret_key'),
        ]);
    }

    /**
     * 下载 Stripe 资金账单（Stripe 未提供该能力，调用报「无此方法」）
     *
     * @throws PayException
     */
    public function downloadFundFlow(array $params): array
    {
        throw PayException::methodNotSupported('stripe', 'downloadFundFlow');
    }

    /**
     * 解析对账单原始数据（Stripe JSON 格式）
     *
     * @param string $rawData 原始对账单 JSON
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    public function parseBill(string $rawData): array
    {
        return $this->parseStripeBill($rawData);
    }

    /**
     * 解析 Stripe Balance Transaction（JSON 格式）
     *
     * @param string $rawData 原始对账单数据
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    protected function parseStripeBill(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $data = json_decode($rawData, true);

        if (!is_array($data) || !isset($data['data'])) {
            return [];
        }

        return array_map(function (array $item): array {
            return [
                'id' => $item['id'] ?? '',
                'amount' => $item['amount'] ?? 0,
                'currency' => $item['currency'] ?? '',
                'net' => $item['net'] ?? 0,
                'fee' => $item['fee'] ?? 0,
                'status' => $item['status'] ?? '',
                'type' => $item['type'] ?? '',
                'created' => $item['created'] ?? 0,
                'available_on' => $item['available_on'] ?? 0,
                'description' => $item['description'] ?? '',
                'source' => $item['source'] ?? '',
            ];
        }, $data['data']);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'stripe';
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
            throw PayException::gatewayError('Stripe 响应格式异常');
        }

        // Stripe 错误响应
        if (isset($data['error'])) {
            $error = $data['error'];
            throw PayException::gatewayError(
                $error['message'] ?? 'Stripe 业务失败',
                $error['code'] ?? $error['type'] ?? '',
            );
        }

        return $data;
    }

    /**
     * 构建认证请求头
     *
     * @return array<string, string>
     */
    protected function buildAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getConfig('secret_key'),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Stripe-Version' => $this->getConfig('api_version', '2024-06-20'),
        ];
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起 Stripe Transfer 分账
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createProfitSharing(array $params): array
    {
        /** @var array<int, Receiver|array<string, mixed>> $receivers */
        $receivers = $params['receivers'];
        $results = [];

        foreach ($receivers as $receiver) {
            if ($receiver instanceof Receiver) {
                $transferData = $receiver->toStripeArray();
                if (isset($params['transaction_id'])) {
                    $transferData['source_transaction'] = $params['transaction_id'];
                }
            } else {
                $this->validateRequired($receiver, ['account', 'amount']);

                $transferData = [
                    'amount' => (int) $receiver['amount'],
                    'currency' => strtolower($receiver['currency'] ?? 'usd'),
                    'destination' => $receiver['account'],
                ];

                if (isset($receiver['source_transaction'])) {
                    $transferData['source_transaction'] = $receiver['source_transaction'];
                } elseif (isset($params['transaction_id'])) {
                    $transferData['source_transaction'] = $params['transaction_id'];
                }
            }

            $results[] = $this->post('v1/transfers', $transferData, $this->buildAuthHeaders());
        }

        return [
            'out_order_no' => $params['out_order_no'],
            'transfers' => $results,
            'count' => count($results),
        ];
    }

    /**
     * 查询 Stripe Transfer
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        return $this->get('v1/transfers', [
            'metadata[out_order_no]' => $outOrderNo,
        ], $this->buildAuthHeaders());
    }

    /**
     * Stripe 分账回退（创建 Reversal）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function returnProfitSharing(array $params): array
    {
        $transferId = $params['transfer_id'] ?? '';

        if ($transferId === '') {
            throw PayException::paramError('Stripe 分账回退需要提供 transfer_id');
        }

        return $this->post("v1/transfers/{$transferId}/reversals", [
            'amount' => (int) $params['return_amount'],
        ], $this->buildAuthHeaders());
    }

    /**
     * 查询 Stripe Reversal
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        return $this->get('v1/transfer_reversals/' . $outReturnNo, [], $this->buildAuthHeaders());
    }

    /**
     * 解冻 Stripe 未分账的剩余资金
     *
     * Stripe 无冻结概念，Transfer 即时到账。
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选，忽略）
     * @return array<string, mixed>
     */
    #[\Override]
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        return [
            'payment_intent' => $transactionId,
            'status' => 'SUCCESS',
            'message' => 'Stripe 无资金冻结机制，Transfer 即时到账',
        ];
    }

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * Stripe 无平台内钱包余额语义，调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToWallet(array $params): array
    {
        throw PayException::methodNotSupported('stripe', 'settleToWallet');
    }

    /**
     * Stripe 结算到银行卡须经由 Connect 账户 Payout，调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToBankCard(array $params): array
    {
        throw PayException::methodNotSupported('stripe', 'settleToBankCard');
    }

    /**
     * 结算到 Stripe Connect 账户（平台向关联账户划转）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        return $this->post('v1/transfers', [
            'amount' => (int) $params['amount'],
            'currency' => strtolower((string) ($params['currency'] ?? $this->getConfig('currency', 'usd'))),
            'destination' => $params['account'],
            'description' => $params['description'] ?? 'Auto settlement',
            'metadata' => [
                'out_biz_no' => $params['out_biz_no'],
            ],
        ], $this->buildAuthHeaders());
    }

    /**
     * 查询结算结果（按 Transfer ID 查询）
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function querySettlement(string $outBizNo): array
    {
        return $this->get("v1/transfers/{$outBizNo}", [], $this->buildAuthHeaders());
    }
}
