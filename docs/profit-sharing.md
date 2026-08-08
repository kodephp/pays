# 分账（Profit Sharing）

分账用于将一笔订单金额按约定比例分给多个接收方（平台、供应商、推广者等）。`ProfitSharingPlugin` 为支持分账的网关提供统一的分账管理能力，并配套类型安全的 `Receiver` 接收方值对象与 `Result` 归一化结果。

## 支持网关

| 网关 | 分账能力 | 说明 |
|------|----------|------|
| 微信支付 | ✅ 完整 | 服务商模式分账（增删接收方 / 分账 / 回退 / 查询 / 配置查询 / 完结解冻） |
| 支付宝 | ✅ 完整 | 交易分账（统一收单结算 / 关系绑定解绑 / 回退 / 查询） |
| Stripe | ✅ 完整 | Connect 平台分账（Transfer / Reversal） |
| 抖音支付 | ✅ 分账 | 分账（发起 / 查询 / 回退 / 解冻），作为网关「特色方法」实现于网关内部 |
| 云闪付 | ✅ 分账 | 分账（发起 / 查询 / 回退 / 解冻），作为网关「特色方法」实现于网关内部 |

> 说明：分账并非所有支付平台都提供标准能力。微信、支付宝、Stripe 具备成熟的分账/转账体系；
> 抖音、云闪付将分账实现为各自网关类的「特色方法」（声明 `ProfitSharingCapableInterface`），
> 复用基类配置、签名与 HTTP 通道，并能被统一入口 `Pay::call()` 直接调用。
>
> **架构要点**：抖音 / 云闪付的分账逻辑在各自网关内完成（组装 + 签名 + 发请求），`ProfitSharingPlugin`
> 在这些平台的分支只做「参数校验 + 类型安全转发」（`forwardToCapableGateway`），不重复承载平台组装逻辑；
> 微信 / 支付宝 / Stripe 的分账逻辑仍由插件内的分支承载。两者对调用方透明。
>
> ⚠️ 抖音 / 云闪付的分账 Endpoint 与字段命名以官方文档为准，投产前请按官方接口联调确认。

## 快速开始

```php
use Kode\Pays\Plugin\ProfitSharingPlugin;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;

$plugin = new ProfitSharingPlugin($wechatGateway);

// 方式一：使用 Receiver 值对象（推荐，类型安全）
$result = $plugin->create([
    'transaction_id' => '4200000000000000',
    'out_order_no' => 'SHARE_001',
    'receivers' => [
        new Receiver(
            type: 'MERCHANT_ID',
            account: '1234567890',
            name: '供应商A',
            amount: Money::fromMinor(100, 'CNY'), // 1.00 元
            description: '供应商分账',
            relationType: 'SERVICE_PROVIDER',
        ),
        new Receiver(
            type: 'PERSONAL_OPENID',
            account: 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            name: '推广者',
            amount: Money::fromMinor(50, 'CNY'),
            description: '推广者分账',
            relationType: 'SERVICE_PROVIDER',
        ),
    ],
]);

// 方式二：使用数组（向后兼容，amount 视为最小货币单位，如分/美分）
$result = $plugin->create([
    'transaction_id' => '4200000000000000',
    'out_order_no' => 'SHARE_001',
    'receivers' => [
        ['type' => 'MERCHANT_ID', 'account' => '1234567890', 'amount' => 100, 'description' => '供应商分账'],
    ],
]);
```

## API 集合

| 方法 | 说明 | 支持网关 |
|------|------|----------|
| `addReceiver(array)` | 添加分账接收方 | 微信、支付宝 |
| `removeReceiver(array)` | 删除分账接收方 | 微信、支付宝 |
| `create(array)` | 发起分账 | 微信、支付宝、Stripe、抖音、云闪付 |
| `query(string $outOrderNo)` | 查询分账结果 | 微信、支付宝、Stripe、抖音、云闪付 |
| `return(array)` | 分账回退 | 微信、支付宝、Stripe、抖音、云闪付 |
| `queryReturn(string $outReturnNo)` | 查询分账回退 | 微信、支付宝、Stripe、抖音、云闪付 |
| `unfreeze(string $transactionId, ?string $outOrderNo = null)` | 解冻剩余资金 / 完结分账 | 微信、抖音、云闪付（支付宝、Stripe 自动完成） |
| `queryConfig(string $outOrderNo, ?string $transactionId = null)` | 查询分账配置（最大比例与关系） | 微信 |

## Receiver 接收方值对象

金额统一由 `Money`（最小货币单位整数）承载，规避浮点误差；网关差异在调用时由
`toWechatArray()` / `toAlipayArray()` / `toStripeArray()` / `toDouyinArray()` / `toUnionPayArray()`
自动换算：

- 微信：`amount` 为「分」；
- 支付宝：`amount` 为「元」（字符串，如 `"1.00"`），`PERSONAL_OPENID` 自动映射为 `loginName`；
- Stripe：`amount` 为「货币最小单位」（如美分），币种转小写；
- 抖音 / 云闪付：`amount` 为「分」（与微信一致，银联 `txnAmt` 同为最小货币单位）。

```php
use Kode\Pays\Plugin\ProfitSharing\Receiver;

$receiver = Receiver::fromArray([
    'type' => 'PERSONAL_OPENID',
    'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'name' => '推广者',
    'amount' => 50,        // 最小货币单位（分）
    'currency' => 'CNY',
]);
```

## Result 归一化结果（可选）

插件方法返回原始网关数组；可用 `ProfitSharingResult` 做类型化访问，不改变底层数据：

```php
use Kode\Pays\Plugin\ProfitSharing\Result;

$result = Result::fromArray($plugin->create($params));
if ($result->isSuccess()) {
    $txnId = $result->getTransactionId();
    $orderNo = $result->getOutOrderNo();
}
```

## 分账配置查询（微信）

发起分账前，可先查询该商户号允许的最大分账比例与已配置的分账关系，便于比例校验：

```php
$config = $plugin->queryConfig('SHARE_001', '4200000000000000');
```
