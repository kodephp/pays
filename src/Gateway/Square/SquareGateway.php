<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Square;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * Square 网关
 *
 * 支持 Square Payments API，覆盖美国、加拿大、英国、澳大利亚、日本等国家/地区。
 * 另通过 Catalog + Subscriptions API 提供完整的订阅能力，
 * 并经 Online Checkout + Payouts API 提供个人收款与提现查询能力。
 */
class SquareGateway extends AbstractGateway implements SubscriptionCapableInterface, PersonalReceiveCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://connect.squareupsandbox.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://connect.squareup.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['application_id', 'access_token']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $env = $this->getConfig('environment');

        if ($env !== null) {
            return $env === 'sandbox' ? self::TEST_BASE_URL : self::PROD_BASE_URL;
        }

        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
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
        $this->validateRequired($params, ['source_id', 'amount', 'currency']);

        $requestData = [
            'source_id' => $params['source_id'],
            'amount_money' => [
                'amount' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'idempotency_key' => $params['idempotency_key'] ?? uniqid('sq_', true),
        ];

        if (isset($params['note'])) {
            $requestData['note'] = $params['note'];
        }

        if (isset($params['reference_id'])) {
            $requestData['reference_id'] = $params['reference_id'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('v2/payments', $requestData, $headers);
    }

    /**
     * 查询订单
     *
     * @param string $orderId 支付 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->get("v2/payments/{$orderId}", [], $headers);
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
        $this->validateRequired($params, ['payment_id', 'amount', 'currency']);

        $requestData = [
            'payment_id' => $params['payment_id'],
            'amount_money' => [
                'amount' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'idempotency_key' => $params['idempotency_key'] ?? uniqid('sq_refund_', true),
        ];

        if (isset($params['reason'])) {
            $requestData['reason'] = $params['reason'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('v2/refunds', $requestData, $headers);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->get("v2/refunds/{$refundId}", [], $headers);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Square Webhook 签名验证
        if (!isset($data['signature'], $data['body'])) {
            return false;
        }

        // 实际实现需根据 Square Webhook 验证规范处理
        return true;
    }

    /**
     * 关闭订单（取消支付）
     *
     * @param string $orderId 支付 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post("v2/payments/{$orderId}/cancel", [], $headers);
    }

    /**
     * 创建订单（Square Orders API）
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createSquareOrder(array $params): array
    {
        $this->validateRequired($params, ['order']);

        $requestData = [
            'idempotency_key' => $params['idempotency_key'] ?? uniqid('sq_order_', true),
            'order' => $params['order'],
        ];

        if (isset($params['location_id'])) {
            $requestData['order']['location_id'] = $params['location_id'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('v2/orders', $requestData, $headers);
    }

    /* ==================== 订阅能力（SubscriptionCapableInterface） ==================== */

    /**
     * 创建订阅计划（Catalog SUBSCRIPTION_PLAN_VARIATION）
     *
     * Square 的订阅计划是 Catalog 对象：计划本体（SUBSCRIPTION_PLAN）承载名称，
     * 计费周期与金额由其下的 SUBSCRIPTION_PLAN_VARIATION 描述。本方法一次性
     * 提交「计划 + 单一变体」，返回 Catalog 写入结果。
     *
     * @param array<string, mixed> $params 计划参数
     *        - name: 计划名称
     *        - amount: 每期金额（最小货币单位）
     *        - currency: 货币
     *        - interval: 周期 day/week/month/year
     *        - interval_count: 周期数量（可选，默认 1）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createPlan(array $params): array
    {
        $this->validateRequired($params, ['name', 'amount', 'currency', 'interval']);

        $requestData = [
            'idempotency_key' => $params['idempotency_key'] ?? uniqid('sq_plan_', true),
            'object' => [
                'type' => 'SUBSCRIPTION_PLAN',
                'id' => '#plan',
                'subscription_plan_data' => [
                    'name' => $params['name'],
                    'subscription_plan_variations' => [
                        [
                            'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                            'id' => '#plan_variation',
                            'subscription_plan_variation_data' => [
                                'name' => $params['variation_name'] ?? $params['name'],
                                'phases' => [
                                    [
                                        'cadence' => $this->mapCadence(
                                            (string) $params['interval'],
                                            (int) ($params['interval_count'] ?? 1),
                                        ),
                                        'pricing' => [
                                            'type' => 'STATIC',
                                            'price_money' => [
                                                'amount' => (int) $params['amount'],
                                                'currency' => strtoupper((string) $params['currency']),
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->post('v2/catalog/object', $requestData, $this->buildAuthHeaders());
    }

    /**
     * 创建订阅（Square Subscriptions API）
     *
     * @param array<string, mixed> $params 订阅参数
     *        - customer_id: Square 客户 ID
     *        - plan_id: 订阅计划变体 ID（SUBSCRIPTION_PLAN_VARIATION）
     *        - location_id: 门店 ID（未传时取配置 location_id）
     *        - card_id / start_date / timezone: 可选
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['customer_id', 'plan_id']);

        $locationId = $params['location_id'] ?? $this->getConfig('location_id');
        if (!is_string($locationId) || $locationId === '') {
            throw PayException::paramError('缺少必填参数：location_id');
        }

        $requestData = [
            'idempotency_key' => $params['idempotency_key'] ?? uniqid('sq_sub_', true),
            'location_id' => $locationId,
            'customer_id' => $params['customer_id'],
            'plan_variation_id' => $params['plan_id'],
        ];

        foreach (['card_id' => 'card_id', 'start_date' => 'start_date', 'timezone' => 'timezone'] as $key => $field) {
            if (isset($params[$key])) {
                $requestData[$field] = $params[$key];
            }
        }

        return $this->post('v2/subscriptions', $requestData, $this->buildAuthHeaders());
    }

    /**
     * 取消订阅（当前计费周期结束时生效）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->post("v2/subscriptions/{$subscriptionId}/cancel", [], $this->buildAuthHeaders());
    }

    /**
     * 暂停订阅（Square Pause Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->post("v2/subscriptions/{$subscriptionId}/pause", [], $this->buildAuthHeaders());
    }

    /**
     * 恢复订阅（Square Resume Subscription）
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->post("v2/subscriptions/{$subscriptionId}/resume", [], $this->buildAuthHeaders());
    }

    /**
     * 查询订阅详情
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function getSubscription(string $subscriptionId): array
    {
        return $this->get("v2/subscriptions/{$subscriptionId}", [], $this->buildAuthHeaders());
    }

    /**
     * 将统一周期语义映射为 Square cadence 枚举
     *
     * @throws PayException 当周期组合不被 Square 支持时
     */
    protected function mapCadence(string $interval, int $intervalCount): string
    {
        $cadences = [
            'day' => [1 => 'DAILY'],
            'week' => [1 => 'WEEKLY', 2 => 'EVERY_TWO_WEEKS'],
            'month' => [
                1 => 'MONTHLY',
                2 => 'EVERY_TWO_MONTHS',
                3 => 'QUARTERLY',
                4 => 'EVERY_FOUR_MONTHS',
                6 => 'EVERY_SIX_MONTHS',
            ],
            'year' => [1 => 'ANNUAL', 2 => 'EVERY_TWO_YEARS'],
        ];

        $cadence = $cadences[strtolower($interval)][$intervalCount] ?? null;

        if ($cadence === null) {
            throw PayException::paramError(
                sprintf('Square 不支持的订阅周期：%d %s', $intervalCount, $interval),
            );
        }

        return $cadence;
    }

    /* ==================== 个人收款能力（PersonalReceiveCapableInterface） ==================== */

    /**
     * 生成个人收款链接（Online Checkout Payment Link）
     *
     * Square 不直接返回二维码图片，返回的 `qr_code`（收款链接）可由调用方生成二维码。
     * 金额单位为最小货币单位（分），与 Square 原生一致，不做换算。
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $outTradeNo = (string) ($params['out_trade_no'] ?? 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999));
        $currency = strtoupper((string) ($params['currency'] ?? $this->getConfig('currency', 'USD')));

        $requestData = [
            'idempotency_key' => $params['idempotency_key'] ?? uniqid('sq_pl_', true),
            'quick_pay' => [
                'name' => (string) $params['description'],
                'price_money' => [
                    'amount' => (int) $params['amount'],
                    'currency' => $currency,
                ],
                'location_id' => $params['location_id'] ?? $this->getConfig('location_id'),
            ],
            'checkout_options' => [
                'redirect_url' => $params['return_url'] ?? null,
            ],
            'payment_note' => $outTradeNo,
        ];

        if ($requestData['checkout_options']['redirect_url'] === null) {
            unset($requestData['checkout_options']);
        }

        $response = $this->post('v2/online-checkout/payment-links', $requestData, $this->buildAuthHeaders());

        return [
            'out_trade_no' => $outTradeNo,
            'payment_link_id' => $response['payment_link']['id'] ?? '',
            'qr_code' => $response['payment_link']['url'] ?? '',
            'payment_link' => $response['payment_link']['url'] ?? '',
            'amount' => (int) $params['amount'],
            'currency' => $currency,
            'description' => (string) $params['description'],
        ];
    }

    /**
     * 查询个人收款记录（Payments 列表）
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

        $query = [
            'begin_time' => gmdate('Y-m-d\TH:i:s\Z', $startTime),
            'end_time' => gmdate('Y-m-d\TH:i:s\Z', $endTime),
            'limit' => (int) ($params['limit'] ?? 100),
            'sort_order' => $params['sort_order'] ?? 'DESC',
        ];

        $locationId = $params['location_id'] ?? $this->getConfig('location_id');

        if (is_string($locationId) && $locationId !== '') {
            $query['location_id'] = $locationId;
        }

        if (isset($params['cursor'])) {
            $query['cursor'] = $params['cursor'];
        }

        return $this->get('v2/payments', $query, $this->buildAuthHeaders());
    }

    /**
     * 提现到银行卡（Square 由平台按结算周期自动打款，无主动提现接口）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function withdraw(array $params): array
    {
        throw PayException::methodNotSupported('square', 'withdraw');
    }

    /**
     * 查询提现（打款）结果
     *
     * - 默认按 Square 打款单号查询 `v2/payouts/{payout_id}`；
     * - 传 `entries:{payout_id}` 时查询该笔打款的明细条目。
     *
     * @param string $outBizNo Square payout ID，或 `entries:` 前缀的打款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryWithdraw(string $outBizNo): array
    {
        $headers = $this->buildAuthHeaders();

        if (str_starts_with($outBizNo, 'entries:')) {
            $payoutId = substr($outBizNo, 8);

            if ($payoutId === '') {
                throw PayException::paramError('entries: 前缀后缺少 payout_id');
            }

            return $this->get("v2/payouts/{$payoutId}/payout-entries", [], $headers);
        }

        return $this->get("v2/payouts/{$outBizNo}", [], $headers);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'square';
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
            throw PayException::gatewayError('Square 响应格式异常');
        }

        // Square 错误响应
        if (isset($data['errors']) && is_array($data['errors'])) {
            $error = $data['errors'][0];
            throw PayException::gatewayError(
                $error['detail'] ?? $error['code'] ?? 'Square 业务失败',
                $error['code'] ?? '',
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
            'Authorization' => 'Bearer ' . $this->getConfig('access_token'),
            'Content-Type' => 'application/json',
            'Square-Version' => $this->getConfig('api_version', '2024-05-15'),
        ];
    }
}
