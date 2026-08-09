# Adyen 支付接入文档

## 环境要求

- PHP >= 8.3
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

### 转账能力（TransferCapableInterface）

> Adyen 真实端点对齐 Transfers API（`POST /pal/servlet/Transfer/v68/transfer`）。
> 金额单位与 Adyen 规范一致：`amount.value` 为最小货币单位（分），`amount.currency` 大写。
> `transferReceipt` 无原生能力，统一抛「无此方法」。

```php
// 单笔转账（bank 收款：iban；card 收款：卡号）
$gateway->singleTransfer([
    'out_biz_no' => 'TF_' . date('YmdHis'),
    'amount'     => 10000,                 // 分
    'currency'   => 'EUR',
    'recipient'  => ['type' => 'bank', 'account' => 'GB29NWBK60161331926819', 'name' => '张三'],
    'description' => '佣金',
    // 'balance_account_id' => 'BA123'      // 可选，注入 balanceAccount
]);

// 批量转账（逐笔调用 singleTransfer 聚合）
$gateway->batchTransfer([
    'out_biz_no' => 'BTF_1',
    'transfer_detail_list' => [
        ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['type' => 'bank', 'account' => 'A1'], 'remark' => 'a'],
        ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['type' => 'bank', 'account' => 'A2'], 'remark' => 'b'],
    ],
]);

// 按商户单号（reference）查询转账
$gateway->queryTransfer('TF_20260809000001');

// 电子回单：Adyen 无此能力，调用抛 PayException（无此方法）
$gateway->transferReceipt('TF_20260809000001');
```

### 对账能力（ReconciliationCapableInterface）

> 对齐 Adyen Report API：先 `POST /pal/servlet/Reports/v68/getReport` 生成报表取 `url`，再下载并解析 CSV。
> `bill_date` 格式为 `YYYYMMDD`。

```php
// 交易对账单（Settlement detail report）
$gateway->downloadBill(['bill_date' => '20260809']);

// 资金账单（Payment accounting report）
$gateway->downloadFundFlow(['bill_date' => '20260809']);

// 解析下载得到的 CSV 原始数据（也可直接传入 CSV 文本）
$gateway->parseBill($rawCsv);
```

### 自动结算能力（SettlementCapableInterface）

> Adyen 出款对齐 Transfers API（`POST /pal/servlet/Transfer/v68/transfer` → 实际出款用 `category` 区分）：
> 从平台余额账户（`balance_account_id` 配置，作为出款来源 `balanceAccount`）出款到收款人。

```php
// 结算到外部银行（category=bank）：需先配置 balance_account_id
$gateway->settleToPayout([
    'out_biz_no' => 'SETTLE_' . date('YmdHis'),
    'amount'     => 10000,                              // 分
    'account'    => 'GB29NWBK60161331926819',          // 收款人 IBAN
    'real_name'  => '张三',
    'currency'   => 'EUR',
]);

// 结算到银行卡（category=card）
$gateway->settleToBankCard([
    'out_biz_no'   => 'SETTLE_C',
    'amount'       => 5000,
    'bank_card_no' => '4111111111111111',
    'real_name'    => '李四',
]);

// 查询结算结果（按 reference）
$gateway->querySettlement('SETTLE_20260809000001');

// 结算到平台内钱包：Adyen 无此语义，调用抛 PayException（无此方法）
$gateway->settleToWallet('SETTLE_20260809000001');
```

### 退款能力（RefundCapableInterface）

> 退款对齐 Adyen 真实退款规范：申请退款 `POST /pal/servlet/Payment/v68/refund`，
> 查询退款 `POST /pal/servlet/Payment/v68/refundWithData`。Adyen 不支持取消退款，
> `cancelRefund` 统一抛 `PayException`（无此方法）。

```php
// 申请退款（金额单位：分）
$result = $gateway->applyRefund([
    'out_refund_no'   => 'R_' . date('YmdHis'),
    'refund_fee'      => 5000,                  // 分
    'transaction_id'  => 'PSP_882211',          // 原支付 PSP 参考号（或 out_trade_no）
    'refund_currency' => 'EUR',
]);

// 查询退款（按原支付 PSP 参考号）
$result = $gateway->queryRefund('PSP_882211');

// 取消退款：Adyen 不支持，调用报「无此方法」
$gateway->cancelRefund('R_20260809000001');
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
