<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Coinbase;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;
use Kode\Pays\Exception\InvalidArgumentException;

/**
 * Coinbase Commerce 网关
 *
 * 支持加密货币支付：BTC、ETH、USDT、USDC、LTC、BCH、DOGE 等。
 * 基于 Coinbase Commerce API v2 实现。
 *
 * 使用示例：
 * ```php
 * $gateway = Pay::coinbase([
 *     'api_key' => 'your_coinbase_commerce_api_key',
 * ]);
 *
 * // 创建加密货币支付订单
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 10000,
 *     'currency' => 'USD',
 *     'description' => '商品购买',
 * ]);
 *
 * // 获取支付页面 URL
 * $payUrl = $result['hosted_url'];
 * ```
 */
class CoinbaseGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key']);
    }

    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('coinbase');
        if ($url !== null) {
            return $url;
        }

        return 'https://api.commerce.coinbase.com/';
    }

    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount']);

        $requestData = [
            'name' => $params['subject'] ?? $params['description'] ?? '商品购买',
            'description' => $params['description'] ?? '',
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => number_format($params['total_amount'] / 100, 2),
                'currency' => $params['currency'] ?? 'USD',
            ],
            'metadata' => [
                'out_trade_no' => $params['out_trade_no'],
                'customer_id' => $params['customer_id'] ?? '',
            ],
        ];

        if (!empty($params['redirect_url'])) {
            $requestData['redirect_url'] = $params['redirect_url'];
        }

        if (!empty($params['cancel_url'])) {
            $requestData['cancel_url'] = $params['cancel_url'];
        }

        $response = $this->post('v2/charges', $requestData);

        return [
            'out_trade_no' => $params['out_trade_no'],
            'charge_id' => $response['data']['id'] ?? '',
            'code' => $response['data']['code'] ?? '',
            'hosted_url' => $response['data']['hosted_url'] ?? '',
            'status' => $response['data']['timeline'][0]['status'] ?? 'NEW',
            'created_at' => $response['data']['created_at'] ?? '',
            'expires_at' => $response['data']['expires_at'] ?? '',
            'pricing' => $response['data']['pricing']['local'] ?? [],
        ];
    }

    public function queryOrder(string $orderId): array
    {
        $response = $this->get("v2/charges/{$orderId}");

        $data = $response['data'] ?? [];

        return [
            'charge_id' => $data['id'] ?? '',
            'code' => $data['code'] ?? '',
            'status' => $this->resolveStatus($data['timeline'] ?? []),
            'pricing' => $data['pricing']['local'] ?? [],
            'payments' => $data['payments'] ?? [],
            'created_at' => $data['created_at'] ?? '',
            'expires_at' => $data['expires_at'] ?? '',
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    public function closeOrder(string $orderId): array
    {
        // Coinbase Commerce 不支持主动取消 charge
        return [
            'charge_id' => $orderId,
            'status' => 'CANCELLED',
            'message' => 'Coinbase Commerce 不支持主动取消，请等待超时',
        ];
    }

    public function refund(array $params): array
    {
        $this->validateRequired($params, ['charge_id']);

        return $this->post('v2/charges/' . $params['charge_id'] . '/refund', [
            'currency' => $params['currency'] ?? 'USD',
            'amount' => isset($params['refund_fee']) ? number_format($params['refund_fee'] / 100, 2) : null,
        ]);
    }

    public function queryRefund(string $refundId): array
    {
        return $this->get("v2/charges/{$refundId}");
    }

    public function verifyNotify(array $data): bool
    {
        $signature = $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] ?? '';
        $payload = file_get_contents('php://input');

        if ($signature === '' || $payload === false) {
            return false;
        }

        $computed = hash_hmac('sha256', $payload, $this->config['webhook_secret'] ?? '');

        return hash_equals($computed, $signature);
    }

    public static function getName(): string
    {
        return 'coinbase';
    }

    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new GatewayException('响应格式异常');
        }

        if (isset($data['error'])) {
            throw new GatewayException(
                $data['error']['message'] ?? '业务失败',
                $data['error']['type'] ?? '',
            );
        }

        return $data;
    }

    protected function resolveHeader(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-CC-Api-Key' => $this->config['api_key'] ?? '',
            'X-CC-Version' => '2018-03-22',
        ];
    }

    /**
     * 解析 Coinbase 时间线状态
     */
    protected function resolveStatus(array $timeline): string
    {
        if (empty($timeline)) {
            return 'NEW';
        }

        $last = end($timeline);

        return $last['status'] ?? 'NEW';
    }
}
