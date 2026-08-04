<?php

declare(strict_types=1);

namespace Kode\Pays\Enum;

/**
 * 交易状态枚举
 *
 * 统一各支付网关对账单/查询接口返回的状态字符串，屏蔽微信、支付宝、
 * 银联、Stripe 等渠道的差异。通过 {@see TradeStatus::fromRaw()} 将原始状态
 * 归一化，便于业务侧做统一判断。
 *
 * 使用示例：
 * ```php
 * $status = TradeStatus::fromRaw($response['trade_state']);
 * if ($status === TradeStatus::SUCCESS) {
 *     // 处理支付成功
 * }
 * ```
 */
enum TradeStatus: string
{
    /** 待支付 / 未支付 */
    case PENDING = 'PENDING';

    /** 支付中（如微信刷卡支付用户输入密码阶段） */
    case USERPAYING = 'USERPAYING';

    /** 支付成功 */
    case SUCCESS = 'SUCCESS';

    /** 支付失败 */
    case FAILED = 'FAILED';

    /** 已关闭 / 已撤销订单 */
    case CLOSED = 'CLOSED';

    /** 已作废 / 已撤销（不可恢复） */
    case REVOKED = 'REVOKED';

    /** 退款中 */
    case REFUNDING = 'REFUNDING';

    /** 已退款 */
    case REFUNDED = 'REFUNDED';

    /** 部分退款 */
    case PARTIAL_REFUNDED = 'PARTIAL_REFUNDED';

    /**
     * 原始状态别名映射表（键为大写后的原始值）
     *
     * @var array<string, self>
     */
    private const ALIASES = [
        'TRADE_SUCCESS' => self::SUCCESS,
        'FINISHED' => self::SUCCESS,
        'PAID' => self::SUCCESS,
        'PAY_SUCCESS' => self::SUCCESS,
        'SETTLED' => self::SUCCESS,
        'FAIL' => self::FAILED,
        'PAYERROR' => self::FAILED,
        'NOTPAY' => self::PENDING,
        'WAIT_BUYER_PAY' => self::PENDING,
        'CLOSED' => self::CLOSED,
        'REVOKED' => self::REVOKED,
        'REFUND' => self::REFUNDED,
        'REFUNDING' => self::REFUNDING,
        'PARTIAL_REFUND' => self::PARTIAL_REFUNDED,
    ];

    /**
     * 判断是否为终态（成功 / 失败 / 关闭 / 撤销 / 退款完成）
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCESS, self::FAILED, self::CLOSED, self::REVOKED,
            self::REFUNDED, self::PARTIAL_REFUNDED => true,
            default => false,
        };
    }

    /**
     * 判断是否为成功态
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    /**
     * 从各网关原始状态字符串归一化
     *
     * @param string|null $raw 原始状态（如 trade_state / trade_status / status）
     * @return self|null 无法识别时返回 null
     */
    public static function fromRaw(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtoupper(trim($raw));

        return self::tryFrom($normalized)
            ?? self::ALIASES[$normalized]
            ?? null;
    }
}
