# 美团支付接入文档

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
| app_id | string | 是 | 美团应用 ID |
| app_secret | string | 是 | 应用密钥（用于签名） |
| merchant_id | string | 是 | 商户号 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$meituan = Pay::meituan([
    'app_id'      => 'mt1234567890',
    'app_secret'  => 'your-app-secret-here',
    'merchant_id' => 'M1234567890',
]);

$result = $meituan->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_fee'    => 100,                    // 金额（分）
    'body'         => '美团外卖订单',
    'notify_url'   => 'https://your-domain.com/notify/meituan',
    'trade_type'   => 'APP',                  // APP、MINI_PROGRAM
    'attach'       => '自定义附加数据',
]);

// 获取支付参数
$payUrl = $result['pay_url'] ?? '';
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
| total_fee | int | 是 | 订单金额（分） |
| body | string | 是 | 商品描述 |
| notify_url | string | 是 | 异步通知地址 |
| trade_type | string | 否 | 交易类型（APP/MINI_PROGRAM） |
| attach | string | 否 | 附加数据 |
| expire_time | string | 否 | 订单过期时间 |

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
| refund_fee | int | 是 | 退款金额（分） |
| out_refund_no | string | 否 | 退款单号（不传则自动生成） |
| refund_desc | string | 否 | 退款原因 |

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

$meituan = Pay::meituan([
    'app_id'      => 'mt123456',
    'app_secret'  => 'your-app-secret',
    'merchant_id' => 'M123456',
]);

if ($meituan->verifyNotify($data)) {
    $orderId = $data['out_trade_no'];
    $status  = $data['trade_status'];

    if ($status === 'SUCCESS') {
        // 支付成功，处理业务逻辑
    }

    echo 'SUCCESS';
} else {
    echo 'FAIL';
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('meituan');

$meituan = Pay::meituan([
    'app_id'      => 'mt_test_xxx',
    'app_secret'  => 'test_secret',
    'merchant_id' => 'M_TEST',
]);
```

### 2. 签名机制

美团支付采用 MD5 签名算法，签名规则如下：
1. 将所有非空参数按 key 升序排序
2. 拼接成 `key1=value1&key2=value2` 格式
3. 末尾追加 `&key=app_secret`
4. 对拼接字符串进行 MD5 加密并转大写

SDK 内部已自动处理签名，开发者无需关心。

### 3. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($meituan->createOrder($params));

if ($response->isSuccess()) {
    $payUrl  = $response->getPayUrl();
    $orderNo = $response->getOutTradeNo();
}
```
