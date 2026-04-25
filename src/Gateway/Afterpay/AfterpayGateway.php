<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Afterpay;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;

/**
 * Afterpay/Clearpay 网关
 *
 * 先买后付（BNPL）支付网关，覆盖澳洲、美国、英国、欧洲。
 * 消费者分 4 期免息付款，商家实时全额到账。
 *
 * 使用示例：
 * ```php
 * $gateway = Pay::afterpay([
 *     'merchant_id' => 'your_merchant_id',
 *     'secret_key' => 'your_secret_key',
 *     'region' => 'US',
 * ]);
 *
 * // 创建先买后付订单
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 10000,
 *     'currency' => 'USD',
 *     'consumer' => [
 *         'phone_number' => '+1234567890',
 *         'given_names' => 'John',
 *         'surname' => 'Doe',
 *         'email' => 'john@example.com',
 *     ],
 *     'redirect_url' => 'https://example.com/success',
 *     'cancel_url' => 'https://example.com/cancel',
 * ]);
 *
 * // 跳转到 Afterpay 支付页面
 * header('Location: ' . $result['checkout_url']);
 * ```
 */
class AfterpayGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['merchant_id', 'secret_key']);
    }

    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('afterpay');
        if ($url !== null) {
            return $url;
        }

        $region = strtolower($this->config['region'] ?? 'us');

        return match ($region) {
            'au' => 'https://api.afterpay.com/',
            'us' => 'https://api.us.afterpay.com/',
            'uk', 'gb' => 'https://api.clearpay.co.uk/',
            'eu' => 'https://api.eu.afterpay.com/',
            default => 'https://api.us.afterpay.com/',
        };
    }

    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'consumer']);

        $requestData = [
            'amount' => [
                'amount' => number_format($params['total_amount'] / 100, 2),
                'currency' => $params['currency'] ?? 'USD',
            ],
            'consumer' => $params['consumer'],
            'billing' => $params['billing'] ?? null,
            'shipping' => $params['shipping'] ?? null,
            'items' => $params['items'] ?? [],
            'merchant' => [
                'redirectConfirmUrl' => $params['redirect_url'] ?? '',
                'redirectCancelUrl' => $params['cancel_url'] ?? '',
            ],
            'merchantReference' => $params['out_trade_no'],
            'taxAmount' => [
                'amount' => number_format(($params['tax_amount'] ?? 0) / 100, 2),
                'currency' => $params['currency'] ?? 'USD',
            ],
            'shippingAmount' => [
                'amount' => number_format(($params['shipping_amount'] ?? 0) / 100, 2),
                'currency' => $params['currency'] ?? 'USD',
            ],
        ];

        // 移除 null 值
        $requestData = array_filter($requestData, fn ($v) => $v !== null);

        $response = $this->post('v2/checkouts', $requestData);

        return [
            'out_trade_no' => $params['out_trade_no'],
            'token' => $response['token'] ?? '',
            'checkout_url' => $response['redirectCheckoutUrl'] ?? '',
            'expires_at' => $response['expires'] ?? '',
            'amount' => $response['amount'] ?? [],
        ];
    }

    public function queryOrder(string $orderId): array
    {
        $response = $this->get("v2/payments/{$orderId}");

        return [
            'token' => $response['token'] ?? '',
            'status' => $response['status'] ?? '',
            'amount' => $response['amount'] ?? [],
            'created_at' => $response['created'] ?? '',
            'merchant_reference' => $response['merchantReference'] ?? '',
            'consumer' => $response['consumer'] ?? [],
            'refunds' => $response['refunds'] ?? [],
        ];
    }

    public function capture(string $token): array
    {
        return $this->post('v2/payments/capture', [
            'token' => $token,
        ]);
    }

    public function refund(array $params): array
    {
        $this->validateRequired($params, ['order_id']);

        $requestData = [
            'token' => $params['order_id'],
            'amount' => [
                'amount' => number_format($params['refund_fee'] / 100, 2),
                'currency' => $params['currency'] ?? 'USD',
            ],
            'merchantReference' => $params['out_refund_no'] ?? '',
        ];

        return $this->post('v2/payments/refund', $requestData);
    }

    public function queryRefund(string $refundId): array
    {
        return $this->get("v2/payments/{$refundId}");
    }

    public function closeOrder(string $orderId): array
    {
        return $this->post("v2/payments/{$orderId}/void", []);
    }

    public function verifyNotify(array $data): bool
    {
        // Afterpay 使用 Basic Auth 验证回调
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($auth === '') {
            return false;
        }

        $expected = 'Basic ' . base64_encode($this->config['merchant_id'] . ':' . $this->config['secret_key']);

        return hash_equals($expected, $auth);
    }

    public static function getName(): string
    {
        return 'afterpay';
    }

    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new GatewayException('响应格式异常');
        }

        if (isset($data['errorCode'])) {
            throw new GatewayException(
                $data['message'] ?? '业务失败',
                $data['errorCode'] ?? '',
            );
        }

        if (isset($data['httpStatusCode']) && $data['httpStatusCode'] >= 400) {
            throw new GatewayException(
                $data['message'] ?? '请求失败',
                (string) ($data['httpStatusCode'] ?? ''),
            );
        }

        return $data;
    }

    protected function resolveHeader(): array
    {
        $credentials = base64_encode($this->config['merchant_id'] . ':' . $this->config['secret_key']);

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $credentials,
            'Accept' => 'application/json',
        ];
    }
}
