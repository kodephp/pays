<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 加密货币通用插件
 *
 * 聚合多个加密货币支付网关，提供统一的加密货币支付管理能力。
 * 支持 Coinbase Commerce，预留其他加密货币网关扩展点。
 *
 * 功能：
 * - 统一创建加密货币订单（法币定价或加密货币定价）
 * - 查询链上确认状态
 * - 查询实时汇率
 * - 多网关路由（自动选择最优网关）
 *
 * 使用示例：
 * ```php
 * $plugin = new CryptoPlugin($coinbaseGateway);
 *
 * // 法币定价，消费者自选加密货币支付
 * $order = $plugin->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 10000,
 *     'currency' => 'USD',
 * ]);
 *
 * // 指定加密货币定价（如只收 USDC）
 * $order = $plugin->createCryptoOrder([
 *     'out_trade_no' => 'ORDER_002',
 *     'crypto_amount' => '50.00',
 *     'crypto_currency' => 'USDC',
 * ]);
 *
 * // 查询链上确认状态
 * $status = $plugin->getOnChainStatus($order['charge_id']);
 *
 * // 查询实时汇率
 * $rate = $plugin->getExchangeRate('BTC', 'USD');
 * ```
 */
class CryptoPlugin
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 加密货币支付网关
     */
    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 创建法币定价的加密货币订单
     *
     * 消费者可在支付页面选择任意支持的加密货币支付。
     *
     * @param array<string, mixed> $params 订单参数
     *        - out_trade_no: 商户订单号
     *        - total_amount: 订单金额（单位：分）
     *        - currency: 法币币种，默认 USD
     *        - description: 订单描述
     *        - redirect_url: 支付成功跳转地址
     *        - cancel_url: 支付取消跳转地址
     * @return array<string, mixed> 订单结果
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->createOrder($params),
            default => throw PayException::invalidArgument('当前网关不支持加密货币支付'),
        };
    }

    /**
     * 创建指定加密货币定价的订单
     *
     * 直接以加密货币金额创建订单，消费者只能用指定币种支付。
     *
     * @param array<string, mixed> $params 订单参数
     *        - out_trade_no: 商户订单号
     *        - crypto_amount: 加密货币金额（字符串，如 "0.5"）
     *        - crypto_currency: 加密货币代码（BTC/ETH/USDC 等）
     *        - description: 订单描述
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createCryptoOrder(array $params): array
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->createCryptoOrder($params),
            default => throw PayException::invalidArgument('当前网关不支持指定加密货币收款'),
        };
    }

    /**
     * 获取加密货币支付地址
     *
     * 获取各币种的区块链收款地址和支付 URI。
     *
     * @param string $orderId 订单 ID（charge_id）
     * @return array<string, array<string, mixed>>
     * @throws PayException
     */
    public function getPaymentAddresses(string $orderId): array
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->getPaymentAddresses($orderId),
            default => throw PayException::invalidArgument('当前网关不支持获取支付地址'),
        };
    }

    /**
     * 查询链上确认状态
     *
     * 获取订单的链上确认数、交易哈希、检测时间等。
     *
     * @param string $orderId 订单 ID（charge_id）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getOnChainStatus(string $orderId): array
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->getConfirmations($orderId),
            default => throw PayException::invalidArgument('当前网关不支持链上确认查询'),
        };
    }

    /**
     * 查询加密货币实时汇率
     *
     * @param string $cryptoCurrency 加密货币代码
     * @param string $fiatCurrency 法币代码
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getExchangeRate(string $cryptoCurrency, string $fiatCurrency = 'USD'): array
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->getExchangeRate($cryptoCurrency, $fiatCurrency),
            default => throw PayException::invalidArgument('当前网关不支持汇率查询'),
        };
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
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->queryOrder($orderId),
            default => throw PayException::invalidArgument('当前网关不支持订单查询'),
        };
    }

    /**
     * 发起退款
     *
     * @param array<string, mixed> $params 退款参数
     *        - charge_id: 订单 ID
     *        - refund_fee: 退款金额（分）
     *        - currency: 退款币种
     * @return array<string, mixed>
     * @throws PayException
     */
    public function refund(array $params): array
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->refund($params),
            default => throw PayException::invalidArgument('当前网关不支持加密货币退款'),
        };
    }

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     * @throws PayException
     */
    public function verifyNotify(array $data): bool
    {
        return match ($this->gateway::getName()) {
            'coinbase' => $this->gateway->verifyNotify($data),
            default => throw PayException::invalidArgument('当前网关不支持通知验证'),
        };
    }

    /**
     * 判断订单是否已确认（达到安全确认数）
     *
     * @param string $orderId 订单 ID
     * @param int $minConfirmations 最小确认数（默认 6）
     * @return array<string, mixed> {confirmed: bool, details: array}
     * @throws PayException
     */
    public function isConfirmed(string $orderId, int $minConfirmations = 6): array
    {
        $confirmations = $this->getOnChainStatus($orderId);

        $allConfirmed = true;
        $details = [];

        foreach ($confirmations as $crypto => $info) {
            $confirmed = ($info['confirmations'] ?? 0) >= $minConfirmations;
            $details[$crypto] = [
                'confirmed' => $confirmed,
                'confirmations' => $info['confirmations'] ?? 0,
                'required' => $minConfirmations,
                'transaction_id' => $info['transaction_id'] ?? '',
            ];

            if (!$confirmed) {
                $allConfirmed = false;
            }
        }

        return [
            'confirmed' => $allConfirmed && !empty($confirmations),
            'details' => $details,
        ];
    }
}
