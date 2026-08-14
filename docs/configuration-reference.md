# 配置参考（各平台字段清单）

本文件汇总各支付平台的**必填 / 可选配置字段**，开发者无需运行代码即可查阅「接入某平台需要传哪些配置」。
字段契约由 `GatewayManifest` 统一维护，并与各网关 `Config::fromArray()` 严格一致（详见[能力与配置发现](manifest-discovery.md)）。

> 约定
> - **必填**：缺失会导致 `Pay::validate()` 返回 `valid=false`，且网关实际调用时多半报错。
> - **可选**：通常含默认值或可留空（如证书路径、沙箱开关）。
> - `sandbox`：多数平台用 `sandbox=true/false` 切换沙箱；少数平台用 `environment`（如 `square`、`adyen`、`google`）。
> - 回调地址 `notify_url` / 前端回跳 `return_url` **不在配置级契约内**，属于每次请求的 `$params`，不在此列出（仅 `qq` 的 `notify_url` 由 Config 持有）。

## 国内主流

### wechat（微信支付）
- 必填：`app_id`、`mch_id`、`api_key`
- 可选：`api_v3_key`、`cert_path`、`key_path`、`platform_cert_path`、`sandbox`

### alipay（支付宝）
- 必填：`app_id`、`private_key`、`public_key`
- 可选：`app_auth_token`、`sandbox`

### unionpay（银联）
- 必填：`mer_id`、`cert_path`、`cert_pwd`
- 可选：`sandbox`

### douyin（抖音支付）
- 必填：`app_id`、`merchant_id`、`salt`
- 可选：`sandbox`

### wechat_v3（微信支付 V3）
- 必填：`mch_id`、`serial_no`、`private_key`、`api_key`
- 可选：`app_id`、`sandbox`

### qq（QQ 钱包）
- 必填：`app_id`、`mch_id`、`api_key`
- 可选：`notify_url`、`sandbox`

## 国际支付

### paypal
- 必填：`client_id`、`client_secret`
- 可选：`sandbox`

### stripe
- 必填：`secret_key`
- 可选：`publishable_key`、`webhook_secret`、`api_version`、`sandbox`

### square
- 必填：`application_id`、`access_token`
- 可选：`environment`、`api_version`

### adyen
- 必填：`api_key`、`merchant_account`
- 可选：`client_key`、`environment`

## 生活服务 / 电商

### meituan（美团）
- 必填：`app_id`、`app_secret`、`merchant_id`
- 可选：`sandbox`

### jd（京东）
- 必填：`merchant_no`、`des_key`、`md5_key`、`rsa_private_key`、`rsa_public_key`
- 可选：`sandbox`

### kuaishou（快手）
- 必填：`app_id`、`app_secret`、`merchant_id`
- 可选：`sandbox`

### apple（Apple Pay）
- 必填：`merchant_identifier`、`merchant_certificate`、`merchant_certificate_key`、`apple_pay_merchant_id`、`domain_name`
- 可选：`sandbox`

### google（Google Pay）
- 必填：`merchant_id`、`merchant_name`、`gateway_merchant_id`
- 可选：`environment`

## 跨境 / 汇款 / 数字银行

### amazon
- 必填：`merchant_id`、`access_key`、`secret_key`、`client_id`、`region`
- 可选：`sandbox`

### klarna
- 必填：`username`、`password`、`region`
- 可选：`sandbox`

### alipay_global（支付宝国际）
- 必填：`app_id`、`private_key`、`public_key`
- 可选：`gateway_url`、`sign_type`、`sandbox`

### wise
- 必填：`api_key`、`profile_id`
- 可选：`sandbox`

### payoneer
- 必填：`api_key`、`api_secret`、`program_id`
- 可选：`sandbox`

### revolut
- 必填：`api_key`、`merchant_id`
- 可选：`sandbox`

### coinbase
- 必填：`api_key`、`webhook_secret`
- 可选：`sandbox`

### afterpay（先买后付）
- 必填：`merchant_id`、`secret_key`、`region`
- 可选：`sandbox`

### hitpay（东南亚）
- 必填：`api_key`、`webhook_secret`
- 可选：`sandbox`

### xendit（东南亚）
- 必填：`secret_key`、`public_key`、`callback_token`
- 可选：`sandbox`

## 聚合支付

### aggregate（多渠道路由）
- 必填：`channels`（子渠道配置集合）
- 可选：无

## 快速拿到可拷贝模板

不必手动对照上表，运行时直接生成模板：

```php
use Kode\Pays\Facade\Pay;

$config = Pay::configExample('wechat');
// => [
//      'app_id'  => '<your_app_id>',
//      'mch_id'  => '<your_mch_id>',
//      'api_key' => '<your_api_key>',
//      'api_v3_key' => '<your_api_v3_key>',
//      'cert_path' => '<your_cert_path>',
//      'key_path' => '<your_key_path>',
//      'platform_cert_path' => '<your_platform_cert_path>',
//      'sandbox' => false,
//    ]

// 替换为真实值后校验
$result = Pay::validate('wechat', $config);
if (!$result['valid']) {
    throw new \RuntimeException(implode('; ', $result['errors']));
}
```

> 注：占位值如 `<your_app_id>` 仅为提示，请替换为真实凭据；`sandbox` 等可选字段已带入默认值（如 `false`）。
