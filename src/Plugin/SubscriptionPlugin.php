<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 订阅支付插件
 *
 * 为支持订阅模式的网关提供统一的订阅管理能力。
 * 支持创建订阅计划、订阅、取消、暂停、恢复等操作。
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
 * ```
 */
class SubscriptionPlugin
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关
     */
    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 创建订阅计划
     *
     * @param array<string, mixed> $params 计划参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createPlan(array $params): array
    {
        $this->validateRequired($params, ['name', 'amount', 'currency', 'interval']);

        $name = $params['name'];
        $amount = $params['amount'];
        $currency = $params['currency'];
        $interval = $params['interval'];
        $intervalCount = $params['interval_count'] ?? 1;

        return match ($this->gateway::getName()) {
            'stripe' => $this->createStripePlan($name, $amount, $currency, $interval, $intervalCount),
            'paypal' => $this->createPaypalPlan($name, $amount, $currency, $interval, $intervalCount),
            default => throw PayException::invalidArgument('当前网关不支持订阅计划创建'),
        };
    }

    /**
     * 创建订阅
     *
     * @param array<string, mixed> $params 订阅参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['customer_id', 'plan_id']);

        return match ($this->gateway::getName()) {
            'stripe' => $this->createStripeSubscription($params),
            'paypal' => $this->createPaypalSubscription($params),
            default => throw PayException::invalidArgument('当前网关不支持订阅创建'),
        };
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
        return match ($this->gateway::getName()) {
            'stripe' => $this->gateway->post("v1/subscriptions/{$subscriptionId}", ['cancel_at_period_end' => true]),
            'paypal' => $this->gateway->post("v1/billing/subscriptions/{$subscriptionId}/cancel", ['reason' => '用户取消']),
            default => throw PayException::invalidArgument('当前网关不支持订阅取消'),
        };
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
        return match ($this->gateway::getName()) {
            'stripe' => $this->gateway->post("v1/subscriptions/{$subscriptionId}", ['pause_collection' => ['behavior' => 'mark_uncollectible']]),
            'paypal' => $this->gateway->post("v1/billing/subscriptions/{$subscriptionId}/suspend", ['reason' => '用户暂停']),
            default => throw PayException::invalidArgument('当前网关不支持订阅暂停'),
        };
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
        return match ($this->gateway::getName()) {
            'stripe' => $this->gateway->post("v1/subscriptions/{$subscriptionId}", ['pause_collection' => null]),
            'paypal' => $this->gateway->post("v1/billing/subscriptions/{$subscriptionId}/activate", ['reason' => '用户恢复']),
            default => throw PayException::invalidArgument('当前网关不支持订阅恢复'),
        };
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
        return match ($this->gateway::getName()) {
            'stripe' => $this->gateway->get("v1/subscriptions/{$subscriptionId}"),
            'paypal' => $this->gateway->get("v1/billing/subscriptions/{$subscriptionId}"),
            default => throw PayException::invalidArgument('当前网关不支持订阅查询'),
        };
    }

    /**
     * 创建 Stripe 订阅计划
     *
     * @param string $name 计划名称
     * @param int $amount 金额（分）
     * @param string $currency 货币
     * @param string $interval 周期：day、week、month、year
     * @param int $intervalCount 周期数量
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createStripePlan(string $name, int $amount, string $currency, string $interval, int $intervalCount): array
    {
        // 先创建 Price
        return $this->gateway->post('v1/prices', [
            'unit_amount' => $amount,
            'currency' => $currency,
            'recurring' => [
                'interval' => $interval,
                'interval_count' => $intervalCount,
            ],
            'product_data' => [
                'name' => $name,
            ],
        ]);
    }

    /**
     * 创建 Stripe 订阅
     *
     * @param array<string, mixed> $params 订阅参数
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createStripeSubscription(array $params): array
    {
        return $this->gateway->post('v1/subscriptions', [
            'customer' => $params['customer_id'],
            'items' => [
                ['price' => $params['plan_id']],
            ],
            'metadata' => $params['metadata'] ?? [],
        ]);
    }

    /**
     * 创建 PayPal 订阅计划
     *
     * @param string $name 计划名称
     * @param int $amount 金额（分）
     * @param string $currency 货币
     * @param string $interval 周期
     * @param int $intervalCount 周期数量
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createPaypalPlan(string $name, int $amount, string $currency, string $interval, int $intervalCount): array
    {
        // 创建产品
        $product = $this->gateway->post('v1/catalogs/products', [
            'name' => $name,
            'type' => 'DIGITAL',
        ]);

        // 创建计划
        return $this->gateway->post('v1/billing/plans', [
            'product_id' => $product['id'],
            'name' => $name,
            'billing_cycles' => [
                [
                    'frequency' => [
                        'interval_unit' => strtoupper($interval),
                        'interval_count' => $intervalCount,
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => number_format($amount / 100, 2),
                            'currency_code' => strtoupper($currency),
                        ],
                    ],
                ],
            ],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
            ],
        ]);
    }

    /**
     * 创建 PayPal 订阅
     *
     * @param array<string, mixed> $params 订阅参数
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createPaypalSubscription(array $params): array
    {
        return $this->gateway->post('v1/billing/subscriptions', [
            'plan_id' => $params['plan_id'],
            'subscriber' => [
                'name' => [
                    'given_name' => $params['customer_name'] ?? 'Customer',
                ],
                'email_address' => $params['customer_email'] ?? '',
            ],
            'application_context' => [
                'return_url' => $params['return_url'] ?? '',
                'cancel_url' => $params['cancel_url'] ?? '',
            ],
        ]);
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
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }
}
