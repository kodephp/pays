# Square 支付接入文档

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
| application_id | string | 是 | Square 应用 ID |
| access_token | string | 是 | Square 访问令牌 |
| environment | string | 否 | 环境：sandbox 或 production，默认 production |
| api_version | string | 否 | API 版本，默认 2024-05-15 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付

```php
<?php

use Kode\Pays\Facade\Pay;

$square = Pay::square([
    'application_id' => 'sandbox-sq0idb-xxx',
    'access_token'   => 'EAAAxxxxxxxxxxxxxxxx',
    'environment'    => 'sandbox',
]);

$result = $square->createOrder([
    'source_id' => 'cnon:card-nonce-ok', // 前端生成的支付 nonce
    'amount'    => 100,                  // 金额（分）
    'currency'  => 'USD',
    'note'      => '测试商品',
    'reference_id' => 'ORDER_001',
]);

$paymentId = $result['payment']['id'];
```

### 创建订单（Orders API）

```php
<?php

use Kode\Pays\Facade\Pay;

$square = Pay::square([
    'application_id' => 'sandbox-sq0idb-xxx',
    'access_token'   => 'EAAAxxxxxxxxxxxxxxxx',
    'environment'    => 'sandbox',
]);

$result = $square->createSquareOrder([
    'location_id' => 'LXXX',
    'order' => [
        'line_items' => [
            [
                'name'     => '测试商品',
                'quantity' => '1',
                'base_price_money' => [
                    'amount'   => 100,
                    'currency' => 'USD',
                ],
            ],
        ],
    ],
]);
```

## API 方法列表

### 创建支付

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| source_id | string | 是 | 支付来源 ID（前端生成的 nonce） |
| amount | int | 是 | 订单金额（分） |
| currency | string | 是 | 货币代码（如 USD） |
| note | string | 否 | 订单备注 |
| reference_id | string | 否 | 商户参考号 |
| idempotency_key | string | 否 | 幂等键（不传则自动生成） |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 关闭订单（取消支付）

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
| payment_id | string | 是 | 支付 ID |
| amount | int | 是 | 退款金额（分） |
| currency | string | 是 | 货币代码 |
| reason | string | 否 | 退款原因 |
| idempotency_key | string | 否 | 幂等键 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 异步通知处理

Square Webhook 通知需根据 Square 官方签名验证规范处理：

```php
<?php

use Kode\Pays\Facade\Pay;

$body = @file_get_contents('php://input');
$signature = $_SERVER['X-Square-Signature'] ?? '';

$square = Pay::square([
    'application_id' => 'xxx',
    'access_token'   => 'xxx',
]);

if ($square->verifyNotify(['signature' => $signature, 'body' => $body])) {
    $event = json_decode($body, true);

    switch ($event['type'] ?? '') {
        case 'payment.created':
            // 支付创建
            break;
        case 'payment.updated':
            // 支付更新
            break;
    }

    http_response_code(200);
} else {
    http_response_code(400);
}
```

## 常见问题

### 1. 沙箱环境使用

```php
$square = Pay::square([
    'application_id' => 'sandbox-sq0idb-xxx',
    'access_token'   => 'EAAAE...',
    'environment'    => 'sandbox',
]);
```

### 2. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($square->createOrder($params));

if ($response->isSuccess()) {
    $paymentId = $response->get('payment.id');
    $status    = $response->get('payment.status');
}
```
