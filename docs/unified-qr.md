# 统一收款码（Unified QR Router）

`UnifiedQrRouter` 提供「一个二维码兼容多支付通道」的聚合收款能力：商家出示统一入口码，
用户扫码后选择通道，路由器为该通道调用对应网关 `createOrder` 生成动态订单二维码，
由 `OrderMonitorDaemon` 后台抓取收款状态并验证。

> 走正规商户扫码 API（非静态个人收款码），订单可关联、可查询、可对账。

## 状态机

```
pending ──route()──▶ ordered ──markPaid()──▶ paid (终态)
   │                      │
   └────── close() ──────▶ closed (终态)
```

- `pending`：入口已创建，待用户扫码选通道
- `ordered`：已路由并生成动态订单（再次 `route()` 幂等返回已有订单）
- `paid`：已支付完成（终态，不可再下单/关闭）
- `closed`：已关闭（终态，再次 `route()` 抛错）

## 基本用法

```php
use Kode\Pays\Core\UnifiedQrRouter;

$router = new UnifiedQrRouter([
    'wechat' => ['app_id' => 'wx1', 'mch_id' => 'm1', 'api_key' => 'k1'],
    'alipay' => ['app_id' => 'a1', 'private_key' => '...'],
]);

// 1. 商家出示统一收款码（QrEntry 值对象）
$entry = $router->createEntry(['wechat', 'alipay'], 100, '商品付款');
echo $entry->getQrContent();      // 统一入口 URL，用于渲染二维码
echo $entry->getRouterId();       // UR20260808120000AB12CD

// 2. 用户扫码选通道后，后端路由下单
$order = $router->route($entry->getRouterId(), 'wechat');
echo $order->getCodeUrl();        // 微信 Native 扫码支付链接
echo $order->getOutTradeNo();     // 商户订单号

// 3. 查询 / 关闭 / 标记支付
$router->getStatus($entry->getRouterId());  // QrEntry|null
$router->close($entry->getRouterId());      // 放弃收款（已下单未支付可关闭）
$router->markPaid($entry->getRouterId());   // 由 OrderMonitorDaemon 验证通过后调用

$router->getPendingEntries();   // array<routerId, QrEntry> 非终态入口
```

## QrEntry 值对象

`createEntry()` / `route()` / `getEntry()` / `getStatus()` / `getPendingEntries()` 均返回
不可变 `Kode\Pays\Core\QrEntry` 对象（替代裸数组），类型安全访问器：

| 访问器 | 说明 |
|--------|------|
| `getRouterId()` | 统一入口 ID |
| `getChannels()` | 支持的通道列表 |
| `getAmount()` | 收款金额（最小货币单位） |
| `getDescription()` | 收款说明 |
| `getStatus()` | 入口状态（见 `QrEntry::STATUS_*`） |
| `getChannel()` | 用户已选通道（下单后填充） |
| `getOutTradeNo()` | 商户订单号（下单后填充） |
| `getPayUrl()` / `getCodeUrl()` | 动态订单支付链接（下单后填充） |
| `getQrContent()` | 二维码内容：待支付为入口 URL，下单后为动态订单链接 |
| `isPending()` / `isOrdered()` / `isPaid()` / `isClosed()` / `isRoutable()` | 状态判定 |

`QrEntry::fromArray()` / `toArray()` 提供与持久化存储（Redis/DB）的往返能力，
生产环境应将 `$router->entries` 替换为外部存储。

## 二维码字段归一化

`PayResponse::getQrContent()` 归一化各网关返回的二维码字段，取第一个非空值：
`qr_code`（支付宝） → `code_url`（微信/银联） → `payment_link`（Stripe） → `pay_url`（通用）。

## 注意事项

- 路由器内置注册表为内存实现，仅适用于单进程；多实例部署须接入共享存储。
- `close()` 对已支付入口抛 `PayException`；已关闭入口再次 `route()` 抛 `PayException`。
- 入口 URL 前缀可通过构造函数 `entryUrlPrefix` 重写为自有域名。
