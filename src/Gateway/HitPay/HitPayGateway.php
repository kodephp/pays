<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\HitPay;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Exception\GatewayException;

/**
 * HitPay 网关
 *
 * HitPay 是新加坡本地支付聚合平台，支持东南亚多国的本地支付方式。
 * 覆盖新加坡、马来西亚、泰国、印度尼西亚等国家。
 *
 * 支持支付方式：
 * - PayNow（新加坡即时支付）
 * - DuitNow（马来西亚即时支付）
 * - PromptPay（泰国即时支付）
 * - QRIS（印度尼西亚二维码支付）
 * - 信用卡/借记卡（Visa、MasterCard）
 * - 银行转账
 *
 * 使用示例：
 * ```php
 * $gateway = Pay::hitpay([
 *     'api_key' => 'your_hitpay_api_key',
 *     'webhook_secret' => 'your_webhook_secret',
 * ]);
 *
 * // 创建支付请求
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 10000,
 *     'currency' => 'SGD',
 *     'description' => '商品购买',
 *     'payment_methods' => ['paynow', 'card'],
 * ]);
 *
 * // 获取支付链接
 * $paymentUrl = $result['url'];
 * ```
 */
class HitPayGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://api.sandbox.hit-pay.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api.hit-pay.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * 创建支付订单
     *
     * @param array<string, mixed> $params 订单参数
     *        - out_trade_no: 商户订单号
     *        - total_amount: 订单金额（单位：分）
     *        - currency: 货币代码（SGD/MYR/THB/IDR 等）
     *        - description: 订单描述
     *        - payment_methods: 支付方式列表
     *        - redirect_url: 支付成功回调
     *        - webhook: Webhook 地址
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount']);

        $amountInCents = $params['total_amount'];
        $currency = strtoupper($params['currency'] ?? 'SGD');
        if (in_array($currency, ['JPY', 'KRW'], true)) {
            $amountInCents = $params['total_amount'];
        }

        $requestData = [
            'amount' => $amountInCents / 100,
            'currency' => $currency,
            'reference_number' => $params['out_trade_no'],
            'description' => $params['description'] ?? '商品购买',
            'redirect_url' => $params['redirect_url'] ?? '',
        ];

        if (!empty($params['payment_methods'])) {
            $requestData['payment_methods'] = $params['payment_methods'];
        }

        if (!empty($params['webhook'])) {
            $requestData['webhook'] = $params['webhook'];
        }

        $headers = $this->resolveHeader();

        return $this->post('v1/payment-requests', $requestData, $headers);
    }

    /**
     * 查询订单
     *
     * @param string $orderId Payment Request ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = $this->resolveHeader();

        return $this->get("v1/payment-requests/{$orderId}", [], $headers);
    }

    /**
     * 关闭订单（取消支付）
     *
     * @param string $orderId Payment Request ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = $this->resolveHeader();

        return $this->delete("v1/payment-requests/{$orderId}", [], $headers);
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     *        - payment_request_id: 支付请求 ID
     *        - refund_amount: 退款金额（单位：分）
     *        - refund_reason: 退款原因
     * @return array<string, mixed>
     * @throws PayException
     */
    public function refund(array $params): array
    {
        $this->validateRequired($params, ['payment_request_id', 'refund_amount']);

        $headers = $this->resolveHeader();

        $responseData = $this->post('v1/refunds', [
            'payment_request_id' => $params['payment_request_id'],
            'amount' => $params['refund_amount'] / 100,
            'refund_reason' => $params['refund_reason'] ?? '',
        ], $headers);

        return [
            'refund_id' => $responseData['id'] ?? '',
            'payment_request_id' => $params['payment_request_id'],
            'amount' => $params['refund_amount'],
            'status' => $responseData['status'] ?? '',
            'created_at' => $responseData['created_at'] ?? '',
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

        return $this->get("v1/refunds/{$refundId}", [], $headers);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        $signature = $_SERVER['HTTP_X_HITPAY_SIGNATURE'] ?? '';
        $payload = file_get_contents('php://input');

        if ($signature === '' || $payload === false) {
            return false;
        }

        $computed = hash_hmac('sha256', $payload, $this->config['webhook_secret'] ?? '');

        return hash_equals($computed, $signature);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'hitpay';
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
            throw new GatewayException('HitPay 响应格式异常');
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            throw new GatewayException(
                json_encode($data['errors']),
                'HITPAY_ERROR',
            );
        }

        return $data;
    }

    /**
     * 解析请求头
     */
    protected function resolveHeader(): array
    {
        return [
            'X-Business-API-Key' => $this->config['api_key'] ?? '',
            'Content-Type' => 'application/json',
        ];
    }
}
