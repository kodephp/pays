# 快手支付接入文档

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
| app_id | string | 是 | 快手应用 ID |
| app_secret | string | 是 | 应用密钥（用于签名） |
| merchant_id | string | 是 | 商户号 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$kuaishou = Pay::kuaishou([
    'app_id'      => 'ks123456',
    'app_secret'  => 'your-app-secret',
    'merchant_id' => 'M123456',
]);

$result = $kuaishou->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 100,
    'subject'      => '快手小店商品',
    'notify_url'   => 'https://your-domain.com/notify/kuaishou',
    'trade_type'   => 'MINI_PROGRAM',
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
| total_amount | int | 是 | 订单金额（分） |
| subject | string | 是 | 商品标题 |
| notify_url | string | 是 | 异步通知地址 |
| trade_type | string | 否 | 交易类型（APP/MINI_PROGRAM） |
| attach | string | 否 | 附加数据 |

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
| out_trade_no | string | 是 | 商户订单号 |
| refund_amount | int | 是 | 退款金额（分） |
| out_refund_no | string | 否 | 退款单号 |
| refund_reason | string | 否 | 退款原因 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('kuaishou');
```

### 2. 签名机制

快手支付采用 MD5 签名算法，规则与微信支付类似。
