# 支付宝国际版接入文档

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
| app_id | string | 是 | 应用 ID |
| private_key | string | 是 | RSA 私钥 |
| public_key | string | 是 | 支付宝公钥 |
| gateway_url | string | 否 | 网关地址 |
| sign_type | string | 否 | 签名类型：RSA2、RSA，默认 RSA2 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$alipayGlobal = Pay::alipayGlobal([
    'app_id'       => '2024xxxxxx',
    'private_key'  => file_get_contents('/path/to/private_key.pem'),
    'public_key'   => file_get_contents('/path/to/public_key.pem'),
    'sign_type'    => 'RSA2',
]);

$result = $alipayGlobal->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '10.00',
    'currency'     => 'USD',
    'subject'      => '跨境商品',
    'notify_url'   => 'https://your-domain.com/notify/alipay_global',
    'merchant_name' => 'Your Store',
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
| total_amount | string | 是 | 订单金额 |
| currency | string | 是 | 货币代码 |
| subject | string | 是 | 商品标题 |
| notify_url | string | 否 | 异步通知地址 |
| body | string | 否 | 商品描述 |
| product_code | string | 否 | 产品代码 |
| merchant_name | string | 否 | 商户名称 |

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
| refund_amount | string | 是 | 退款金额 |
| refund_reason | string | 否 | 退款原因 |
| out_refund_no | string | 否 | 退款单号 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 签名机制

支付宝国际版采用 RSA2 签名算法：
1. 参数按 key 升序排序
2. 拼接为 `key1=value1&key2=value2` 格式
3. 使用 RSA 私钥签名（SHA256）
4. Base64 编码签名结果

SDK 内部已自动处理签名。

### 2. 支持的市场

支付宝国际版（Alipay+）支持：
- 东南亚（泰国、马来西亚、新加坡、菲律宾）
- 欧洲（英国、法国、德国、意大利）
- 中东（阿联酋、沙特）
- 大洋洲（澳大利亚、新西兰）

### 3. 多币种结算

支持 30+ 种货币结算，包括：
- USD、EUR、GBP、JPY
- SGD、THB、MYR、PHP
- AUD、NZD、AED 等

### 4. 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('alipay_global');
```
