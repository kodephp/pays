<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 分账能力接口
 *
 * 抖音、银联等支付平台将「分账」作为各自网关的「特色方法」直接实现于网关类内部
 * （复用基类配置、签名与 HTTP 通道），而非依赖统一插件层。实现本接口的网关即可被
 * 统一入口 {@see \Kode\Pays\Facade\Pay::call()} 与 {@see \Kode\Pays\Plugin\ProfitSharingPlugin}
 * 类型安全地调用其分账方法，满足「统一入口可调用各平台特色方法」的设计目标。
 *
 * 方法命名与微信/支付宝/Stripe 的插件方法保持语义一致，便于上层统一封装：
 * - createProfitSharing  : 发起分账
 * - queryProfitSharing  : 查询分账结果
 * - returnProfitSharing : 分账回退
 * - queryProfitSharingReturn : 查询分账回退结果
 * - unfreezeProfitSharing : 解冻未分账的剩余资金
 */
interface ProfitSharingCapableInterface
{
    /**
     * 发起分账
     *
     * @param array<string, mixed> $params 分账参数（transaction_id / out_order_no / receivers 等）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createProfitSharing(array $params): array;

    /**
     * 查询分账结果
     *
     * @param string $outOrderNo 商户分账订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryProfitSharing(string $outOrderNo): array;

    /**
     * 分账回退
     *
     * @param array<string, mixed> $params 回退参数（out_order_no / out_return_no / return_amount 等）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function returnProfitSharing(array $params): array;

    /**
     * 查询分账回退结果
     *
     * @param string $outReturnNo 商户回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryProfitSharingReturn(string $outReturnNo): array;

    /**
     * 解冻未分账的剩余资金
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选，缺省由网关自动生成）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array;
}
