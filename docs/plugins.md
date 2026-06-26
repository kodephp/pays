# Kode Pays SDK 插件总览

Kode Pays 提供丰富的插件体系，覆盖支付业务的完整生命周期。所有插件均位于 `Kode\Pays\Plugin` 命名空间下，通过组合 `GatewayInterface` 扩展网关能力。

## 插件列表

| 插件 | 类名 | 支持网关 | 核心功能 |
|------|------|----------|----------|
| 分账插件 | `ProfitSharingPlugin` | 微信、支付宝、Stripe | 创建分账、查询分账、分账回退、解冻资金 |
| 转账插件 | `TransferPlugin` | 微信、支付宝、Stripe | 单笔转账、批量转账、查询转账、电子回单 |
| 退款插件 | `RefundPlugin` | 微信、支付宝、Stripe、PayPal | 申请退款、查询退款、取消退款 |
| 红包插件 | `RedPacketPlugin` | 微信、支付宝 | 普通红包、裂变红包、查询红包 |
| 订阅插件 | `SubscriptionPlugin` | Stripe、PayPal | 订阅计划、订阅管理、暂停/恢复/取消 |
| 对账插件 | `ReconciliationPlugin` | 微信、支付宝、Stripe | 下载对账单、解析对账单、差异比对 |
| 个人收款插件 | `PersonalReceivePlugin` | 微信、支付宝、Stripe | 收款码、查询记录、提现到银行卡 |
| 自动结算插件 | `AutoSettlementPlugin` | 微信、支付宝、Stripe、PayPal | 支付后自动提现到钱包 |
| 加密货币插件 | `CryptoPlugin` | Coinbase | 加密货币订单、链上确认、汇率查询 |

## 插件架构

所有插件遵循统一的设计模式：

1. **组合而非继承**：构造函数接收 `GatewayInterface`，可选注入 `FundConstraintValidator`
2. **多网关分发**：使用 `match` 表达式根据 `GatewayInterface::getName()` 分发到具体实现
3. **统一异常**：不支持的场景抛出 `PayException::invalidArgument()`

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

class ExamplePlugin
{
    public function __construct(
        protected GatewayInterface $gateway,
    ) {
    }

    public function doSomething(array $params): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->doWechatSomething($params),
            'alipay' => $this->doAlipaySomething($params),
            default  => throw PayException::invalidArgument('当前网关不支持此功能'),
        };
    }
}
```

## 分账插件 (ProfitSharingPlugin)

支持微信、支付宝、Stripe 三个网关的分账能力。

### 配置

无需额外配置，依赖网关本身的配置即可。微信需在商户平台开通分账功能并添加接收方；支付宝需配置分账关系；Stripe 需创建 Connected Account。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\ProfitSharingPlugin;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new ProfitSharingPlugin($wechat);

// 添加分账接收方（微信）
$plugin->addReceiver([
    'type'         => 'MERCHANT_ID',
    'account'      => '1234567890',
    'name'         => '供应商A',
    'relation_type'=> 'SUPPLIER',
]);

// 创建分账（按接收方分配金额）
$result = $plugin->create([
    'transaction_id' => '4200000000000000',
    'out_order_no'   => 'SHARE_' . date('YmdHis'),
    'receivers'      => [
        ['type' => 'MERCHANT_ID', 'account' => '1234567890', 'amount' => 100, 'description' => '供应商分账'],
        ['type' => 'PERSONAL_OPENID', 'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', 'amount' => 50, 'description' => '推广者分账'],
    ],
]);

// 查询分账结果
$result = $plugin->query('SHARE_20240425000001');

// 分账回退
$result = $plugin->return([
    'out_order_no'  => 'SHARE_20240425000001',
    'out_return_no' => 'RETURN_' . date('YmdHis'),
    'return_amount' => 100,
]);

// 查询分账回退结果
$result = $plugin->queryReturn('RETURN_20240425000001');

// 解冻剩余资金（订单分账完成后调用，将未分账金额释放给商户）
$result = $plugin->unfreeze('4200000000000000');
```

### 注意事项

- 微信分账金额不能超过订单可分账金额（默认订单金额的 30%，可在商户平台调整）
- 支付宝分账需先调用 `addReceiver` 或在商户后台配置分账关系
- Stripe 通过 Transfer 到 Connected Account 实现分账
- 分账回退只能在分账发起 30 天内操作

## 转账插件 (TransferPlugin)

支持微信、支付宝、Stripe 的转账能力。

### 配置

微信转账需使用商户证书；支付宝需配置应用私钥；Stripe 需配置 secret_key。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\TransferPlugin;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new TransferPlugin($wechat);

// 单笔转账到零钱
$result = $plugin->single([
    'out_biz_no'  => 'TRANSFER_' . date('YmdHis'),
    'amount'      => 100,
    'recipient'   => ['type' => 'openid', 'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', 'name' => '张三'],
    'description' => '佣金提现',
]);

// 批量转账
$result = $plugin->batch([
    'out_biz_no' => 'BATCH_' . date('YmdHis'),
    'transfer_detail_list' => [
        ['out_detail_no' => 'D001', 'amount' => 100, 'recipient' => ['account' => 'openid1', 'name' => '张三'], 'remark' => '佣金'],
        ['out_detail_no' => 'D002', 'amount' => 200, 'recipient' => ['account' => 'openid2', 'name' => '李四'], 'remark' => '奖励'],
    ],
]);

// 查询转账结果
$result = $plugin->query('TRANSFER_20240425000001');

// 获取电子回单（PDF 二进制流）
$result = $plugin->receipt('TRANSFER_20240425000001');
```

### 注意事项

- 单笔转账金额需在网关限额内（微信单笔上限 20000 元）
- 批量转账单次最多 3000 笔
- 收款方姓名需与微信实名认证一致，否则会失败
- 建议配合 `FundConstraintValidator` 做风控验证

## 退款插件 (RefundPlugin)

支持微信、支付宝、Stripe、PayPal 的退款能力。

### 配置

无需额外配置。微信退款需上传商户证书；PayPal 退款需 capture_id（扣款成功后才能退）。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\RefundPlugin;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new RefundPlugin($wechat);

// 申请退款
$result = $plugin->apply([
    'out_trade_no'  => 'ORDER_001',
    'out_refund_no' => 'REFUND_' . date('YmdHis'),
    'total_fee'     => 100,
    'refund_fee'    => 50,
    'refund_desc'   => '商品质量问题',
]);

// 查询退款
$result = $plugin->query('REFUND_20240425000001');

// 取消退款（仅 Stripe 支持取消未处理完的退款）
$result = $plugin->cancel('REFUND_20240425000001');
```

### 各网关参数对照

| 参数 | 微信 | 支付宝 | Stripe | PayPal |
|------|------|--------|--------|--------|
| 商户订单号 | `out_trade_no` | `out_trade_no` 或 `trade_no` | metadata.order_id | - |
| 退款单号 | `out_refund_no` | `out_request_no` | `out_refund_no` | - |
| 退款金额（单位） | `refund_fee`（分） | `refund_amount`（元） | `refund_fee`（分） | `amount.value` |
| 退款原因 | `refund_desc` | `refund_reason` | `refund_desc` | `note` |

### 注意事项

- 微信退款金额不能超过订单总额
- 支付宝部分退款需保证 `refund_amount <= total_amount`
- Stripe 退款在创建后 1 小时内可取消
- PayPal 部分退款需传入 `amount` 字段，不传则全额退款
- 退款成功后建议触发 `Events::REFUND_SUCCESS` 事件通知业务系统

## 红包插件 (RedPacketPlugin)

支持微信、支付宝的红包能力（裂变红包仅微信支持）。

### 配置

微信红包需开通现金红包产品权限；支付宝需开通红包营销能力。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\RedPacketPlugin;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new RedPacketPlugin($wechat);

// 发放普通红包（单发）
$result = $plugin->send([
    'mch_billno'   => 'REDPACK_' . date('YmdHis'),
    'send_name'    => '某某公司',
    're_openid'    => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'total_amount' => 100,
    'total_num'    => 1,
    'wishing'      => '恭喜发财',
    'act_name'     => '新年活动',
    'remark'       => '参与活动领取红包',
    'scene_id'     => 'PRODUCT_1',  // 可选
]);

// 发放裂变红包（群发，用户分享后好友可领）
$result = $plugin->group([
    'mch_billno'   => 'GROUP_' . date('YmdHis'),
    'send_name'    => '某某公司',
    're_openid'    => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'total_amount' => 300,
    'total_num'    => 3,
    'wishing'      => '裂变红包',
    'act_name'     => '分享活动',
    'remark'       => '分享给好友领取',
]);

// 查询红包记录
$result = $plugin->query('REDPACK_20240425000001');
```

### 注意事项

- 单个红包金额范围：1 元 - 200 元
- 裂变红包 `total_num` 不能超过 100
- 红包发放后 24 小时内未领取会自动退回商户账户
- 建议配合 `FundConstraintValidator` 的红包约束（`setRedPacketConstraints`）做风控

## 订阅插件 (SubscriptionPlugin)

支持 Stripe、PayPal 的订阅与周期扣款能力。

### 配置

Stripe 需创建 Product 与 Price；PayPal 需创建订阅计划。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\SubscriptionPlugin;

// Stripe 订阅
$stripe = Pay::stripe(['secret_key' => 'sk_test_...']);
$plugin = new SubscriptionPlugin($stripe);

// 创建订阅计划
$plan = $plugin->createPlan([
    'name'     => '月度会员',
    'amount'   => 9900,            // Stripe 单位：分
    'currency' => 'usd',
    'interval' => 'month',
]);

// 创建订阅
$subscription = $plugin->createSubscription([
    'customer_id' => 'cus_xxx',
    'plan_id'     => $plan['id'],
]);

// 暂停订阅（暂停扣款，可恢复）
$plugin->pauseSubscription($subscription['id']);

// 恢复订阅
$plugin->resumeSubscription($subscription['id']);

// 取消订阅（终止周期扣款）
$plugin->cancelSubscription($subscription['id']);
```

### PayPal 订阅示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\SubscriptionPlugin;

$paypal = Pay::paypal([
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'sandbox'       => true,
]);

$plugin = new SubscriptionPlugin($paypal);

$plan = $plugin->createPlan([
    'name'          => '月度会员',
    'amount'        => '9.99',
    'currency'      => 'USD',
    'interval'      => 'month',
    'interval_count' => 1,
]);

$subscription = $plugin->createSubscription([
    'plan_id'      => $plan['id'],
    'subscriber'   => ['email' => 'customer@example.com'],
]);
```

### 注意事项

- 暂停与取消的语义不同：暂停可恢复，取消不可恢复（需重新订阅）
- Stripe 订阅扣款失败会自动重试 4 次
- PayPal 订阅扣款失败会按计划配置的重试策略执行
- 取消订阅前请确认是否有未结算账单

## 对账插件 (ReconciliationPlugin)

支持微信、支付宝、Stripe 的对账单下载与差异比对。

### 配置

无需额外配置。微信对账单可下载交易账单和资金账单两种。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\ReconciliationPlugin;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new ReconciliationPlugin($wechat);

// 下载交易对账单
$bill = $plugin->downloadBill([
    'bill_date' => '20240425',
    'bill_type' => 'ALL', // ALL / SUCCESS / REFUND / RECHARGE_REFUND
]);

// 下载资金账单（微信独有）
$fundFlow = $plugin->downloadFundFlow([
    'bill_date'    => '20240425',
    'account_type' => 'Basic', // Basic / Operation / Fees
]);

// 解析对账单（CSV 字符串转为数组）
$records = $plugin->parseBill($rawCsvData);

// 系统订单与对账单差异比对
$diff = $plugin->diff($systemOrders, $records);

if ($diff['is_consistent']) {
    echo '对账一致';
} else {
    // 仅在系统订单中存在（对账单缺失，可能是漏单或未结算）
    print_r($diff['only_in_system']);
    // 仅在对账单中存在（系统订单缺失，可能是订单丢失）
    print_r($diff['only_in_bill']);
    // 金额不一致订单
    print_r($diff['amount_mismatch']);
}
```

### 注意事项

- 对账单通常在 T+1 日生成，请勿查询当日数据
- 微信对账单 tar 包需解压后传入 `parseBill`
- Stripe 对账单需通过 Balance Transaction API 获取
- 建议每日定时任务执行对账，对账差异需及时人工处理

## 个人收款插件 (PersonalReceivePlugin)

支持微信、支付宝、Stripe 的个人收款码、查询记录、提现到银行卡。

### 配置

无需额外配置。提现需配置实名信息与银行卡。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\PersonalReceivePlugin;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new PersonalReceivePlugin($wechat);

// 生成个人收款码
$result = $plugin->createQrCode([
    'amount'      => 100,
    'description' => '商品付款',
    'attach'      => ['product_id' => '123'],
]);

// 查询收款记录
$records = $plugin->queryRecords([
    'start_time' => '2024-04-01 00:00:00',
    'end_time'   => '2024-04-25 23:59:59',
]);

// 提现到银行卡
$result = $plugin->withdraw([
    'amount'       => 5000,
    'bank_card_no' => '622202************',
    'real_name'    => '张三',
    'out_biz_no'   => 'WITHDRAW_' . date('YmdHis'),
]);

// 查询提现结果
$result = $plugin->queryWithdraw('WITHDRAW_20240425000001');
```

### 注意事项

- 收款码有效期为 2 小时，过期需重新生成
- 提现到银行卡 T+1 到账，节假日顺延
- 单笔提现金额上限 50000 元，单日累计上限 200000 元
- 实名认证姓名必须与银行卡持卡人一致

## 自动结算插件 (AutoSettlementPlugin)

支持微信、支付宝、Stripe、PayPal 的支付后自动结算能力。需配合 `WalletManager` 使用。

### 配置

需先实例化 `WalletManager` 并绑定用户钱包账户。

### 使用示例

```php
<?php

use Kode\Pays\Core\WalletManager;
use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\AutoSettlementPlugin;

$walletManager = new WalletManager();

// 绑定微信零钱账户（实时结算）
$walletManager->bind('user_001', 'wechat_wallet', [
    'account'          => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'real_name'        => '张三',
    'auto_settlement'  => true,
    'min_amount'       => 100,            // 满 1 元才结算
    'settlement_type'  => 'realtime',     // 实时结算
]);

// 绑定银行卡（每日定时结算）
$walletManager->bind('user_001', 'bank_card', [
    'account'          => '622202************',
    'real_name'        => '张三',
    'bank_code'        => 'ICBC',
    'auto_settlement'  => true,
    'min_amount'       => 5000,           // 满 50 元才结算
    'settlement_type'  => 'daily',
    'settlement_time'  => '02:00',        // 每日凌晨 2 点结算
]);

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$plugin = new AutoSettlementPlugin($wechat, $walletManager);

// 支付成功后触发结算
$order = $wechat->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_fee'    => 1000,
    'body'         => '商品购买',
]);

// 单笔结算
$result = $plugin->settle('user_001', [
    'transaction_id' => $order['transaction_id'],
    'amount'         => $order['total_fee'],
    'out_biz_no'     => 'SETTLE_' . date('YmdHis'),
    'description'    => '订单自动结算',
]);

// 批量结算（适合定时任务）
$results = $plugin->settleBatch([
    ['user_id' => 'user_001', 'transaction_id' => 'T001', 'amount' => 1000, 'out_biz_no' => 'S001'],
    ['user_id' => 'user_002', 'transaction_id' => 'T002', 'amount' => 2000, 'out_biz_no' => 'S002'],
]);

// 查询结算状态
$result = $plugin->query('SETTLE_20240425000001');
```

### 注意事项

- 结算金额必须大于 `min_amount` 才会触发，否则跳过
- `realtime` 结算实时执行；`daily` 结算需配合定时任务
- 结算失败会保留记录，可重新调用 `settle` 重试
- 批量结算最多 100 笔，超过请分批调用
- 结算操作建议配合 `IdempotencyGuard` 防止重复结算

## 加密货币插件 (CryptoPlugin)

支持 Coinbase Commerce 的加密货币订单管理与链上确认。

### 配置

需配置 Coinbase API Key。加密货币订单支持法币定价或加密货币定价两种方式。

### 使用示例

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\CryptoPlugin;

$coinbase = Pay::coinbase([
    'api_key' => 'your-coinbase-api-key',
]);

$plugin = new CryptoPlugin($coinbase);

// 创建法币定价订单（用户支付等值的加密货币）
$result = $plugin->createOrder([
    'out_trade_no' => 'ORDER_001',
    'total_amount' => 10000,         // 单位：分
    'currency'     => 'USD',
    'metadata'     => ['product_id' => 'P001'],
]);

// 创建加密货币定价订单（指定币种和数量）
$result = $plugin->createCryptoOrder([
    'out_trade_no'    => 'ORDER_002',
    'crypto_amount'   => '50.00',
    'crypto_currency' => 'USDC',
    'metadata'        => ['product_id' => 'P002'],
]);

// 查询链上确认状态
$status = $plugin->getOnChainStatus($chargeId);

// 查询订单状态
$result = $plugin->queryOrder($chargeId);

// 检查是否达到安全确认数（默认 6 个区块）
$result = $plugin->isConfirmed($chargeId, 6);
```

### 注意事项

- 加密货币价格波动大，建议法币定价以锁定汇率
- 安全确认数建议 BTC 设置 3-6，ETH/USDT 设置 12-20
- Coinbase Webhook 推送 `charge:confirmed` 事件后才可发货
- 区块链确认不可逆，确认完成后无需担心双花攻击

## 插件与约束验证

转账、红包等资金操作插件支持注入 `FundConstraintValidator` 进行风控验证：

```php
<?php

use Kode\Pays\Core\FundConstraintValidator;
use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\TransferPlugin;

$validator = new FundConstraintValidator();

// 配置转账约束
$validator->setTransferConstraints([
    'min_amount'         => 100,         // 最小转账金额（分）
    'max_amount'         => 200000,      // 最大转账金额（分）
    'daily_limit'        => 1000000,     // 单日累计上限（分）
    'daily_count_limit'  => 100,         // 单日笔数上限
    'allowed_hours'      => [9, 22],     // 允许转账时段（9-22 点）
    'blacklist'          => ['blocked_user_001'], // 黑名单用户
]);

// 配置红包约束
$validator->setRedPacketConstraints([
    'min_amount'         => 100,
    'max_amount'         => 200000,
    'max_total_num'      => 100,         // 裂变红包最大数量
    'daily_limit'        => 500000,
    'daily_count_limit'  => 50,
]);

// 创建带约束验证的转账插件
$plugin = new TransferPlugin($wechatGateway, $validator);

// 以下转账将自动触发约束验证
$result = $plugin->single([
    'out_biz_no'  => 'TRANSFER_001',
    'amount'      => 50000,
    'recipient'   => ['account' => 'openid_xxx', 'name' => '张三'],
    'user_id'     => 'user_001',
]);
```

约束验证失败会抛出 `PayException`，错误码为 `1004`（InvalidArgumentException）。

## 开发自定义插件

参考现有插件实现，核心要点：

1. **构造函数接收 `GatewayInterface`**，可选注入 `FundConstraintValidator`
2. **使用 `match` 表达式** 根据网关名称分发，default 抛出 `PayException::invalidArgument()`
3. **通过 `getGatewayConfig()`** 或反射获取网关配置（如 api_key、mch_id）
4. **统一异常处理**：网关业务错误抛 `GatewayException`，参数错误抛 `InvalidArgumentException`
5. **完整中文注释**：所有 public 方法必须有中文注释和 `@throws` 标注
6. **配套测试**：每个网关分支必须测试正反例

详细的插件开发流程请参考 [开发指南](development.md#新增插件)。
