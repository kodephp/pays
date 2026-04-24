<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 支付网关接口
 *
 * 所有支付网关必须实现此接口，确保统一调用方式
 */
interface GatewayInterface
{
    /**
     * 创建支付订单
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应数据
     * @throws PayException
     */
    public function createOrder(array $params): array;

    /**
     * 查询订单状态
     *
     * @param string $orderId 商户订单号或平台订单号
     * @return array<string, mixed> 订单信息
     * @throws PayException
     */
    public function queryOrder(string $orderId): array;

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     * @return array<string, mixed> 退款响应数据
     * @throws PayException
     */
    public function refund(array $params): array;

    /**
     * 查询退款状态
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed> 退款信息
     * @throws PayException
     */
    public function queryRefund(string $refundId): array;

    /**
     * 验证异步通知签名
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool 验证结果
     */
    public function verifyNotify(array $data): bool;

    /**
     * 关闭订单
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed> 关闭结果
     * @throws PayException
     */
    public function closeOrder(string $orderId): array;

    /**
     * 获取网关唯一标识
     *
     * @return string 网关标识，如 wechat、alipay
     */
    public static function getName(): string;
}
