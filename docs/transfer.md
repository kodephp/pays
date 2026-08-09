# 转账 / 企业付款（Transfer）

> 本文档说明 kode/pays 的转账能力设计：转账逻辑如何下沉到各网关原生方法、插件与统一入口
> 如何复用、以及不支持的能力如何优雅报「无此方法」。

## 设计原则

转账遵循本 SDK 的统一架构：**各平台的转账逻辑集合在各自网关类内部**（继承 `AbstractGateway`，
复用基类配置、签名与 HTTP 通道），通过统一入口 `Pay::call()` 动态派发调用。

- 平台特色方法由网关类直接实现，并声明 `TransferCapableInterface`：
  - `singleTransfer(array $params): array`
  - `batchTransfer(array $params): array`
  - `queryTransfer(string $outBizNo): array`
  - `transferReceipt(string $outBizNo): array`
- `TransferPlugin` 退化为「参数校验 + 资金约束校验 + 类型安全转发」层，不重复承载平台组装逻辑。
- 不支持某方法时（如 Stripe 无电子回单），统一抛 `PayException::methodNotSupported`
  （`ERROR_METHOD_NOT_SUPPORTED`，文案含「无此方法」）。

## 支持平台与方法映射

| 平台 | `singleTransfer` | `batchTransfer` | `queryTransfer` | `transferReceipt` | 说明 |
|------|------------------|-----------------|-----------------|-------------------|------|
| 微信支付 | ✅ 企业付款到零钱 | ✅ 批量转账到零钱 | ✅ | ✅ | 金额单位为分 |
| 支付宝 | ✅ 单笔转账 | ✅ 批量转账 | ✅ | ✅ | 金额单位为分；复用 `buildRequestParams` 标准签名 |
| Stripe | ✅ Payout | ✅（逐笔 Payout 聚合） | ✅ | ❌ 抛「无此方法」 | 金额单位为最小货币单位 |
| 微信支付 V3 | ✅ 商家转账（单条明细批次） | ✅ `transfer/batches` | ✅ | ✅ `transfer/bill-receipt` | 金额单位为分；收款人姓名以平台证书 RSA-OAEP 加密 |

> 能力开关：微信 / 微信 V3 / 支付宝 / Stripe 在 `GatewayManifest` 中声明 `CAP_TRANSFER => true`。
> 调用前可用 `GatewayManifest::supports('wechat', GatewayManifest::CAP_TRANSFER)` 判断。
>
> 微信 V3 的商家转账统一以「批次」表达，`singleTransfer` 即仅含一条明细的批次；
> 传入 `recipient.name` 时需配置 `platform_certificate` 与 `platform_serial_no`，
> 否则抛配置错误（微信要求敏感字段加密传输）。

## 统一入口

```php
use Kode\Pays\Facade\Pay;

// 语义化快捷方法（内部经 Pay::call 派发）
Pay::transferSingle('wechat', [
    'out_biz_no' => 'TRANSFER_' . date('YmdHis'),
    'amount'     => 100,
    'recipient'  => ['type' => 'openid', 'account' => 'oUpF8u...', 'name' => '张三'],
]);
Pay::transferBatch('alipay', [/* out_biz_no + transfer_detail_list */]);
Pay::transferQuery('wechat', 'TRANSFER_20240425000001');
Pay::transferReceipt('alipay', 'TRANSFER_20240425000001');

// 等价：直接派发网关原生方法
Pay::call('wechat', 'singleTransfer', $params);
```

## 插件调用（含资金约束校验）

```php
use Kode\Pays\Core\FundConstraintValidator;
use Kode\Pays\Plugin\TransferPlugin;

$validator = new FundConstraintValidator();
$validator->setTransferConstraints(['min_amount' => 100, 'max_amount' => 200000]);

$plugin = new TransferPlugin($wechat, $validator);
$plugin->single([/* ... */]);   // 校验不通过抛 ParamError
```

- 插件在 `single()` 阶段调用 `FundConstraintValidator::validateTransfer()` 做金额/时间/黑白名单/日限额校验。
- 网关未实现 `TransferCapableInterface` 时，`single/batch/query/receipt` 抛清晰异常。

## 缺方法即「无此方法」

```php
try {
    Pay::transferReceipt('stripe', 'T1'); // Stripe 无电子回单
} catch (PayException $e) {
    // 网关 stripe 不支持方法：transferReceipt（无此方法）
}
```

## 生产联调提示

- 微信「企业付款到零钱」接口实际要求 XML + MD5 签名；当前网关方法沿用既有插件构造，
  投产前如需严格合规，请在 `WechatPayGateway::singleTransfer()` 接入 `Signer::md5` 与 `arrayToXml`。
- 支付宝转账已通过 `buildRequestParams()` 走标准 RSA2 签名，字段以官方文档为准。
- Stripe Payout 货币单位、目的地账户类型（Connected Account）以官方文档为准。
