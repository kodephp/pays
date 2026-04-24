# 微信支付接入文档

## 环境要求

- PHP >= 8.2
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
