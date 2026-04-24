# 支付宝接入文档

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

### 普通公钥模式（AlipayConfig）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_id | string | 是 | 支付宝应用 ID |
| private_key | string | 是 | 应用私钥（RSA 或 RSA2） |
| public_key | string | 是 | 支付宝公钥 |
| app_auth_token | string | 否 | 应用授权令牌（第三方授权时使用） |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => file_get_contents('/path/to/private_key.pem'),
    'public_key'  => file_get_contents('/path/to/public_key.pem'),
]);

// 电脑网站支付
$result = $alipay->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '0.01',
    'subject'      => '测试商品',
    'product_code' => 'FAST_INSTANT_TRADE_PAY',
    'notify_url'   => 'https://your-domain.com/notify/alipay',
    'return_url'   => 'https://your-domain.com/return',
]);

// 跳转到支付宝收银台
header('Location: ' . $result['url']);
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
| total_amount | string | 是 | 订单金额（元） |
| subject | string | 是 | 订单标题 |
| product_code | string | 是 | 产品码（如 FAST_INSTANT_TRADE_PAY） |
| notify_url | string | 是 | 异步通知地址 |
| return_url | string | 否 | 同步跳转地址 |
| body | string | 否 | 订单描述 |

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

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 条件 | 商户订单号（与 trade_no 二选一） |
| trade_no | string | 条件 | 支付宝交易号（与 out_trade_no 二选一） |
| refund_amount | string | 是 | 退款金额（元） |
| out_request_no | string | 是 | 退款请求号 |
| refund_reason | string | 否 | 退款原因 |

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

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => '...',
    'public_key'  => '...',
]);

if ($alipay->verifyNotify($data)) {
    $orderId = $data['out_trade_no'];
    $tradeStatus = $data['trade_status'];
    
    if ($tradeStatus === 'TRADE_SUCCESS') {
        // 支付成功处理
    }
    
    echo 'success'; // 必须返回 success，否则支付宝会重复通知
} else {
    echo 'fail';
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('alipay');
```

### 2. 不同支付场景的产品码

| 场景 | product_code |
|------|-------------|
| 电脑网站支付 | FAST_INSTANT_TRADE_PAY |
| 手机网站支付 | QUICK_WAP_WAY |
| App 支付 | QUICK_MSECURITY_PAY |
| 当面付 | FACE_TO_FACE_PAYMENT |

### 3. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($gateway->createOrder($params));

if ($response->isSuccess()) {
    $payUrl = $response->getPayUrl();
}
```
