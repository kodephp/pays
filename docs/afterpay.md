# Afterpay/Clearpay 接入文档

Afterpay（澳洲品牌，在美国/英国称为 Clearpay）是全球领先的先买后付（BNPL）支付平台，覆盖澳洲、美国、英国、欧洲市场。

## 环境要求

- PHP 8.2+
- 有效的 Afterpay 商户账户
- Merchant ID 和 Secret Key

## 安装

```bash
composer require kode/pays
```

## 配置说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| merchant_id | string | 是 | Afterpay Merchant ID |
| secret_key | string | 是 | API Secret Key |
| region | string | 否 | 区域：AU/US/UK/EU，默认 US |
| sandbox | bool | 否 | 是否沙箱模式，默认 false |

## 区域域名对照

| 区域 | 生产环境 | 沙箱环境 |
|------|----------|----------|
| AU | api.afterpay.com | api-sandbox.afterpay.com |
| US | api.us.afterpay.com | api.us.sandbox.afterpay.com |
| UK | api.clearpay.co.uk | api-sandbox.clearpay.co.uk |
| EU | api.eu.afterpay.com | api-sandbox.eu.afterpay.com |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::afterpay([
    'merchant_id' => 'your_merchant_id',
    'secret_key' => 'your_secret_key',
    'region' => 'US',
]);

// 创建先买后付订单
$result = $gateway->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 10000,
    'currency' => 'USD',
    'consumer' => [
        'phone_number' => '+1234567890',
        'given_names' => 'John',
        'surname' => 'Doe',
        'email' => 'john@example.com',
    ],
    'billing' => [
        'name' => 'John Doe',
        'line1' => '123 Main St',
        'city' => 'New York',
        'postcode' => '10001',
        'countryCode' => 'US',
    ],
    'items' => [
        [
            'name' => 'T-Shirt',
            'quantity' => 1,
            'price' => ['amount' => '50.00', 'currency' => 'USD'],
        ],
    ],
    'redirect_url' => 'https://example.com/success',
    'cancel_url' => 'https://example.com/cancel',
]);

// 跳转到 Afterpay 支付页面
header('Location: ' . $result['checkout_url']);
```

## API 方法列表

### createOrder(array $params): array

创建先买后付订单。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | int | 是 | 订单金额（单位：分） |
| currency | string | 否 | 币种，默认 USD |
| consumer | array | 是 | 消费者信息 |
| billing | array | 否 | 账单地址 |
| shipping | array | 否 | 收货地址 |
| items | array | 否 | 商品列表 |
| redirect_url | string | 否 | 支付成功跳转地址 |
| cancel_url | string | 否 | 支付取消跳转地址 |
| tax_amount | int | 否 | 税费（分） |
| shipping_amount | int | 否 | 运费（分） |

**consumer 结构：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| phone_number | string | 是 | 手机号 |
| given_names | string | 是 | 名 |
| surname | string | 是 | 姓 |
| email | string | 是 | 邮箱 |

**返回值：**

| 字段 | 类型 | 说明 |
|------|------|------|
| out_trade_no | string | 商户订单号 |
| token | string | Checkout Token |
| checkout_url | string | 支付页面 URL |
| expires_at | string | 过期时间 |

### capture(string $token): array

捕获/确认订单（用户完成 Afterpay 流程后调用）。

### queryOrder(string $orderId): array

查询订单状态。

### refund(array $params): array

发起退款。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| order_id | string | 是 | 订单 Token |
| refund_fee | int | 是 | 退款金额（分） |
| out_refund_no | string | 否 | 商户退款单号 |
| currency | string | 否 | 币种 |

### closeOrder(string $orderId): array

取消/作废订单。

### verifyNotify(array $data): bool

验证异步通知（Basic Auth）。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::afterpay([
    'merchant_id' => 'your_merchant_id',
    'secret_key' => 'your_secret_key',
]);

// 验证 Basic Auth
if (!$gateway->verifyNotify($_POST)) {
    http_response_code(401);
    echo '认证失败';
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$status = $payload['status'] ?? '';

switch ($status) {
    case 'APPROVED':
        // 支付已批准
        $token = $payload['token'] ?? '';
        // 调用 capture 确认
        $gateway->capture($token);
        break;
    case 'DECLINED':
        // 支付被拒绝
        break;
}

http_response_code(200);
echo 'OK';
```

## 常见问题

**Q: Afterpay 和 Clearpay 有什么区别？**
A: 是同一公司的不同品牌，Afterpay 用于澳洲/美国/欧洲，Clearpay 用于英国。

**Q: 消费者如何付款？**
A: 消费者分 4 期免息付款，首期在下单时支付，之后每 2 周支付一期。

**Q: 商家多久收到款项？**
A: 商家在消费者下单时即可收到全额款项（扣除手续费），由 Afterpay 承担分期风险。

**Q: 最低/最高订单金额？**
A: 美国市场通常为 $35 - $2000，具体以 Afterpay 政策为准。
