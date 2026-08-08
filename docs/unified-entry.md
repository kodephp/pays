# 统一入口与平台清单（Unified Entry & Gateway Manifest）

> 本篇说明 kode/pays 的「统一入口」设计：如何用一个方法调用任意已接入平台、如何把各平台的
> 域名/方法/能力收敛到一张清单中、如何安全地校验异步回调、以及如何低成本新增一个平台。

## 为什么需要统一入口

支付 SDK 往往要对接几十个平台，每个平台的域名、签名方案、能力范围都不相同。如果业务代码
直接依赖具体网关类，会产生两类问题：

1. **散落**：各平台域名写在各自网关类里，新增/核对要翻多个文件；
2. **耦合**：业务代码里到处是 `if ($channel === 'wechat') { ... } else if ...`，难以维护。

本 SDK 用两层解决：

- **`GatewayManifest`（平台清单）**：把「域名、签名方案、能力开关、区域」集中声明到一张
  registry，其他模块只查清单。
- **`Pay` 门面（统一入口）**：用一个方法 `Pay::call()` 调用任意已接入平台的任意方法，
  接入哪个平台都能用，各平台的特色方法也能正常调用。

## 1. 平台清单 GatewayManifest

内置平台在首次访问时自动登记，覆盖全部已支持渠道。你也可以显式查询：

```php
use Kode\Pays\Core\GatewayManifest;

// 全部平台标识
GatewayManifest::names();                 // ['wechat', 'alipay', ...]

// 单个平台清单
$entry = GatewayManifest::get('wechat');   // ['label','region','base_url','signature','capabilities', ...]

// 关键查询（无需创建网关实例）
GatewayManifest::baseUrl('wechat');         // 生产域名
GatewayManifest::baseUrl('wechat', true);   // 沙箱域名
GatewayManifest::signatureScheme('wechat'); // 'md5' / 'rsa2' / 'hmac_sha256' / 'ecdsa' / 'none'
GatewayManifest::region('wechat');          // 'domestic' / 'international' / ...

// 能力开关：判断某平台是否支持某项能力
GatewayManifest::supports('wechat', GatewayManifest::CAP_PROFIT_SHARING); // true
GatewayManifest::supports('wechat', GatewayManifest::CAP_SUBSCRIPTION);   // false
```

能力常量（部分）：

| 常量 | 含义 |
|------|------|
| `CAP_CREATE_ORDER` | 创建订单 |
| `CAP_QUERY_ORDER` | 查询订单 |
| `CAP_REFUND` | 申请退款 |
| `CAP_QUERY_REFUND` | 查询退款 |
| `CAP_CLOSE_ORDER` | 关闭订单 |
| `CAP_VERIFY_NOTIFY` | 异步通知验签 |
| `CAP_TRANSFER` | 企业付款/转账 |
| `CAP_PROFIT_SHARING` | 分账 |
| `CAP_SUBSCRIPTION` | 订阅/周期扣款 |
| `CAP_RECONCILIATION` | 对账 |
| `CAP_QR` | 二维码支付 |
| `CAP_RED_PACKET` | 现金红包 |
| `CAP_WEBHOOK` | Webhook 事件订阅 |

> 说明：内置平台的域名优先由 `GatewayManifest::baseUrl()` 通过网关类声明的
> `PROD_BASE_URL` / `SANDBOX_BASE_URL` 常量反射获取，使域名信息也收敛到统一清单查询；
> 新建平台建议在清单里直接声明 `base_url` / `sandbox_url`（见下文 `extend`）。

## 2. 统一入口 Pay::call

`Pay::call($gateway, $method, ...$args)` 是统一调用的核心：第一个参数是平台标识，第二个是
方法名，其后是该方法的参数。无论是接口标准方法还是各平台特色方法，都走同一入口。

```php
use Kode\Pays\Facade\Pay;

// 标准方法
Pay::call('wechat', 'createOrder', $orderParams);
Pay::call('wechat', 'queryOrder', $orderId);
Pay::call('wechat', 'refund', $refundParams);
Pay::call('wechat', 'closeOrder', $orderId);

// 各平台「特色方法」同样可用（接口之外的方法）
Pay::call('wechat', 'someWechatSpecificMethod', $arg1, $arg2);
```

为常用操作提供语义化快捷方法（等价于 `call`，可读性更好）：

```php
Pay::createOrder('alipay', $params);
Pay::queryOrder('alipay', $orderId);
Pay::refund('alipay', $params);
Pay::queryRefund('alipay', $refundId);
Pay::closeOrder('alipay', $orderId);
```

分账等「增值能力」也提供统一入口（内部经 `Pay::call()` 动态派发到目标网关的分账特色方法）：

```php
// 统一发起分账（微信 / 支付宝 / Stripe / 抖音 / 云闪付 等已接入分账能力的平台）
Pay::profitSharingCreate('douyin', $params);
Pay::profitSharingQuery('douyin', $outOrderNo);
Pay::profitSharingReturn('douyin', $params);
Pay::profitSharingUnfreeze('douyin', $transactionId, $outOrderNo);

// 等价写法：直接用 call 派发网关原生分账方法
Pay::call('douyin', 'createProfitSharing', $params);
```

> 平台「特色方法」（如 `createProfitSharing`、`queryProfitSharing`）由各网关类直接实现并声明
> `ProfitSharingCapableInterface`，因此 `Pay::call()` 与 `ProfitSharingPlugin` 都能类型安全地调用。

转账 / 企业付款同样提供统一入口（内部经 `Pay::call()` 派发到目标网关的转账特色方法）：

```php
// 统一发起单笔转账（微信 / 支付宝 / Stripe 等已接入转账能力的平台）
Pay::transferSingle('wechat', [
    'out_biz_no' => 'TRANSFER_' . date('YmdHis'),
    'amount'     => 100,            // 微信 / 支付宝单位为分
    'recipient'  => ['type' => 'openid', 'account' => 'oUpF8u...', 'name' => '张三'],
]);
Pay::transferBatch('alipay', [
    'out_biz_no' => 'BATCH_' . date('YmdHis'),
    'transfer_detail_list' => [/* ... */],
]);
Pay::transferQuery('wechat', 'TRANSFER_20240425000001');
Pay::transferReceipt('alipay', 'TRANSFER_20240425000001');

// 等价写法：直接用 call 派发网关原生转账方法
Pay::call('wechat', 'singleTransfer', $params);
```

> 转账逻辑下沉到各网关类内部，声明 `TransferCapableInterface`（`singleTransfer` /
> `batchTransfer` / `queryTransfer` / `transferReceipt`）。`Pay::call()` 缺方法时仍抛「无此方法」；
> 例如 `Pay::transferReceipt('stripe', $no)` 会因 Stripe 不提供电子回单而报「无此方法」。

红包 / 现金红包同样提供统一入口（内部经 `Pay::call()` 派发到目标网关的红包特色方法）：

```php
// 统一发放普通红包（微信 / 支付宝已接入红包能力的平台）
Pay::redPacketSend('wechat', [
    'mch_billno'   => 'REDPACK_' . date('YmdHis'),
    'send_name'    => '某某公司',
    're_openid'    => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'total_amount' => 100,            // 微信 / 支付宝单位为分
    'total_num'    => 1,
    'wishing'      => '恭喜发财',
    'act_name'     => '新年活动',
    'remark'       => '参与活动领取红包',
]);
Pay::redPacketGroup('alipay', [/* mch_billno + total_num >= 3 + ... */]);
Pay::redPacketQuery('wechat', 'REDPACK_20240425000001');

// 等价写法：直接用 call 派发网关原生红包方法
Pay::call('wechat', 'sendRedPacket', $params);
```

> 红包逻辑下沉到各网关类内部，声明 `RedPacketCapableInterface`（`sendRedPacket` /
> `groupRedPacket` / `queryRedPacket`）。`Pay::call()` 缺方法时仍抛「无此方法」；
> 例如未实现 `RedPacketCapableInterface` 的平台（如 unionpay / douyin）调用红包入口会报「无此方法」。

订阅 / 代扣同样提供统一入口（内部经 `Pay::call()` 派发到目标网关的订阅特色方法）：

```php
// 统一创建订阅计划（Stripe / PayPal 已接入订阅能力的平台）
Pay::subscriptionCreatePlan('stripe', [
    'name'           => '月度会员',
    'amount'         => 9900,            // 最小货币单位（分）
    'currency'       => 'usd',
    'interval'       => 'month',
]);
Pay::subscriptionCreate('stripe', ['customer_id' => 'cus_xxx', 'plan_id' => 'price_xxx']);
Pay::subscriptionCancel('stripe', 'sub_xxx');
Pay::subscriptionPause('paypal', 'sub_xxx');
Pay::subscriptionResume('stripe', 'sub_xxx');
Pay::subscriptionGet('paypal', 'sub_xxx');

// 等价写法：直接用 call 派发网关原生订阅方法
Pay::call('stripe', 'createPlan', $params);
```

> 订阅逻辑下沉到各网关类内部，声明 `SubscriptionCapableInterface`（`createPlan` /
> `createSubscription` / `cancelSubscription` / `pauseSubscription` / `resumeSubscription` /
> `getSubscription`）。`Pay::call()` 缺方法时仍抛「无此方法」；
> 例如未实现 `SubscriptionCapableInterface` 的平台调用订阅入口会报「无此方法」。详见
> [订阅能力设计](subscription.md)。

个人收款同样提供统一入口（内部经 `Pay::call()` 派发到目标网关的个人收款特色方法）：

```php
// 统一生成个人收款码（微信 / 支付宝 / Stripe 已接入个人收款能力的平台）
Pay::personalReceiveQrCode('wechat', [
    'amount'      => 100,
    'description' => '商品付款',
    'attach'      => ['product_id' => '123'],
]);
Pay::personalReceiveQueryRecords('alipay', [
    'start_time' => '2024-04-01 00:00:00',
    'end_time'   => '2024-04-25 23:59:59',
]);
Pay::personalReceiveWithdraw('wechat', [
    'amount'       => 5000,
    'bank_card_no' => '6222************',
    'real_name'    => '张三',
    'out_biz_no'   => 'WITHDRAW_' . date('YmdHis'),
]);
Pay::personalReceiveQueryWithdraw('alipay', 'WITHDRAW_20240425000001');

// 等价写法：直接用 call 派发网关原生个人收款方法
Pay::call('wechat', 'createQrCode', $params);

// Stripe 未提供提现能力，调用会报「无此方法」
Pay::personalReceiveWithdraw('stripe', $params);
```

> 个人收款逻辑下沉到各网关类内部，声明 `PersonalReceiveCapableInterface`（`createQrCode` /
> `queryRecords` / `withdraw` / `queryWithdraw`）。`Pay::call()` 缺方法时仍抛「无此方法」；
> 例如 Stripe 未实现 `withdraw` / `queryWithdraw`，调用个人收款提现入口会报「无此方法」。详见
> [个人收款能力设计](personal-receive.md)。

如需拿到强类型网关实例（便于 IDE 自动补全平台特色方法）：

```php
$wechat = Pay::gateway('wechat', $config);
$wechat->createOrder([/* ... */]);      // 标准方法
$wechat->someSpecificMethod(...);       // 平台特色方法
```

> 前置条件：调用前需通过 `Pay::registerConfig('wechat', $config)` 预登记配置，或在调用时
> 传入配置。`Pay::call()` 内部统一解析并缓存网关实例。

被调用的方法在该网关上不存在时，`Pay::call()` 会抛出 `PayException`
（`ERROR_METHOD_NOT_SUPPORTED`，文案含「无此方法」），明确提示该平台不支持此能力：

```php
try {
    Pay::call('paypal', 'createProfitSharing', $params); // paypal 未实现分账
} catch (PayException $e) {
    // 网关 paypal 不支持方法：createProfitSharing（无此方法）
}
```

## 3. 安全校验 NotifyGuard

异步回调（notify）是支付安全的高风险面。`Pay::verify()` 在调用各平台 `verifyNotify()`
之前，先经过 `NotifyGuard` 做通用安全过滤：

- **必填字段**：通知必须包含业务所需字段；
- **签名字段**：存在签名机制时，通知必须携带签名字段；
- **时间戳防重放**：时间戳需落在有效窗口内（默认 ±5 分钟容差）；
- **nonce 防重放**：同一 nonce 不允许被重复使用（由调用方提供已见 nonce 集合）。

```php
use Kode\Pays\Facade\Pay;

$ok = Pay::verify('wechat', $_POST, [
    'timestamp'   => (int) ($_POST['timestamp'] ?? 0),
    'nonce'       => $_POST['nonce'] ?? null,
    'seen_nonces' => $alreadySeenNonces, // 调用方维护，用于重放检测
    'max_age'     => 300,                // 时间窗口（秒）
]);
```

`NotifyGuard` 为纯函数式、无外部依赖，也可单独用于其他校验场景：

```php
use Kode\Pays\Core\NotifyGuard;

NotifyGuard::guard($data, [
    'require_fields' => ['out_trade_no', 'total_fee'],
    'timestamp'      => $ts,
    'nonce'          => $nonce,
    'seen_nonces'    => $seen,
]);
// 校验不通过会抛出 PayException（signError / paramError）
```

## 4. 一次登记新增平台：Pay::extend

新增一个支付平台，只需「一次登记」即可纳入统一入口与平台清单，无需改动既有业务代码：

```php
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Facade\Pay;

Pay::extend('mypay', [
    'label'        => 'MyPay',
    'region'       => GatewayManifest::REGION_DOMESTIC,
    'signature'    => GatewayManifest::SIGN_MD5,
    'base_url'     => 'https://api.mypay.com/',
    'sandbox_url'  => 'https://sandbox.mypay.com/',
    'capabilities' => [
        GatewayManifest::CAP_PROFIT_SHARING => true,
        GatewayManifest::CAP_QR => true,
    ],
], MyPayGateway::class, MyPayConfig::class);

// 登记后即可通过统一入口调用
Pay::createOrder('mypay', $params);
```

`Pay::extend()` 等价于同时执行：

```php
GatewayManifest::register('mypay', $manifest);   // 写入平台清单
GatewayFactory::register('mypay', MyPayGateway::class, MyPayConfig::class); // 注册网关
```

其中 `MyPayGateway` 只需实现 `GatewayInterface`（建议继承 `AbstractGateway` 复用 HTTP / 事件 /
中间件等能力），`MyPayConfig` 实现 `ConfigInterface` 即可。

## 设计小结

- **综合到一起**：各平台域名、签名、能力集中在 `GatewayManifest`，新增/核对一处搞定；
- **统一入口**：`Pay::call()` 一个方法调用任意已接入平台、任意方法（含特色方法）；
- **易扩展**：`Pay::extend()` 一次登记新增平台；
- **安全性**：`NotifyGuard` + `Pay::verify()` 统一拦截畸形/重放回调，再走平台级验签。
