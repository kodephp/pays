<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 转账插件
 *
 * 为支持转账/企业付款的网关提供统一的转账管理能力。
 * 支持单笔转账、批量转账、查询转账结果、获取电子回单。
 *
 * 支持网关：
 * - 微信支付（企业付款到零钱、企业付款到银行卡）
 * - 支付宝（单笔转账、批量转账）
 * - Stripe（Payout）
 *
 * 使用示例：
 * ```php
 * $plugin = new TransferPlugin($wechatGateway);
 *
 * // 单笔转账到零钱
 * $result = $plugin->single([
 *     'out_biz_no'  => 'TRANSFER_' . date('YmdHis'),
 *     'amount'      => 100,
 *     'recipient'   => ['type' => 'openid', 'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', 'name' => '张三'],
 *     'description' => '佣金提现',
 * ]);
 *
 * // 批量转账
 * $result = $plugin->batch([
 *     'out_biz_no' => 'BATCH_' . date('YmdHis'),
 *     'transfer_detail_list' => [
 *         ['out_detail_no' => 'D001', 'amount' => 100, 'recipient' => [...], 'remark' => '佣金'],
 *         ['out_detail_no' => 'D002', 'amount' => 200, 'recipient' => [...], 'remark' => '奖励'],
 *     ],
 * ]);
 * ```
 */
class TransferPlugin
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
     * 发起单笔转账
     *
     * @param array<string, mixed> $params 转账参数
     *        - out_biz_no: 商户转账单号
     *        - amount: 转账金额（微信单位为分）
     *        - recipient: 接收方信息
     *          微信: {type: openid/bank_card, account, name, bank_name?}
     *          支付宝: {type: ALIPAY_USER_ID/ALIPAY_LOGON_ID, account, name}
     *          Stripe: {type: connect_account, account}
     *        - description: 转账备注/说明
     * @return array<string, mixed> 转账结果
     * @throws PayException
     */
    public function single(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->singleWechatTransfer($params),
            'alipay' => $this->singleAlipayTransfer($params),
            'stripe' => $this->singleStripeTransfer($params),
            default => throw PayException::invalidArgument('当前网关不支持转账功能'),
        };
    }

    /**
     * 发起批量转账
     *
     * @param array<string, mixed> $params 批量转账参数
     *        - out_biz_no: 商户批量单号
     *        - transfer_detail_list: 明细列表
     *          [{out_detail_no, amount, recipient: {type, account, name}, remark}]
     * @return array<string, mixed>
     * @throws PayException
     */
    public function batch(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        if (!is_array($params['transfer_detail_list']) || empty($params['transfer_detail_list'])) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        return match ($this->gateway::getName()) {
            'wechat' => $this->batchWechatTransfer($params),
            'alipay' => $this->batchAlipayTransfer($params),
            'stripe' => $this->batchStripeTransfer($params),
            default => throw PayException::invalidArgument('当前网关不支持批量转账'),
        };
    }

    /**
     * 查询转账结果
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outBizNo): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatTransfer($outBizNo),
            'alipay' => $this->queryAlipayTransfer($outBizNo),
            'stripe' => $this->queryStripeTransfer($outBizNo),
            default => throw PayException::invalidArgument('当前网关不支持转账查询'),
        };
    }

    /**
     * 查询转账电子回单
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function receipt(string $outBizNo): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->receiptWechatTransfer($outBizNo),
            'alipay' => $this->receiptAlipayTransfer($outBizNo),
            default => throw PayException::invalidArgument('当前网关不支持电子回单'),
        };
    }

    /* ==================== 微信支付转账实现 ==================== */

    /**
     * 微信单笔转账到零钱
     */
    protected function singleWechatTransfer(array $params): array
    {
        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['type', 'account', 'name']);

        $requestData = [
            'mch_appid' => $this->getGatewayConfig('app_id'),
            'mchid' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'partner_trade_no' => $params['out_biz_no'],
            'openid' => $recipient['account'],
            'check_name' => 'FORCE_CHECK',
            're_user_name' => $recipient['name'],
            'amount' => (int) $params['amount'],
            'desc' => $params['description'] ?? '企业付款',
            'spbill_create_ip' => $params['client_ip'] ?? '127.0.0.1',
        ];

        return $this->gateway->post('mmpaymkttransfers/promotion/transfers', $requestData);
    }

    /**
     * 微信批量转账到零钱
     */
    protected function batchWechatTransfer(array $params): array
    {
        $transferList = array_map(function (array $item): array {
            $recipient = $item['recipient'];
            return [
                'out_detail_no' => $item['out_detail_no'],
                'transfer_amount' => (int) $item['amount'],
                'transfer_remark' => $item['remark'] ?? '',
                'openid' => $recipient['account'],
                'user_name' => $recipient['name'] ?? '',
            ];
        }, $params['transfer_detail_list']);

        return $this->gateway->post('v3/transfer/batches', [
            'appid' => $this->getGatewayConfig('app_id'),
            'out_batch_no' => $params['out_biz_no'],
            'batch_name' => $params['batch_name'] ?? '批量转账',
            'batch_remark' => $params['batch_remark'] ?? '',
            'total_amount' => array_sum(array_column($params['transfer_detail_list'], 'amount')),
            'total_num' => count($params['transfer_detail_list']),
            'transfer_detail_list' => $transferList,
        ]);
    }

    /**
     * 查询微信转账结果
     */
    protected function queryWechatTransfer(string $outBizNo): array
    {
        return $this->gateway->get("v3/transfer/batches/out-batch-no/{$outBizNo}");
    }

    /**
     * 查询微信转账电子回单
     */
    protected function receiptWechatTransfer(string $outBizNo): array
    {
        return $this->gateway->get("v3/transfer/batches/out-batch-no/{$outBizNo}/details/out-detail-no/{$outBizNo}/electronic-receipt");
    }

    /* ==================== 支付宝转账实现 ==================== */

    /**
     * 支付宝单笔转账
     */
    protected function singleAlipayTransfer(array $params): array
    {
        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['type', 'account']);

        return $this->gateway->post('', [
            'method' => 'alipay.fund.trans.uni.transfer',
            'biz_content' => json_encode([
                'out_biz_no' => $params['out_biz_no'],
                'trans_amount' => number_format($params['amount'] / 100, 2),
                'product_code' => 'TRANS_ACCOUNT_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'order_title' => $params['description'] ?? '转账',
                'payee_info' => [
                    'identity_type' => $recipient['type'],
                    'identity' => $recipient['account'],
                    'name' => $recipient['name'] ?? '',
                ],
                'remark' => $params['description'] ?? '',
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 支付宝批量转账
     */
    protected function batchAlipayTransfer(array $params): array
    {
        $detailList = array_map(function (array $item): array {
            $recipient = $item['recipient'];
            return [
                'out_biz_no' => $item['out_detail_no'],
                'trans_amount' => number_format($item['amount'] / 100, 2),
                'product_code' => 'TRANS_ACCOUNT_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'order_title' => $item['remark'] ?? '转账',
                'payee_info' => [
                    'identity_type' => $recipient['type'] ?? 'ALIPAY_USER_ID',
                    'identity' => $recipient['account'],
                    'name' => $recipient['name'] ?? '',
                ],
            ];
        }, $params['transfer_detail_list']);

        return $this->gateway->post('', [
            'method' => 'alipay.fund.trans.batch.create',
            'biz_content' => json_encode([
                'out_biz_no' => $params['out_biz_no'],
                'product_code' => 'TRANS_ACCOUNT_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'total_trans_amount' => number_format(array_sum(array_column($params['transfer_detail_list'], 'amount')) / 100, 2),
                'total_count' => count($params['transfer_detail_list']),
                'order_detail' => $detailList,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝转账结果
     */
    protected function queryAlipayTransfer(string $outBizNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.trans.common.query',
            'biz_content' => json_encode([
                'product_code' => 'TRANS_ACCOUNT_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'out_biz_no' => $outBizNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝转账电子回单
     */
    protected function receiptAlipayTransfer(string $outBizNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.trans.invoice.query',
            'biz_content' => json_encode([
                'out_biz_no' => $outBizNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== Stripe Payout 实现 ==================== */

    /**
     * Stripe 单笔 Payout
     */
    protected function singleStripeTransfer(array $params): array
    {
        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['account']);

        return $this->gateway->post('v1/payouts', [
            'amount' => (int) $params['amount'],
            'currency' => strtolower($params['currency'] ?? 'usd'),
            'destination' => $recipient['account'],
            'description' => $params['description'] ?? '',
            'metadata' => [
                'out_biz_no' => $params['out_biz_no'],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * Stripe 批量 Payout
     */
    protected function batchStripeTransfer(array $params): array
    {
        $results = [];

        foreach ($params['transfer_detail_list'] as $item) {
            $results[] = $this->singleStripeTransfer([
                'out_biz_no' => $item['out_detail_no'],
                'amount' => $item['amount'],
                'currency' => $item['currency'] ?? 'usd',
                'recipient' => $item['recipient'],
                'description' => $item['remark'] ?? '',
            ]);
        }

        return [
            'out_biz_no' => $params['out_biz_no'],
            'payouts' => $results,
            'count' => count($results),
        ];
    }

    /**
     * 查询 Stripe Payout
     */
    protected function queryStripeTransfer(string $outBizNo): array
    {
        return $this->gateway->get('v1/payouts', [
            'metadata[out_biz_no]' => $outBizNo,
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
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
