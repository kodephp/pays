<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 退款插件
 *
 * 为支持退款的网关提供统一的退款管理能力：申请退款、查询退款、取消退款。
 *
 * 平台组装逻辑已下沉到各网关原生方法（网关声明 {@see RefundCapableInterface}），
 * 本插件仅负责「参数校验 + 类型安全转发」，不承载平台组装逻辑。
 *
 * 支持网关：
 * - 微信支付（申请退款、查询退款；取消退款未提供，调用报「无此方法」）
 * - 支付宝（申请退款、查询退款；取消退款未提供，调用报「无此方法」）
 * - Stripe（创建退款、查询退款、取消退款）
 * - PayPal（退款、查询退款；取消退款未提供，调用报「无此方法」）
 * - Adyen（申请退款、查询退款；取消退款未提供，调用报「无此方法」）
 * - Revolut（申请退款、查询退款；取消退款未提供，调用报「无此方法」）
 *
 * 使用示例：
 * ```php
 * $plugin = new RefundPlugin($wechatGateway);
 *
 * // 申请退款
 * $result = $plugin->apply([
 *     'out_trade_no'  => 'ORDER_001',
 *     'out_refund_no' => 'REFUND_001',
 *     'total_fee'     => 100,
 *     'refund_fee'    => 50,
 *     'refund_desc'   => '商品质量问题',
 * ]);
 *
 * // 查询退款
 * $result = $plugin->query('REFUND_001');
 *
 * // 统一入口等价写法
 * \Kode\Pays\Facade\Pay::refundApply('wechat', $params);
 * \Kode\Pays\Facade\Pay::refundQuery('wechat', 'REFUND_001');
 * ```
 */
class RefundPlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力，并实现退款能力接口）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需继承 AbstractGateway）
     */
    public function __construct(GatewayInterface $gateway)
    {
        self::assertHttpCapable($gateway);

        $this->gateway = $gateway;
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     *        - out_trade_no: 原商户订单号（与 transaction_id 二选一）
     *        - transaction_id: 原支付交易号（与 out_trade_no 二选一）
     *        - out_refund_no: 商户退款单号（必填）
     *        - refund_fee: 退款金额（微信等以分为单位，必填）
     *        - total_fee: 原订单总金额（微信单位为分，可选）
     *        - refund_desc: 退款原因/说明（可选）
     *        - notify_url: 退款结果通知地址（可选）
     *        - currency: 货币代码（PayPal 等适用，可选）
     * @return array<string, mixed> 退款结果
     * @throws PayException
     */
    public function apply(array $params): array
    {
        if (empty($params['out_trade_no']) && empty($params['transaction_id'])) {
            throw PayException::paramError('out_trade_no 和 transaction_id 必须至少提供一个');
        }

        $this->validateRequired($params, ['out_refund_no', 'refund_fee']);

        return $this->forwardToCapableGateway('applyRefund', $params);
    }

    /**
     * 查询退款结果
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outRefundNo): array
    {
        return $this->forwardToCapableGateway('queryRefund', $outRefundNo);
    }

    /**
     * 取消退款（仅部分网关支持，如 Stripe）
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function cancel(string $outRefundNo): array
    {
        return $this->forwardToCapableGateway('cancelRefund', $outRefundNo);
    }

    /**
     * 类型安全地转发到网关原生方法
     *
     * @param mixed ...$args
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof RefundCapableInterface) {
            throw PayException::invalidArgument(sprintf(
                '网关 %s 未实现退款能力接口（RefundCapableInterface）',
                $this->gateway::getName(),
            ));
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var RefundCapableInterface $gateway */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }

    /**
     * 验证必填参数
     *
     * @param array<string, mixed> $params
     * @param string[] $required
     * @throws PayException
     */
    protected function validateRequired(array $params, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }
}
