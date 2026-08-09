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

### V3 版本配置（WechatV3Config）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| mch_id | string | 是 | 微信支付商户号 |
| serial_no | string | 是 | API 证书序列号 |
| private_key | string | 是 | API 证书私钥（PEM 格式） |
| api_key | string | 是 | APIv3 密钥 |
| app_id | string | 否 | 应用 ID（JSAPI/小程序需要） |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |

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
