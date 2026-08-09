<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\CryptoCapableInterface;
use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 加密货币通用插件
 *
 * 聚合多个加密货币支付网关，提供统一的加密货币支付管理能力。
 * 支持 Coinbase Commerce，预留其他加密货币网关扩展点。
 *
 * 本插件仅做「参数可信转发」：平台组装逻辑已下沉到各网关原生方法（见
 * {@see CryptoCapableInterface}），插件不再承载任何平台内联分支。未实现
 * {@see CryptoCapableInterface} 的网关调用加密货币能力时会统一报「无此方法」。
 *
 * 功能：
 * - 统一创建加密货币订单（法币定价或加密货币定价）
 * - 查询链上确认状态
 * - 查询实时汇率
 * - 多网关路由（自动选择最优网关由上层负责）
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
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 加密货币支付网关
     */
    public function __construct(GatewayInterface $gateway)
    {
        self::assertHttpCapable($gateway);

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
        return $this->forwardToCapableGateway('createOrder', $params);
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
        return $this->forwardToCapableGateway('createCryptoOrder', $params);
    }

    /**
     * 获取加密货币支付地址
     *
     * 获取各币种的区块链收款地址和支付 URI。
     *
     * @param string $orderId 订单 ID（charge_id）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getPaymentAddresses(string $orderId): array
    {
        return $this->forwardToCapableGateway('getPaymentAddresses', $orderId);
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
        return $this->forwardToCapableGateway('getConfirmations', $orderId);
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
        return $this->forwardToCapableGateway('getExchangeRate', $cryptoCurrency, $fiatCurrency);
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
        return $this->forwardToCapableGateway('queryOrder', $orderId);
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
        return $this->forwardToCapableGateway('refund', $params);
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
        return $this->forwardToCapableGateway('verifyNotify', $data);
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

    /**
     * 类型安全地转发到网关原生方法
     *
     * 统一消除原先散落在各方法中的 `match($gateway::getName())` 硬编码字符串与
     * `instanceof` 判断：只要网关实现了 {@see CryptoCapableInterface} 且具备目标方法，
     * 即可被本插件复用，新增加密货币网关无需改动插件。
     *
     * @param mixed ...$args
     * @return mixed
     * @throws PayException
     *
     * @phpstan-assert CryptoCapableInterface $this->gateway
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): mixed
    {
        if (!$this->gateway instanceof CryptoCapableInterface) {
            throw PayException::invalidArgument(sprintf(
                '网关 %s 未实现加密货币能力接口（CryptoCapableInterface）',
                $this->gateway::getName(),
            ));
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var CryptoCapableInterface $gateway */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }
}
