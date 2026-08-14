# 对账（Reconciliation）

> 本文档说明 kode/pays 的对账能力设计：对账逻辑如何下沉到各网关原生方法、插件与统一入口
> 如何复用、以及不支持的能力如何优雅报「无此方法」。
>
> 平台无关的「系统订单 vs 对账单差异比对」由 `ReconciliationPlugin::diff()` 负责，可在已下载并解析的
> 记录上直接调用，无需网关能力。

## 设计原则

对账遵循本 SDK 的统一架构：**各平台的对账逻辑集合在各自网关类内部**（继承 `AbstractGateway`，
复用基类配置、签名与 HTTP 通道），通过统一入口 `Pay::call()` 动态派发调用。

- 平台特色方法由网关类直接实现，并声明 `ReconciliationCapableInterface`：
  - `downloadBill(array $params): array`（下载交易对账单）
  - `downloadFundFlow(array $params): array`（下载资金账单）
  - `parseBill(string $rawData): array`（解析对账单原始数据，CSV / JSON）
- `ReconciliationPlugin` 退化为「参数校验 + 类型安全转发」层，不重复承载平台组装逻辑。
- 不支持某方法时统一抛 `PayException::methodNotSupported`
  （`ERROR_METHOD_NOT_SUPPORTED`，文案含「无此方法」）。

## 支持平台与方法映射

| 平台 | `downloadBill` | `downloadFundFlow` | `parseBill` | 说明 |
|------|----------------|--------------------|-------------|------|
| 微信支付 | ✅ `pay/downloadbill` | ✅ `pay/downloadfundflow` | ✅ CSV | 金额单位为分；当前沿用既有构造，投产前请接入 `Signer::md5` 与 `arrayToXml` |
| 支付宝 | ✅ `alipay.data.dataservice.bill.downloadurl.query` | ✅ `alipay.data.bill.ereceipt.apply` | ✅ CSV | 复用 `buildRequestParams` 标准 RSA2 签名；`downloadBill` 申请下载地址后自动下载文件、ZIP 包取首个明细 CSV 解压解析为记录（需 PHP ZipArchive 扩展），返回结构含 `records` |
| Stripe | ✅ `v1/balance_transactions` | ❌ 报「无此方法」 | ✅ JSON | Balance Transaction 列表（`created` 时间区间）；资金账单能力暂未提供 |
| 微信支付 V3 | ✅ `bill/tradebill` | ✅ `bill/fundflowbill` | ✅ CSV | 两步流程：先取含 `download_url` 的元数据，再下载并解析 CSV。**交易账单**下载内容若以 GZIP 魔数开头会自动解压；**资金账单**为「AES-256-ECB 加密 + GZIP 压缩」，网关自动解密（需 32 字节 `api_v3_key`）并校验 `hash_value`，解密后直接得到 CSV 记录 |
| 美团支付 | ✅ `api/bill/download` | ✅ `api/bill/fundflow` | ✅ CSV | 金额单位为分；MD5(`app_secret`) 签名，账单内容置于 `bill_content` |
| 京东支付 | ✅ `api/bill/download` | ✅ `api/bill/fundflow` | ✅ CSV | 金额单位为分；MD5(`md5_key`) 签名，账单内容置于 `billContent` |
| Adyen | ✅ Report API（Settlement detail report） | ✅ Report API（Payment accounting report） | ✅ CSV | 两步：先 `getReport` 取 `url`，再下载并解析 CSV；`bill_date` 为 YYYYMMDD |
| Revolut | ✅ `/api/1.0/transactions`（日期区间） | ❌ 报「无此方法」 | ✅ JSON | 交易列表按 `from`/`to` 时间窗拉取并解析；无独立资金账单 API |

> 能力开关：微信 / 微信 V3 / 支付宝 / Stripe / 美团 / 京东 / Adyen / Revolut 在 `GatewayManifest` 中声明 `CAP_RECONCILIATION => true`。
> 调用前可用 `GatewayManifest::supports('wechat', GatewayManifest::CAP_RECONCILIATION)` 判断。

## 余额查询（BalanceCapableInterface）

与对账单下载互补，用于账实核对与可用资金监控。目前仅微信支付 V3 提供标准余额接口：

| 平台 | `queryBalance` | `queryDayEndBalance` | 说明 |
|------|----------------|----------------------|------|
| 微信支付 V3 | ✅ `GET /v3/merchant/fund/balance` | ✅ `GET /v3/merchant/fund/dayendbalance/{date}` | 实时余额 / 日终余额；服务商模式自动注入 `sub_mchid` |

> 能力开关：微信支付 V3 在 `GatewayManifest` 中声明 `CAP_BALANCE => true`，
> 可用 `GatewayManifest::supports('wechat_v3', GatewayManifest::CAP_BALANCE)` 判断。
> `account_type` 仅接受 `BASIC` / `OPERATION` / `FEES`；`queryDayEndBalance` 的 `date` 必须为 `YYYY-MM-DD`。

```php
$balance = Pay::call('wechat_v3', 'queryBalance', [['account_type' => 'BASIC']]);
$dayEnd  = Pay::call('wechat_v3', 'queryDayEndBalance', ['2026-08-01', ['account_type' => 'OPERATION']]);
```

## 统一入口

```php
use Kode\Pays\Facade\Pay;

// 语义化快捷方法（内部经 Pay::call 派发）
Pay::reconciliationDownloadBill('wechat', [
    'bill_date' => '20240425',
    'bill_type' => 'ALL',
]);
Pay::reconciliationDownloadFundFlow('alipay', [
    'bill_date' => '20240425',
    'account_type' => 'Basic',
]);
Pay::reconciliationParseBill('wechat', $rawCsvData);

// 等价：直接派发网关原生方法
Pay::call('wechat', 'downloadBill', $params);

// Stripe 资金账单能力未提供，调用会报「无此方法」
Pay::reconciliationDownloadFundFlow('stripe', $params); // 抛 PayException（无此方法）
```

## 插件调用

```php
use Kode\Pays\Plugin\ReconciliationPlugin;

$plugin = new ReconciliationPlugin($wechatGateway);

// 下载交易对账单
$bill = $plugin->downloadBill([
    'bill_date' => '20240425',
    'bill_type' => 'ALL',
]);

// 下载资金账单（V3：自动 AES-256-ECB 解密 + GZIP 解压 + SHA1 校验）
$fundFlow = $plugin->downloadFundFlow([
    'bill_date' => '20240425',
    'account_type' => 'BASIC', // BASIC / OPERATION / FEES
]);

// $fundFlow 结构：
// [
//   'bill_date'   => '20240425',
//   'account_type'=> 'BASIC',
//   'download_url'=> 'https://...',
//   'hash_type'   => 'SHA1',
//   'hash_value'  => '...',          // 与解密明文 SHA1 比对，不一致抛 gatewayError
//   'raw_data'    => [...],          // 微信返回的元数据
//   'records'     => [ /* 解析后的资金流水 */ ],
// ]

// 解析对账单原始数据
$records = $plugin->parseBill($rawCsvData);

// 比对系统订单与对账单差异（平台无关）
$report = $plugin->diff($systemOrders, $billRecords);
```

插件只做参数校验与转发；平台组装逻辑在网关内部。网关未实现 `ReconciliationCapableInterface`
（或不支持某方法，如 Stripe 的 `downloadFundFlow`）时，统一抛「无此方法」。

## 差异比对（平台无关）

`diff()` 不依赖网关能力，直接比对两组交易记录：

- 以 `out_trade_no` / `order_id`（系统侧）与 `out_trade_no` / `merchant_order_no`（账单侧）为关联键；
- 输出 `only_in_system`（仅系统有）、`only_in_bill`（仅账单有）、`amount_mismatch`（金额不一致）、
  `status_mismatch`（状态不一致），以及整体 `is_consistent` 判定。

## 生产联调提示

- **微信 V3 对账**：`downloadBill` 先取 `bill/tradebill` 元数据再下载并解析 CSV（下载内容以 GZIP 魔数开头时自动解压）；
  `downloadFundFlow` 先取 `bill/fundflowbill` 元数据，再下载「AES-256-ECB 加密 + GZIP 压缩」的文件，
  网关自动以 `api_v3_key` 解密、GZIP 解压并解析，无需调用方介入。**需配置 32 字节 `api_v3_key`**，
  缺失或长度不符时解密抛 `configError`；元数据的 `hash_value`（SHA1）与解密明文不一致时抛 `gatewayError`（防篡改）。
  对账单接口返回 CSV 文本（统一入口将原始文本置于 `data` 字段），由 `parseBill` 解析。
- **微信 V2 对账**：当前实现沿用既有插件构造（`downloadbill` / `downloadfundflow` 请求数组经 `post` 直发）。
  投产前如需严格合规，建议在网关 `downloadBill` / `downloadFundFlow` 内接入 `Signer::md5` 与 `arrayToXml`，
  并按官方要求配置证书。
- **支付宝对账**：已复用 `buildRequestParams` 标准 RSA2 签名；`downloadBill` 返回的是账单下载地址，
  需二次拉取账单文件后再用 `parseBill` 解析 CSV。
- **Stripe 对账**：通过 `v1/balance_transactions` 按 `created` 时间区间拉取 Balance Transaction 列表，
  经 `parseBill` 解析 JSON。Stripe 不提供面向资金账单的下载能力，调用 `downloadFundFlow` 会明确报「无此方法」。
- **日期格式**：对账单日期 `bill_date` 统一为 `YYYYMMDD`。
