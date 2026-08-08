<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 统一收款入口值对象
 *
 * 不可变包装 {@see UnifiedQrRouter} 在内存/持久化中维护的入口数据，
 * 提供类型安全的访问器，替代裸数组操作。内部状态变更（下单、支付、关闭）
 * 由路由器通过替换实例完成，本对象本身保持不可变。
 *
 * 状态机：pending → ordered → paid（终态） / closed（终态）
 *
 * 使用示例：
 * ```php
 * $entry = $router->createEntry(['wechat', 'alipay'], 100, '商品付款');
 * echo $entry->getRouterId();   // UR20260808120000AB12CD
 * echo $entry->getQrContent();  // https://pay.kodephp.com/r/UR...
 * if ($entry->isPending()) { ... }
 * ```
 */
final class QrEntry
{
    /** 入口状态：待支付 */
    public const string STATUS_PENDING = 'pending';

    /** 入口状态：已下单（用户已选通道并生成动态订单码） */
    public const string STATUS_ORDERED = 'ordered';

    /** 入口状态：已支付（终态） */
    public const string STATUS_PAID = 'paid';

    /** 入口状态：已关闭/失败（终态） */
    public const string STATUS_CLOSED = 'closed';

    /**
     * @param string $routerId 统一入口 ID
     * @param array<int, string> $channels 支持的通道标识列表
     * @param int $amount 收款金额（最小货币单位，如分）
     * @param string $description 收款说明
     * @param string $status 入口状态（见 STATUS_* 常量）
     * @param string|null $channel 用户已选通道（下单后填充）
     * @param string|null $outTradeNo 商户订单号（下单后填充）
     * @param string|null $payUrl 动态订单支付链接（下单后填充）
     * @param string|null $qrContent 二维码内容（待支付时为统一入口 URL，下单后为动态订单链接）
     * @param array<string, mixed>|null $attach 附加数据
     * @param int|null $createdAt 创建时间戳
     * @param int|null $paidAt 支付完成时间戳
     */
    public function __construct(
        public readonly string $routerId,
        public readonly array $channels,
        public readonly int $amount,
        public readonly string $description,
        public readonly string $status,
        public readonly ?string $channel = null,
        public readonly ?string $outTradeNo = null,
        public readonly ?string $payUrl = null,
        public readonly ?string $qrContent = null,
        public readonly ?array $attach = null,
        public readonly ?int $createdAt = null,
        public readonly ?int $paidAt = null,
    ) {
    }

    /**
     * 从原始数组重建入口（用于持久化读取/路由器内部存储）
     *
     * @param array<string, mixed> $data
     * @throws PayException 当缺少 router_id 或 amount 时
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['router_id']) || !is_string($data['router_id'])) {
            throw PayException::paramError('QrEntry 数据缺少 router_id');
        }

        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            throw PayException::paramError('QrEntry 数据缺少数值型 amount');
        }

        $channels = $data['channels'] ?? [];

        return new self(
            routerId: $data['router_id'],
            channels: is_array($channels) ? array_values($channels) : [],
            amount: (int) $data['amount'],
            description: (string) ($data['description'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_PENDING),
            channel: isset($data['channel']) ? (string) $data['channel'] : null,
            outTradeNo: isset($data['out_trade_no']) ? (string) $data['out_trade_no'] : null,
            payUrl: isset($data['pay_url']) ? (string) $data['pay_url'] : null,
            qrContent: isset($data['qr_content']) ? (string) $data['qr_content'] : null,
            attach: $data['attach'] ?? null,
            createdAt: isset($data['created_at']) ? (int) $data['created_at'] : null,
            paidAt: isset($data['paid_at']) ? (int) $data['paid_at'] : null,
        );
    }

    /**
     * 还原为原始数组（用于持久化存储）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'router_id' => $this->routerId,
            'channels' => $this->channels,
            'amount' => $this->amount,
            'description' => $this->description,
            'status' => $this->status,
            'channel' => $this->channel,
            'out_trade_no' => $this->outTradeNo,
            'pay_url' => $this->payUrl,
            'qr_content' => $this->qrContent,
            'attach' => $this->attach,
            'created_at' => $this->createdAt,
            'paid_at' => $this->paidAt,
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * 获取统一入口 ID
     */
    public function getRouterId(): string
    {
        return $this->routerId;
    }

    /**
     * 获取支持的通道标识列表
     *
     * @return array<int, string>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * 获取收款金额（最小货币单位）
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * 获取收款说明
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 获取入口状态
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 获取用户已选通道（下单后填充）
     */
    public function getChannel(): ?string
    {
        return $this->channel;
    }

    /**
     * 获取商户订单号（下单后填充）
     */
    public function getOutTradeNo(): ?string
    {
        return $this->outTradeNo;
    }

    /**
     * 获取动态订单支付链接（下单后填充）
     */
    public function getPayUrl(): ?string
    {
        return $this->payUrl;
    }

    /**
     * 获取附加数据
     *
     * @return array<string, mixed>|null
     */
    public function getAttach(): ?array
    {
        return $this->attach;
    }

    /**
     * 获取创建时间戳
     */
    public function getCreatedAt(): ?int
    {
        return $this->createdAt;
    }

    /**
     * 获取支付完成时间戳
     */
    public function getPaidAt(): ?int
    {
        return $this->paidAt;
    }

    public function isOrdered(): bool
    {
        return $this->status === self::STATUS_ORDERED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * 是否处于可下单状态（待支付或已下单）
     */
    public function isRoutable(): bool
    {
        return $this->isPending() || $this->isOrdered();
    }

    /**
     * 获取用于渲染二维码的内容
     *
     * 下单前返回统一入口 URL（qr_content），下单后返回动态订单支付链接（payUrl）。
     *
     * @return string|null
     */
    public function getQrContent(): ?string
    {
        return $this->payUrl ?? $this->qrContent;
    }

    /**
     * 获取动态订单支付链接（下单后才有值）
     */
    public function getCodeUrl(): ?string
    {
        return $this->payUrl;
    }
}
