<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Xendit;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Exception\GatewayException;

/**
 * Xendit 网关
 *
 * Xendit 是东南亚领先的支付聚合平台，覆盖印度尼西亚、菲律宾、马来西亚、
 * 泰国、越南等国家，支持多种本地支付方式。
 *
 * 支持支付方式：
 * - 信用卡/借记卡（Visa、MasterCard、JCB、AMEX）
 * - 虚拟账户（印尼 BCA、Mandiri、BNI、BRI 等）
 * - 电子钱包（GoPay、OVO、DANA、ShopeePay、GrabPay 等）
 * - 便利店支付（Indomaret、Alfamart）
 * - QRIS（印尼统一二维码）
 * - 直接借记
 * - 分期付款
 *
 * 使用示例：
 * ```php
 * $gateway = Pay::xendit([
 *     'secret_key' => 'xnd_development_your_secret_key',
 *     'callback_token' => 'your_callback_token',
 * ]);
 *
 * // 创建发票（通用支付）
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 100000,
 *     'currency' => 'IDR',
 *     'description' => '商品购买',
 *     'payment_methods' => ['CARD', 'BANK_TRANSFER', 'EWALLET'],
 *     'success_redirect_url' => 'https://example.com/success',
 *     'failure_redirect_url' => 'https://example.com/failure',
 * ]);
 *
 * // 获取支付链接
 * $paymentUrl = $result['invoice_url'];
 * ```
 */
class XenditGateway extends AbstractGateway implements BalanceCapableInterface, WebhookCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://api.xendit.co/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api.xendit.co/';

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
        return self::PROD_BASE_URL;
    }

    /**
     * 创建支付订单（Invoice API）
     *
     * @param array<string, mixed> $params 订单参数
     *        - out_trade_no: 商户订单号
     *        - total_amount: 订单金额（单位：最小货币单位）
     *        - currency: 货币代码（IDR/PHP/MYR/THB/VND）
     *        - description: 订单描述
     *        - payment_methods: 支付方式列表
     *        - success_redirect_url: 支付成功回调
     *        - failure_redirect_url: 支付失败回调
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount']);

        $requestData = [
            'external_id' => $params['out_trade_no'],
            'amount' => $params['total_amount'],
            'description' => $params['description'] ?? '商品购买',
            'currency' => $params['currency'] ?? 'IDR',
        ];

        if (!empty($params['success_redirect_url'])) {
            $requestData['success_redirect_url'] = $params['success_redirect_url'];
        }

        if (!empty($params['failure_redirect_url'])) {
            $requestData['failure_redirect_url'] = $params['failure_redirect_url'];
        }

        if (!empty($params['payment_methods'])) {
            $requestData['payment_methods'] = $params['payment_methods'];
        }

        if (!empty($params['customer'])) {
            $requestData['customer'] = $params['customer'];
        }

        if (!empty($params['customer_email'])) {
            $requestData['customer_email'] = $params['customer_email'];
        }

        $headers = $this->resolveHeader();

        return $this->post('v2/invoices', $requestData, $headers);
    }

    /**
     * 查询订单
     *
     * @param string $orderId Invoice ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = $this->resolveHeader();

        return $this->get("v2/invoices/{$orderId}", [], $headers);
    }

    /**
     * 关闭订单（过期发票）
     *
     * @param string $orderId Invoice ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = $this->resolveHeader();

        return $this->post("invoices/{$orderId}/expire!", [], $headers);
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     *        - invoice_id: 发票 ID
     *        - refund_amount: 退款金额
     *        - refund_reason: 退款原因
     * @return array<string, mixed>
     * @throws PayException
     */
    public function refund(array $params): array
    {
        $this->validateRequired($params, ['invoice_id', 'refund_amount']);

        $headers = $this->resolveHeader();

        $responseData = $this->post('refunds', [
            'invoice_id' => $params['invoice_id'],
            'amount' => $params['refund_amount'],
            'reason' => $params['refund_reason'] ?? '',
        ], $headers);

        return [
            'refund_id' => $responseData['id'] ?? '',
            'invoice_id' => $params['invoice_id'],
            'amount' => $params['refund_amount'],
            'status' => $responseData['status'] ?? '',
            'created_at' => $responseData['created'] ?? '',
        ];
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
        $headers = $this->resolveHeader();

        return $this->get("refunds/{$refundId}", [], $headers);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        $callbackToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';

        if ($callbackToken === '') {
            return false;
        }

        return hash_equals($callbackToken, $this->config['callback_token'] ?? '');
    }

    /**
     * 验证 Webhook 原始请求签名（与运行时解耦版本）
     *
     * 复用 {@see verifyNotify()} 的 X-Callback-Token 令牌比对逻辑，接收原始报文与请求头。
     *
     * @param string $payload 原始请求体（令牌比对不依赖报文内容，仅作占位入参）
     * @param array<string, string> $headers 请求头（含 X-Callback-Token）
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        $callbackToken = $this->webhookHeader($headers, 'X-Callback-Token');
        if ($callbackToken === '') {
            return false;
        }

        return hash_equals($callbackToken, $this->config['callback_token'] ?? '');
    }

    /**
     * 解析 Webhook 原始请求体为统一事件结构
     *
     * @param string $payload 原始请求体（JSON 字符串）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function parseWebhook(string $payload): array
    {
        $data = $this->decodeJson($payload);

        return [
            'gateway' => 'xendit',
            'event_id' => $data['id'] ?? null,
            'event_type' => $data['event_type'] ?? $data['type'] ?? 'unknown',
            'data' => $data,
            'raw' => $payload,
        ];
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'xendit';
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
            throw new GatewayException('Xendit 响应格式异常');
        }

        if (isset($data['error_code'])) {
            throw new GatewayException(
                $data['message'] ?? 'Xendit 业务失败',
                $data['error_code'] ?? 'XENDIT_ERROR',
            );
        }

        return $data;
    }

    /* ==================== 余额查询能力（BalanceCapableInterface） ==================== */

    /**
     * 查询账户实时余额
     *
     * 对齐 Xendit 真实余额规范：`GET /balance` 返回账户当前余额（整数，已为账户币种最小单位），
     * 例如 IDR 下返回 `balance` 即印尼盾分。Xendit 余额接口无独立币种字段，币种取自配置 `currency`。
     *
     * @param array<string, mixed> $params 可选参数（Xendit 余额接口无需额外业务参数）
     * @return array<string, mixed> 含 available_amount（分）/ pending_amount / currency / raw
     * @throws PayException
     */
    #[\Override]
    public function queryBalance(array $params = []): array
    {
        $response = $this->get('balance', [], $this->resolveHeader());

        return [
            'available_amount' => (int) ($response['balance'] ?? 0),
            'pending_amount' => 0,
            'currency' => strtoupper((string) ($this->getConfig('currency', 'IDR'))),
            'raw' => $response,
        ];
    }

    /**
     * 查询日终余额
     *
     * Xendit 未提供按日期的「日终余额」接口，`/balance` 仅返回实时余额，故本方法不支持；
     * 如需历史资金快照请结合 `downloadBill` 对账。
     *
     * @param string $date 对账日期，格式 YYYY-MM-DD
     * @param array<string, mixed> $params 可选参数
     * @throws PayException
     */
    #[\Override]
    public function queryDayEndBalance(string $date, array $params = []): array
    {
        throw PayException::methodNotSupported('xendit', 'queryDayEndBalance');
    }

    /**
     * 解析请求头
     *
     * @return array<string, mixed>
     */
    protected function resolveHeader(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->config['secret_key'] . ':'),
            'Content-Type' => 'application/json',
            'api-version' => '2022-01-01',
        ];
    }

    /**
     * 创建虚拟账户支付
     *
     * @param array<string, mixed> $params 虚拟账户参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createVirtualAccount(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'payment_method']);

        $headers = $this->resolveHeader();

        $requestData = [
            'external_id' => $params['out_trade_no'],
            'bank_code' => $params['bank_code'],
            'name' => $params['name'] ?? '',
            'expected_amount' => $params['total_amount'],
        ];

        if (!empty($params['description'])) {
            $requestData['description'] = $params['description'];
        }

        return $this->post('callback_virtual_accounts', $requestData, $headers);
    }

    /**
     * 创建电子钱包支付
     *
     * @param array<string, mixed> $params 电子钱包参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createEWallet(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'ewallet_type']);

        $headers = $this->resolveHeader();

        $requestData = [
            'external_id' => $params['out_trade_no'],
            'amount' => $params['total_amount'],
            'phone' => $params['phone'] ?? '',
            'ewallet_type' => $params['ewallet_type'],
        ];

        if (!empty($params['callback_url'])) {
            $requestData['callback_url'] = $params['callback_url'];
        }

        if (!empty($params['redirect_url'])) {
            $requestData['redirect_url'] = $params['redirect_url'];
        }

        return $this->post('ewallets', $requestData, $headers);
    }

    /**
     * 创建 QRIS 支付
     *
     * @param array<string, mixed> $params QRIS 参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createQRIS(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount']);

        $headers = $this->resolveHeader();

        $requestData = [
            'external_id' => $params['out_trade_no'],
            'amount' => $params['total_amount'],
            'callback_url' => $params['callback_url'] ?? '',
            'qr_code' => 'QRIS',
        ];

        return $this->post('qr_codes', $requestData, $headers);
    }
}
