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
| Square | ✅ Catalog Plan | ✅ Subscriptions | ✅ | ✅ | ✅ | ✅ |
| 支付宝 | ✅ 本地周期规则 | ✅ 页面签约（返回跳转链接） | ✅ 解约 | ❌ | ❌ | ✅ 协议查询 |
| 微信支付（V2） | ❌ 后台配置模板 | ✅ entrustweb（返回跳转链接） | ✅ 解约 | ❌ | ❌ | ✅ 签约关系查询 |
| Adyen | ❌ 无计划实体 | ✅ 令牌化首期支付 | ✅ 禁用令牌 | ❌ | ❌ | ✅ 令牌列表 |

> ❌ 表示平台无对应端点，调用即抛「无此方法」（`ERROR_METHOD_NOT_SUPPORTED`）。
>
> 能力开关：以上平台均在 `GatewayManifest` 中声明 `CAP_SUBSCRIPTION => true`。
> 调用前可用 `GatewayManifest::supports('alipay', GatewayManifest::CAP_SUBSCRIPTION)` 判断。

### 平台原生扩展方法

统一契约之外，各平台还提供订阅闭环所需的原生方法（经 `Pay::call()` 派发）：

| 平台 | 方法 | 说明 |
|------|------|------|
| 支付宝 | `payWithAgreement(array)` | 协议代扣（`alipay.trade.pay`，`CYCLE_PAY_AUTH`） |
| 支付宝 | `modifyExecutionPlan(array)` | 修改周期扣款执行计划（延后扣款日，最接近「暂停」的替代） |
| 微信支付 | `payWithContract(array)` | 委托代扣申请扣款（`pay/pappayapply`） |
| 微信支付 | `queryContractOrder(string)` | 查询代扣订单（`pay/paporderquery`） |
| Adyen | `chargeRecurring(array)` | 后续期次扣款（ContAuth + Subscription） |

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
- **Square**：`createPlan` 一次性提交 `SUBSCRIPTION_PLAN` 及其下的
  `SUBSCRIPTION_PLAN_VARIATION`（周期由 cadence 枚举描述，`interval + interval_count`
  会自动映射为 `MONTHLY` / `QUARTERLY` / `ANNUAL` 等，不支持的组合直接报参数错误）；
  `createSubscription` 的 `plan_id` 需传**变体 ID**，`location_id` 未传时取配置项。
- **支付宝**：周期扣款无服务端计划实体，`createPlan` 只在本地组装 `period_rule_params`
  且不发请求；`createSubscription` 返回签约跳转链接（`alipay.user.agreement.page.sign`），
  签约结果由 `notify_url` 异步回调。协议标识默认按支付宝协议号，传 `ext:{外部协议号}`
  时按商户侧协议号定位。**金额以「元」为单位**（与其他平台的分不同），且仅支持 CNY、
  周期仅支持 day / month。签约后需商户按周期主动调 `payWithAgreement()` 扣款。
- **微信支付（V2）**：委托代扣模板（`plan_id`）只能在商户平台后台配置，故 `createPlan`
  报「无此方法」；`createSubscription` 返回 `papay/entrustweb` 签约跳转链接（查询串经
  MD5 签名，签名字节与发送字节一致）。协议标识默认按 `contract_id`，传
  `plan:{plan_id}:{contract_code}` 时按「模板 + 商户协议号」定位。扣款走
  `payWithContract()`（异步返回，需用 `queryContractOrder()` 确认最终状态）。
- **Adyen**：无计划 / 订阅实体，订阅 = 令牌化支付方式 + 商户侧调度。
  `createSubscription` 以 `shopperInteraction=Ecommerce` +
  `recurringProcessingModel=Subscription` + `storePaymentMethod=true` 发起首期支付并拿到
  令牌；后续期次用 `chargeRecurring()`（ContAuth）。取消即禁用令牌，传
  `shopper:{shopperReference}` 可一次性禁用该购物者全部令牌。
- **金额单位**：Stripe / PayPal / Square / Adyen 以「最小货币单位（分）」传入；
  **支付宝周期扣款以「元」传入**；微信委托代扣 `total_fee` 以「分」传入。
