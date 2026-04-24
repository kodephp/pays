# Apple Pay 接入文档

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
| merchant_identifier | string | 是 | 商户标识符 |
| merchant_certificate | string | 是 | 商户证书（PEM 格式） |
| merchant_certificate_key | string | 是 | 商户证书私钥 |
| apple_pay_merchant_id | string | 是 | 苹果分配的商户 ID |
| domain_name | string | 是 | 域名 |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

Apple Pay 支付流程：
1. 前端通过 Apple Pay JS 获取 `paymentToken`
2. 将 `paymentToken` 传给后端
3. 后端调用 `createOrder` 完成支付

```php
<?php

use Kode\Pays\Facade\Pay;

$apple = Pay::apple([
    'merchant_identifier'      => 'merchant.com.yourdomain',
    'merchant_certificate'     => file_get_contents('/path/to/cert.pem'),
    'merchant_certificate_key' => file_get_contents('/path/to/key.pem'),
    'apple_pay_merchant_id'    => 'your_apple_merchant_id',
    'domain_name'              => 'your-domain.com',
]);

$result = $apple->createOrder([
    'out_trade_no'  => 'ORDER_' . date('YmdHis'),
    'total_amount'  => '10.00',
    'currency'      => 'CNY',
    'payment_token' => $frontendPaymentToken, // 前端传来的 token
    'description'   => 'Apple Pay 商品',
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
| currency | string | 是 | 货币代码（CNY/USD） |
| payment_token | array | 是 | Apple Pay Payment Token |
| description | string | 否 | 订单描述 |

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

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 常见问题

### 1. 前端集成示例

```javascript
const paymentRequest = {
    countryCode: 'CN',
    currencyCode: 'CNY',
    total: {
        label: 'Your Store',
        amount: '10.00',
    },
    merchantCapabilities: ['supports3DS'],
    supportedNetworks: ['visa', 'masterCard', 'chinaUnionPay'],
};

const session = new ApplePaySession(14, paymentRequest);

session.onpaymentauthorized = (event) => {
    const paymentToken = event.payment.token;
    
    // 将 token 传给后端
    fetch('/api/pay/apple', {
        method: 'POST',
        body: JSON.stringify({
            out_trade_no: 'ORDER_001',
            total_amount: '10.00',
            currency: 'CNY',
            payment_token: paymentToken,
        }),
    });
};
```

### 2. 证书配置

Apple Pay 需要以下证书：
- **Merchant Identity Certificate**：用于服务端验证
- **Payment Processing Certificate**：用于解密 payment token

证书需在 Apple Developer 后台申请并下载。
