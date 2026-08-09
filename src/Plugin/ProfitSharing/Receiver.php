<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin\ProfitSharing;

use Kode\Pays\Enum\Currency;
use Kode\Pays\Support\Money;

/**
 * 分账接收方值对象（不可变）
 *
 * 将「一个分账接收方」封装为类型安全的不可变对象：金额统一用 {@see Money}
 * （最小货币单位整数）承载，规避浮点误差；网关差异（微信分 / 支付宝元 / Stripe 美分 /
 * 抖音分 / 银联分）由各 {@see to*Array()} 映射方法在调用时按需换算。
 *
 * 使用示例：
 * ```php
 * use Kode\Pays\Plugin\ProfitSharing\Receiver;
 * use Kode\Pays\Support\Money;
 *
 * $receiver = new Receiver(
 *     type: 'MERCHANT_ID',
 *     account: '1234567890',
 *     name: '供应商A',
 *     amount: Money::fromMinor(100, 'CNY'), // 1.00 元
 *     description: '供应商分账',
 *     relationType: 'SERVICE_PROVIDER',
 * );
 *
 * // 或基于数组构建（amount 视为最小货币单位）
 * $receiver = Receiver::fromArray([
 *     'type' => 'PERSONAL_OPENID',
 *     'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
 *     'name' => '推广者',
 *     'amount' => 50,
 *     'currency' => 'CNY',
 * ]);
 * ```
 */
final class Receiver
{
    /**
     * @param string $type 接收方类型（微信 MERCHANT_ID/PERSONAL_OPENID，支付宝 userId/loginName 等）
     * @param string $account 接收方账号（微信 openid/商户号，支付宝 userId，Stripe account id 等）
     * @param string|null $name 接收方名称（微信/支付宝必填，Stripe 可选）
     * @param Money $amount 分账金额（最小货币单位）
     * @param string $description 分账描述
     * @param string $relationType 分账关系类型（微信 relation_type，如 SERVICE_PROVIDER）
     */
    public function __construct(
        public readonly string $type,
        public readonly string $account,
        public readonly ?string $name,
        public readonly Money $amount,
        public readonly string $description,
        public readonly string $relationType,
    ) {
    }

    /**
     * 由数组构建接收方
     *
     * 数组形式下 `amount` 视为「最小货币单位」（与微信分、Stripe 美分一致），
     * 也可直接传入 {@see Money} 实例；`currency` 缺省为 CNY。
     *
     * @param array<string, mixed> $data 接收方数据
     * @param Currency|null $defaultCurrency 显式币种（可选，覆盖 data['currency']）
     * @return self
     */
    public static function fromArray(array $data, ?Currency $defaultCurrency = null): self
    {
        $currency = $defaultCurrency
            ?? Currency::fromCode((string) ($data['currency'] ?? 'CNY'))
            ?? Currency::CNY;

        $money = $data['amount'] instanceof Money
            ? $data['amount']
            : Money::fromMinor((int) ($data['amount'] ?? 0), $currency);

        return new self(
            type: (string) ($data['type'] ?? ''),
            account: (string) ($data['account'] ?? ''),
            name: isset($data['name']) ? (string) $data['name'] : null,
            amount: $money,
            description: (string) ($data['description'] ?? $data['desc'] ?? '分账'),
            relationType: (string) ($data['relation_type'] ?? 'SERVICE_PROVIDER'),
        );
    }

    /**
     * 归一化数组（含币种与最小单位金额）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'account' => $this->account,
            'name' => $this->name,
            'amount' => $this->amount->getMinorAmount(),
            'currency' => $this->amount->getCurrency()->value,
            'description' => $this->description,
            'relation_type' => $this->relationType,
        ];
    }

    /**
     * 转为微信分账接收方参数（amount 为分）
     *
     * @return array<string, mixed>
     */
    public function toWechatArray(): array
    {
        return [
            'type' => $this->type,
            'account' => $this->account,
            'amount' => $this->amount->getMinorAmount(),
            'description' => $this->description,
        ];
    }

    /**
     * 转为支付宝分账参数（amount 转为主单位元）
     *
     * @return array<string, mixed>
     */
    public function toAlipayArray(): array
    {
        $transInType = match ($this->type) {
            'PERSONAL_OPENID', 'PERSONAL_SUB_OPENID' => 'loginName',
            default => 'userId',
        };

        return [
            'trans_in_type' => $transInType,
            'trans_in' => $this->account,
            'amount' => $this->amount->getAmount(),
            'desc' => $this->description,
        ];
    }

    /**
     * 转为 Stripe Transfer 参数（amount 为货币最小单位，如美分）
     *
     * @return array<string, mixed>
     */
    public function toStripeArray(): array
    {
        return [
            'account' => $this->account,
            'amount' => $this->amount->getMinorAmount(),
            'currency' => strtolower($this->amount->getCurrency()->value),
        ];
    }

    /**
     * 转为抖音 ecpay 分账接收方参数
     *
     * 抖音 ecpay「发起结算及分账」(settle) 的 settle_params 仅使用
     * merchant_uid（分账方商户号，即进件商户 id）+ amount（分），
     * 故只映射这两个字段。
     *
     * @return array<string, mixed>
     */
    public function toDouyinArray(): array
    {
        return [
            'merchant_uid' => $this->account,
            'amount' => $this->amount->getMinorAmount(),
        ];
    }

    /**
     * 转为银联全渠道 accSplitData 分账域接收方参数
     *
     * 银联全渠道无独立接收方结构，分账接收方经 accSplitData 分账域承载，
     * 仅使用 merchant_uid（分账方商户号）+ amount（分，与 txnAmt 同最小货币单位）。
     *
     * @return array<string, mixed>
     */
    public function toUnionPayArray(): array
    {
        return [
            'merchant_uid' => $this->account,
            'amount' => $this->amount->getMinorAmount(),
        ];
    }

    /**
     * 转为美团分账接收方参数
     *
     * 美团分账域仅使用 account（分账方商户号 / 用户标识）+ amount（分）。
     *
     * @return array<string, mixed>
     */
    public function toMeituanArray(): array
    {
        return [
            'account' => $this->account,
            'amount' => $this->amount->getMinorAmount(),
        ];
    }

    /**
     * 转为京东分账接收方参数
     *
     * 京东分账域仅使用 account（分账方商户号 / 用户标识）+ amount（分）。
     *
     * @return array<string, mixed>
     */
    public function toJdArray(): array
    {
        return [
            'account' => $this->account,
            'amount' => $this->amount->getMinorAmount(),
        ];
    }
}
