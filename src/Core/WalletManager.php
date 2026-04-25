<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

/**
 * 钱包管理器
 *
 * 统一管理各支付渠道的钱包账户，包括微信零钱、支付宝余额、银行卡、四方支付钱包等。
 * 支持账户绑定、余额查询、自动结算规则配置。
 *
 * 使用示例：
 * ```php
 * $wallet = new WalletManager();
 *
 * // 绑定微信零钱账户
 * $wallet->bind('user_001', 'wechat_wallet', [
 *     'openid' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
 *     'auto_settlement' => true,
 *     'min_amount' => 100,
 * ]);
 *
 * // 绑定银行卡
 * $wallet->bind('user_001', 'bank_card', [
 *     'card_no' => '622202************',
 *     'real_name' => '张三',
 *     'bank_code' => 'ICBC',
 *     'auto_settlement' => true,
 * ]);
 *
 * // 查询用户所有钱包
 * $wallets = $wallet->getUserWallets('user_001');
 *
 * // 获取自动结算目标账户
 * $target = $wallet->getAutoSettlementTarget('user_001', 'wechat');
 * ```
 */
class WalletManager
{
    /**
     * 用户钱包账户存储
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $wallets = [];

    /**
     * 默认结算规则
     */
    protected array $defaultRules = [
        'auto_settlement' => false,
        'min_amount' => 0,
        'settlement_type' => 'realtime',
        'settlement_time' => '00:00',
    ];

    /**
     * 支持的账户类型
     */
    protected array $supportedTypes = [
        'wechat_wallet',
        'alipay_balance',
        'bank_card',
        'unionpay_card',
        'stripe_connect',
        'paypal_wallet',
        'aggregate_wallet',
        'fourth_party_wallet',
    ];

    /**
     * 绑定钱包账户
     *
     * @param string $userId 用户 ID
     * @param string $type 账户类型
     * @param array<string, mixed> $config 账户配置
     *        - account: 账户标识（openid/支付宝账号/银行卡号等）
     *        - real_name: 真实姓名
     *        - auto_settlement: 是否自动结算
     *        - min_amount: 自动结算最小金额（分）
     *        - settlement_type: 结算类型（realtime/daily/weekly/monthly）
     *        - settlement_time: 结算时间（如 00:00）
     *        - bank_code: 银行编码（银行卡类型必填）
     *        - branch_name: 开户支行
     *        - is_default: 是否默认账户
     * @return array<string, mixed> 绑定结果
     * @throws PayException
     */
    public function bind(string $userId, string $type, array $config): array
    {
        if (!in_array($type, $this->supportedTypes, true)) {
            throw PayException::invalidArgument("不支持的账户类型：{$type}");
        }

        if (empty($config['account'])) {
            throw PayException::paramError('账户标识（account）不能为空');
        }

        $wallet = array_merge($this->defaultRules, [
            'id' => $this->generateWalletId($userId, $type),
            'user_id' => $userId,
            'type' => $type,
            'account' => $config['account'],
            'real_name' => $config['real_name'] ?? '',
            'auto_settlement' => $config['auto_settlement'] ?? false,
            'min_amount' => $config['min_amount'] ?? 0,
            'settlement_type' => $config['settlement_type'] ?? 'realtime',
            'settlement_time' => $config['settlement_time'] ?? '00:00',
            'bank_code' => $config['bank_code'] ?? '',
            'branch_name' => $config['branch_name'] ?? '',
            'is_default' => $config['is_default'] ?? false,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!isset($this->wallets[$userId])) {
            $this->wallets[$userId] = [];
        }

        // 如果设为默认，取消其他默认
        if ($wallet['is_default']) {
            foreach ($this->wallets[$userId] as &$existing) {
                $existing['is_default'] = false;
            }
        }

        $this->wallets[$userId][] = $wallet;

        return [
            'success' => true,
            'wallet_id' => $wallet['id'],
            'type' => $type,
            'message' => '钱包账户绑定成功',
        ];
    }

    /**
     * 解绑钱包账户
     *
     * @param string $userId 用户 ID
     * @param string $walletId 钱包账户 ID
     * @return array<string, mixed>
     */
    public function unbind(string $userId, string $walletId): array
    {
        if (!isset($this->wallets[$userId])) {
            return ['success' => false, 'message' => '用户未绑定任何钱包'];
        }

        $found = false;
        $this->wallets[$userId] = array_values(array_filter(
            $this->wallets[$userId],
            function (array $wallet) use ($walletId, &$found): bool {
                if ($wallet['id'] === $walletId) {
                    $found = true;
                    return false;
                }
                return true;
            }
        ));

        return [
            'success' => $found,
            'message' => $found ? '钱包账户解绑成功' : '未找到指定钱包账户',
        ];
    }

    /**
     * 获取用户所有钱包账户
     *
     * @param string $userId 用户 ID
     * @return array<int, array<string, mixed>>
     */
    public function getUserWallets(string $userId): array
    {
        return $this->wallets[$userId] ?? [];
    }

    /**
     * 获取指定类型的钱包账户
     *
     * @param string $userId 用户 ID
     * @param string $type 账户类型
     * @return array<string, mixed>|null
     */
    public function getWalletByType(string $userId, string $type): ?array
    {
        $wallets = $this->getUserWallets($userId);

        foreach ($wallets as $wallet) {
            if ($wallet['type'] === $type && $wallet['status'] === 'active') {
                return $wallet;
            }
        }

        return null;
    }

    /**
     * 获取默认钱包账户
     *
     * @param string $userId 用户 ID
     * @return array<string, mixed>|null
     */
    public function getDefaultWallet(string $userId): ?array
    {
        $wallets = $this->getUserWallets($userId);

        foreach ($wallets as $wallet) {
            if ($wallet['is_default'] && $wallet['status'] === 'active') {
                return $wallet;
            }
        }

        // 如果没有默认账户，返回第一个活跃账户
        foreach ($wallets as $wallet) {
            if ($wallet['status'] === 'active') {
                return $wallet;
            }
        }

        return null;
    }

    /**
     * 获取自动结算目标账户
     *
     * 根据支付网关自动匹配对应的钱包类型
     *
     * @param string $userId 用户 ID
     * @param string $gatewayName 网关名称
     * @return array<string, mixed>|null
     */
    public function getAutoSettlementTarget(string $userId, string $gatewayName): ?array
    {
        $typeMap = [
            'wechat' => 'wechat_wallet',
            'alipay' => 'alipay_balance',
            'stripe' => 'stripe_connect',
            'paypal' => 'paypal_wallet',
            'aggregate' => 'aggregate_wallet',
        ];

        $targetType = $typeMap[$gatewayName] ?? '';

        if ($targetType !== '') {
            $wallet = $this->getWalletByType($userId, $targetType);
            if ($wallet !== null && ($wallet['auto_settlement'] ?? false)) {
                return $wallet;
            }
        }

        // 回退到默认账户
        return $this->getDefaultWallet($userId);
    }

    /**
     * 检查是否满足自动结算条件
     *
     * @param string $userId 用户 ID
     * @param string $gatewayName 网关名称
     * @param int $amount 待结算金额（分）
     * @return array<string, mixed>|null 满足条件返回目标账户，否则返回 null
     */
    public function checkSettlementCondition(string $userId, string $gatewayName, int $amount): ?array
    {
        $target = $this->getAutoSettlementTarget($userId, $gatewayName);

        if ($target === null) {
            return null;
        }

        $minAmount = (int) ($target['min_amount'] ?? 0);

        if ($amount < $minAmount) {
            return null;
        }

        // 检查结算时间规则
        if ($target['settlement_type'] !== 'realtime') {
            $currentTime = date('H:i');
            $settlementTime = $target['settlement_time'] ?? '00:00';

            if ($currentTime < $settlementTime) {
                return null;
            }
        }

        return $target;
    }

    /**
     * 更新钱包账户状态
     *
     * @param string $userId 用户 ID
     * @param string $walletId 钱包账户 ID
     * @param string $status 状态（active/frozen/closed）
     * @return array<string, mixed>
     */
    public function updateStatus(string $userId, string $walletId, string $status): array
    {
        $validStatuses = ['active', 'frozen', 'closed'];

        if (!in_array($status, $validStatuses, true)) {
            return ['success' => false, 'message' => '无效的状态值'];
        }

        if (!isset($this->wallets[$userId])) {
            return ['success' => false, 'message' => '用户未绑定任何钱包'];
        }

        foreach ($this->wallets[$userId] as &$wallet) {
            if ($wallet['id'] === $walletId) {
                $wallet['status'] = $status;
                $wallet['updated_at'] = date('Y-m-d H:i:s');
                return ['success' => true, 'message' => '状态更新成功'];
            }
        }

        return ['success' => false, 'message' => '未找到指定钱包账户'];
    }

    /**
     * 生成钱包账户 ID
     */
    protected function generateWalletId(string $userId, string $type): string
    {
        return md5($userId . $type . microtime(true));
    }
}
