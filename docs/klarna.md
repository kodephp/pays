# Klarna 接入文档

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
| username | string | 是 | API 用户名 |
| password | string | 是 | API 密码 |
| region | string | 否 | 区域：eu、us、oc，默认 eu |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付会话

```php
<?php

use Kode\Pays\Facade\Pay;

$klarna = Pay::klarna([
    'username' => 'PK12345_abc123...',
    'password' => 'your-api-password',
    'region'   => 'eu',
]);

$result = $klarna->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 100.00,
    'currency'     => 'EUR',
    'country'      => 'DE',
    'items' => [
        [
            'name'     => 'T-Shirt',
            'quantity' => 1,
            'price'    => 50.00,
        ],
        [
            'name'     => 'Jeans',
            'quantity' => 1,
            'price'    => 50.00,
        ],
    ],
]);

$sessionId = $result['session_id'];
```

### 授权并创建订单

```php
$authorizationToken = $frontendToken; // 前端获取的授权令牌

$result = $klarna->authorize($authorizationToken, [
    'out_trade_no' => 'ORDER_001',
    'total_amount' => 100.00,
    'currency'     => 'EUR',
    'items' => [
        ['name' => 'T-Shirt', 'quantity' => 1, 'price' => 50.00],
    ],
]);
```

## API 方法列表

### 创建支付会话

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | float | 是 | 订单金额 |
| currency | string | 是 | 货币代码 |
| items | array | 是 | 商品列表 |
| country | string | 否 | 国家代码，默认 DE |
| shipping_address | array | 否 | 收货地址 |
| customer | array | 否 | 顾客信息 |

### 授权并创建订单

```php
$gateway->authorize(string $authorizationToken, array $params): array
```

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 关闭订单（取消）

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
| order_id | string | 是 | Klarna 订单 ID |
| refund_amount | float | 是 | 退款金额 |
| description | string | 否 | 退款说明 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 区域设置

Klarna 支持三个区域：
- `eu` — 欧洲
- `us` — 美国
- `oc` — 大洋洲

```php
$klarna = Pay::klarna([
    'region' => 'us',
]);
```

### 2. 先买后付流程

1. 创建支付会话（`createOrder`）
2. 前端加载 Klarna Widget
3. 用户选择支付方式（Pay Later / Pay in 3）
4. 前端获取 `authorization_token`
5. 后端调用 `authorize()` 完成订单

### 3. 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('klarna');
```
