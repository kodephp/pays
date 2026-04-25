<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

/**
 * 资金操作约束验证器
 *
 * 对转账、分账、红包、退款等资金操作进行统一的约束验证。
 * 包括金额限制、频率限制、时间限制、账户状态验证等。
 *
 * 使用示例：
 * ```php
 * $validator = new FundConstraintValidator();
 *
 * // 配置转账约束
 * $validator->setTransferConstraints([
 *     'min_amount' => 100,
 *     'max_amount' => 200000,
 *     'daily_limit' => 1000000,
 *     'daily_count_limit' => 100,
 *     'allowed_hours' => [9, 22],
 * ]);
 *
 * // 验证转账请求
 * $result = $validator->validateTransfer([
 *     'amount' => 50000,
 *     'user_id' => 'user_001',
 * ]);
 * if (!$result['valid']) {
 *     throw new \Exception($result['message']);
 * }
 * ```
 */
class FundConstraintValidator
{
    /**
     * 转账约束配置
     */
    protected array $transferConstraints = [
        'min_amount' => 1,
        'max_amount' => PHP_INT_MAX,
        'daily_limit' => PHP_INT_MAX,
        'daily_count_limit' => PHP_INT_MAX,
        'allowed_hours' => [0, 23],
        'blacklist' => [],
        'whitelist' => [],
    ];

    /**
     * 分账约束配置
     */
    protected array $sharingConstraints = [
        'min_amount' => 1,
        'max_amount' => PHP_INT_MAX,
        'max_receivers' => 50,
        'daily_limit' => PHP_INT_MAX,
        'daily_count_limit' => PHP_INT_MAX,
        'allowed_hours' => [0, 23],
    ];

    /**
     * 红包约束配置
     */
    protected array $redPacketConstraints = [
        'min_amount' => 100,
        'max_amount' => 200000,
        'max_total_num' => 100,
        'daily_limit' => PHP_INT_MAX,
        'daily_count_limit' => PHP_INT_MAX,
        'allowed_hours' => [0, 23],
    ];

    /**
     * 退款约束配置
     */
    protected array $refundConstraints = [
        'min_amount' => 1,
        'max_amount' => PHP_INT_MAX,
        'daily_limit' => PHP_INT_MAX,
        'daily_count_limit' => PHP_INT_MAX,
        'allowed_hours' => [0, 23],
        'max_refund_ratio' => 1.0,
    ];

    /**
     * 操作记录（用于频率限制）
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $operationLogs = [];

    /**
     * 设置转账约束
     *
     * @param array<string, mixed> $constraints 约束配置
     */
    public function setTransferConstraints(array $constraints): void
    {
        $this->transferConstraints = array_merge($this->transferConstraints, $constraints);
    }

    /**
     * 设置分账约束
     *
     * @param array<string, mixed> $constraints 约束配置
     */
    public function setSharingConstraints(array $constraints): void
    {
        $this->sharingConstraints = array_merge($this->sharingConstraints, $constraints);
    }

    /**
     * 设置红包约束
     *
     * @param array<string, mixed> $constraints 约束配置
     */
    public function setRedPacketConstraints(array $constraints): void
    {
        $this->redPacketConstraints = array_merge($this->redPacketConstraints, $constraints);
    }

    /**
     * 设置退款约束
     *
     * @param array<string, mixed> $constraints 约束配置
     */
    public function setRefundConstraints(array $constraints): void
    {
        $this->refundConstraints = array_merge($this->refundConstraints, $constraints);
    }

    /**
     * 验证转账操作
     *
     * @param array<string, mixed> $params 转账参数
     *        - amount: 转账金额（分）
     *        - user_id: 操作用户 ID
     *        - recipient_account: 接收方账户
     * @return array<string, mixed> 验证结果 {valid: bool, message: string}
     */
    public function validateTransfer(array $params): array
    {
        $constraints = $this->transferConstraints;
        $amount = (int) ($params['amount'] ?? 0);
        $userId = $params['user_id'] ?? '';
        $recipient = $params['recipient_account'] ?? '';

        // 金额范围验证
        $amountCheck = $this->validateAmountRange($amount, $constraints['min_amount'], $constraints['max_amount']);
        if (!$amountCheck['valid']) {
            return $amountCheck;
        }

        // 时间限制验证
        $timeCheck = $this->validateTimeWindow($constraints['allowed_hours']);
        if (!$timeCheck['valid']) {
            return $timeCheck;
        }

        // 黑白名单验证
        $listCheck = $this->validateAccountList($recipient, $constraints['whitelist'], $constraints['blacklist']);
        if (!$listCheck['valid']) {
            return $listCheck;
        }

        // 日限额验证
        if ($userId !== '') {
            $limitCheck = $this->validateDailyLimit($userId, 'transfer', $amount, $constraints['daily_limit'], $constraints['daily_count_limit']);
            if (!$limitCheck['valid']) {
                return $limitCheck;
            }
        }

        $this->recordOperation($userId, 'transfer', $amount);

        return ['valid' => true, 'message' => '验证通过'];
    }

    /**
     * 验证分账操作
     *
     * @param array<string, mixed> $params 分账参数
     *        - amount: 分账总金额（分）
     *        - user_id: 操作用户 ID
     *        - receivers: 接收方列表
     * @return array<string, mixed>
     */
    public function validateSharing(array $params): array
    {
        $constraints = $this->sharingConstraints;
        $amount = (int) ($params['amount'] ?? 0);
        $userId = $params['user_id'] ?? '';
        $receivers = $params['receivers'] ?? [];

        // 金额范围验证
        $amountCheck = $this->validateAmountRange($amount, $constraints['min_amount'], $constraints['max_amount']);
        if (!$amountCheck['valid']) {
            return $amountCheck;
        }

        // 接收方数量验证
        $receiverCount = is_array($receivers) ? count($receivers) : 0;
        if ($receiverCount > $constraints['max_receivers']) {
            return [
                'valid' => false,
                'message' => "分账接收方数量超过限制，最大允许 {$constraints['max_receivers']} 个",
            ];
        }

        // 时间限制验证
        $timeCheck = $this->validateTimeWindow($constraints['allowed_hours']);
        if (!$timeCheck['valid']) {
            return $timeCheck;
        }

        // 日限额验证
        if ($userId !== '') {
            $limitCheck = $this->validateDailyLimit($userId, 'sharing', $amount, $constraints['daily_limit'], $constraints['daily_count_limit']);
            if (!$limitCheck['valid']) {
                return $limitCheck;
            }
        }

        $this->recordOperation($userId, 'sharing', $amount);

        return ['valid' => true, 'message' => '验证通过'];
    }

    /**
     * 验证红包操作
     *
     * @param array<string, mixed> $params 红包参数
     *        - total_amount: 红包总金额（分）
     *        - total_num: 红包个数
     *        - user_id: 操作用户 ID
     * @return array<string, mixed>
     */
    public function validateRedPacket(array $params): array
    {
        $constraints = $this->redPacketConstraints;
        $amount = (int) ($params['total_amount'] ?? 0);
        $totalNum = (int) ($params['total_num'] ?? 1);
        $userId = $params['user_id'] ?? '';

        // 金额范围验证
        $amountCheck = $this->validateAmountRange($amount, $constraints['min_amount'], $constraints['max_amount']);
        if (!$amountCheck['valid']) {
            return $amountCheck;
        }

        // 红包个数验证
        if ($totalNum > $constraints['max_total_num']) {
            return [
                'valid' => false,
                'message' => "红包个数超过限制，最大允许 {$constraints['max_total_num']} 个",
            ];
        }

        // 单个红包最小金额验证（微信要求普通红包至少 1 元，裂变红包至少 0.01 元）
        $avgAmount = (int) ($amount / $totalNum);
        if ($avgAmount < 1) {
            return [
                'valid' => false,
                'message' => '单个红包金额不能低于 0.01 元',
            ];
        }

        // 时间限制验证
        $timeCheck = $this->validateTimeWindow($constraints['allowed_hours']);
        if (!$timeCheck['valid']) {
            return $timeCheck;
        }

        // 日限额验证
        if ($userId !== '') {
            $limitCheck = $this->validateDailyLimit($userId, 'red_packet', $amount, $constraints['daily_limit'], $constraints['daily_count_limit']);
            if (!$limitCheck['valid']) {
                return $limitCheck;
            }
        }

        $this->recordOperation($userId, 'red_packet', $amount);

        return ['valid' => true, 'message' => '验证通过'];
    }

    /**
     * 验证退款操作
     *
     * @param array<string, mixed> $params 退款参数
     *        - refund_fee: 退款金额（分）
     *        - total_fee: 原订单总金额（分）
     *        - user_id: 操作用户 ID
     * @return array<string, mixed>
     */
    public function validateRefund(array $params): array
    {
        $constraints = $this->refundConstraints;
        $refundAmount = (int) ($params['refund_fee'] ?? 0);
        $totalAmount = (int) ($params['total_fee'] ?? 0);
        $userId = $params['user_id'] ?? '';

        // 金额范围验证
        $amountCheck = $this->validateAmountRange($refundAmount, $constraints['min_amount'], $constraints['max_amount']);
        if (!$amountCheck['valid']) {
            return $amountCheck;
        }

        // 退款比例验证
        if ($totalAmount > 0) {
            $ratio = $refundAmount / $totalAmount;
            if ($ratio > $constraints['max_refund_ratio']) {
                return [
                    'valid' => false,
                    'message' => '退款金额超过原订单金额',
                ];
            }
        }

        // 时间限制验证
        $timeCheck = $this->validateTimeWindow($constraints['allowed_hours']);
        if (!$timeCheck['valid']) {
            return $timeCheck;
        }

        // 日限额验证
        if ($userId !== '') {
            $limitCheck = $this->validateDailyLimit($userId, 'refund', $refundAmount, $constraints['daily_limit'], $constraints['daily_count_limit']);
            if (!$limitCheck['valid']) {
                return $limitCheck;
            }
        }

        $this->recordOperation($userId, 'refund', $refundAmount);

        return ['valid' => true, 'message' => '验证通过'];
    }

    /**
     * 验证金额范围
     */
    protected function validateAmountRange(int $amount, int $min, int $max): array
    {
        if ($amount < $min) {
            return [
                'valid' => false,
                'message' => "金额不能低于 {$min} 分",
            ];
        }

        if ($amount > $max) {
            return [
                'valid' => false,
                'message' => "金额不能高于 {$max} 分",
            ];
        }

        return ['valid' => true, 'message' => '金额验证通过'];
    }

    /**
     * 验证时间窗口
     */
    protected function validateTimeWindow(array $allowedHours): array
    {
        $currentHour = (int) date('G');
        $startHour = $allowedHours[0] ?? 0;
        $endHour = $allowedHours[1] ?? 23;

        if ($currentHour < $startHour || $currentHour > $endHour) {
            return [
                'valid' => false,
                'message' => "当前时间不在允许操作的时间段内（{$startHour}:00 - {$endHour}:59）",
            ];
        }

        return ['valid' => true, 'message' => '时间验证通过'];
    }

    /**
     * 验证账户黑白名单
     */
    protected function validateAccountList(string $account, array $whitelist, array $blacklist): array
    {
        if (!empty($blacklist) && in_array($account, $blacklist, true)) {
            return [
                'valid' => false,
                'message' => '该账户已被列入黑名单',
            ];
        }

        if (!empty($whitelist) && !in_array($account, $whitelist, true)) {
            return [
                'valid' => false,
                'message' => '该账户不在白名单中',
            ];
        }

        return ['valid' => true, 'message' => '账户验证通过'];
    }

    /**
     * 验证日限额
     */
    protected function validateDailyLimit(string $userId, string $type, int $amount, int $dailyLimit, int $countLimit): array
    {
        $today = date('Y-m-d');
        $key = "{$userId}:{$type}:{$today}";
        $logs = $this->operationLogs[$key] ?? [];

        $totalAmount = array_sum(array_column($logs, 'amount'));
        $totalCount = count($logs);

        if ($totalAmount + $amount > $dailyLimit) {
            return [
                'valid' => false,
                'message' => "今日 {$type} 金额已达上限 {$dailyLimit} 分",
            ];
        }

        if ($totalCount + 1 > $countLimit) {
            return [
                'valid' => false,
                'message' => "今日 {$type} 次数已达上限 {$countLimit} 次",
            ];
        }

        return ['valid' => true, 'message' => '限额验证通过'];
    }

    /**
     * 记录操作日志
     */
    protected function recordOperation(string $userId, string $type, int $amount): void
    {
        $today = date('Y-m-d');
        $key = "{$userId}:{$type}:{$today}";

        if (!isset($this->operationLogs[$key])) {
            $this->operationLogs[$key] = [];
        }

        $this->operationLogs[$key][] = [
            'amount' => $amount,
            'time' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 获取用户今日操作统计
     *
     * @param string $userId 用户 ID
     * @param string $type 操作类型
     * @return array<string, mixed>
     */
    public function getDailyStats(string $userId, string $type): array
    {
        $today = date('Y-m-d');
        $key = "{$userId}:{$type}:{$today}";
        $logs = $this->operationLogs[$key] ?? [];

        return [
            'total_amount' => array_sum(array_column($logs, 'amount')),
            'total_count' => count($logs),
            'logs' => $logs,
        ];
    }

    /**
     * 清空操作日志
     */
    public function clearLogs(): void
    {
        $this->operationLogs = [];
    }
}
