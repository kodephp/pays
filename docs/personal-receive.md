# 个人收款（Personal Receive）

> 本文档说明 kode/pays 的个人收款能力设计：收款逻辑如何下沉到各网关原生方法、插件与统一入口
> 如何复用、以及不支持的能力如何优雅报「无此方法」。
>
> 收款到账后的「真伪校验 / 轮询确认 / 幂等防护」由 [`PersonalReceiveVerifier`](personal_receive.md)
> 负责（见同名文档），二者配合：插件负责生成收款码、查询记录、提现，验证器负责一致性校验。

## 设计原则

个人收款遵循本 SDK 的统一架构：**各平台的收款逻辑集合在各自网关类内部**（继承 `AbstractGateway`，
复用基类配置、签名与 HTTP 通道），通过统一入口 `Pay::call()` 动态派发调用。

- 平台特色方法由网关类直接实现，并声明 `PersonalReceiveCapableInterface`：
  - `createQrCode(array $params): array`（生成收款二维码 / Payment Link）
  - `queryRecords(array $params): array`（查询收款记录）
  - `withdraw(array $params): array`（提现到银行卡）
  - `queryWithdraw(string $outBizNo): array`（查询提现结果）
- `PersonalReceivePlugin` 退化为「参数校验 + 类型安全转发」层，不重复承载平台组装逻辑。
- 不支持某方法时统一抛 `PayException::methodNotSupported`
  （`ERROR_METHOD_NOT_SUPPORTED`，文案含「无此方法」）。

## 支持平台与方法映射

| 平台 | `createQrCode` | `queryRecords` | `withdraw` | `queryWithdraw` | 说明 |
|------|----------------|----------------|------------|------------------|------|
| 微信支付 | ✅ `pay/unifiedorder`（NATIVE） | ✅ `pay/downloadbill` | ✅ `mmpaymkttransfers/pay_bank` | ✅ `mmpaymkttransfers/query_bank` | 金额单位为分；提现银行卡号/姓名经 RSA 加密（`encryptBankCard`），投产前请接入 `Signer::md5` 与 `arrayToXml` |
| 微信支付 V3 | ✅ `v3/pay/transactions/native` | ✅ `v3/bill/tradebill` | ✅ `v3/transfer/batches`（到零钱） | ✅ `v3/transfer/batches/out-batch-no/{no}` | 金额单位为分；`notify_url` 必填；APIv3 无付款到银行卡通道，提现统一到零钱（需 `account` openid） |
| 支付宝 | ✅ `alipay.trade.precreate` | ✅ `alipay.trade.query` | ✅ `alipay.fund.trans.uni.transfer` | ✅ `alipay.fund.trans.common.query` | 金额单位为分；复用 `buildRequestParams` 标准 RSA2 签名 |
| 云闪付 | ✅ `backTransReq.do`（txnType=01/07 二维码消费） | ✅ `queryTrans.do`（须传 `out_trade_no`） | ✅ `backTransReq.do`（txnType=12 代付） | ✅ `queryTrans.do`（bizType=000401） | 金额单位为分（`txnAmt`）；全部请求经 RSA 签名；银联无交易列表接口，只能逐笔查询 |
| Stripe | ✅ `v1/prices` + `v1/payment_links` | ✅ `v1/payment_intents` | ✅ `v1/payouts` | ✅ `v1/payouts/{id}`，或 `meta:` 前缀按单号过滤 | 只能打到已关联的外部账户（`ba_xxx` / `card_xxx`），不接受任意银行卡号 |
| PayPal | ✅ `v2/invoicing`（发票二维码） | ✅ `v1/reporting/transactions` | ✅ `v1/payments/payouts` | ✅ `v1/payments/payouts/{batch}`，或 `item:` 前缀查明细 | 金额入参为分，内部换算为两位小数；提现语义为付款给指定收款账户（默认按邮箱） |
| Square | ✅ `v2/online-checkout/payment-links` | ✅ `v2/payments` | ❌ 报「无此方法」 | ✅ `v2/payouts/{id}`，或 `entries:` 前缀查明细 | 金额单位为分；Square 按结算周期自动打款，无主动提现接口 |
| Revolut | ✅ `api/1.0/orders`（checkout_url） | ✅ `api/1.0/orders` 列表 | ✅ `api/1.0/pay` | ✅ `api/1.0/transactions?request_id=` | 金额入参为分，出款换算为主单位；支持 `iban` / `counterparty_id` / 卡出款 |

> 能力开关：微信 / 微信 V3 / 支付宝 / 云闪付 / Stripe / PayPal / Square / Revolut
> 在 `GatewayManifest` 中声明 `CAP_PERSONAL_RECEIVE => true`。
> 调用前可用 `GatewayManifest::supports('wechat', GatewayManifest::CAP_PERSONAL_RECEIVE)` 判断。

### 标识前缀约定

统一契约 `queryWithdraw(string $outBizNo)` 只收单个字符串，各平台用前缀区分「二选一入参」：

| 平台 | 默认语义 | 前缀语义 |
|------|---------|---------|
| Stripe | Stripe 打款单号 `po_xxx` | `meta:{out_biz_no}` → 列表接口按 metadata 过滤 |
| PayPal | `payout_batch_id` | `item:{payout_item_id}` → 查单笔明细 |
| Square | `payout_id` | `entries:{payout_id}` → 查打款明细条目 |

## 统一入口

```php
use Kode\Pays\Facade\Pay;

// 语义化快捷方法（内部经 Pay::call 派发）
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
    'out_biz_no'   => 'WD_20240425000001',
]);
Pay::personalReceiveQueryWithdraw('alipay', 'WD_20240425000001');

// 等价：直接派发网关原生方法
Pay::call('wechat', 'createQrCode', $params);

// Stripe 提现（打到已关联的外部账户）
Pay::personalReceiveWithdraw('stripe', [
    'out_biz_no'  => 'WD_20260810000001',
    'amount'      => 5000,
    'currency'    => 'usd',
    'destination' => 'ba_1234567890',
]);
Pay::personalReceiveQueryWithdraw('stripe', 'meta:WD_20260810000001');

// Square 无主动提现接口，调用会报「无此方法」
Pay::personalReceiveWithdraw('square', $params); // 抛 PayException（无此方法）
```

## 插件调用

```php
use Kode\Pays\Plugin\PersonalReceivePlugin;

$plugin = new PersonalReceivePlugin($wechatGateway);

// 生成个人收款码
$result = $plugin->createQrCode([
    'amount'      => 100,
    'description' => '商品付款',
    'attach'      => ['product_id' => '123'],
]);

// 查询收款记录
$records = []; // queryRecords 直接走网关，无本地返回
$plugin->queryRecords([
    'start_time' => '2024-04-01 00:00:00',
    'end_time'   => '2024-04-25 23:59:59',
]);

// 提现到银行卡
$plugin->withdraw([
    'amount'       => 5000,
    'bank_card_no' => '6222************',
    'real_name'    => '张三',
    'out_biz_no'   => 'WD_20240425000001',
]);

// 查询提现结果
$plugin->queryWithdraw('WD_20240425000001');
```

插件只做参数校验与转发；平台组装逻辑在网关内部。网关未实现 `PersonalReceiveCapableInterface`
（或不支持某方法，如 Square 的 `withdraw`）时，统一抛「无此方法」。

## 生产联调提示

- **微信个人收款**：当前实现沿用既有插件构造（`unifiedorder` / `pay_bank` 等请求数组经 `post` 直发）。
  投产前如需严格合规，建议在网关 `createQrCode` / `withdraw` 内接入 `Signer::md5` 与 `arrayToXml`，
  并按官方要求配置证书（apiclient_cert.pem 等）；`encryptBankCard` 需配置 `bank_public_key`。
- **支付宝个人收款**：已复用 `buildRequestParams` 标准 RSA2 签名，金额按分（`amount / 100`，两位小数）。
- **Stripe 个人收款**：通过 `v1/prices` 创建临时价格再生成 `v1/payment_links`，`out_trade_no` 写入
  `metadata` 便于后续对账；提现走 `v1/payouts`，`out_biz_no` 同样写入 `metadata`，
  因此可用 `meta:` 前缀反查。注意 Stripe 只能打到本账户已关联的外部账户。
- **云闪付个人收款**：二维码消费与代付均为后台交易（`backTransReq.do`），
  代付产品（bizType=000401）需单独签约；收款人账号如需按银联要求加密，
  请用 `account_encrypted` 传密文（不传则按 `bank_card_no` 明文上报，仅测试环境适用）。
  银联无交易列表接口，`queryRecords` 必须传 `out_trade_no` 逐笔查询，批量对账请用对账文件。
- **PayPal 个人收款**：`createQrCode` 依次调用「创建发票 → 发送发票 → 生成二维码」三个端点，
  传 `auto_send => false` 可只建草稿不发送。提现为 Payouts 批次，
  PayPal 不支持按商户单号反查，须保存返回的 `payout_batch_id`。
- **Square 个人收款**：收款链接为 Quick Pay 模式，需要配置 `location_id`（也可按次传入）；
  Square 返回的是收款链接而非二维码图片，二维码由调用方自行生成。
- **Revolut 个人收款**：收款走 Merchant Orders，返回 `checkout_url`；
  提现复用出款接口 `/api/1.0/pay`，`out_biz_no` 即 `request_id`，可据此查询。
- **金额单位**：全部平台的收款与提现金额统一以「分」（最小货币单位）传入，
  需要主单位的平台（支付宝、PayPal、Revolut 出款）由网关内部换算。
