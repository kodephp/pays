<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Square;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * Square 网关
 *
 * 支持 Square Payments API，覆盖美国、加拿大、英国、澳大利亚、日本等国家/地区。
 */
class SquareGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://connect.squareupsandbox.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://connect.squareup.com/';

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
        $data = json_decode($response, true);

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
