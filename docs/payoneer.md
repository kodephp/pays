# Payoneer 接入文档

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
| api_key | string | 是 | API 密钥 |
| api_secret | string | 是 | API 密钥密码 |
| program_id | string | 是 | 项目 ID |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$payoneer = Pay::payoneer([
    'api_key'    => 'your-api-key',
    'api_secret' => 'your-api-secret',
    'program_id' => 'your-program-id',
]);

$result = $payoneer->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'amount'       => 100.00,
    'currency'     => 'USD',
    'payee_id'     => 'payee_123456',
    'description'  => 'Payoneer 付款',
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
| amount | float | 是 | 金额 |
| currency | string | 是 | 货币代码 |
| payee_id | string | 是 | 收款人 ID |
| description | string | 否 | 付款描述 |
| payment_date | string | 否 | 计划付款日期 |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 取消订单

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
| amount | float | 是 | 退款金额 |
| reason | string | 否 | 退款原因 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('payoneer');
```

### 2. 覆盖范围

Payoneer 覆盖全球 200+ 国家和地区，支持 150+ 种货币。

### 3. 使用场景

- 跨境电商平台付款
- 自由职业者付款
- 联盟营销佣金发放
- 广告平台收益结算
