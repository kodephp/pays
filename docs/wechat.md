# 微信支付接入文档

## 环境要求

- PHP >= 8.3
- ext-openssl
- ext-json
- Composer

## 安装

```bash
composer require kode/pays
```

## 配置说明

### V2 版本配置（WechatConfig）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_id | string | 是 | 微信应用 ID |
| mch_id | string | 是 | 微信支付商户号 |
| api_key | string | 是 | API 密钥（32位） |
| app_secret | string | 否 | 应用密钥（JSAPI/小程序需要） |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |
| sp_appid | string | 否 | 服务商模式：服务商公众号 appid（覆盖顶层 appid） |
| sp_mchid | string | 否 | 服务商模式：服务商商户号（覆盖顶层 mchid） |
| sub_mch_id | string | 否 | 服务商模式：子商户号 |
| sub_appid | string | 否 | 服务商模式：子商户 appid（同时作为 JSAPI 二次签名的 appId） |

### V3 版本配置（WechatV3Config）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| mch_id | string | 是 | 微信支付商户号 |
| serial_no | string | 是 | API 证书序列号 |
| private_key | string | 是 | API 证书私钥（PEM 格式） |
| api_key | string | 是 | APIv3 密钥 |
| app_id | string | 否 | 应用 ID（JSAPI/小程序需要；服务商模式填子商户 sub_appid） |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |
| sp_appid | string | 否 | 服务商模式：服务商 appid |
| sp_mchid | string | 否 | 服务商模式：服务商商户号 |
| sub_appid | string | 否 | 服务商模式：子商户 appid（同时作为 JSAPI 二次签名的 appId） |
| sub_mchid | string | 否 | 服务商模式：子商户商户号 |

## 快速开始

### V2 版本

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat([
    'app_id'  => 'wx1234567890abcdef',
    'mch_id'  => '1234567890',
    'api_key' => 'your-api-key-here',
]);

// Native 支付（扫码支付）
$result = $wechat->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_fee'    => 100,
    'body'         => '测试商品',
    'trade_type'   => 'NATIVE',
    'notify_url'   => 'https://your-domain.com/notify/wechat',
]);

$codeUrl = $result['code_url']; // 二维码链接
```

### V3 版本

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat_v3([
    'mch_id'      => '1234567890',
    'serial_no'   => 'YOUR_CERT_SERIAL',
    'private_key' => file_get_contents('/path/to/apiclient_key.pem'),
    'api_key'     => 'your-apiv3-key',
    'app_id'      => 'wx1234567890abcdef',
]);

$result = $wechat->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'description'  => '测试商品',
    'amount'       => 100,
    'notify_url'   => 'https://your-domain.com/notify/wechat',
    'trade_type'   => 'native',
]);
```

## API 方法列表

### 创建订单

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_fee/amount | int | 是 | 订单金额（分） |
| body/description | string | 是 | 商品描述 |
| trade_type | string | 是 | 交易类型（NATIVE/JSAPI/APP/H5/MWEB） |
| notify_url | string | 是 | 异步通知地址 |
| openid | string | 条件 | JSAPI/小程序支付必填 |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 关闭订单

```php
$gateway->closeOrder(string $orderId): array
```

### 申请退款

```php
$gateway->refund(array $params): array
```

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 委托代扣（订阅能力，V2 专有）

微信自动续费（papay 委托代扣）仅 V2 提供，V3 无对应端点。`WechatPayGateway`
经 `SubscriptionCapableInterface` 统一暴露，请求均走 `signedV2Post`（MD5 + XML），
金额单位为「分」。

| 方法 | 微信接口 | 说明 |
|------|---------|------|
| `createPlan(array)` | 无 | 模板（`plan_id`）只能在商户平台后台配置，抛「无此方法」 |
| `createSubscription(array)` | `papay/entrustweb` | 返回签约跳转链接（`method` / `url`），签名参与字节与查询串一致 |
| `cancelSubscription(string)` | `papay/deletecontract` | 申请解约 |
| `pauseSubscription(string)` | 无 | 抛「无此方法」，停扣只需不再发起扣款 |
| `resumeSubscription(string)` | 无 | 抛「无此方法」 |
| `getSubscription(string)` | `papay/querycontract` | 查询签约关系 |
| `payWithContract(array)` | `pay/pappayapply` | 按协议号申请扣款（`trade_type=PAP`） |
| `queryContractOrder(string)` | `pay/paporderquery` | 查询代扣订单最终状态 |

协议标识：`cancelSubscription()` / `getSubscription()` 默认按 `contract_id`；
传 `plan:{plan_id}:{contract_code}` 时按「模板 ID + 商户协议号」定位（对应微信文档二选一入参）。

```php
use Kode\Pays\Facade\Pay;

// 1. 生成签约跳转链接
$sign = Pay::call('wechat', 'createSubscription', [[
    'customer_id' => 'CONTRACT_20260810',  // contract_code
    'plan_id' => '123456',                 // 商户平台配置的模板 ID
    'notify_url' => 'https://example.com/notify/wechat-sign',
]]);

// 2. 查询签约关系（按模板 + 商户协议号）
Pay::call('wechat', 'getSubscription', ['plan:123456:CONTRACT_20260810']);

// 3. 按周期发起代扣
Pay::call('wechat', 'payWithContract', [[
    'out_trade_no' => 'PAP_202608',
    'total_fee' => 2990,          // 分
    'body' => '会员月卡续费',
    'contract_id' => 'Wx1522***',
    'notify_url' => 'https://example.com/notify/wechat-pay',
]]);
```

## V3 网关扩展能力

`wechat_v3` 网关（`WechatPayV3Gateway`）除基础下单/查询/关单/退款外，还实现了以下能力接口，
可经 `Pay::call()` 与对应插件统一调用。所有端点均为 APIv3 真实地址，金额单位为「分」。

### 退款能力（RefundCapableInterface）

| 方法 | 端点 | 说明 |
|------|------|------|
| `applyRefund(array $params)` | `POST /v3/refund/domestic/refunds` | 接收归一化扁平参数（`refund_fee` / `total_fee`），`out_trade_no` 与 `transaction_id` 至少其一 |
| `queryRefund(string $outRefundNo)` | `GET /v3/refund/domestic/refunds/{out_refund_no}` | 按商户退款单号查询 |
| `cancelRefund(string $outRefundNo)` | — | APIv3 无该接口，抛「无此方法」 |

> `refund()` 为 `GatewayInterface` 基础方法，接收 APIv3 原生结构（`amount.refund` / `amount.total`）；
> `applyRefund()` 为能力接口方法，接收归一化后的扁平参数，二者可按需选用。

### 分账能力（ProfitSharingCapableInterface）

| 方法 | 端点 |
|------|------|
| `createProfitSharing(array $params)` | `POST /v3/profitsharing/orders` |
| `queryProfitSharing(string $outOrderNo, ?string $transactionId = null)` | `GET /v3/profitsharing/orders/{out_order_no}` |
| `returnProfitSharing(array $params)` | `POST /v3/profitsharing/return-orders` |
| `queryProfitSharingReturn(string $outReturnNo, ?string $outOrderNo = null)` | `GET /v3/profitsharing/return-orders/{out_return_no}` |
| `unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null)` | `POST /v3/profitsharing/orders/unfreeze` |

接收方姓名（`name`）属敏感字段，网关会自动以平台证书 RSA-OAEP 加密后传输，
故传入姓名时需配置 `platform_certificate` 与 `platform_serial_no`。

```php
$wechat->createProfitSharing([
    'transaction_id'   => '4200001234567890',
    'out_order_no'     => 'SHARE_' . date('YmdHis'),
    'unfreeze_unsplit' => true,
    'receivers'        => [
        ['type' => 'MERCHANT_ID', 'account' => '1900000110', 'amount' => 100, 'description' => '服务费'],
    ],
]);
```

### 自动结算能力（SettlementCapableInterface）

| 方法 | 实现 |
|------|------|
| `settleToWallet(array $params)` | 复用商家转账 `POST /v3/transfer/batches`，需 `out_biz_no` / `amount` / `account`(openid) |
| `settleToBankCard(array $params)` | APIv3 无付款到银行卡通道，抛「无此方法」（请改用 V2 网关 `wechat`） |
| `settleToPayout(array $params)` | 微信无外部账户 Payout 语义，抛「无此方法」 |
| `querySettlement(string $outBizNo)` | 复用 `GET /v3/transfer/batches/out-batch-no/{out_batch_no}` |

### 个人收款能力（PersonalReceiveCapableInterface）

| 方法 | 实现 |
|------|------|
| `createQrCode(array $params)` | Native 下单 `POST /v3/pay/transactions/native`，返回 `code_url`；`notify_url` 必填 |
| `queryRecords(array $params)` | 复用交易对账单 `GET /v3/bill/tradebill` |
| `withdraw(array $params)` | APIv3 无付款到银行卡通道，统一走商家转账到零钱，需 `account`(openid) |
| `queryWithdraw(string $outBizNo)` | 复用转账批次查询 |

> 现金红包为微信 V2 专有接口，APIv3 无对应端点，`wechat_v3` 不提供红包能力，请使用 V2 网关 `wechat`。

## 完整使用示例

### 查询订单

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

// 通过商户订单号查询
$result = $wechat->queryOrder('ORDER_202404250001');

// 判断订单状态
$state = $result['trade_state'] ?? '';
if ($state === 'SUCCESS') {
    echo '订单已支付，交易号：' . ($result['transaction_id'] ?? '') . PHP_EOL;
} elseif ($state === 'NOTPAY') {
    echo '订单未支付' . PHP_EOL;
} elseif ($state === 'CLOSED') {
    echo '订单已关闭' . PHP_EOL;
}
```

### 关闭订单

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

// 用户超时未支付时关闭订单
$result = $wechat->closeOrder('ORDER_202404250001');
```

### 申请退款

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

$result = $wechat->refund([
    'out_trade_no'  => 'ORDER_202404250001',
    'out_refund_no' => 'REFUND_' . date('YmdHis'),
    'total_fee'     => 100,
    'refund_fee'    => 50,
    'refund_desc'   => '商品质量问题',
]);

echo '退款单号：' . ($result['refund_id'] ?? '') . PHP_EOL;
echo '退款状态：' . ($result['refund_status'] ?? '') . PHP_EOL;
```

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$data = $_POST;

$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

if ($wechat->verifyNotify($data)) {
    // 处理业务逻辑
    $orderId = $data['out_trade_no'];
    
    // 返回成功响应
    echo '<xml><return_code><![CDATA[SUCCESS]]></return_code></xml>';
} else {
    echo '<xml><return_code><![CDATA[FAIL]]></return_code></xml>';
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('wechat');
```

### 2. 事件监听

```php
use Kode\Pays\Facade\Pay;
use Kode\Pays\Event\Events;

Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    // 支付成功处理
});
```

### 3. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($gateway->createOrder($params));

if ($response->isSuccess()) {
    $payUrl = $response->getPayUrl();
    $orderNo = $response->getOutTradeNo();
}
```

## 支付授权目录与开放平台关联

### 支付授权目录（商户平台配置，非 SDK 暴露）

「支付授权目录」是 **微信商户平台**（`pay.weixin.qq.com → 产品中心 → 开发配置`）里的后台配置项，
指**调起 JSAPI 支付的 H5 网页所在的 URL 目录**。它**不是 SDK 代码需要暴露或生成的目录**，
而是开发者把前端支付页部署到的已备案目录（精确到最后一级，如 `https://domain.com/pay/`）。

SDK 只负责：`app_id` 发起统一下单 → 拿 `prepay_id` → 把支付参数交给前端页；
该前端页**必须位于已配置的授权目录下**并调用 `WeixinJSBridge.invoke('getBrandWCPayRequest', …)`。

| 场景 | 是否需要授权目录 | 关键入参 |
| --- | --- | --- |
| 公众号 JSAPI | 需要 | `openid`（必填，缺省会直接抛 `paramError`） |
| H5 支付（MWEB） | 需配置 H5 授权域名 | `scene_info.h5_info.wap_url/wap_name`，浏览器跳转 `mweb_url` 的 `Referer` 须命中授权域名 |
| 小程序支付 | 不需要 | 用小程序自身 `app_id` |
| Native 扫码 | 不需要 | `product_id` |

### 微信开放平台关联（公众号 / 小程序共享 unionid）

开放平台绑定（`open.weixin.qq.com` 将多个公众号 / 小程序绑到同一开放平台账号 → 共享 `unionid`）
是**控制台配置 + 账号主体一致**事项，**非 SDK 功能**。SDK 要做的是保证账号标识正确传参：

- 普通模式：每个公众号 / 小程序用各自的 `app_id` 初始化一个 Gateway 实例即可；`unionid` 打通由开放平台保证。
- **服务商模式（一个主体关联多公众号 / 小程序 / 子商户）**：在 Gateway 配置中设置 `sp_*` / `sub_*` 字段，
  SDK 会在**下单 / 查询 / 关单 / 退款 / 转账 / 分账等全链路**自动透传，将交易落到对应关联账号。
  配置方式（仅需设置实际存在的字段，普通商户不配置则完全不影响）：

```php
use Kode\Pays\Facade\Pay;

// V2 服务商模式：sp_* 配置覆盖顶层 appid/mchid，sub_* 注入子商户字段
$gateway = Pay::wechat([
    'sp_appid'   => 'wxServiceProvider',   // 服务商公众号 appid（覆盖顶层 appid）
    'sp_mchid'   => '1900000109',          // 服务商商户号（覆盖顶层 mchid）
    'sub_mchid'  => '1900000000',          // 子商户号
    'sub_appid'  => 'wxSubAppid',          // 关联的公众号 / 小程序 appid
    // ... 其余密钥配置（api_key / app_secret / cert_path / key_path）
]);

// V3 服务商模式：sp_* / sub_* 直接作为下单请求体字段
$gatewayV3 = Pay::wechat_v3([
    'sp_appid'   => 'wxServiceProvider',
    'sp_mchid'   => '1900000109',
    'sub_mchid'  => '1900000000',
    'sub_appid'  => 'wxSubAppid',
    // ... 其余密钥配置（private_key / serial_no / api_key）
]);

// 此后所有接口（下单 / 查询 / 关单 / 退款 / 转账 / 分账）均自动带上服务商 / 子商户标识
$order = $gateway->createOrder([
    'out_trade_no' => 'ORDER_' . time(),
    'description'  => '商品',
    'amount'       => 1,
    'notify_url'   => 'https://domain.com/notify',
    'trade_type'   => 'jsapi',
    'openid'       => $userOpenid,          // 子商户 / 子公众号下的 openid
]);
```

> 说明：V2 与 V3 配置契约已统一——两者均支持 `sp_appid` / `sp_mchid` / `sub_appid` / `sub_mch_id`（V2 的末位为下划线 `sub_mch_id`，V3 为 `sub_mchid`）。
> V2 中 `sp_appid` / `sp_mchid` 会覆盖统一下单等入口写入的顶层 `appid` / `mchid`；V3 则将其作为请求体字段透传。
> 透传仅在配置实际存在时生效，且 `sub_*` 不会覆盖调用方显式传入的同名字段，普通商户请求行为完全不变。

### openid 获取（OAuth 网页授权）

JSAPI / 小程序支付必须传入**对应该 `app_id` 的 `openid`**。公众号场景需通过 `snsapi_base` 网页授权获取，
SDK 提供 `WechatOauth` 助手封装「引导授权 → 用 code 换 openid」流程：

```php
use Kode\Pays\Gateway\Wechat\WechatOauth;

$oauth = new WechatOauth(['app_id' => 'wx123', 'app_secret' => 'APP_SECRET']);

// 1) 引导用户访问以下地址（微信回跳 $redirectUri 并携带 code）
$authUrl = $oauth->buildAuthorizeUrl('https://domain.com/callback', 'snsapi_base', 'STATE');

// 2) 回调中用 code 换取 openid（已绑定开放平台且 scope=snsapi_userinfo 时还会返回 unionid）
$result = $oauth->getOpenId($_GET['code']);
$openid = $result['openid'];

// 3) 将 openid 传入 createOrder
```

> 小程序场景无需 OAuth：由小程序前端 `wx.login` 拿 `code`，后端调 `auth.code2Session` 换 `openid`（微信小程序官方接口）。

### openid 获取（小程序 code2Session）

小程序登录链路与公众号 OAuth 对称：小程序端 `wx.login()` 取得临时 `code` 后，后端用 `WechatMiniProgram` 助手调 `auth.code2Session` 换取 `openid` / `session_key`（已绑定开放平台时还会返回 `unionid`）。`session_key` 用于服务端解密小程序敏感数据，应妥善保管、不可下发前端。

```php
use Kode\Pays\Gateway\Wechat\WechatMiniProgram;

$mp = new WechatMiniProgram(['app_id' => 'wx123', 'app_secret' => 'APP_SECRET']);

// 1) 小程序端 wx.login() 取得 code 传给后端
// 2) 后端用 code 换 openid / session_key
$result = $mp->code2Session($codeFromClient);
$openid     = $result['openid'];      // 用于 createOrder 的 openid
$sessionKey = $result['session_key']; // 用于解密小程序用户数据

// 3) 将 openid 传入 createOrder（trade_type=miniprogram 或 jsapi）
```

### JSAPI 二次签名（前端调起支付）

统一下单拿到 `prepay_id` 后，前端需二次签名才能调起支付。SDK 提供 `buildJsApiConfig()` 直接返回前端所需字段：

```php
// V2（公众号 JSAPI，MD5 二次签名）
$config = $gateway->buildJsApiConfig($prepayId);
// => ['appId','timeStamp','nonceStr','package'=>'prepay_id=xxx','signType'=>'MD5','paySign']

// V3（小程序 / 公众号 JSAPI，RSA 二次签名，用商户私钥签发）
$config = $gateway->buildJsApiConfig($prepayId);
// => ['appId','timeStamp','nonceStr','package'=>'prepay_id=xxx','signType'=>'RSA','paySign']
```

> **服务商模式注意**：`buildJsApiConfig()` 返回的 `appId` 自动采用子商户 `sub_appid`（即交易归属的公众号 / 小程序），而非服务商顶层 `app_id`；未配置 `sub_appid` 时回落到 `app_id`。这保证了前端调起支付所用的 `appId` 与 `prepay_id` 所属账号一致，避免「appId 与 prepay_id 不匹配」报错。

前端据此调用 `WeixinJSBridge.invoke('getBrandWCPayRequest', config)`（V2）或 `wx.requestPayment(config)`（V3 小程序）。
