# Xendit 接入文档

Xendit 是东南亚领先的支付聚合平台，覆盖印度尼西亚、菲律宾、马来西亚、泰国、越南等国家，支持多种本地支付方式。

## 环境要求

- PHP 8.3+
- 有效的 Xendit 商户账户
- Secret Key（私钥）和 Callback Token

## 安装

```bash
composer require kode/pays
```

## 配置说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| secret_key | string | 是 | Xendit API 私钥（xnd_development_ 或 xnd_production_） |
| public_key | string | 否 | 公钥（用于前端集成） |
| callback_token | string | 否 | Webhook 回调验证令牌 |
| sandbox | bool | 否 | 是否沙箱模式，默认 false |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::xendit([
    'secret_key' => 'xnd_development_your_secret_key',
    'callback_token' => 'your_callback_token',
]);

// 创建发票（通用支付）
$result = $gateway->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 100000,
    'currency' => 'IDR',
    'description' => '商品购买',
    'payment_methods' => ['CARD', 'BANK_TRANSFER', 'EWALLET'],
    'success_redirect_url' => 'https://example.com/success',
    'failure_redirect_url' => 'https://example.com/failure',
]);

// 获取支付链接
$paymentUrl = $result['invoice_url'];
header('Location: ' . $paymentUrl);
```

## 支持支付方式

### 信用卡/借记卡
- Visa、MasterCard、JCB、AMEX

### 虚拟账户（Virtual Account）
| 银行 | 代码 | 国家 |
|------|------|------|
| BCA | `BCA` | 印度尼西亚 |
| Mandiri | `MANDIRI` | 印度尼西亚 |
| BNI | `BNI` | 印度尼西亚 |
| BRI | `BRI` | 印度尼西亚 |
| Permata | `PERMATA` | 印度尼西亚 |
| CIMB | `CIMB` | 印度尼西亚 |
| BDO | `BDO` | 菲律宾 |
| BPI | `BPI` | 菲律宾 |

### 电子钱包
| 钱包 | 代码 | 国家 |
|------|------|------|
| GoPay | `GOPAY` | 印度尼西亚 |
| OVO | `OVO` | 印度尼西亚 |
| DANA | `DANA` | 印度尼西亚 |
| ShopeePay | `SHOPEEPAY` | 印度尼西亚 |
| GrabPay | `GRABPAY` | 印度尼西亚 |
| GCash | `GCASH` | 菲律宾 |
| PayMaya | `PAYMAYA` | 菲律宾 |
| GrabPay | `GRABPAY` | 马来西亚 |

### QRIS（印度尼西亚统一二维码）
- 支持所有印尼银行和电子钱包扫码支付

### 便利店支付
- Indomaret、Alfamart（印度尼西亚）

## API 方法列表

### createOrder(array $params): array

创建支付发票。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号（external_id） |
| total_amount | float | 是 | 订单金额 |
| currency | string | 否 | 货币代码，默认 IDR |
| description | string | 否 | 订单描述 |
| payment_methods | array | 否 | 支付方式列表 |
| success_redirect_url | string | 否 | 支付成功跳转地址 |
| failure_redirect_url | string | 否 | 支付失败跳转地址 |
| customer_email | string | 否 | 客户邮箱 |

### createVirtualAccount(array $params): array

创建虚拟账户支付。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | float | 是 | 订单金额 |
| bank_code | string | 是 | 银行代码（BCA、MANDIRI 等） |
| name | string | 是 | 客户姓名 |
| description | string | 否 | 描述 |

### createEWallet(array $params): array

创建电子钱包支付。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | float | 是 | 订单金额 |
| ewallet_type | string | 是 | 钱包类型（GOPAY、OVO 等） |
| phone | string | 否 | 客户手机号 |
| callback_url | string | 否 | 回调地址 |
| redirect_url | string | 否 | 重定向地址 |

### createQRIS(array $params): array

创建 QRIS 支付。

### queryOrder(string $orderId): array

查询发票状态。

### refund(array $params): array

发起退款。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| invoice_id | string | 是 | 发票 ID |
| refund_amount | float | 是 | 退款金额 |
| refund_reason | string | 否 | 退款原因 |

### queryRefund(string $refundId): array

查询退款状态。

### closeOrder(string $orderId): array

过期发票。

### verifyNotify(array $data): bool

验证异步通知签名（Callback Token 验证）。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::xendit([
    'secret_key' => 'xnd_development_your_secret_key',
    'callback_token' => 'your_callback_token',
]);

if (!$gateway->verifyNotify($_POST)) {
    http_response_code(400);
    echo '验证失败';
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$status = $payload['status'] ?? '';

switch ($status) {
    case 'PAID':
        // 支付成功
        $invoiceId = $payload['id'] ?? '';
        $externalId = $payload['external_id'] ?? '';
        $amount = $payload['amount'] ?? 0;
        // 更新订单状态
        break;
    case 'EXPIRED':
        // 发票已过期
        break;
}

http_response_code(200);
echo 'OK';
```

## 货币支持

| 货币 | 代码 | 最小单位 | 国家 |
|------|------|----------|------|
| 印尼盾 | IDR | 1（无小数） | 印度尼西亚 |
| 菲律宾比索 | PHP | 0.01 | 菲律宾 |
| 马来西亚林吉特 | MYR | 0.01 | 马来西亚 |
| 泰铢 | THB | 0.01 | 泰国 |
| 越南盾 | VND | 1（无小数） | 越南 |

## 常见问题

**Q: Xendit 支持哪些国家？**
A: 印度尼西亚、菲律宾、马来西亚、泰国、越南。

**Q: IDR 和 VND 是否有小数？**
A: IDR（印尼盾）和 VND（越南盾）无小数单位，金额直接传整数。

**Q: 结算周期是多长？**
A: 通常 T+1 或 T+2 工作日结算到商户银行账户。

**Q: 如何区分测试和正式环境？**
A: 使用 `xnd_development_` 前缀的密钥为测试环境，`xnd_production_` 前缀的为正式环境。
