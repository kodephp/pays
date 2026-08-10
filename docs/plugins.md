# Kode Pays SDK 插件总览

Kode Pays 提供丰富的插件体系，覆盖支付业务的完整生命周期。所有插件均位于 `Kode\Pays\Plugin` 命名空间下，通过组合 `GatewayInterface` 扩展网关能力。

## 插件列表

| 插件 | 类名 | 支持网关 | 核心功能 |
|------|------|----------|----------|
| 分账插件 | `ProfitSharingPlugin` | 微信、微信 V3、支付宝、Stripe、抖音、云闪付 | 创建分账、查询分账、分账回退、解冻资金（网关原生方法 + 插件校验转发） |
| 转账插件 | `TransferPlugin` | 微信、支付宝、Stripe | 单笔转账、批量转账、查询转账、电子回单 |
| 退款插件 | `RefundPlugin` | 微信、微信 V3、支付宝、Stripe、PayPal、Adyen、Revolut | 申请退款、查询退款、取消退款 |
| 红包插件 | `RedPacketPlugin` | 微信、支付宝 | 普通红包、裂变红包、查询红包 |
| 订阅插件 | `SubscriptionPlugin` | Stripe、PayPal | 订阅计划、订阅管理、暂停/恢复/取消 |
| 对账插件 | `ReconciliationPlugin` | 微信、支付宝、Stripe | 下载对账单、解析对账单、差异比对（网关原生方法 + 插件校验转发） |
| 个人收款插件 | `PersonalReceivePlugin` | 微信、微信 V3、支付宝、Stripe | 收款码、查询记录、提现到银行卡 |
| 自动结算插件 | `AutoSettlementPlugin` | 微信、微信 V3、支付宝、Stripe、PayPal | 支付后自动提现到钱包（网关原生方法 + 插件编排转发） |
| 加密货币插件 | `CryptoPlugin` | Coinbase | 加密货币订单、链上确认、汇率查询（网关原生方法 + 插件校验转发） |

## 插件架构

所有插件遵循统一的设计模式：

1. **组合而非继承**：构造函数接收 `GatewayInterface`，可选注入 `FundConstraintValidator`
2. **能力下沉 + 类型安全转发**：平台组装逻辑下沉到各网关原生方法（网关声明对应 `XxxCapableInterface`），
   插件仅做「参数校验 + 类型安全转发」（`forwardToCapableGateway`），不再承载 `match($gateway::getName())`
   平台内联分支；未实现能力接口或缺少可选方法的网关调用会统一报「无此方法」
3. **统一异常**：不支持的场景抛出 `PayException::invalidArgument()` / `PayException::methodNotSupported()`

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\XxxCapableInterface;
use Kode\Pays\Core\PayException;

class ExamplePlugin
{
    public function __construct(
        protected GatewayInterface $gateway,
    ) {
    }

    public function doSomething(array $params): array
    {
        // 直接转发到网关原生方法，由网关自行处理平台差异
        if (!$this->gateway instanceof XxxCapableInterface) {
            throw PayException::invalidArgument('当前网关不支持此功能');
        }

        return $this->gateway->doSomething($params);
    }
}
```

## 分账插件 (ProfitSharingPlugin)

支持微信、支付宝、Stripe、抖音、云闪付的分账能力。

> 架构说明：分账的平台组装逻辑已下沉到各网关原生方法（网关声明 `ProfitSharingCapableInterface`，
> 含 `createProfitSharing` / `queryProfitSharing` / `returnProfitSharing` / `queryProfitSharingReturn` /
> `unfreezeProfitSharing`；微信、支付宝额外实现可选能力 `addProfitSharingReceiver` / `removeProfitSharingReceiver`，
> 微信另实现 `queryProfitSharingConfig`）。本插件仅做「参数校验 + 类型安全转发」，彻底消除原先的
> `match($gateway::getName())` 平台内联分支；未实现 `ProfitSharingCapableInterface` 的网关调用分账方法会统一报「无此方法」，
> 可选能力（如 `queryProfitSharingConfig`）若网关未实现同样报「无此方法」。

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

// 微信查询分账结果（transaction_id 为微信必填项，其余平台忽略该参数）
$result = $plugin->query('SHARE_20240425000001', '4200000000000000');

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

> 架构说明：转账逻辑已下沉到各网关类内部（实现 `TransferCapableInterface` 的
> `singleTransfer` / `batchTransfer` / `queryTransfer` / `transferReceipt` 原生方法，
> 复用基类配置与签名）。`TransferPlugin` 仅负责「参数校验 + 资金约束校验 + 类型安全转发」，
> 因此也能通过统一入口 `Pay::transferSingle(...)` 等语义化方法直接调用。

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

### 统一入口（等价写法）

```php
// 经 Pay::call 动态派发到网关原生转账方法
Pay::transferSingle('wechat', $params);
Pay::transferBatch('alipay', $batchParams);
Pay::transferQuery('wechat', 'TRANSFER_20240425000001');
Pay::transferReceipt('alipay', 'TRANSFER_20240425000001');
```

### 注意事项

- 单笔转账金额需在网关限额内（微信单笔上限 20000 元）
- 批量转账单次最多 3000 笔
- Stripe 不提供电子回单能力，`transferReceipt` 会报「无此方法」
- 收款方姓名需与微信实名认证一致，否则会失败
- 建议配合 `FundConstraintValidator` 做风控验证

## 退款插件 (RefundPlugin)

支持微信、支付宝、Stripe、PayPal、Adyen、Revolut 的退款能力。退款逻辑已下沉到各网关原生方法，网关声明
`RefundCapableInterface`（`applyRefund` / `queryRefund` / `cancelRefund`），`RefundPlugin`
仅做「参数校验 + 类型安全转发」，不承载平台组装逻辑。

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

// 推荐：统一入口（内部经 Pay::call 派发到网关原生 applyRefund）
$result = Pay::refundApply('wechat', [
    'out_trade_no'  => 'ORDER_001',
    'out_refund_no' => 'REFUND_' . date('YmdHis'),
    'total_fee'     => 100,
    'refund_fee'    => 50,
    'refund_desc'   => '商品质量问题',
]);
Pay::refundQuery('alipay', 'REFUND_001');
Pay::refundCancel('stripe', 'REFUND_001'); // 仅 Stripe 支持

// 等价：直接通过插件
$plugin = new RefundPlugin($wechat);
$result = $plugin->apply([
    'out_trade_no'  => 'ORDER_001',
    'out_refund_no' => 'REFUND_' . date('YmdHis'),
    'total_fee'     => 100,
    'refund_fee'    => 50,
    'refund_desc'   => '商品质量问题',
]);
$result = $plugin->query('REFUND_20240425000001');
$result = $plugin->cancel('REFUND_20240425000001'); // 仅 Stripe 支持取消未处理完的退款
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

支持微信、支付宝的红包能力（普通红包 / 裂变(群)红包 / 查询）。红包逻辑已下沉到各网关原生方法，
`RedPacketPlugin` 只做「参数校验 + 类型安全转发」，不重复承载平台组装逻辑。

### 配置

微信红包需开通现金红包产品权限；支付宝需开通红包营销能力。网关需声明 `RedPacketCapableInterface`。

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

// 发放裂变红包（群发，用户分享后好友可领；微信要求 total_num >= 3）
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

### 统一入口（等价写法）

上述能力同样可由 `Pay` 门面统一派发（内部经 `Pay::call` 调用网关原生红包方法）：

```php
Pay::redPacketSend('wechat', [/* 同 send 参数 */]);
Pay::redPacketGroup('alipay', [/* 同 group 参数 */]);
Pay::redPacketQuery('wechat', 'REDPACK_20240425000001');
```

### 注意事项

- 单个红包金额范围：1 元 - 200 元
- 裂变(群)红包 `total_num` 微信要求 `>= 3`；支付宝对应 `GROUP_RED_PACKET` 场景
- 红包发放后 24 小时内未领取会自动退回商户账户
- 网关未实现 `RedPacketCapableInterface` 时调用会报「无此方法」
- 微信现金红包投产前请接入 `Signer::md5` 与 `arrayToXml`（详见 docs/red-packet.md）

## 订阅插件 (SubscriptionPlugin)

支持 Stripe、PayPal 的订阅与周期扣款能力。订阅逻辑已下沉到各网关原生方法，插件只做「参数校验
+ 类型安全转发」（架构说明见 [订阅能力设计](subscription.md)）。

### 配置

Stripe 需 `secret_key`；PayPal 需 `client_id` / `client_secret`。两网关在 `GatewayManifest`
中声明 `CAP_SUBSCRIPTION => true`。

### 使用示例（插件）

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\SubscriptionPlugin;

// Stripe 订阅
$stripe = Pay::stripe(['secret_key' => 'sk_test_...']);
$plugin = new SubscriptionPlugin($stripe);

// 创建订阅计划（金额单位为分）
$plan = $plugin->createPlan([
    'name'           => '月度会员',
    'amount'         => 9900,
    'currency'       => 'usd',
    'interval'       => 'month',
    'interval_count' => 3,
]);

// 创建订阅
$subscription = $plugin->createSubscription([
    'customer_id' => 'cus_xxx',
    'plan_id'     => $plan['id'],
]);

// 暂停 / 恢复 / 取消
$plugin->pauseSubscription($subscription['id']);
$plugin->resumeSubscription($subscription['id']);
$plugin->cancelSubscription($subscription['id']);

// 查询
$plugin->getSubscription($subscription['id']);
```

### PayPal 订阅示例（插件）

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
    'name'           => '月度会员',
    'amount'         => 9900,            // 金额单位为分（网关内部换算为两位小数字符串）
    'currency'       => 'usd',
    'interval'       => 'month',
    'interval_count' => 1,
]);

$subscription = $plugin->createSubscription([
    'plan_id'        => $plan['id'],
    'customer_email' => 'customer@example.com',
]);
```

### 统一入口（等价写法）

```php
use Kode\Pays\Facade\Pay;

Pay::subscriptionCreatePlan('stripe', [
    'name' => '月度会员', 'amount' => 9900, 'currency' => 'usd', 'interval' => 'month',
]);
Pay::subscriptionCreate('paypal', ['plan_id' => $plan['id'], 'customer_email' => 'a@b.com']);
Pay::subscriptionCancel('stripe', 'sub_xxx');
Pay::subscriptionPause('paypal', 'sub_xxx');
Pay::subscriptionResume('stripe', 'sub_xxx');
Pay::subscriptionGet('paypal', 'sub_xxx');
```

### 注意事项

- 暂停与取消的语义不同：暂停可恢复，取消不可恢复（需重新订阅）
- 平台未实现 `SubscriptionCapableInterface`（或不支持某方法）时，插件 / 统一入口统一抛「无此方法」
- Stripe 订阅扣款失败会自动重试；PayPal 按订阅计划配置的重试策略执行
- 取消订阅前请确认是否有未结算账单
- 金额单位：Stripe / PayPal 订阅金额统一以「分」传入（`amount`），网关内部换算为最小货币单位字符串

## 对账插件 (ReconciliationPlugin)

支持微信、支付宝、Stripe 的对账单下载与差异比对。

### 配置

无需额外配置。微信对账单可下载交易账单和资金账单两种。

> 微信 V2 对账单接口（`pay/downloadbill`、`pay/downloadfundflow`）以 MD5 签名 + XML 报文调用，返回 CSV 原始文本，由 `WechatBillParser` 统一解析；V3 对账单则走 APIv3 签名并返回下载链接。

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

> 架构说明：个人收款能力已下沉到各网关原生方法（网关声明 `PersonalReceiveCapableInterface`），
> 本插件仅做「参数校验 + 类型安全转发」，不再承载平台组装逻辑。Stripe 未提供提现能力，
> 调用 `withdraw` / `queryWithdraw` 会明确报「无此方法」。完整设计见
> [个人收款能力设计](personal-receive.md)。

### 配置

无需额外配置。提现需配置实名信息与银行卡（微信提现需 `bank_public_key` 做 RSA 加密）。

### 使用示例（插件）

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
$plugin->queryRecords([
    'start_time' => '2024-04-01 00:00:00',
    'end_time'   => '2024-04-25 23:59:59',
]);

// 提现到银行卡
$plugin->withdraw([
    'amount'       => 5000,
    'bank_card_no' => '622202************',
    'real_name'    => '张三',
    'out_biz_no'   => 'WITHDRAW_' . date('YmdHis'),
]);

// 查询提现结果
$plugin->queryWithdraw('WITHDRAW_20240425000001');
```

### 统一入口（等价写法）

```php
// 与插件调用等价，内部经 Pay::call 派发到网关原生方法
Pay::personalReceiveQrCode('wechat', [
    'amount'      => 100,
    'description' => '商品付款',
    'attach'      => ['product_id' => '123'],
]);
Pay::personalReceiveWithdraw('wechat', [
    'amount'       => 5000,
    'bank_card_no' => '622202************',
    'real_name'    => '张三',
    'out_biz_no'   => 'WITHDRAW_' . date('YmdHis'),
]);

// Stripe 未实现提现能力，调用会报「无此方法」
Pay::personalReceiveWithdraw('stripe', $params); // 抛 PayException（无此方法）
```

### 注意事项

- 收款码有效期为 2 小时，过期需重新生成
- 提现到银行卡 T+1 到账，节假日顺延
- 单笔提现金额上限 50000 元，单日累计上限 200000 元
- 实名认证姓名必须与银行卡持卡人一致
- 金额统一以「分」为单位传入（微信 / 支付宝）
- 收款到账后的真伪校验 / 轮询确认 / 幂等防护由 `PersonalReceiveVerifier` 负责（见同名文档）

## 对账插件 (ReconciliationPlugin)

支持微信、支付宝、Stripe 的交易对账单下载、资金账单下载与对账单解析；并提供平台无关的 `diff()` 差异比对能力。

> 架构说明：对账能力已下沉到各网关原生方法（网关声明 `ReconciliationCapableInterface`），
> 本插件仅做「参数校验 + 类型安全转发」，不再承载平台组装逻辑。Stripe 未提供资金账单能力，
> 调用 `downloadFundFlow` 会明确报「无此方法」。`diff()` 为平台无关工具方法，可直接比对系统订单与对账单差异。
> 完整设计见 [对账能力设计](reconciliation.md)。

### 配置

无需额外配置。下载对账单需相应网关具备对账权限（微信需 `api_key`、支付宝需 `private_key`、Stripe 需 `secret_key`）。

### 使用示例（插件）

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
    'bill_type' => 'ALL',
]);

// 下载资金账单
$fundFlow = $plugin->downloadFundFlow([
    'bill_date' => '20240425',
    'account_type' => 'Basic',
]);

// 解析对账单原始数据
$records = $plugin->parseBill($rawCsvData);

// 比对系统订单与对账单差异（平台无关）
$report = $plugin->diff($systemOrders, $billRecords);
```

### 统一入口（等价写法）

```php
// 与插件调用等价，内部经 Pay::call 派发到网关原生方法
Pay::reconciliationDownloadBill('wechat', [
    'bill_date' => '20240425',
    'bill_type' => 'ALL',
]);
Pay::reconciliationDownloadFundFlow('alipay', [
    'bill_date' => '20240425',
    'account_type' => 'Basic',
]);
Pay::reconciliationParseBill('wechat', $rawCsvData);

// Stripe 未实现对账资金账单能力，调用会报「无此方法」
Pay::reconciliationDownloadFundFlow('stripe', $params); // 抛 PayException（无此方法）
```

### 注意事项

- 对账单日期格式为 `YYYYMMDD`
- 微信对账单为 CSV 文本，需配置 MD5 签名方可投产（当前沿用既有构造，投产前请接入 `Signer::md5`）
- 支付宝对账单下载接口返回的是账单下载地址，需二次拉取账单文件
- Stripe 对账基于 Balance Transaction 列表（`created` 时间区间），资金账单能力暂未提供

## 自动结算插件 (AutoSettlementPlugin)

支持微信、支付宝、Stripe、PayPal 的支付后自动结算能力。需配合 `WalletManager` 使用。

> 架构说明：结算能力已下沉到各网关原生方法（网关声明 `SettlementCapableInterface`，含
> `settleToWallet` / `settleToBankCard` / `settleToPayout` / `querySettlement`）。本插件仅承担
> 「编排 + 类型安全转发」：先由 `WalletManager` 判定结算条件与目标账户，再把领域语义的结算目标类型
> 映射到网关能力方法。插件内不再有任何 `match($gateway::getName())` 平台内联分支，也不再通过反射
> 读取网关私有配置。未实现 `SettlementCapableInterface` 的网关调用结算方法会统一报「无此方法」；
> 平台不支持的结算语义（如微信/支付宝的 `settleToPayout`、Stripe/PayPal 的 `settleToWallet`）
> 由网关自身抛出同类异常。

### 结算目标类型与网关能力映射

| 钱包目标类型 | 网关能力方法 | 支持网关 |
|--------------|--------------|----------|
| `wechat_wallet` | `settleToWallet` | 微信（企业付款到零钱）、微信 V3（商家转账批次） |
| `alipay_balance` | `settleToWallet` | 支付宝（单笔转账到账户） |
| `bank_card` | `settleToBankCard` | 微信（企业付款到银行卡）、支付宝（无密转账到银行卡）；微信 V3 无该通道，报「无此方法」 |
| `stripe_connect` | `settleToPayout` | Stripe（Connect 转账） |
| `paypal_wallet` | `settleToPayout` | PayPal（Payouts 批次） |

该映射描述的是「结算语义」而非「网关品牌」，新增网关只需实现 `SettlementCapableInterface`，
无需改动插件代码。

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
- 结算金额入参统一为**分**，网关内部按平台要求换算（支付宝/PayPal 自动转两位小数）
- 也可绕过钱包规则，直接通过统一入口调用结算能力：
  `Pay::settlementToWallet()` / `Pay::settlementToBankCard()` / `Pay::settlementToPayout()` / `Pay::settlementQuery()`

## 加密货币插件 (CryptoPlugin)

支持 Coinbase Commerce 的加密货币订单管理与链上确认。

> 架构说明：加密货币能力已下沉到各网关原生方法（网关声明 `CryptoCapableInterface`，
> 含 `createOrder` / `createCryptoOrder` / `getPaymentAddresses` / `getConfirmations` /
> `getExchangeRate` / `queryOrder` / `refund` / `verifyNotify`）。本插件仅做「能力断言 +
> 类型安全转发」，不再承载任何平台内联分支（原先散落的 `match($gateway::getName())` 与
> `instanceof CoinbaseGateway` 已消除）。未实现 `CryptoCapableInterface` 的网关调用加密货币
> 方法会统一报「无此方法」。完整设计见 [Coinbase 接入文档](coinbase.md)。

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
