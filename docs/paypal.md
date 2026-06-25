# PayPal 接入文档

## 环境要求

- PHP >= 8.2
- ext-json
- ext-openssl
- Composer
- PayPal Business 账号及 REST API 应用（Client ID / Secret）

## 安装

```bash
composer require kode/pays
```

## 配置说明

PayPal 网关对应 `Kode\Pays\Gateway\Paypal\PaypalGateway`，配置类 `Kode\Pays\Config\PaypalConfig`。

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| client_id | string | 是 | PayPal 应用 Client ID |
| client_secret | string | 是 | PayPal 应用 Client Secret |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |

环境地址：

- 沙箱环境：`https://api-m.sandbox.paypal.com/`
- 生产环境：`https://api-m.paypal.com/`

访问令牌（access_token）由 SDK 自动通过 `client_credentials` 授权方式获取并缓存于网关实例，无需开发者手动处理。

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$paypal = Pay::paypal([
    'client_id'     => 'AYxxx-your-client-id',
    'client_secret' => 'ELxxx-your-client-secret',
]);

// 创建支付订单
$result = $paypal->createOrder([
    'intent'         => 'CAPTURE',
    'purchase_units' => [
        [
            'reference_id' => 'ORDER_' . date('YmdHis'),
            'amount'       => [
                'currency_code' => 'USD',
                'value'         => '10.99',
            ],
        ],
    ],
]);

// 用于前端跳转 PayPal 授权的订单号
$orderId = $result['id'] ?? '';
$approveUrl = '';
foreach ($result['links'] ?? [] as $link) {
    if (($link['rel'] ?? '') === 'approve') {
        $approveUrl = $link['href'];
        break;
    }
}
```

## API 方法列表

### 创建订单

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| intent | string | 是 | 意图：`CAPTURE`（立即扣款）或 `AUTHORIZE`（先授权后扣款） |
| purchase_units | array | 是 | 订单单元数组，每项含 `amount.currency_code` 与 `amount.value` |

`purchase_units` 结构示例：

```php
[
    [
        'reference_id' => 'ORDER_001',
        'amount' => [
            'currency_code' => 'USD',
            'value'         => '10.99',
        ],
    ],
]
```

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

`$orderId` 为 PayPal 返回的订单 ID（`result['id']`）。

### 申请退款

```php
$gateway->refund(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| capture_id | string | 是 | 已扣款（capture）的交易 ID |
| amount | array | 否 | 部分退款金额（含 `value`、`currency_code`），省略为全额退款 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

`$refundId` 为 PayPal 返回的退款 ID。

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

> PayPal 通过 Webhook 推送事件，完整验签需校验传输层证书链与签名。当前为简化实现，直接返回 `true`。生产环境建议结合 PayPal 官方 Webhook 验证接口或 SDK 完成严格验签，避免伪造通知造成资金风险。

### 关闭订单

```php
$gateway->closeOrder(string $orderId): array
```

调用 PayPal 的 `v2/checkout/orders/{id}/cancel` 接口取消订单。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$payload = json_decode(file_get_contents('php://input'), true) ?: [];

$paypal = Pay::paypal([
    'client_id'     => 'AYxxx-your-client-id',
    'client_secret' => 'ELxxx-your-client-secret',
]);

if ($paypal->verifyNotify($payload)) {
    $eventType = $payload['event_type'] ?? '';
    $resource  = $payload['resource'] ?? [];

    if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
        // 处理支付成功业务
    }

    // 返回 200 响应
    http_response_code(200);
    echo 'ok';
} else {
    http_response_code(400);
    echo 'fail';
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('paypal');
```

或在配置中直接设置 `'sandbox' => true`：

```php
$paypal = Pay::paypal([
    'client_id'     => 'AYxxx-sandbox-client-id',
    'client_secret' => 'ELxxx-sandbox-client-secret',
    'sandbox'       => true,
]);
```

### 2. 访问令牌自动获取

SDK 首次调用接口时，会使用 `client_id:client_secret` 的 Basic 认证向 `v1/oauth2/token` 发起 `grant_type=client_credentials` 请求获取 access_token，并缓存到网关实例。后续请求复用该令牌，避免重复请求。如需强制刷新令牌，可重新创建网关实例。

### 3. 金额格式

PayPal 金额为字符串类型（`value`），保留两位小数（如 `"10.99"`），币种代码遵循 ISO-4217（如 `USD`、`EUR`、`CNY`）。

### 4. Webhook 安全建议

由于当前 `verifyNotify` 为简化实现，建议在生产环境：

- 配置 PayPal Dashboard 的 Webhook 仅推送到 HTTPS 端点
- 校验请求来源 IP 或使用 PayPal `v1/notifications/verify-webhook-signature` 接口完成严格验签
- 对关键业务（如发货）以 `queryOrder` 查询结果为准做二次确认

### 5. 事件监听

```php
use Kode\Pays\Facade\Pay;
use Kode\Pays\Event\Events;

Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    // 支付成功后处理业务
});
```
