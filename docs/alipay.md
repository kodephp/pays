# 支付宝接入文档

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

### 普通公钥模式（AlipayConfig）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_id | string | 是 | 支付宝应用 ID |
| private_key | string | 是 | 应用私钥（RSA 或 RSA2） |
| public_key | string | 是 | 支付宝公钥 |
| app_auth_token | string | 否 | 应用授权令牌（第三方授权时使用） |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => file_get_contents('/path/to/private_key.pem'),
    'public_key'  => file_get_contents('/path/to/public_key.pem'),
]);

// 电脑网站支付
$result = $alipay->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '0.01',
    'subject'      => '测试商品',
    'product_code' => 'FAST_INSTANT_TRADE_PAY',
    'notify_url'   => 'https://your-domain.com/notify/alipay',
    'return_url'   => 'https://your-domain.com/return',
]);

// 跳转到支付宝收银台
header('Location: ' . $result['url']);
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
| total_amount | string | 是 | 订单金额（元） |
| subject | string | 是 | 订单标题 |
| product_code | string | 是 | 产品码（如 FAST_INSTANT_TRADE_PAY） |
| notify_url | string | 是 | 异步通知地址 |
| return_url | string | 否 | 同步跳转地址 |
| body | string | 否 | 订单描述 |

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
| out_trade_no | string | 条件 | 商户订单号（与 trade_no 二选一） |
| trade_no | string | 条件 | 支付宝交易号（与 out_trade_no 二选一） |
| refund_amount | string | 是 | 退款金额（元） |
| out_request_no | string | 是 | 退款请求号 |
| refund_reason | string | 否 | 退款原因 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

## 周期扣款（订阅能力）

支付宝周期扣款经 `SubscriptionCapableInterface` 统一暴露，全部请求走 RSA2 签名
（`buildRequestParams`）。金额单位为「元」，仅支持 CNY。

| 方法 | 支付宝接口 | 说明 |
|------|-----------|------|
| `createPlan(array)` | 无（本地组装） | 支付宝无服务端计划实体，本方法只组装并校验 `period_rule_params` |
| `createSubscription(array)` | `alipay.user.agreement.page.sign` | 返回签约跳转链接（`method` / `url`） |
| `cancelSubscription(string)` | `alipay.user.agreement.unsign` | 解约 |
| `pauseSubscription(string)` | 无 | 抛「无此方法」，改用 `modifyExecutionPlan()` 延后扣款 |
| `resumeSubscription(string)` | 无 | 抛「无此方法」 |
| `getSubscription(string)` | `alipay.user.agreement.query` | 查询协议 |
| `payWithAgreement(array)` | `alipay.trade.pay` | 按协议号发起代扣 |
| `modifyExecutionPlan(array)` | `alipay.user.agreement.executionplan.modify` | 修改下次扣款日 |

协议标识：`cancelSubscription()` / `getSubscription()` 默认按支付宝协议号
（`agreement_no`）；传 `ext:` 前缀（如 `ext:SUB_20260810`）时按商户侧协议号
（`external_agreement_no`）定位。

```php
use Kode\Pays\Facade\Pay;

// 1. 组装周期规则（本地，不发请求）
$plan = Pay::call('alipay', 'createPlan', [[
    'name' => '会员月卡',
    'amount' => 29.90,      // 元
    'currency' => 'CNY',
    'interval' => 'month',  // 仅支持 day / month
    'interval_count' => 1,
    'total_payments' => 12,
]]);

// 2. 生成签约跳转链接
$sign = Pay::call('alipay', 'createSubscription', [[
    'customer_id' => 'SUB_20260810',   // external_agreement_no
    'plan_id' => $plan['plan_id'],
    'period_rule_params' => $plan['period_rule_params'],
    'notify_url' => 'https://example.com/notify/alipay-sign',
]]);
// $sign['url'] 引导用户跳转完成签约

// 3. 签约成功后按周期发起代扣
Pay::call('alipay', 'payWithAgreement', [[
    'out_trade_no' => 'CYCLE_202608',
    'total_amount' => '29.90',
    'subject' => '会员月卡续费',
    'agreement_no' => '20260810...',
    'notify_url' => 'https://example.com/notify/alipay-pay',
]]);
```

## 完整使用示例

### 查询订单

```php
<?php

use Kode\Pays\Facade\Pay;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => file_get_contents('/path/to/private_key.pem'),
    'public_key'  => file_get_contents('/path/to/public_key.pem'),
]);

// 通过商户订单号查询
$result = $alipay->queryOrder('ORDER_202404250001');

$tradeStatus = $result['trade_status'] ?? '';
if ($tradeStatus === 'TRADE_SUCCESS') {
    echo '订单已支付，交易号：' . ($result['trade_no'] ?? '') . PHP_EOL;
} elseif ($tradeStatus === 'WAIT_BUYER_PAY') {
    echo '订单待支付' . PHP_EOL;
} elseif ($tradeStatus === 'TRADE_CLOSED') {
    echo '订单已关闭' . PHP_EOL;
}
```

### 关闭订单

```php
<?php

use Kode\Pays\Facade\Pay;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => file_get_contents('/path/to/private_key.pem'),
    'public_key'  => file_get_contents('/path/to/public_key.pem'),
]);

// 用户超时未支付时关闭订单
$result = $alipay->closeOrder('ORDER_202404250001');
```

### 申请退款

```php
<?php

use Kode\Pays\Facade\Pay;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => file_get_contents('/path/to/private_key.pem'),
    'public_key'  => file_get_contents('/path/to/public_key.pem'),
]);

$result = $alipay->refund([
    'out_trade_no'  => 'ORDER_202404250001',
    'out_request_no' => 'REFUND_' . date('YmdHis'),
    'refund_amount' => '0.50',
    'refund_reason' => '商品质量问题',
]);

echo '退款状态：' . ($result['fund_change'] ?? '') . PHP_EOL;
echo '买家付款金额：' . ($result['buyer_pay_amount'] ?? '') . PHP_EOL;
```

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$data = $_POST;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => '...',
    'public_key'  => '...',
]);

if ($alipay->verifyNotify($data)) {
    $orderId = $data['out_trade_no'];
    $tradeStatus = $data['trade_status'];
    
    if ($tradeStatus === 'TRADE_SUCCESS') {
        // 支付成功处理
    }
    
    echo 'success'; // 必须返回 success，否则支付宝会重复通知
} else {
    echo 'fail';
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('alipay');
```

### 2. 不同支付场景的产品码

| 场景 | product_code |
|------|-------------|
| 电脑网站支付 | FAST_INSTANT_TRADE_PAY |
| 手机网站支付 | QUICK_WAP_WAY |
| App 支付 | QUICK_MSECURITY_PAY |
| 当面付 | FACE_TO_FACE_PAYMENT |

### 3. 使用 PayResponse 包装结果

```php
use Kode\Pays\Core\PayResponse;

$response = new PayResponse($gateway->createOrder($params));

if ($response->isSuccess()) {
    $payUrl = $response->getPayUrl();
}
```
