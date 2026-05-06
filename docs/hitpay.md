# HitPay 接入文档

HitPay 是新加坡本地支付聚合平台，支持东南亚多国的本地支付方式，覆盖新加坡、马来西亚、泰国、印度尼西亚等国家。

## 环境要求

- PHP 8.2+
- 有效的 HitPay 商户账户
- API Key 和 Webhook Secret

## 安装

```bash
composer require kode/pays
```

## 配置说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| api_key | string | 是 | HitPay API 密钥 |
| webhook_secret | string | 否 | Webhook 签名密钥，用于验证回调 |
| sandbox | bool | 否 | 是否沙箱模式，默认 false |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::hitpay([
    'api_key' => 'your_hitpay_api_key',
    'webhook_secret' => 'your_webhook_secret',
]);

// 创建支付请求
$result = $gateway->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 10000,
    'currency' => 'SGD',
    'description' => '商品购买',
    'payment_methods' => ['paynow', 'card'],
    'redirect_url' => 'https://example.com/success',
    'webhook' => 'https://example.com/notify/hitpay',
]);

// 获取支付链接
$paymentUrl = $result['url'];
header('Location: ' . $paymentUrl);
```

## 支持支付方式

| 支付方式 | 代码 | 国家 | 说明 |
|----------|------|------|------|
| PayNow | `paynow` | 新加坡 | 即时银行转账 |
| DuitNow | `duitnow` | 马来西亚 | 即时银行转账 |
| PromptPay | `promptpay` | 泰国 | 即时银行转账 |
| QRIS | `qris` | 印度尼西亚 | 二维码支付 |
| 信用卡 | `card` | 全部 | Visa、MasterCard |
| 银行转账 | `bank_transfer` | 全部 | 传统银行转账 |

## API 方法列表

### createOrder(array $params): array

创建支付请求。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | int | 是 | 订单金额（单位：分） |
| currency | string | 否 | 货币代码，默认 SGD |
| description | string | 否 | 订单描述 |
| payment_methods | array | 否 | 支付方式列表 |
| redirect_url | string | 否 | 支付成功跳转地址 |
| webhook | string | 否 | Webhook 地址 |

**返回值：**

| 字段 | 类型 | 说明 |
|------|------|------|
| id | string | Payment Request ID |
| url | string | 支付页面 URL |
| reference_number | string | 商户订单号 |
| amount | float | 金额 |
| status | string | 状态 |

### queryOrder(string $orderId): array

查询订单状态。

### closeOrder(string $orderId): array

取消支付请求。

### refund(array $params): array

发起退款。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| payment_request_id | string | 是 | 支付请求 ID |
| refund_amount | int | 是 | 退款金额（分） |
| refund_reason | string | 否 | 退款原因 |

### queryRefund(string $refundId): array

查询退款状态。

### verifyNotify(array $data): bool

验证异步通知签名（HMAC-SHA256）。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::hitpay([
    'api_key' => 'your_api_key',
    'webhook_secret' => 'your_webhook_secret',
]);

if (!$gateway->verifyNotify($_POST)) {
    http_response_code(400);
    echo '签名验证失败';
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$status = $payload['status'] ?? '';

switch ($status) {
    case 'completed':
        // 支付成功
        break;
    case 'failed':
        // 支付失败
        break;
}

http_response_code(200);
echo 'OK';
```

## 常见问题

**Q: HitPay 支持哪些国家？**
A: 主要支持新加坡、马来西亚、泰国、印度尼西亚、菲律宾等东南亚国家。

**Q: 支付金额是否有限制？**
A: 单笔交易最高限额以 HitPay 商户协议为准，通常为 SGD 100,000。

**Q: 结算周期是多长？**
A: 通常 T+2 工作日结算到商户银行账户。
