<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

/**
 * 支付结果统一响应对象
 *
 * 封装支付网关返回的结果，提供统一的访问方式，
 * 避免直接操作原始数组带来的不便和类型安全问题。
 *
 * 使用示例：
 * ```php
 * $response = $gateway->createOrder($params);
 * $payResponse = new PayResponse($response);
 *
 * if ($payResponse->isSuccess()) {
 *     $orderId = $payResponse->get('out_trade_no');
 *     $payUrl = $payResponse->get('pay_url');
 * }
 * ```
 */
class PayResponse
{
    /**
     * 原始响应数据
     *
     * @var array<string, mixed>
     */
    protected array $raw;

    /**
     * 是否成功
     */
    protected bool $success;

    /**
     * 错误码
     */
    protected ?string $code;

    /**
     * 错误信息
     */
    protected ?string $message;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $raw 原始响应数据
     */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
        $this->success = ($raw['success'] ?? true) === true;
        $this->code = $raw['code'] ?? null;
        $this->message = $raw['message'] ?? null;
    }

    /**
     * 判断是否成功
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * 判断是否失败
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return !$this->success;
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
     * 获取指定字段值
     *
     * @param string $key 字段名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    /**
     * 判断是否存在指定字段
     *
     * @param string $key 字段名
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->raw);
    }

    /**
     * 获取错误码
     *
     * @return string|null
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * 获取错误信息
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * 获取商户订单号
     *
     * @return string|null
     */
    public function getOutTradeNo(): ?string
    {
        return $this->get('out_trade_no')
            ?? $this->get('out_order_no')
            ?? $this->get('order_id')
            ?? null;
    }

    /**
     * 获取第三方交易号
     *
     * @return string|null
     */
    public function getTransactionId(): ?string
    {
        return $this->get('transaction_id')
            ?? $this->get('trade_no')
            ?? $this->get('qry_id')
            ?? null;
    }

    /**
     * 获取支付 URL（扫码支付、H5 支付等）
     *
     * @return string|null
     */
    public function getPayUrl(): ?string
    {
        return $this->get('code_url')
            ?? $this->get('pay_url')
            ?? $this->get('mweb_url')
            ?? $this->get('url')
            ?? null;
    }

    /**
     * 获取预支付交易会话标识
     *
     * @return string|null
     */
    public function getPrepayId(): ?string
    {
        return $this->get('prepay_id') ?? null;
    }

    /**
     * 获取实际使用的支付渠道（聚合支付场景）
     *
     * @return string|null
     */
    public function getChannel(): ?string
    {
        return $this->get('_channel') ?? null;
    }

    /**
     * 获取支付金额
     *
     * @return int|float|null
     */
    public function getAmount(): int|float|null
    {
        return $this->get('total_fee')
            ?? $this->get('amount')
            ?? $this->get('total_amount')
            ?? null;
    }

    /**
     * 获取支付状态
     *
     * @return string|null
     */
    public function getTradeStatus(): ?string
    {
        return $this->get('trade_state')
            ?? $this->get('trade_status')
            ?? $this->get('status')
            ?? null;
    }

    /**
     * 获取支付时间
     *
     * @return string|null
     */
    public function getPayTime(): ?string
    {
        return $this->get('time_end')
            ?? $this->get('gmt_payment')
            ?? $this->get('paid_at')
            ?? null;
    }

    /**
     * 获取买家标识
     *
     * @return string|null
     */
    public function getBuyerId(): ?string
    {
        return $this->get('openid')
            ?? $this->get('buyer_user_id')
            ?? $this->get('buyer_id')
            ?? null;
    }

    /**
     * 获取退款金额
     *
     * @return int|float|null
     */
    public function getRefundAmount(): int|float|null
    {
        return $this->get('refund_fee')
            ?? $this->get('refund_amount')
            ?? null;
    }

    /**
     * 获取退款状态
     *
     * @return string|null
     */
    public function getRefundStatus(): ?string
    {
        return $this->get('refund_status')
            ?? $this->get('status')
            ?? null;
    }

    /**
     * 将响应转换为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * 将响应转换为 JSON 字符串
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->raw, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 魔术方法：支持直接通过属性访问
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * 魔术方法：判断是否可通过属性访问
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
