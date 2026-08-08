# 订阅 / 代扣（Subscription）

> 本文档说明 kode/pays 的订阅能力设计：订阅逻辑如何下沉到各网关原生方法、插件与统一入口
> 如何复用、以及不支持的能力如何优雅报「无此方法」。

## 设计原则

订阅遵循本 SDK 的统一架构：**各平台的订阅逻辑集合在各自网关类内部**（继承 `AbstractGateway`，
复用基类配置、签名与 HTTP 通道），通过统一入口 `Pay::call()` 动态派发调用。

- 平台特色方法由网关类直接实现，并声明 `SubscriptionCapableInterface`：
  - `createPlan(array $params): array`（创建订阅计划 / Price / Product+Plan）
  - `createSubscription(array $params): array`（创建订阅）
  - `cancelSubscription(string $subscriptionId): array`（取消订阅）
  - `pauseSubscription(string $subscriptionId): array`（暂停订阅）
  - `resumeSubscription(string $subscriptionId): array`（恢复订阅）
  - `getSubscription(string $subscriptionId): array`（查询订阅详情）
- `SubscriptionPlugin` 退化为「参数校验 + 类型安全转发」层，不重复承载平台组装逻辑。
- 与平台无关的差异对比方法 `diff()` 保留在插件内（不触达网关）。
- 不支持某方法时统一抛 `PayException::methodNotSupported`
  （`ERROR_METHOD_NOT_SUPPORTED`，文案含「无此方法」）。

## 支持平台与方法映射

| 平台 | createPlan | createSubscription | cancel | pause | resume | get |
|------|-----------|-------------------|--------|-------|--------|-----|
| Stripe | ✅ Price | ✅ Subscription | ✅ | ✅ | ✅ | ✅ |
| PayPal | ✅ Product+Plan | ✅ Subscription | ✅ | ✅ | ✅ | ✅ |

> 能力开关：Stripe / PayPal 在 `GatewayManifest` 中声明 `CAP_SUBSCRIPTION => true`。
> 调用前可用 `GatewayManifest::supports('stripe', GatewayManifest::CAP_SUBSCRIPTION)` 判断。

## 统一入口

```php
use Kode\Pays\Facade\Pay;

// 语义化快捷方法（内部经 Pay::call 派发）
Pay::subscriptionCreatePlan('stripe', [
    'name'          => '月度会员',
    'amount'        => 9900,
    'currency'      => 'usd',
    'interval'      => 'month',
    'interval_count' => 3,
]);
Pay::subscriptionCreate('stripe', [
    'customer_id' => 'cus_xxx',
    'plan_id'     => 'price_xxx',
]);
Pay::subscriptionCancel('stripe', 'sub_xxx');
Pay::subscriptionPause('paypal', 'sub_xxx');
Pay::subscriptionResume('stripe', 'sub_xxx');
Pay::subscriptionGet('paypal', 'sub_xxx');

// 等价：直接派发网关原生方法
Pay::call('stripe', 'createPlan', $params);
```

> 各平台方法语义不同（如 Stripe 先建 Price、PayPal 先建 Product 再建 Plan），但统一入口屏蔽差异，
> 调用方无需关心平台细节；平台不支持某方法时抛「无此方法」。

## 插件调用

```php
use Kode\Pays\Plugin\SubscriptionPlugin;

$plugin = new SubscriptionPlugin($stripeGateway);

// 创建订阅计划
$plan = $plugin->createPlan([
    'name'          => '月度会员',
    'amount'        => 9900,
    'currency'      => 'usd',
    'interval'      => 'month',
]);

// 创建订阅
$subscription = $plugin->createSubscription([
    'customer_id' => 'cus_xxx',
    'plan_id'     => $plan['id'],
]);

// 取消 / 暂停 / 恢复 / 查询
$plugin->cancelSubscription('sub_xxx');
$plugin->pauseSubscription('sub_xxx');
$plugin->resumeSubscription('sub_xxx');
$plugin->getSubscription('sub_xxx');
```

插件只做参数校验与转发；平台组装逻辑在网关内部。网关未实现 `SubscriptionCapableInterface`
（或不支持某方法）时，统一抛「无此方法」。

## 生产联调提示

- **Stripe**：`createPlan` 经 `v1/prices` 创建 Price（金额单位为最小货币单位，如分），
  并携带 `Authorization: Bearer <secret_key>`；`createSubscription` 经 `v1/subscriptions`。
  取消 / 暂停 / 恢复均对 `v1/subscriptions/{id}` 发 POST，暂停为 `pause_collection` 置为
  `mark_uncollectible`，恢复为置 `null`。
- **PayPal**：`createPlan` 先 `v1/catalogs/products` 建 Product，再 `v1/billing/plans` 建 Plan
  （金额按分，两位小数字符串）；其余操作走 `v1/billing/subscriptions/*`。所有请求经
  `getAccessToken()` 获取 Bearer Token，金额按分（`amount / 100`，两位小数）。
- **金额单位**：Stripe / PayPal 订阅金额统一以「最小货币单位（分）」传入。
