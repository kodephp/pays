<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\WalletManager;

/**
 * 自动结算插件
 *
 * 支付成功后自动将资金结算到用户绑定的钱包账户。
 * 支持实时结算、定时结算、按金额阈值结算等多种模式。
 * 自动关联对应渠道：微信支付→微信零钱、支付宝→支付宝余额、银行卡→银行卡转账。
 *
 * 使用示例：
 * ```php
 * $walletManager = new WalletManager();
 * $walletManager->bind('user_001', 'wechat_wallet', [
 *     'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
 *     'real_name' => '张三',
 *     'auto_settlement' => true,
 *     'min_amount' => 100,
 *     'settlement_type' => 'realtime',
 * ]);
 *
 * $plugin = new AutoSettlementPlugin($wechatGateway, $walletManager);
 *
 * // 支付成功后自动触发结算
 * $order = $gateway->createOrder([...]);
 * $result = $plugin->settle('user_001', [
 *     'transaction_id' => $order['transaction_id'],
 *     'amount' => $order['total_fee'],
 *     'out_biz_no' => 'SETTLE_' . date('YmdHis'),
 * ]);
 * ```
 */
class AutoSettlementPlugin
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 钱包管理器
     */
    protected WalletManager $walletManager;

    /**
     * 结算成功回调
     *
     * @var callable|null
     */
    protected $onSettlementSuccess = null;

    /**
     * 结算失败回调
     *
     * @var callable|null
     */
    protected $onSettlementFailed = null;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关
     * @param WalletManager $walletManager 钱包管理器
     */
    public function __construct(GatewayInterface $gateway, WalletManager $walletManager)
    {
        $this->gateway = $gateway;
        $this->walletManager = $walletManager;
    }

    /**
     * 执行自动结算
     *
     * 根据用户配置的钱包规则，自动将资金结算到对应账户。
     *
     * @param string $userId 用户 ID
     * @param array<string, mixed> $params 结算参数
     *        - transaction_id: 原支付交易号
     *        - amount: 结算金额（分）
     *        - out_biz_no: 商户结算单号
     *        - description: 结算说明（可选）
     *        - force: 是否强制结算（跳过条件检查，可选）
     * @return array<string, mixed> 结算结果
     * @throws PayException
     */
    public function settle(string $userId, array $params): array
    {
        $this->validateRequired($params, ['transaction_id', 'amount', 'out_biz_no']);

        $amount = (int) $params['amount'];
        $gatewayName = $this->gateway::getName();

        // 检查是否强制结算
        $force = $params['force'] ?? false;

        if (!$force) {
            $target = $this->walletManager->checkSettlementCondition($userId, $gatewayName, $amount);
        } else {
            $target = $this->walletManager->getAutoSettlementTarget($userId, $gatewayName);
        }

        if ($target === null) {
            return [
                'success' => false,
                'settled' => false,
                'reason' => '未满足自动结算条件（未绑定钱包或金额不足）',
                'user_id' => $userId,
                'amount' => $amount,
            ];
        }

        // 根据目标账户类型执行对应的结算方式
        $result = match ($target['type']) {
            'wechat_wallet' => $this->settleToWechatWallet($target, $params),
            'alipay_balance' => $this->settleToAlipayBalance($target, $params),
            'bank_card' => $this->settleToBankCard($target, $params),
            'stripe_connect' => $this->settleToStripeConnect($target, $params),
            'paypal_wallet' => $this->settleToPaypalWallet($target, $params),
            default => throw PayException::invalidArgument("不支持的结算目标类型：{$target['type']}"),
        };

        $result['settled'] = $result['success'] ?? false;
        $result['target_type'] = $target['type'];
        $result['target_account'] = $target['account'];
        $result['amount'] = $amount;
        $result['user_id'] = $userId;

        // 触发回调
        if ($result['settled'] && $this->onSettlementSuccess !== null) {
            ($this->onSettlementSuccess)($result);
        } elseif (!$result['settled'] && $this->onSettlementFailed !== null) {
            ($this->onSettlementFailed)($result);
        }

        return $result;
    }

    /**
     * 批量结算
     *
     * 对多个用户或订单进行批量结算。
     *
     * @param array<int, array<string, mixed>> $batch 结算批次
     *        [{user_id, transaction_id, amount, out_biz_no, description}]
     * @return array<int, array<string, mixed>> 每条结算结果
     */
    public function settleBatch(array $batch): array
    {
        $results = [];

        foreach ($batch as $item) {
            try {
                $results[] = $this->settle($item['user_id'], $item);
            } catch (PayException $e) {
                $results[] = [
                    'success' => false,
                    'settled' => false,
                    'reason' => $e->getMessage(),
                    'user_id' => $item['user_id'] ?? '',
                    'amount' => $item['amount'] ?? 0,
                ];
            }
        }

        return $results;
    }

    /**
     * 查询结算结果
     *
     * @param string $outBizNo 商户结算单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outBizNo): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->gateway->post('mmpaymkttransfers/promotion/transfers', [
                'partner_trade_no' => $outBizNo,
            ]),
            'alipay' => $this->gateway->post('', [
                'method' => 'alipay.fund.trans.common.query',
                'biz_content' => json_encode([
                    'out_biz_no' => $outBizNo,
                ], JSON_UNESCAPED_UNICODE),
            ]),
            'stripe' => $this->gateway->get("v1/transfers/{$outBizNo}", [], [
                'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
            ]),
            default => throw PayException::invalidArgument('当前网关不支持结算查询'),
        };
    }

    /**
     * 注册结算成功回调
     *
     * @param callable $callback 回调函数(array $result): void
     */
    public function onSettlementSuccess(callable $callback): void
    {
        $this->onSettlementSuccess = $callback;
    }

    /**
     * 注册结算失败回调
     *
     * @param callable $callback 回调函数(array $result): void
     */
    public function onSettlementFailed(callable $callback): void
    {
        $this->onSettlementFailed = $callback;
    }

    /* ==================== 各渠道结算实现 ==================== */

    /**
     * 结算到微信零钱
     */
    protected function settleToWechatWallet(array $target, array $params): array
    {
        $transferPlugin = new TransferPlugin($this->gateway);

        return $transferPlugin->single([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'recipient' => [
                'type' => 'openid',
                'account' => $target['account'],
                'name' => $target['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '自动结算',
        ]);
    }

    /**
     * 结算到支付宝余额
     */
    protected function settleToAlipayBalance(array $target, array $params): array
    {
        $transferPlugin = new TransferPlugin($this->gateway);

        return $transferPlugin->single([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'recipient' => [
                'type' => 'ALIPAY_USER_ID',
                'account' => $target['account'],
                'name' => $target['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '自动结算',
        ]);
    }

    /**
     * 结算到银行卡
     */
    protected function settleToBankCard(array $target, array $params): array
    {
        $gatewayName = $this->gateway::getName();

        return match ($gatewayName) {
            'wechat' => $this->gateway->post('mmpaymkttransfers/pay_bank', [
                'partner_trade_no' => $params['out_biz_no'],
                'enc_bank_no' => $this->encryptBankCard($target['account']),
                'enc_true_name' => $this->encryptBankCard($target['real_name'] ?? ''),
                'bank_code' => $target['bank_code'] ?? '',
                'amount' => (int) $params['amount'],
                'desc' => $params['description'] ?? '自动结算到银行卡',
            ]),
            'alipay' => $this->gateway->post('', [
                'method' => 'alipay.fund.trans.uni.transfer',
                'biz_content' => json_encode([
                    'out_biz_no' => $params['out_biz_no'],
                    'trans_amount' => number_format($params['amount'] / 100, 2),
                    'product_code' => 'TRANS_BANKCARD_NO_PWD',
                    'biz_scene' => 'DIRECT_TRANSFER',
                    'order_title' => '自动结算',
                    'payee_info' => [
                        'identity_type' => 'BANKCARD_ACCOUNT',
                        'identity' => $target['account'],
                        'name' => $target['real_name'] ?? '',
                        'bank_code' => $target['bank_code'] ?? '',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            default => throw PayException::invalidArgument("网关 {$gatewayName} 不支持结算到银行卡"),
        };
    }

    /**
     * 结算到 Stripe Connect 账户
     */
    protected function settleToStripeConnect(array $target, array $params): array
    {
        return $this->gateway->post('v1/transfers', [
            'amount' => (int) $params['amount'],
            'currency' => 'usd',
            'destination' => $target['account'],
            'description' => $params['description'] ?? 'Auto settlement',
            'metadata' => [
                'out_biz_no' => $params['out_biz_no'],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * 结算到 PayPal 钱包
     */
    protected function settleToPaypalWallet(array $target, array $params): array
    {
        return $this->gateway->post('v1/payments/payouts', [
            'sender_batch_header' => [
                'sender_batch_id' => $params['out_biz_no'],
                'email_subject' => 'Auto Settlement',
                'email_message' => $params['description'] ?? 'Your payment has been settled.',
            ],
            'items' => [
                [
                    'recipient_type' => 'EMAIL',
                    'amount' => [
                        'value' => number_format($params['amount'] / 100, 2),
                        'currency' => 'USD',
                    ],
                    'receiver' => $target['account'],
                    'sender_item_id' => $params['out_biz_no'],
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('access_token', ''),
        ]);
    }

    /* ==================== 通用工具方法 ==================== */

    /**
     * 加密银行卡信息
     */
    protected function encryptBankCard(string $data): string
    {
        $publicKey = $this->getGatewayConfig('bank_public_key');

        if (empty($publicKey)) {
            return base64_encode($data);
        }

        openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        return base64_encode($encrypted);
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
}
