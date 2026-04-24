# Adyen 支付接入文档

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
| api_key | string | 是 | Adyen API 密钥 |
| merchant_account | string | 是 | 商户账户名 |
| environment | string | 否 | 环境：test 或 live，默认 test |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付会话（Sessions API，推荐）

```php
<?php

use Kode\Pays\Facade\Pay;

$adyen = Pay::adyen([
    'api_key'          => 'AQE1hmfxJ...',
    'merchant_account' => 'YourMerchantAccount',
    'environment'      => 'test',
]);

$result = $adyen->createOrder([
    'amount' => [
        'value'    => 1000,
        'currency' => 'USD',
    ],
    'reference'   => 'ORDER_' . date('YmdHis'),
    'return_url'  => 'https://your-domain.com/return',
    'country_code' => 'US',
    'shopper_email' => 'customer@example.com',
]);

// 获取会话 ID，前端使用 Adyen Checkout SDK 完成支付
$sessionId = $result['id'];
$sessionData = $result['sessionData'];
```

### 直接支付（Payments API）

```php
<?php

use Kode\Pays\Facade\Pay;

$adyen = Pay::adyen([
    'api_key'          => 'AQE1hmfxJ...',
    'merchant_account' => 'YourMerchantAccount',
]);

$result = $adyen->createPayment([
    'amount' => [
        'value'    => 1000,
        'currency' => 'USD',
    ],
    'reference'       => 'ORDER_001',
    'payment_method'  => [
        'type'   => 'scheme',
        'number' => '4111111111111111',
        'expiryMonth' => '03',
        'expiryYear'  => '2030',
        'cvc'    => '737',
        'holderName' => 'Test User',
    ],
    'return_url' => 'https://your-domain.com/return',
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
| amount | array | 是 | 金额对象（含 value 和 currency） |
| currency | string | 是 | 货币代码 |
| reference | string | 是 | 商户订单号 |
| return_url | string | 是 | 支付完成后返回地址 |
| country_code | string | 否 | 国家代码（如 US、CN） |
| shopper_email | string | 否 | 顾客邮箱 |
| shopper_reference | string | 否 | 顾客参考号 |
| line_items | array | 否 | 商品明细 |

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
| original_reference | string | 是 | 原始支付 PSP 参考号 |
| amount | int | 是 | 退款金额 |
| currency | string | 是 | 货币代码 |
| reference | string | 否 | 退款参考号 |

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

$payload = @file_get_contents('php://input');
$hmacSignature = $_SERVER['HTTP_X_ADYEN_HMACSIGNATURE'] ?? '';

$adyen = Pay::adyen([
    'api_key'          => 'AQE1hmfxJ...',
    'merchant_account' => 'YourMerchantAccount',
]);

if ($adyen->verifyNotify(['hmacSignature' => $hmacSignature, 'payload' => $payload])) {
    $notification = json_decode($payload, true);

    switch ($notification['eventCode'] ?? '') {
        case 'AUTHORISATION':
            if ($notification['success'] === 'true') {
                // 支付授权成功
            }
            break;
        case 'REFUND':
            // 退款完成
            break;
        case 'CANCEL_OR_REFUND':
            // 取消或退款
            break;
    }

    http_response_code(200);
    echo '[accepted]';
} else {
    http_response_code(400);
}
```

## 常见问题

### 1. 沙箱环境使用

```php
$adyen = Pay::adyen([
    'api_key'          => 'AQE1hmfxJ...',
    'merchant_account' => 'YourMerchantAccount',
    'environment'      => 'test',
]);
```

### 2. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($adyen->createOrder($params));

if ($response->isSuccess()) {
    $sessionId   = $response->get('id');
    $sessionData = $response->get('sessionData');
}
```

### 3. 支持的支付方式

Adyen 支持全球 250+ 种支付方式，包括但不限于：

- 国际信用卡（Visa、MasterCard、American Express）
- 本地支付方式（iDEAL、Sofort、Giropay、Bancontact）
- 电子钱包（PayPal、Apple Pay、Google Pay）
- 银行转账、先买后付（Klarna、Afterpay）

具体支持的支付方式取决于商户账户配置和目标市场。
