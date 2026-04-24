# Stripe 支付接入文档

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

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| secret_key | string | 是 | Stripe 密钥（sk_test_xxx 或 sk_live_xxx） |
| publishable_key | string | 否 | 可发布密钥（前端使用） |
| webhook_secret | string | 否 | Webhook 签名密钥（用于验证通知） |
| api_version | string | 否 | API 版本，默认 2024-06-20 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建 PaymentIntent

```php
<?php

use Kode\Pays\Facade\Pay;

$stripe = Pay::stripe([
    'secret_key' => 'sk_test_your_stripe_secret_key_here',
]);

$result = $stripe->createOrder([
    'amount'   => 1000,        // 金额（分）
    'currency' => 'usd',       // 货币代码
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'description'  => '测试商品',
]);

// 获取客户端密钥，前端使用 stripe.js 完成支付
$clientSecret = $result['client_secret'];
```

### 创建 Checkout Session

```php
<?php

use Kode\Pays\Facade\Pay;

$stripe = Pay::stripe([
    'secret_key' => 'sk_test_your_stripe_secret_key_here',
]);

$session = $stripe->createCheckoutSession([
    'line_items' => [[
        'amount'   => 1000,
        'currency' => 'usd',
        'name'     => '测试商品',
        'quantity' => 1,
    ]],
    'mode'        => 'payment',
    'success_url' => 'https://your-domain.com/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => 'https://your-domain.com/cancel',
    'client_reference_id' => 'ORDER_001',
]);

// 跳转到 Stripe 收银台
header('Location: ' . $session['url']);
```

## API 方法列表

### 创建订单（PaymentIntent）

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| amount | int | 是 | 订单金额（分） |
| currency | string | 是 | 货币代码（如 usd、cny） |
| out_trade_no | string | 否 | 商户订单号（存入 metadata） |
| description | string | 否 | 订单描述 |
| customer | string | 否 | Stripe 客户 ID |
| receipt_email | string | 否 | 接收收据的邮箱 |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 关闭订单（取消 PaymentIntent）

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
| payment_intent | string | 是 | PaymentIntent ID |
| amount | int | 否 | 退款金额（分），不传则全额退款 |
| reason | string | 否 | 退款原因 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知（Webhook）

```php
$gateway->verifyNotify(array $data): bool
```

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$payload    = @file_get_contents('php://input');
$sigHeader  = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$stripe = Pay::stripe([
    'secret_key'     => 'sk_test_your_stripe_secret_key_here',
    'webhook_secret' => 'whsec_your_webhook_secret_here',
]);

if ($stripe->verifyNotify(['payload' => $payload, 'sig_header' => $sigHeader])) {
    $event = json_decode($payload, true);

    switch ($event['type'] ?? '') {
        case 'payment_intent.succeeded':
            // 支付成功
            break;
        case 'payment_intent.payment_failed':
            // 支付失败
            break;
    }

    http_response_code(200);
} else {
    http_response_code(400);
}
```

## 常见问题

### 1. 沙箱环境使用

Stripe 使用测试密钥（sk_test_xxx）自动进入沙箱环境，无需额外配置：

```php
$stripe = Pay::stripe([
    'secret_key' => 'sk_test_xxx',
]);
```

### 2. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($stripe->createOrder($params));

if ($response->isSuccess()) {
    $clientSecret = $response->get('client_secret');
    $orderNo      = $response->getOutTradeNo();
}
```
