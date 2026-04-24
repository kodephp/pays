<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Stripe;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * Stripe 网关
 *
 * 支持 Stripe Checkout、PaymentIntent、订阅等支付场景。
 * 覆盖全球 40+ 个国家/地区，支持 135+ 种货币。
 */
class StripeGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://api.stripe.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://api.stripe.com/';

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
     * 查询退款
     *
     * @param string $refundId 退款 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->get("v1/refunds/{$refundId}", [], $headers);
    }

    /**
     * 验证异步通知签名（Webhook）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['payload'], $data['sig_header'], $this->config['webhook_secret'])) {
            return false;
        }

        $payload = $data['payload'];
        $sigHeader = $data['sig_header'];
        $secret = $this->config['webhook_secret'];

        $expectedSig = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSig, $sigHeader);
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
        $data = json_decode($response, true);

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
}
