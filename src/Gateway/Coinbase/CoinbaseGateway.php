<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Coinbase;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;

/**
 * Coinbase Commerce 网关
 *
 * 支持加密货币支付：BTC、ETH、USDT、USDC、LTC、BCH、DOGE 等。
 * 基于 Coinbase Commerce API v2 实现。
 *
 * 增强功能：
 * - 支持指定加密货币收款（不做法币转换）
 * - 支持区块链地址直接支付
 * - 支持链上确认数查询
 * - 支持多币种价格查询
 *
 * 使用示例：
 * ```php
 * $gateway = Pay::coinbase([
 *     'api_key' => 'your_coinbase_commerce_api_key',
 * ]);
 *
 * // 创建加密货币支付订单（法币定价）
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 10000,
 *     'currency' => 'USD',
 *     'description' => '商品购买',
 * ]);
 *
 * // 创建指定加密货币收款订单（如只收 USDC）
 * $result = $gateway->createCryptoOrder([
 *     'out_trade_no' => 'ORDER_002',
 *     'crypto_amount' => '50.00',
 *     'crypto_currency' => 'USDC',
 *     'description' => 'USDC 收款',
 * ]);
 *
 * // 查询链上确认数
 * $confirmations = $gateway->getConfirmations($chargeId);
 * ```
 */
class CoinbaseGateway extends AbstractGateway
{
    /**
     * 支持的加密货币列表
     */
    protected array $supportedCryptos = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'USDT' => 'tether',
        'USDC' => 'usd_coin',
        'LTC' => 'litecoin',
        'BCH' => 'bitcoin_cash',
        'DOGE' => 'dogecoin',
        'DAI' => 'dai',
    ];

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

        return $this->formatChargeResponse($response, $params['out_trade_no']);
    }

    /**
     * 创建指定加密货币收款订单
     *
     * 直接以加密货币金额创建订单，不做法币转换。
     *
     * @param array<string, mixed> $params
     *        - out_trade_no: 商户订单号
     *        - crypto_amount: 加密货币金额（字符串，如 "0.5"）
     *        - crypto_currency: 加密货币代码（BTC/ETH/USDC 等）
     *        - description: 订单描述
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createCryptoOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'crypto_amount', 'crypto_currency']);

        $cryptoCurrency = strtoupper($params['crypto_currency']);

        if (!isset($this->supportedCryptos[$cryptoCurrency])) {
            throw PayException::invalidArgument("不支持的加密货币：{$cryptoCurrency}");
        }

        $requestData = [
            'name' => $params['description'] ?? '加密货币收款',
            'description' => $params['description'] ?? '',
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => $params['crypto_amount'],
                'currency' => $cryptoCurrency,
            ],
            'metadata' => [
                'out_trade_no' => $params['out_trade_no'],
                'crypto_currency' => $cryptoCurrency,
                'crypto_amount' => $params['crypto_amount'],
            ],
        ];

        if (!empty($params['redirect_url'])) {
            $requestData['redirect_url'] = $params['redirect_url'];
        }

        if (!empty($params['cancel_url'])) {
            $requestData['cancel_url'] = $params['cancel_url'];
        }

        $response = $this->post('v2/charges', $requestData);

        return $this->formatChargeResponse($response, $params['out_trade_no']);
    }

    /**
     * 获取加密货币支付地址
     *
     * 为指定 charge 获取各币种的区块链收款地址。
     *
     * @param string $chargeId Charge ID
     * @return array<string, array<string, mixed>> 各币种收款地址
     */
    public function getPaymentAddresses(string $chargeId): array
    {
        $response = $this->get("v2/charges/{$chargeId}");
        $data = $response['data'] ?? [];
        $addresses = [];

        foreach ($data['addresses'] ?? [] as $crypto => $address) {
            $addresses[$crypto] = [
                'address' => $address,
                'currency' => $crypto,
                'uri' => $this->buildCryptoUri($crypto, $address, $data['pricing'][$crypto] ?? []),
            ];
        }

        return $addresses;
    }

    /**
     * 查询链上确认数
     *
     * @param string $chargeId Charge ID
     * @return array<string, mixed> 各币种的确认数信息
     */
    public function getConfirmations(string $chargeId): array
    {
        $response = $this->get("v2/charges/{$chargeId}");
        $data = $response['data'] ?? [];
        $confirmations = [];

        foreach ($data['payments'] ?? [] as $payment) {
            $crypto = $payment['value']['crypto']['currency'] ?? '';
            $confirmations[$crypto] = [
                'transaction_id' => $payment['transaction_id'] ?? '',
                'status' => $payment['status'] ?? '',
                'confirmations' => $payment['confirmations'] ?? 0,
                'confirmations_required' => $payment['confirmations_required'] ?? 0,
                'amount' => $payment['value']['crypto'] ?? [],
                'detected_at' => $payment['detected_at'] ?? '',
            ];
        }

        return $confirmations;
    }

    /**
     * 查询加密货币实时价格
     *
     * @param string $cryptoCurrency 加密货币代码
     * @param string $fiatCurrency 法币代码
     * @return array<string, mixed>
     */
    public function getExchangeRate(string $cryptoCurrency, string $fiatCurrency = 'USD'): array
    {
        $response = $this->get('v2/exchange-rates', [
            'currency' => strtoupper($fiatCurrency),
        ]);

        $rates = $response['data']['rates'] ?? [];
        $cryptoKey = strtolower($cryptoCurrency);

        return [
            'crypto_currency' => strtoupper($cryptoCurrency),
            'fiat_currency' => strtoupper($fiatCurrency),
            'rate' => $rates[$cryptoKey] ?? '0',
            'timestamp' => $response['data']['timestamp'] ?? '',
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
            'pricing' => $data['pricing'] ?? [],
            'payments' => $data['payments'] ?? [],
            'addresses' => $data['addresses'] ?? [],
            'created_at' => $data['created_at'] ?? '',
            'expires_at' => $data['expires_at'] ?? '',
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    public function closeOrder(string $orderId): array
    {
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
     * 格式化 Charge 响应
     */
    protected function formatChargeResponse(array $response, string $outTradeNo): array
    {
        $data = $response['data'] ?? [];

        return [
            'out_trade_no' => $outTradeNo,
            'charge_id' => $data['id'] ?? '',
            'code' => $data['code'] ?? '',
            'hosted_url' => $data['hosted_url'] ?? '',
            'status' => $this->resolveStatus($data['timeline'] ?? []),
            'created_at' => $data['created_at'] ?? '',
            'expires_at' => $data['expires_at'] ?? '',
            'pricing' => $data['pricing'] ?? [],
            'addresses' => $data['addresses'] ?? [],
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

    /**
     * 构建加密货币支付 URI
     */
    protected function buildCryptoUri(string $crypto, string $address, array $pricing): string
    {
        $amount = $pricing['amount'] ?? '';

        return match ($crypto) {
            'bitcoin' => "bitcoin:{$address}?amount={$amount}",
            'ethereum' => "ethereum:{$address}?value={$amount}",
            'litecoin' => "litecoin:{$address}?amount={$amount}",
            'bitcoin_cash' => "bitcoincash:{$address}?amount={$amount}",
            'dogecoin' => "dogecoin:{$address}?amount={$amount}",
            default => $address,
        };
    }
}
