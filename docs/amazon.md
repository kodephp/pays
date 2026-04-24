# Amazon Pay 接入文档

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
| merchant_id | string | 是 | Amazon 商户 ID |
| access_key | string | 是 | 访问密钥 ID |
| secret_key | string | 是 | 密钥 |
| client_id | string | 是 | 客户端 ID |
| region | string | 否 | 区域：na、eu、jp，默认 na |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$amazon = Pay::amazon([
    'merchant_id' => 'A2QEXAMPLE123',
    'access_key'  => 'AKIA...',
    'secret_key'  => 'your-secret-key',
    'client_id'   => 'amzn1.application-oa2-client.xxx',
    'region'      => 'na',
]);

// 先设置订单详情
$result = $amazon->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '10.00',
    'currency'     => 'USD',
    'amazon_order_reference_id' => 'S01-1234567-1234567',
    'description'  => 'Amazon Pay 商品',
]);

// 确认订单
$amazon->confirmOrder('S01-1234567-1234567');
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
| total_amount | string | 是 | 订单金额 |
| currency | string | 是 | 货币代码 |
| amazon_order_reference_id | string | 是 | Amazon 订单引用 ID |
| description | string | 否 | 订单描述 |

### 确认订单

```php
$gateway->confirmOrder(string $orderReferenceId): array
```

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
| amazon_capture_id | string | 是 | Amazon 捕获 ID |
| refund_amount | string | 是 | 退款金额 |
| currency | string | 否 | 货币代码 |
| refund_reason | string | 否 | 退款原因 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知（IPN）

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 区域设置

Amazon Pay 支持三个区域：
- `na` — 北美（美国、加拿大）
- `eu` — 欧洲
- `jp` — 日本

```php
$amazon = Pay::amazon([
    'region' => 'eu',
]);
```

### 2. 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('amazon');
```
