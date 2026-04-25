# Coinbase Commerce 接入文档

Coinbase Commerce 是 Coinbase 推出的加密货币支付解决方案，支持 BTC、ETH、USDT、USDC、LTC、BCH、DOGE 等主流加密货币支付。

## 环境要求

- PHP 8.2+
- 有效的 Coinbase Commerce 账户
- API Key

## 安装

```bash
composer require kode/pays
```

## 配置说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| api_key | string | 是 | Coinbase Commerce API Key |
| webhook_secret | string | 否 | Webhook 签名密钥，用于验证回调 |
| sandbox | bool | 否 | 是否沙箱模式，默认 false |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::coinbase([
    'api_key' => 'your_coinbase_commerce_api_key',
    'webhook_secret' => 'your_webhook_secret',
]);

// 创建加密货币支付订单
$result = $gateway->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 10000,
    'currency' => 'USD',
    'description' => '商品购买',
    'redirect_url' => 'https://example.com/success',
    'cancel_url' => 'https://example.com/cancel',
]);

// 获取支付页面 URL
$payUrl = $result['hosted_url'];
echo "请跳转到支付页面：{$payUrl}";
```

## API 方法列表

### createOrder(array $params): array

创建加密货币支付订单。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | int | 是 | 订单金额（单位：分） |
| currency | string | 否 | 法币币种，默认 USD |
| description | string | 否 | 订单描述 |
| redirect_url | string | 否 | 支付成功跳转地址 |
| cancel_url | string | 否 | 支付取消跳转地址 |

**返回值：**

| 字段 | 类型 | 说明 |
|------|------|------|
| out_trade_no | string | 商户订单号 |
| charge_id | string | Coinbase Charge ID |
| code | string | Charge 短码 |
| hosted_url | string | 支付页面 URL |
| status | string | 状态：NEW/PENDING/CONFIRMED/FAILED/RESOLVED/UNRESOLVED |
| created_at | string | 创建时间 |
| expires_at | string | 过期时间 |

### queryOrder(string $orderId): array

查询订单状态。

### closeOrder(string $orderId): array

关闭订单（Coinbase Commerce 不支持主动取消，返回提示信息）。

### refund(array $params): array

发起退款。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| charge_id | string | 是 | Charge ID |
| refund_fee | int | 否 | 退款金额（分），不填则全额退款 |
| currency | string | 否 | 退款币种 |

### verifyNotify(array $data): bool

验证异步通知签名。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::coinbase([
    'api_key' => 'your_api_key',
    'webhook_secret' => 'your_webhook_secret',
]);

// 验证签名
if (!$gateway->verifyNotify($_POST)) {
    http_response_code(400);
    echo '签名验证失败';
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$event = $payload['event']['type'] ?? '';

switch ($event) {
    case 'charge:confirmed':
        // 支付已确认（至少 6 个区块确认）
        $orderId = $payload['event']['data']['metadata']['out_trade_no'] ?? '';
        // 更新订单状态为已支付
        break;
    case 'charge:failed':
        // 支付失败
        break;
    case 'charge:resolved':
        // 支付已解决（超时后手动解决）
        break;
}

http_response_code(200);
echo 'OK';
```

## 常见问题

**Q: 加密货币支付需要多久确认？**
A: 不同币种确认时间不同，BTC 约 10-60 分钟，ETH 约 1-5 分钟，USDT (TRC20) 约 1-3 分钟。

**Q: 支持哪些加密货币？**
A: BTC、ETH、USDT、USDC、LTC、BCH、DOGE、DAI 等，具体以 Coinbase Commerce 支持列表为准。

**Q: 如何防止重复支付？**
A: 每个 Charge 有唯一 code，建议以 charge_id 作为幂等键。
