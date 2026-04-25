<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 分账插件
 *
 * 为支持分账的网关提供统一的分账管理能力。
 * 分账用于将一笔订单金额按约定比例分给多个接收方（如平台、供应商、推广者等）。
 *
 * 支持网关：
 * - 微信支付（服务商模式分账）
 * - 支付宝（交易分账）
 * - Stripe（Connect 平台分账 / Transfer）
 *
 * 使用示例：
 * ```php
 * $plugin = new ProfitSharingPlugin($wechatGateway);
 *
 * // 创建分账
 * $result = $plugin->create([
 *     'transaction_id' => '4200000000000000',
 *     'out_order_no' => 'SHARE_001',
 *     'receivers' => [
 *         ['type' => 'MERCHANT_ID', 'account' => '123456', 'amount' => 100, 'description' => '供应商分账'],
 *         ['type' => 'PERSONAL_OPENID', 'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', 'amount' => 50, 'description' => '推广者分账'],
 *     ],
 * ]);
 *
 * // 查询分账结果
 * $result = $plugin->query('SHARE_001');
 *
 * // 分账回退
 * $result = $plugin->return([
 *     'out_order_no' => 'SHARE_001',
 *     'out_return_no' => 'RETURN_001',
 *     'return_amount' => 100,
 * ]);
 *
 * // 解冻剩余资金
 * $result = $plugin->unfreeze('4200000000000000');
 * ```
 */
class ProfitSharingPlugin
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
     * 创建分账订单
     *
     * 将一笔已支付订单的金额按接收方列表进行分账。
     *
     * @param array<string, mixed> $params 分账参数
     *        - transaction_id: 原支付订单号（微信 transaction_id / 支付宝 trade_no / Stripe payment_intent）
     *        - out_order_no: 商户分账订单号
     *        - receivers: 接收方列表
     *          微信: [{type: MERCHANT_ID/PERSONAL_OPENID, account, amount, description}]
     *          支付宝: [{trans_in_type: userId/loginName, trans_in, amount, desc}]
     *          Stripe: [{account: connect_account_id, amount, currency}]
     * @return array<string, mixed> 分账结果
     * @throws PayException
     */
    public function create(array $params): array
    {
        $this->validateRequired($params, ['transaction_id', 'out_order_no', 'receivers']);

        if (!is_array($params['receivers']) || empty($params['receivers'])) {
            throw PayException::paramError('receivers 必须是非空数组');
        }

        return match ($this->gateway::getName()) {
            'wechat' => $this->createWechatSharing($params),
            'alipay' => $this->createAlipaySharing($params),
            'stripe' => $this->createStripeSharing($params),
            default => throw PayException::invalidArgument('当前网关不支持分账功能'),
        };
    }

    /**
     * 查询分账结果
     *
     * @param string $outOrderNo 商户分账订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outOrderNo): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatSharing($outOrderNo),
            'alipay' => $this->queryAlipaySharing($outOrderNo),
            'stripe' => $this->queryStripeSharing($outOrderNo),
            default => throw PayException::invalidArgument('当前网关不支持分账查询'),
        };
    }

    /**
     * 分账回退
     *
     * 将已分账的金额退回给原订单付款方。
     *
     * @param array<string, mixed> $params 回退参数
     *        - out_order_no: 商户分账订单号
     *        - out_return_no: 商户回退单号
     *        - return_amount: 回退金额（微信单位为分）
     *        - description: 回退描述（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function return(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->returnWechatSharing($params),
            'alipay' => $this->returnAlipaySharing($params),
            'stripe' => $this->returnStripeSharing($params),
            default => throw PayException::invalidArgument('当前网关不支持分账回退'),
        };
    }

    /**
     * 查询分账回退结果
     *
     * @param string $outReturnNo 商户回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryReturn(string $outReturnNo): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatReturn($outReturnNo),
            'alipay' => $this->queryAlipayReturn($outReturnNo),
            'stripe' => $this->queryStripeReturn($outReturnNo),
            default => throw PayException::invalidArgument('当前网关不支持分账回退查询'),
        };
    }

    /**
     * 解冻剩余资金
     *
     * 分账完成后，将未分账的剩余资金解冻给原订单收款方。
     *
     * @param string $transactionId 原支付订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function unfreeze(string $transactionId): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->unfreezeWechat($transactionId),
            'alipay' => $this->unfreezeAlipay($transactionId),
            'stripe' => $this->unfreezeStripe($transactionId),
            default => throw PayException::invalidArgument('当前网关不支持资金解冻'),
        };
    }

    /**
     * 添加分账接收方
     *
     * 在分账前将接收方添加到网关的接收方列表中。
     *
     * @param array<string, mixed> $receiver 接收方信息
     * @return array<string, mixed>
     * @throws PayException
     */
    public function addReceiver(array $receiver): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->addWechatReceiver($receiver),
            'alipay' => $this->addAlipayReceiver($receiver),
            default => throw PayException::invalidArgument('当前网关不支持添加分账接收方'),
        };
    }

    /**
     * 删除分账接收方
     *
     * @param array<string, mixed> $receiver 接收方信息
     * @return array<string, mixed>
     * @throws PayException
     */
    public function removeReceiver(array $receiver): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->removeWechatReceiver($receiver),
            'alipay' => $this->removeAlipayReceiver($receiver),
            default => throw PayException::invalidArgument('当前网关不支持删除分账接收方'),
        };
    }

    /* ==================== 微信支付分账实现 ==================== */

    /**
     * 创建微信分账
     */
    protected function createWechatSharing(array $params): array
    {
        $receivers = array_map(function (array $r): array {
            return [
                'type' => $r['type'],
                'account' => $r['account'],
                'amount' => (int) $r['amount'],
                'description' => $r['description'] ?? '分账',
            ];
        }, $params['receivers']);

        return $this->gateway->post('secapi/pay/profitsharing', [
            'transaction_id' => $params['transaction_id'],
            'out_order_no' => $params['out_order_no'],
            'receivers' => json_encode($receivers, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询微信分账结果
     */
    protected function queryWechatSharing(string $outOrderNo): array
    {
        return $this->gateway->post('pay/profitsharingquery', [
            'transaction_id' => '',
            'out_order_no' => $outOrderNo,
        ]);
    }

    /**
     * 微信分账回退
     */
    protected function returnWechatSharing(array $params): array
    {
        return $this->gateway->post('secapi/pay/profitsharingreturn', [
            'out_order_no' => $params['out_order_no'],
            'out_return_no' => $params['out_return_no'],
            'return_account_type' => $params['return_account_type'] ?? 'MERCHANT_ID',
            'return_account' => $params['return_account'] ?? '',
            'return_amount' => (int) $params['return_amount'],
            'description' => $params['description'] ?? '分账回退',
        ]);
    }

    /**
     * 查询微信分账回退结果
     */
    protected function queryWechatReturn(string $outReturnNo): array
    {
        return $this->gateway->post('pay/profitsharingreturnquery', [
            'out_return_no' => $outReturnNo,
        ]);
    }

    /**
     * 微信解冻剩余资金
     */
    protected function unfreezeWechat(string $transactionId): array
    {
        return $this->gateway->post('secapi/pay/profitsharingfinish', [
            'transaction_id' => $transactionId,
            'out_order_no' => 'UNFREEZE_' . time(),
            'description' => '解冻剩余资金',
        ]);
    }

    /**
     * 添加微信分账接收方
     */
    protected function addWechatReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['type', 'account', 'name']);

        return $this->gateway->post('pay/profitsharingaddreceiver', [
            'receiver' => json_encode([
                'type' => $receiver['type'],
                'account' => $receiver['account'],
                'name' => $receiver['name'],
                'relation_type' => $receiver['relation_type'] ?? 'SERVICE_PROVIDER',
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 删除微信分账接收方
     */
    protected function removeWechatReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['type', 'account']);

        return $this->gateway->post('pay/profitsharingremovereceiver', [
            'receiver' => json_encode([
                'type' => $receiver['type'],
                'account' => $receiver['account'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== 支付宝分账实现 ==================== */

    /**
     * 创建支付宝分账
     */
    protected function createAlipaySharing(array $params): array
    {
        $royaltyParameters = array_map(function (array $r): array {
            return [
                'trans_out_type' => $r['trans_out_type'] ?? 'userId',
                'trans_out' => $r['trans_out'] ?? '',
                'trans_in_type' => $r['trans_in_type'] ?? 'userId',
                'trans_in' => $r['trans_in'],
                'amount' => (float) $r['amount'],
                'desc' => $r['desc'] ?? $r['description'] ?? '分账',
            ];
        }, $params['receivers']);

        return $this->gateway->post('', [
            'method' => 'alipay.trade.order.settle',
            'biz_content' => json_encode([
                'out_request_no' => $params['out_order_no'],
                'trade_no' => $params['transaction_id'],
                'royalty_parameters' => $royaltyParameters,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝分账结果
     */
    protected function queryAlipaySharing(string $outOrderNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.trade.order.settle.query',
            'biz_content' => json_encode([
                'out_request_no' => $outOrderNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 支付宝分账回退
     */
    protected function returnAlipaySharing(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.trade.refund',
            'biz_content' => json_encode([
                'out_request_no' => $params['out_return_no'],
                'trade_no' => $params['transaction_id'] ?? '',
                'refund_amount' => (float) $params['return_amount'],
                'refund_reason' => $params['description'] ?? '分账回退',
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝分账回退结果
     */
    protected function queryAlipayReturn(string $outReturnNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.trade.fastpay.refund.query',
            'biz_content' => json_encode([
                'out_request_no' => $outReturnNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 支付宝解冻剩余资金
     */
    protected function unfreezeAlipay(string $transactionId): array
    {
        // 支付宝分账完成后自动解冻，无需额外操作
        return [
            'trade_no' => $transactionId,
            'status' => 'SUCCESS',
            'message' => '支付宝分账完成后自动解冻剩余资金',
        ];
    }

    /**
     * 添加支付宝分账接收方
     */
    protected function addAlipayReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['account', 'name']);

        return $this->gateway->post('', [
            'method' => 'alipay.trade.royalty.relation.bind',
            'biz_content' => json_encode([
                'receiver_list' => [
                    [
                        'type' => $receiver['type'] ?? 'userId',
                        'account' => $receiver['account'],
                        'name' => $receiver['name'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 删除支付宝分账接收方
     */
    protected function removeAlipayReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['account']);

        return $this->gateway->post('', [
            'method' => 'alipay.trade.royalty.relation.unbind',
            'biz_content' => json_encode([
                'receiver_list' => [
                    [
                        'type' => $receiver['type'] ?? 'userId',
                        'account' => $receiver['account'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== Stripe Connect 分账实现 ==================== */

    /**
     * 创建 Stripe Transfer 分账
     */
    protected function createStripeSharing(array $params): array
    {
        $results = [];

        foreach ($params['receivers'] as $index => $receiver) {
            $this->validateRequired($receiver, ['account', 'amount']);

            $transferData = [
                'amount' => (int) $receiver['amount'],
                'currency' => strtolower($receiver['currency'] ?? 'usd'),
                'destination' => $receiver['account'],
            ];

            if (isset($receiver['source_transaction'])) {
                $transferData['source_transaction'] = $receiver['source_transaction'];
            } elseif (isset($params['transaction_id'])) {
                $transferData['source_transaction'] = $params['transaction_id'];
            }

            $results[] = $this->gateway->post('v1/transfers', $transferData, [
                'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
            ]);
        }

        return [
            'out_order_no' => $params['out_order_no'],
            'transfers' => $results,
            'count' => count($results),
        ];
    }

    /**
     * 查询 Stripe Transfer
     */
    protected function queryStripeSharing(string $outOrderNo): array
    {
        return $this->gateway->get('v1/transfers', [
            'metadata[out_order_no]' => $outOrderNo,
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * Stripe 分账回退（创建 Reversal）
     */
    protected function returnStripeSharing(array $params): array
    {
        $transferId = $params['transfer_id'] ?? '';

        if ($transferId === '') {
            throw PayException::paramError('Stripe 分账回退需要提供 transfer_id');
        }

        return $this->gateway->post("v1/transfers/{$transferId}/reversals", [
            'amount' => (int) $params['return_amount'],
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * 查询 Stripe Reversal
     */
    protected function queryStripeReturn(string $outReturnNo): array
    {
        return $this->gateway->get('v1/transfer_reversals/' . $outReturnNo, [], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * Stripe 解冻剩余资金
     */
    protected function unfreezeStripe(string $transactionId): array
    {
        // Stripe 无冻结概念，Transfer 即时到账
        return [
            'payment_intent' => $transactionId,
            'status' => 'SUCCESS',
            'message' => 'Stripe 无资金冻结机制，Transfer 即时到账',
        ];
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
        // 通过反射获取网关的 config 属性
        $reflection = new \ReflectionClass($this->gateway);

        if ($reflection->hasProperty('config')) {
            $property = $reflection->getProperty('config');
            $property->setAccessible(true);
            $config = $property->getValue($this->gateway);

            return $config[$key] ?? $default;
        }

        return $default;
    }
}
