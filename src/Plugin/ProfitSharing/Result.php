<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin\ProfitSharing;

/**
 * 分账结果归一化包装
 *
 * 分账各网关返回的字段名不一致（status / result_code / code，transaction_id / trade_no
 * 等）。本对象对原始响应做一层类型化访问，便于业务侧统一判断，不改变底层数据。
 *
 * 用法（可选，插件方法仍返回原始数组）：
 * ```php
 * $result = ProfitSharingResult::fromArray($plugin->create($params));
 * if ($result->isSuccess()) {
 *     $txnId = $result->getTransactionId();
 * }
 * ```
 */
final class Result
{
    /**
     * @param array<string, mixed> $raw 原始响应数据
     */
    public function __construct(private readonly array $raw)
    {
    }

    /**
     * 由原始响应构建
     *
     * @param array<string, mixed> $raw
     * @return self
     */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    /**
     * 获取原始响应数据
     *
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * 归一化状态（status / result_code / code）
     *
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return $this->raw['status']
            ?? $this->raw['result_code']
            ?? $this->raw['code']
            ?? null;
    }

    /**
     * 归一化交易号（transaction_id / trade_no / payment_intent）
     *
     * @return string|null
     */
    public function getTransactionId(): ?string
    {
        return $this->raw['transaction_id']
            ?? $this->raw['trade_no']
            ?? $this->raw['payment_intent']
            ?? null;
    }

    /**
     * 归一化商户分账单号（out_order_no / out_request_no）
     *
     * @return string|null
     */
    public function getOutOrderNo(): ?string
    {
        return $this->raw['out_order_no']
            ?? $this->raw['out_request_no']
            ?? null;
    }

    /**
     * 归一化消息（message / return_msg / sub_msg）
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->raw['message']
            ?? $this->raw['return_msg']
            ?? $this->raw['sub_msg']
            ?? null;
    }

    /**
     * 是否成功（SUCCESS / FINISHED）
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        $status = $this->getStatus();

        return $status === 'SUCCESS' || $status === 'FINISHED';
    }
}
