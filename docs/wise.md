# Wise 接入文档

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
| api_key | string | 是 | Wise API 密钥 |
| profile_id | string | 是 | 个人/企业资料 ID |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建转账订单

```php
<?php

use Kode\Pays\Facade\Pay;

$wise = Pay::wise([
    'api_key'    => 'your-api-key',
    'profile_id' => '12345678',
]);

$result = $wise->createOrder([
    'out_trade_no'    => 'ORDER_' . date('YmdHis'),
    'source_currency' => 'GBP',
    'target_currency' => 'EUR',
    'amount'          => 100.00,
    'recipient'       => [
        'currency' => 'EUR',
        'type'     => 'iban',
        'accountHolderName' => 'John Doe',
        'details'  => [
            'iban' => 'DE89370400440532013000',
        ],
    ],
]);
```

## API 方法列表

### 创建转账

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| source_currency | string | 是 | 源货币 |
| target_currency | string | 是 | 目标货币 |
| amount | float | 是 | 金额 |
| recipient | array | 是 | 收款人信息 |

### 查询转账

```php
$gateway->queryOrder(string $orderId): array
```

### 取消转账

```php
$gateway->closeOrder(string $orderId): array
```

### 申请退款

```php
$gateway->refund(array $params): array
```

> 注意：Wise 不支持直接退款，返回提示信息。

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('wise');
```

### 2. 支持的货币

Wise 支持 50+ 种货币，包括：
- EUR、GBP、USD、AUD、CAD
- CNY、JPY、KRW、SGD、HKD
- 以及更多新兴市场货币

### 3. 转账流程

1. 创建报价（Quote）
2. 创建收款人账户（Recipient）
3. 创建转账（Transfer）
4. 资金到账（通常 1-2 个工作日）
