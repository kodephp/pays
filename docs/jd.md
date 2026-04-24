# 京东支付接入文档

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
| merchant_no | string | 是 | 京东商户号 |
| des_key | string | 是 | DES 密钥 |
| md5_key | string | 是 | MD5 密钥（用于签名） |
| rsa_private_key | string | 否 | RSA 私钥 |
| rsa_public_key | string | 否 | RSA 公钥 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$jd = Pay::jd([
    'merchant_no' => 'JD123456',
    'des_key'     => 'your-des-key',
    'md5_key'     => 'your-md5-key',
]);

$result = $jd->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '10.00',
    'subject'      => '京东商品',
    'notify_url'   => 'https://your-domain.com/notify/jd',
    'trade_type'   => 'APP',
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
| total_amount | string | 是 | 订单金额（元） |
| subject | string | 是 | 商品标题 |
| notify_url | string | 是 | 异步通知地址 |
| trade_type | string | 否 | 交易类型（APP/WEB） |
| return_url | string | 否 | 同步返回地址 |
| body | string | 否 | 商品描述 |
| expire_time | string | 否 | 过期时间 |

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

SandboxManager::enable('jd');

$jd = Pay::jd([
    'merchant_no' => 'JD_TEST',
    'des_key'     => 'test_des_key',
    'md5_key'     => 'test_md5_key',
]);
```

### 2. 签名机制

京东支付采用 MD5 签名算法，规则与微信支付类似：
1. 参数按 key 升序排序
2. 拼接为 `key1=value1&key2=value2` 格式
3. 末尾追加 `&key=md5_key`
4. MD5 加密后转大写

SDK 内部已自动处理签名。
