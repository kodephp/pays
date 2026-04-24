<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Google;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Google Pay 网关
 *
 * 支持 Google Pay 网页支付和应用内支付。
 * 通过 Google Pay Payment Token 进行支付处理。
 */
class GoogleGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://payments.googleapis.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://payments.googleapis.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['merchant_id', 'merchant_name', 'gateway_merchant_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('google');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * 创建支付订单
     *
     * Google Pay 支付需要前端先获取 paymentMethodToken，
     * 然后将 token 传给后端完成支付。
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency', 'payment_token']);

        $requestData = [
            'merchantId' => $this->getConfig('merchant_id'),
            'gatewayMerchantId' => $this->getConfig('gateway_merchant_id'),
            'outTradeNo' => $params['out_trade_no'],
            'totalAmount' => $params['total_amount'],
            'currency' => $params['currency'],
            'paymentMethodToken' => $params['payment_token'],
        ];

        if (isset($params['description'])) {
            $requestData['description'] = $params['description'];
        }

        return $this->post('v1/paymentgateway/payment/start', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 查询订单状态
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        return $this->post('v1/paymentgateway/payment/query', [
            'merchantId' => $this->getConfig('merchant_id'),
            'outTradeNo' => $orderId,
        ], [
            'Content-Type' => 'application/json',
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
        $this->validateRequired($params, ['out_trade_no', 'refund_amount']);

        return $this->post('v1/paymentgateway/payment/refund', [
            'merchantId' => $this->getConfig('merchant_id'),
            'outTradeNo' => $params['out_trade_no'],
            'refundAmount' => $params['refund_amount'],
            'outRefundNo' => $params['out_refund_no'] ?? uniqid('refund_', true),
        ], [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 查询退款状态
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        return $this->post('v1/paymentgateway/payment/queryRefund', [
            'merchantId' => $this->getConfig('merchant_id'),
            'outRefundNo' => $refundId,
        ], [
            'Content-Type' => 'application/json',
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
        if (!isset($data['payment_token'])) {
            return false;
        }

        $token = $data['payment_token'];

        return is_array($token) && isset($token['paymentMethodData']);
    }

    /**
     * 关闭订单
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        return $this->post('v1/paymentgateway/payment/close', [
            'merchantId' => $this->getConfig('merchant_id'),
            'outTradeNo' => $orderId,
        ], [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'google';
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
            throw PayException::gatewayError('Google Pay 响应格式异常');
        }

        if (($data['status'] ?? '') !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['message'] ?? 'Google Pay 业务失败',
                $data['status'] ?? '',
            );
        }

        return $data;
    }
}
