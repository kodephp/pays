<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 退款插件
 *
 * 为支持退款的网关提供统一的退款管理能力。
 * 支持申请退款、查询退款、撤销退款（部分网关）。
 *
 * 支持网关：
 * - 微信支付（申请退款、查询退款）
 * - 支付宝（申请退款、查询退款）
 * - Stripe（创建退款、查询退款、取消退款）
 * - PayPal（退款、查询退款）
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
 * ```
 */
class RefundPlugin
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关
     */
    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     *        - out_trade_no: 原商户订单号（与 transaction_id 二选一）
     *        - transaction_id: 原支付交易号（与 out_trade_no 二选一）
     *        - out_refund_no: 商户退款单号
     *        - total_fee: 原订单总金额（微信单位为分）
     *        - refund_fee: 退款金额（微信单位为分）
     *        - refund_desc: 退款原因/说明
     *        - notify_url: 退款结果通知地址（可选）
     * @return array<string, mixed> 退款结果
     * @throws PayException
     */
    public function apply(array $params): array
    {
        if (empty($params['out_trade_no']) && empty($params['transaction_id'])) {
            throw PayException::paramError('out_trade_no 和 transaction_id 必须至少提供一个');
        }

        $this->validateRequired($params, ['out_refund_no', 'refund_fee']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->applyWechatRefund($params),
            'alipay' => $this->applyAlipayRefund($params),
            'stripe' => $this->applyStripeRefund($params),
            'paypal' => $this->applyPaypalRefund($params),
            default => throw PayException::invalidArgument('当前网关不支持退款功能'),
        };
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
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatRefund($outRefundNo),
            'alipay' => $this->queryAlipayRefund($outRefundNo),
            'stripe' => $this->queryStripeRefund($outRefundNo),
            'paypal' => $this->queryPaypalRefund($outRefundNo),
            default => throw PayException::invalidArgument('当前网关不支持退款查询'),
        };
    }

    /**
     * 取消退款（仅部分网关支持）
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function cancel(string $outRefundNo): array
    {
        return match ($this->gateway::getName()) {
            'stripe' => $this->cancelStripeRefund($outRefundNo),
            default => throw PayException::invalidArgument('当前网关不支持取消退款'),
        };
    }

    /* ==================== 微信支付退款实现 ==================== */

    /**
     * 微信申请退款
     */
    protected function applyWechatRefund(array $params): array
    {
        $requestData = [
            'appid' => $this->getGatewayConfig('app_id'),
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_refund_no' => $params['out_refund_no'],
            'refund_fee' => (int) $params['refund_fee'],
            'refund_desc' => $params['refund_desc'] ?? '',
        ];

        if (!empty($params['out_trade_no'])) {
            $requestData['out_trade_no'] = $params['out_trade_no'];
        } else {
            $requestData['transaction_id'] = $params['transaction_id'];
        }

        if (isset($params['total_fee'])) {
            $requestData['total_fee'] = (int) $params['total_fee'];
        }

        if (!empty($params['notify_url'])) {
            $requestData['notify_url'] = $params['notify_url'];
        }

        return $this->gateway->post('secapi/pay/refund', $requestData);
    }

    /**
     * 查询微信退款
     */
    protected function queryWechatRefund(string $outRefundNo): array
    {
        return $this->gateway->post('pay/refundquery', [
            'appid' => $this->getGatewayConfig('app_id'),
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_refund_no' => $outRefundNo,
        ]);
    }

    /* ==================== 支付宝退款实现 ==================== */

    /**
     * 支付宝申请退款
     */
    protected function applyAlipayRefund(array $params): array
    {
        $bizContent = [
            'out_request_no' => $params['out_refund_no'],
            'refund_amount' => number_format($params['refund_fee'] / 100, 2),
        ];

        if (!empty($params['out_trade_no'])) {
            $bizContent['out_trade_no'] = $params['out_trade_no'];
        } else {
            $bizContent['trade_no'] = $params['transaction_id'];
        }

        if (!empty($params['refund_desc'])) {
            $bizContent['refund_reason'] = $params['refund_desc'];
        }

        return $this->gateway->post('', [
            'method' => 'alipay.trade.refund',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝退款
     */
    protected function queryAlipayRefund(string $outRefundNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.trade.fastpay.refund.query',
            'biz_content' => json_encode([
                'out_request_no' => $outRefundNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== Stripe 退款实现 ==================== */

    /**
     * Stripe 创建退款
     */
    protected function applyStripeRefund(array $params): array
    {
        $requestData = [
            'payment_intent' => $params['transaction_id'] ?? '',
            'amount' => (int) $params['refund_fee'],
            'reason' => $this->mapStripeRefundReason($params['refund_desc'] ?? ''),
            'metadata' => [
                'out_refund_no' => $params['out_refund_no'],
            ],
        ];

        return $this->gateway->post('v1/refunds', $requestData, [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * 查询 Stripe 退款
     */
    protected function queryStripeRefund(string $outRefundNo): array
    {
        return $this->gateway->get('v1/refunds', [
            'metadata[out_refund_no]' => $outRefundNo,
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * 取消 Stripe 退款
     */
    protected function cancelStripeRefund(string $outRefundNo): array
    {
        $refunds = $this->queryStripeRefund($outRefundNo);
        $refundId = $refunds['data'][0]['id'] ?? '';

        if ($refundId === '') {
            throw PayException::paramError('未找到对应的 Stripe 退款记录');
        }

        return $this->gateway->post("v1/refunds/{$refundId}/cancel", [], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * 映射 Stripe 退款原因
     */
    protected function mapStripeRefundReason(string $desc): string
    {
        return match (true) {
            str_contains($desc, '欺诈') || str_contains($desc, 'fraud') => 'fraudulent',
            str_contains($desc, '重复') || str_contains($desc, 'duplicate') => 'duplicate',
            str_contains($desc, '请求') || str_contains($desc, 'request') => 'requested_by_customer',
            default => 'requested_by_customer',
        };
    }

    /* ==================== PayPal 退款实现 ==================== */

    /**
     * PayPal 申请退款
     */
    protected function applyPaypalRefund(array $params): array
    {
        $captureId = $params['transaction_id'] ?? '';

        if ($captureId === '') {
            throw PayException::paramError('PayPal 退款需要提供 capture_id（transaction_id）');
        }

        $requestData = [
            'amount' => [
                'value' => number_format($params['refund_fee'] / 100, 2),
                'currency_code' => strtoupper($params['currency'] ?? 'USD'),
            ],
            'invoice_id' => $params['out_refund_no'],
            'note_to_payer' => $params['refund_desc'] ?? '',
        ];

        return $this->gateway->post("v2/payments/captures/{$captureId}/refund", $requestData, [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('access_token', ''),
        ]);
    }

    /**
     * 查询 PayPal 退款
     */
    protected function queryPaypalRefund(string $outRefundNo): array
    {
        return $this->gateway->get("v2/payments/refunds/{$outRefundNo}", [], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('access_token', ''),
        ]);
    }

    /* ==================== 通用工具方法 ==================== */

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
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }

    /**
     * 获取网关配置项
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function getGatewayConfig(string $key, mixed $default = null): mixed
    {
        $reflection = new \ReflectionClass($this->gateway);

        if ($reflection->hasProperty('config')) {
            $property = $reflection->getProperty('config');
            $property->setAccessible(true);
            $config = $property->getValue($this->gateway);

            return $config[$key] ?? $default;
        }

        return $default;
    }

    /**
     * 生成随机字符串
     */
    protected function generateNonceStr(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
