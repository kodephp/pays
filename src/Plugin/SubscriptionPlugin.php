<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 订阅支付插件
 *
 * 为支持订阅模式的网关提供统一的订阅管理能力：创建订阅计划、创建订阅、
 * 取消、暂停、恢复、查询订阅。
 *
 * 架构说明（对齐「统一入口」设计）：
 * 各平台的订阅逻辑已下沉到各自的网关类内部，实现 {@see SubscriptionCapableInterface}。
 * 本插件只做「参数校验 + 类型安全转发」，不重复承载平台组装逻辑，保证单一职责：
 * - 校验通过后，经 {@see forwardToCapableGateway()} 调用网关原生方法；
 * - 网关未实现 {@see SubscriptionCapableInterface}（或不支持某方法）时，统一抛「无此方法」。
 *
 * 与平台无关的差异对比方法 {@see diff()} 保留在插件内。
 *
 * 支持网关：Stripe、PayPal、Square（完整六方法）；
 * 支付宝（周期扣款）、微信支付 V2（委托代扣 papay）、Adyen（Recurring 令牌）
 * 受平台端点限制不支持暂停 / 恢复，调用即抛「无此方法」，详见 docs/subscription.md。
 *
 * 使用示例：
 * ```php
 * $plugin = new SubscriptionPlugin($stripeGateway);
 *
 * // 创建订阅计划
 * $plan = $plugin->createPlan([
 *     'name' => '月度会员',
 *     'amount' => 9900,
 *     'currency' => 'usd',
 *     'interval' => 'month',
 * ]);
 *
 * // 创建订阅
 * $subscription = $plugin->createSubscription([
 *     'customer_id' => 'cus_xxx',
 *     'plan_id' => $plan['id'],
 * ]);
 *
 * // 统一入口亦可：Pay::subscriptionCreate('stripe', [...])
 * ```
 */
class SubscriptionPlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需实现 SubscriptionCapableInterface）
     */
    public function __construct(GatewayInterface $gateway)
    {
        self::assertHttpCapable($gateway);

        $this->gateway = $gateway;
    }

    /**
     * 创建订阅计划
     *
     * @param array<string, mixed> $params 计划参数
     *        - name: 计划名称
     *        - amount: 金额（最小货币单位，如分）
     *        - currency: 货币
     *        - interval: 周期 day/week/month/year
     *        - interval_count: 周期数量（可选，默认 1）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createPlan(array $params): array
    {
        $this->validateRequired($params, ['name', 'amount', 'currency', 'interval']);

        return $this->forwardToCapableGateway('createPlan', $params);
    }

    /**
     * 创建订阅
     *
     * @param array<string, mixed> $params 订阅参数
     *        - customer_id: 客户 ID（Stripe）
     *        - plan_id: 计划 / Price ID
     *        - customer_name / customer_email / return_url / cancel_url: PayPal 等所需
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['customer_id', 'plan_id']);

        return $this->forwardToCapableGateway('createSubscription', $params);
    }

    /**
     * 取消订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->forwardToCapableGateway('cancelSubscription', $subscriptionId);
    }

    /**
     * 暂停订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->forwardToCapableGateway('pauseSubscription', $subscriptionId);
    }

    /**
     * 恢复订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->forwardToCapableGateway('resumeSubscription', $subscriptionId);
    }

    /**
     * 查询订阅详情
     *
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->forwardToCapableGateway('getSubscription', $subscriptionId);
    }

    /**
     * 对比系统订单与对账单差异
     *
     * 平台无关的差异对比逻辑，保留在插件内（不涉及网关派发）。
     *
     * @param array<int, array<string, mixed>> $systemOrders 系统订单列表
     * @param array<int, array<string, mixed>> $billRecords 对账单记录列表
     * @return array<string, mixed> 差异报告
     */
    public function diff(array $systemOrders, array $billRecords): array
    {
        $systemMap = [];
        foreach ($systemOrders as $order) {
            $key = $order['out_trade_no'] ?? $order['order_id'] ?? '';
            if ($key !== '') {
                $systemMap[$key] = $order;
            }
        }

        $billMap = [];
        foreach ($billRecords as $record) {
            $key = $record['out_trade_no'] ?? $record['merchant_order_no'] ?? '';
            if ($key !== '') {
                $billMap[$key] = $record;
            }
        }

        $onlyInSystem = [];
        $onlyInBill = [];
        $amountMismatch = [];
        $statusMismatch = [];

        // 系统有但账单没有的
        foreach ($systemMap as $key => $order) {
            if (!isset($billMap[$key])) {
                $onlyInSystem[] = $order;
                continue;
            }

            $bill = $billMap[$key];

            $sysAmount = $order['amount'] ?? $order['total_amount'] ?? null;
            $billAmount = $bill['amount'] ?? $bill['total_amount'] ?? null;
            if ($sysAmount !== null && $billAmount !== null && (float) $sysAmount !== (float) $billAmount) {
                $amountMismatch[] = ['key' => $key, 'system' => $sysAmount, 'bill' => $billAmount];
            }

            $sysStatus = $order['status'] ?? $order['trade_state'] ?? null;
            $billStatus = $bill['status'] ?? $bill['trade_state'] ?? null;
            if ($sysStatus !== null && $billStatus !== null && $sysStatus !== $billStatus) {
                $statusMismatch[] = ['key' => $key, 'system' => $sysStatus, 'bill' => $billStatus];
            }
        }

        // 账单有但系统没有的
        foreach ($billMap as $key => $record) {
            if (!isset($systemMap[$key])) {
                $onlyInBill[] = $record;
            }
        }

        return [
            'only_in_system' => $onlyInSystem,
            'only_in_bill' => $onlyInBill,
            'amount_mismatch' => $amountMismatch,
            'status_mismatch' => $statusMismatch,
            'summary' => [
                'system_count' => count($systemMap),
                'bill_count' => count($billMap),
                'only_in_system_count' => count($onlyInSystem),
                'only_in_bill_count' => count($onlyInBill),
                'amount_mismatch_count' => count($amountMismatch),
                'status_mismatch_count' => count($statusMismatch),
            ],
        ];
    }

    /**
     * 类型安全转发到支持订阅的网关原生方法
     *
     * Stripe / PayPal 的「订阅」是其各自网关类内部实现的特色方法
     * （声明了 {@see SubscriptionCapableInterface}）。插件在此只做校验与转发，不重复承载
     * 平台组装逻辑。网关不支持某方法时抛 {@see PayException::methodNotSupported}（无此方法）。
     *
     * @param string $method 网关原生订阅方法名
     * @param mixed ...$args 透传参数
     * @return array<string, mixed>
     * @throws PayException 当网关未实现订阅能力接口或不支持该方法时
     *
     * @phpstan-assert SubscriptionCapableInterface $this->gateway
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof SubscriptionCapableInterface) {
            throw PayException::invalidArgument(
                sprintf('网关 %s 未实现订阅能力接口（SubscriptionCapableInterface）', $this->gateway::getName()),
            );
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var SubscriptionCapableInterface $gateway */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }

    /**
     * 验证必填参数
     *
     * @param array<string, mixed> $params
     * @param string[] $required
     * @throws PayException
     */
    protected function validateRequired(array $params, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }
}
