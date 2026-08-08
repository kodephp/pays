<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 订阅能力接口
 *
 * 各平台「订阅 / 代扣」逻辑已集合到各自网关方法内部（网关继承 {@see AbstractGateway} 复用
 * 基础配置 / HTTP 通道 / 签名）。本接口用于：
 * - 供 {@see \Kode\Pays\Facade\Pay::call()} 动态派发到平台原生订阅方法；
 * - 供插件做类型安全转发（网关未实现或不支持某方法时统一抛「无此方法」）。
 *
 * 方法名与 {@see \Kode\Pays\Plugin\SubscriptionPlugin} 的公开方法一一对应，便于 Pay 统一入口
 * 直接 call 派发。
 */
interface SubscriptionCapableInterface
{
    /**
     * 创建订阅计划
     *
     * @param array<string, mixed> $params 计划参数（name/amount/currency/interval/interval_count）
     * @return array<string, mixed>
     */
    public function createPlan(array $params): array;

    /**
     * 创建订阅
     *
     * @param array<string, mixed> $params 订阅参数（customer_id/plan_id/...）
     * @return array<string, mixed>
     */
    public function createSubscription(array $params): array;

    /**
     * 取消订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId): array;

    /**
     * 暂停订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     */
    public function pauseSubscription(string $subscriptionId): array;

    /**
     * 恢复订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     */
    public function resumeSubscription(string $subscriptionId): array;

    /**
     * 查询订阅详情
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array;
}
