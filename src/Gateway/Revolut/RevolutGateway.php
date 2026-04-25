<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Revolut;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Revolut 网关
 *
 * 支持 Revolut 商户支付、卡支付、Apple Pay、Google Pay 等。
 * 覆盖欧洲、英国、美国、澳大利亚等市场。
 */
class RevolutGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://sandbox-merchant.revolut.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://merchant.revolut.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key', 'merchant_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('revolut');
        if ($url !== null) {
            return $url;
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
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency']);

        $requestData = [
            'amount' => (int) ($params['total_amount'] * 100),
            'currency' => $params['currency'],
            'description' => $params['description'] ?? '',
            'merchant_order_ext_ref' => $params['out_trade_no'],
            'capture_mode' => $params['capture_mode'] ?? 'automatic',
        ];

        if (isset($params['customer_email'])) {
            $requestData['customer_email'] = $params['customer_email'];
        }

        if (isset($params['redirect_url'])) {
            $requestData['redirect_url'] = $params['redirect_url'];
        }

        return $this->post('api/orders', $requestData, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 查询订单状态
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        return $this->get("api/orders/{$orderId}", [], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 捕获授权订单
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function captureOrder(string $orderId): array
    {
        return $this->post("api/orders/{$orderId}/capture", [], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 取消订单
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        return $this->post("api/orders/{$orderId}/cancel", [], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
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
        $this->validateRequired($params, ['order_id', 'refund_amount']);

        return $this->post("api/orders/{$params['order_id']}/refund", [
            'amount' => (int) ($params['refund_amount'] * 100),
            'description' => $params['description'] ?? '',
        ], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        return $this->get("api/orders/{$refundId}", [], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Revolut Webhook 使用签名验证
        if (!isset($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        $payload = json_encode($data);
        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_key'));

        return hash_equals($expected, $signature);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'revolut';
    }

    /**
     * 解析响应内容
     *
     * @param string $response JSON 响应字符串
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw PayException::gatewayError('Revolut 响应格式异常');
        }

        if (isset($data['message'])) {
            throw PayException::gatewayError(
                $data['message'],
                $data['code'] ?? '',
            );
        }

        return $data;
    }
}
