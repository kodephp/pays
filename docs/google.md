# Google Pay 接入文档

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
| merchant_id | string | 是 | Google 商户 ID |
| merchant_name | string | 是 | 商户显示名称 |
| gateway_merchant_id | string | 是 | 网关商户 ID |
| environment | string | 否 | TEST 或 PRODUCTION，默认 TEST |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

Google Pay 支付流程：
1. 前端通过 Google Pay API 获取 `paymentMethodToken`
2. 将 token 传给后端
3. 后端调用 `createOrder` 完成支付

```php
<?php

use Kode\Pays\Facade\Pay;

$google = Pay::google([
    'merchant_id'         => 'BCR2DN4T7ZTLKJ3H',
    'merchant_name'       => 'Your Store Name',
    'gateway_merchant_id' => 'your_gateway_merchant_id',
    'environment'         => 'TEST',
]);

$result = $google->createOrder([
    'out_trade_no'  => 'ORDER_' . date('YmdHis'),
    'total_amount'  => '10.00',
    'currency'      => 'USD',
    'payment_token' => $frontendPaymentToken,
    'description'   => 'Google Pay 商品',
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
| currency | string | 是 | 货币代码（USD/CNY） |
| payment_token | array | 是 | Google Pay Payment Method Token |
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
const paymentDataRequest = {
    apiVersion: 2,
    apiVersionMinor: 0,
    merchantInfo: {
        merchantId: 'BCR2DN4T7ZTLKJ3H',
        merchantName: 'Your Store Name',
    },
    allowedPaymentMethods: [{
        type: 'CARD',
        parameters: {
            allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
            allowedCardNetworks: ['VISA', 'MASTERCARD'],
        },
        tokenizationSpecification: {
            type: 'PAYMENT_GATEWAY',
            parameters: {
                gateway: 'example',
                gatewayMerchantId: 'your_gateway_merchant_id',
            },
        },
    }],
    transactionInfo: {
        totalPriceStatus: 'FINAL',
        totalPrice: '10.00',
        currencyCode: 'USD',
    },
};

const paymentData = await googlePayClient.loadPaymentData(paymentDataRequest);

// 将 paymentData.paymentMethodData 传给后端
fetch('/api/pay/google', {
    method: 'POST',
    body: JSON.stringify({
        out_trade_no: 'ORDER_001',
        total_amount: '10.00',
        currency: 'USD',
        payment_token: paymentData.paymentMethodData,
    }),
});
```

### 2. 测试环境

Google Pay 测试环境无需真实支付，使用 TEST 环境即可：

```php
$google = Pay::google([
    'merchant_id'         => 'BCR2DN4T7ZTLKJ3H',
    'merchant_name'       => 'Test Store',
    'gateway_merchant_id' => 'test_merchant',
    'environment'         => 'TEST',
]);
```
