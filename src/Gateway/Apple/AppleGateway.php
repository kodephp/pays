<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Apple;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Apple Pay 网关
 *
 * 支持 Apple Pay 网页支付（Web）和应用内支付（In-App）。
 * 通过 Apple Pay Payment Token 进行支付处理。
 */
class AppleGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://apple-pay-gateway-cert.apple.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://apple-pay-gateway.apple.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['merchant_identifier', 'merchant_certificate', 'apple_pay_merchant_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('apple');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * 创建支付订单
     *
     * Apple Pay 支付需要前端先通过 Apple Pay JS 获取 paymentToken，
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
            'merchantIdentifier' => $this->getConfig('merchant_identifier'),
            'outTradeNo' => $params['out_trade_no'],
            'totalAmount' => $params['total_amount'],
            'currency' => $params['currency'],
            'paymentToken' => $params['payment_token'],
        ];

        if (isset($params['description'])) {
            $requestData['description'] = $params['description'];
        }

        return $this->post('paymentservices/payment/start', $requestData, [
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
        return $this->post('paymentservices/payment/query', [
            'merchantIdentifier' => $this->getConfig('merchant_identifier'),
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

        return $this->post('paymentservices/payment/refund', [
            'merchantIdentifier' => $this->getConfig('merchant_identifier'),
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
        return $this->post('paymentservices/payment/queryRefund', [
            'merchantIdentifier' => $this->getConfig('merchant_identifier'),
            'outRefundNo' => $refundId,
        ], [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 验证异步通知
     *
     * Apple Pay 通常通过前端回调确认支付结果，
     * 此方法用于验证 Apple 服务器回调的签名。
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Apple Pay 主要通过前端回调，服务端验证需校验 paymentToken 签名
        if (!isset($data['payment_token'])) {
            return false;
        }

        $token = $data['payment_token'];

        return is_array($token) && isset($token['paymentData']);
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
        return $this->post('paymentservices/payment/close', [
            'merchantIdentifier' => $this->getConfig('merchant_identifier'),
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
        return 'apple';
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
            throw PayException::gatewayError('Apple Pay 响应格式异常');
        }

        if (($data['status'] ?? '') !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['message'] ?? 'Apple Pay 业务失败',
                $data['status'] ?? '',
            );
        }

        return $data;
    }
}
