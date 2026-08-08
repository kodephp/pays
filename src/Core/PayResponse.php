<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Enum\Currency;
use Kode\Pays\Enum\TradeType;
use Kode\Pays\Support\Money;

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
     * 注意：不同网关金额字段类型不一致（微信 total_fee 为整数分，
     * 支付宝 total_amount 为字符串元），故返回类型包含字符串。
     *
     * @return int|float|string|null
     */
    public function getAmount(): int|float|string|null
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
     * 以枚举形式获取归一化交易状态
     *
     * 将网关返回的原始状态字符串通过 {@see \Kode\Pays\Enum\TradeStatus::fromRaw()}
     * 归一化，无法识别时返回 null。
     *
     * @return \Kode\Pays\Enum\TradeStatus|null
     */
    public function getTradeStatusEnum(): ?\Kode\Pays\Enum\TradeStatus
    {
        return \Kode\Pays\Enum\TradeStatus::fromRaw($this->getTradeStatus());
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
     * 同 {@see getAmount()}，返回类型包含字符串以兼容各网关。
     *
     * @return int|float|string|null
     */
    public function getRefundAmount(): int|float|string|null
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
     * 以枚举形式获取币种
     *
     * 读取响应中的 currency / fee_type 字段，无法识别时返回 null。
     *
     * @return \Kode\Pays\Enum\Currency|null
     */
    public function getCurrencyEnum(): ?Currency
    {
        return Currency::fromCode(
            $this->get('currency') ?? $this->get('fee_type') ?? '',
        );
    }

    /**
     * 以枚举形式获取交易类型（支付场景）
     *
     * 读取响应中的 trade_type 字段，通过 {@see \Kode\Pays\Enum\TradeType::fromRaw()}
     * 归一化，无法识别时返回 null。
     *
     * @return \Kode\Pays\Enum\TradeType|null
     */
    public function getTradeTypeEnum(): ?TradeType
    {
        $raw = $this->get('trade_type');

        return TradeType::fromRaw(is_string($raw) ? $raw : null);
    }

    /**
     * 以 Money 值对象形式获取支付金额
     *
     * 自动识别金额字段（total_fee / amount / total_amount）与币种：
     * 含小数点的字符串或浮点视为「主单位（元）」，整数视为「最小单位（分）」，
     * 二者按币种小数位换算。币种优先使用传入参数，其次取响应币种，缺省按 CNY。
     *
     * @param \Kode\Pays\Enum\Currency|null $currency 显式币种（可选）
     * @return \Kode\Pays\Support\Money|null 无金额或金额非法时返回 null
     */
    public function getAmountMoney(?Currency $currency = null): ?Money
    {
        return $this->toMoney($this->getAmount(), $currency);
    }

    /**
     * 以 Money 值对象形式获取退款金额
     *
     * 识别退款金额字段（refund_fee / refund_amount），换算规则同 {@see getAmountMoney()}。
     *
     * @param \Kode\Pays\Enum\Currency|null $currency 显式币种（可选）
     * @return \Kode\Pays\Support\Money|null
     */
    public function getRefundAmountMoney(?Currency $currency = null): ?Money
    {
        return $this->toMoney($this->getRefundAmount(), $currency);
    }

    /**
     * 将原始金额值转换为 Money 值对象
     *
     * @param int|float|string|null $value 原始金额
     * @param \Kode\Pays\Enum\Currency|null $currency 显式币种（可选）
     * @return \Kode\Pays\Support\Money|null
     */
    private function toMoney(int|float|string|null $value, ?Currency $currency): ?Money
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $currency ??= $this->getCurrencyEnum() ?? Currency::CNY;
        $hasDecimal = (is_string($value) && str_contains($value, '.')) || is_float($value);

        return $hasDecimal
            ? Money::fromMajor($value, $currency)
            : Money::fromMinor((int) $value, $currency);
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
     * @throws \JsonException 当响应数据无法编码为 JSON 时抛出
     */
    public function toJson(): string
    {
        return json_encode($this->raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
