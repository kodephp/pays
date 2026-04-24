<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Adyen;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * Adyen 网关
 *
 * 支持 Adyen Payments API，覆盖全球 200+ 个国家/地区，支持 250+ 种支付方式。
 * 提供统一的全球支付、本地支付、订阅支付能力。
 */
class AdyenGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://pal-test.adyen.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://pal-live.adyen.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key', 'merchant_account']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $env = $this->getConfig('environment', 'test');

        return $env === 'live' ? self::PROD_BASE_URL : self::TEST_BASE_URL;
    }

    /**
     * 创建支付会话（Sessions API，推荐）
     *
     * @param array<string, mixed> $params 会话参数
     * @return array<string, mixed> 会话响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['amount', 'currency', 'reference']);

        $requestData = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'amount' => [
                'value' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'reference' => $params['reference'],
            'returnUrl' => $params['return_url'] ?? '',
        ];

        if (isset($params['country_code'])) {
            $requestData['countryCode'] = $params['country_code'];
        }

        if (isset($params['shopper_email'])) {
            $requestData['shopperEmail'] = $params['shopper_email'];
        }

        if (isset($params['shopper_reference'])) {
            $requestData['shopperReference'] = $params['shopper_reference'];
        }

        if (isset($params['line_items'])) {
            $requestData['lineItems'] = $params['line_items'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('checkout/v70/sessions', $requestData, $headers);
    }

    /**
     * 查询订单（Payment Details）
     *
     * @param string $orderId 支付会话 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post('checkout/v70/payments/details', [
            'paymentData' => $orderId,
        ], $headers);
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
        $this->validateRequired($params, ['original_reference', 'amount', 'currency']);

        $requestData = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'originalReference' => $params['original_reference'],
            'amount' => [
                'value' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'reference' => $params['reference'] ?? uniqid('adyen_refund_', true),
        ];

        $headers = $this->buildAuthHeaders();

        return $this->post('pal/servlet/Payment/v68/refund', $requestData, $headers);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款 PSP 参考号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post('pal/servlet/Payment/v68/refundWithData', [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'originalReference' => $refundId,
        ], $headers);
    }

    /**
     * 验证异步通知签名（Webhook）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['hmacSignature'], $data['payload'])) {
            return false;
        }

        // Adyen HMAC 签名验证
        $expectedHmac = base64_encode(hash_hmac('sha256', $data['payload'], $this->getConfig('api_key'), true));

        return hash_equals($expectedHmac, $data['hmacSignature']);
    }

    /**
     * 关闭订单（取消支付）
     *
     * @param string $orderId 支付 PSP 参考号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post('pal/servlet/Payment/v68/cancel', [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'originalReference' => $orderId,
        ], $headers);
    }

    /**
     * 创建支付请求（Payments API，直接支付）
     *
     * @param array<string, mixed> $params 支付参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createPayment(array $params): array
    {
        $this->validateRequired($params, ['amount', 'currency', 'reference', 'payment_method']);

        $requestData = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'amount' => [
                'value' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'reference' => $params['reference'],
            'paymentMethod' => $params['payment_method'],
            'returnUrl' => $params['return_url'] ?? '',
        ];

        if (isset($params['shopper_interaction'])) {
            $requestData['shopperInteraction'] = $params['shopper_interaction'];
        }

        if (isset($params['recurring'])) {
            $requestData['recurring'] = $params['recurring'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('checkout/v70/payments', $requestData, $headers);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'adyen';
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
            throw PayException::gatewayError('Adyen 响应格式异常');
        }

        // Adyen 错误响应
        if (isset($data['status']) && $data['status'] >= 400) {
            throw PayException::gatewayError(
                $data['message'] ?? 'Adyen 业务失败',
                (string) ($data['errorCode'] ?? $data['status']),
            );
        }

        // Adyen 支付拒绝响应
        if (isset($data['resultCode']) && in_array($data['resultCode'], ['Refused', 'Error'], true)) {
            throw PayException::gatewayError(
                $data['refusalReason'] ?? 'Adyen 支付被拒绝',
                $data['resultCode'],
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
            'X-API-Key' => $this->getConfig('api_key'),
            'Content-Type' => 'application/json',
        ];
    }
}
