<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 退款能力接口
 *
 * 将「申请退款 / 查询退款 / 取消退款」的平台组装与签名逻辑下沉到各网关原生方法，
 * 由 {@see \Kode\Pays\Plugin\RefundPlugin} 统一做参数校验后转发，
 * 再通过 {@see \Kode\Pays\Facade\Pay} 的统一入口对外暴露。
 *
 * 设计约定：
 * - 金额统一以「最小货币单位（如分）」传入，网关内部按需转换（支付宝/Stripe/PayPal 转元）。
 * - 不支持的能力（如微信/支付宝/PayPal 取消退款）应抛
 *   {@see \Kode\Pays\Core\PayException::methodNotSupported}，由 Pay::call 统一返回「无此方法」。
 * - 网关方法仅承载「平台组装 + 签名 + 发请求」，不重复做业务参数校验（校验在插件层）。
 */
interface RefundCapableInterface
{
    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数（out_refund_no / refund_fee 必填，
     *                                       out_trade_no 与 transaction_id 至少其一）
     * @return array<string, mixed> 退款结果
     */
    public function applyRefund(array $params): array;

    /**
     * 查询退款结果
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     */
    public function queryRefund(string $outRefundNo): array;

    /**
     * 取消退款（仅部分网关支持，如 Stripe）
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     */
    public function cancelRefund(string $outRefundNo): array;
}
