<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Paypal;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * PayPal 网关
 *
 * 支持 PayPal Checkout、订阅等支付场景
 */
class PaypalGateway extends AbstractGateway
{
    /**
     * 沙箱环境基础 URL
     */
    protected const string SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://api-m.paypal.com/';

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
     * 查询退款
     *
     * @param string $refundId 退款 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        return $this->get("v2/payments/refunds/{$refundId}", [], $headers);
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
}
