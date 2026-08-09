# Revolut 接入文档

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
| api_key | string | 是 | Revolut API 密钥 |
| merchant_id | string | 是 | 商户 ID |
| sandbox | bool | 否 | 是否沙箱环境，默认 false |

## 快速开始

### 创建支付订单

```php
<?php

use Kode\Pays\Facade\Pay;

$revolut = Pay::revolut([
    'api_key'     => 'your-api-key',
    'merchant_id' => 'your-merchant-id',
]);

$result = $revolut->createOrder([
    'out_trade_no'    => 'ORDER_' . date('YmdHis'),
    'total_amount'    => 10.00,
    'currency'        => 'EUR',
    'description'     => 'Revolut 商品',
    'customer_email'  => 'customer@example.com',
    'redirect_url'    => 'https://your-domain.com/success',
    'capture_mode'    => 'automatic',
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
| total_amount | float | 是 | 订单金额 |
| currency | string | 是 | 货币代码 |
| description | string | 否 | 订单描述 |
| customer_email | string | 否 | 顾客邮箱 |
| redirect_url | string | 否 | 支付完成后跳转地址 |
| capture_mode | string | 否 | 捕获模式：automatic、manual |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 捕获授权订单

```php
$gateway->captureOrder(string $orderId): array
```

### 取消订单

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
| order_id | string | 是 | 订单 ID |
| refund_amount | float | 是 | 退款金额 |
| description | string | 否 | 退款说明 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

### 转账能力（TransferCapableInterface）

> Revolut 真实端点对齐 `/api/1.0/pay`。**金额单位为最小货币单位（分）**，SDK 内部 `÷100` 转为主单位小数传给 Revolut（与 `createOrder` 的 `×100` 方向相反，已明确区分）。
> `transferReceipt` 无原生能力，统一抛「无此方法」。

```php
// 单笔转账（源账户取 account_id，缺失回退 merchant_id）
$gateway->singleTransfer([
    'out_biz_no' => 'TF_' . date('YmdHis'),
    'amount'     => 4224,                  // 分（Revolut 收到 42.24）
    'currency'   => 'EUR',
    'recipient'  => ['type' => 'revolut', 'account' => 'acc_xxx'],  // 或 card / iban / counterparty_id
    // 'account_id' => 'src_acc'           // 可选，指定源账户
]);

// 批量转账（逐笔调用 singleTransfer 聚合）
$gateway->batchTransfer([
    'out_biz_no' => 'BTF_1',
    'transfer_detail_list' => [
        ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['type' => 'revolut', 'account' => 'A1'], 'remark' => 'a'],
        ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['type' => 'revolut', 'account' => 'A2'], 'remark' => 'b'],
    ],
]);

// 按 request_id 查询转账
$gateway->queryTransfer('TF_20260809000001');

// 电子回单：Revolut 无此能力，调用抛 PayException（无此方法）
$gateway->transferReceipt('TF_20260809000001');
```

### 对账能力（ReconciliationCapableInterface）

> Revolut 无独立报表 API，对账对齐交易列表 `GET /api/1.0/transactions`（`from`/`to` 日期区间），解析 JSON 为 records。
> `downloadFundFlow` 无原生能力，统一抛「无此方法」。

```php
// 交易对账单（按 bill_date 推导 from/to 时间窗拉取）
$gateway->downloadBill(['bill_date' => '20260809']);

// 资金账单：Revolut 无此能力，调用抛 PayException（无此方法）
$gateway->downloadFundFlow(['bill_date' => '20260809']);

// 解析交易列表 JSON 原始数据（也可直接传入 JSON 文本）
$gateway->parseBill($rawJson);
```

## 常见问题

### 1. 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('revolut');
```

### 2. 捕获模式

- `automatic` — 自动捕获（默认）
- `manual` — 手动捕获，需要先授权再调用 `captureOrder()`

### 3. 支持的支付方式

Revolut 支持：
- 信用卡/借记卡
- Apple Pay
- Google Pay
- Revolut Pay
