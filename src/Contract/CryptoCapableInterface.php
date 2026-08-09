<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 加密货币支付能力接口
 *
 * 聚合加密货币支付网关（如 Coinbase Commerce）的统一能力契约。
 * 插件（CryptoPlugin）仅做「参数可信转发」，平台组装与签名逻辑下沉到各网关原生方法。
 *
 * 扩展新的加密货币网关时，只需让其 implements 本接口即可被 CryptoPlugin 复用，
 * 无需改动插件；未实现本接口的网关调用加密货币能力时会统一报「无此方法」。
 *
 * 设计要点（与分账/转账/红包/订阅/个人收款/对账/退款一致）：
 * - 能力方法由各网关自行实现（含请求组装、签名、发请求）
 * - 插件只负责把调用转发到网关原生方法，并做类型安全断言
 * - 返回类型中 verifyNotify 为 bool，其余为 array，故转发入口返回 mixed
 */
interface CryptoCapableInterface
{
    /**
     * 创建法币定价的加密货币订单
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createOrder(array $params): array;

    /**
     * 创建指定加密货币定价的订单
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createCryptoOrder(array $params): array;

    /**
     * 获取加密货币支付地址
     *
     * @param string $orderId Charge ID
     * @return array<string, mixed>
     */
    public function getPaymentAddresses(string $orderId): array;

    /**
     * 查询链上确认状态
     *
     * @param string $orderId Charge ID
     * @return array<string, mixed>
     */
    public function getConfirmations(string $orderId): array;

    /**
     * 查询加密货币实时汇率
     *
     * @param string $cryptoCurrency 加密货币代码
     * @param string $fiatCurrency 法币代码
     * @return array<string, mixed>
     */
    public function getExchangeRate(string $cryptoCurrency, string $fiatCurrency = 'USD'): array;

    /**
     * 查询订单状态
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     */
    public function queryOrder(string $orderId): array;

    /**
     * 发起退款
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function refund(array $params): array;

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool 验证结果
     */
    public function verifyNotify(array $data): bool;
}
