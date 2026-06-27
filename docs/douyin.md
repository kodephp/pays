# 抖音支付接入文档

## 环境要求

- PHP >= 8.3
- ext-openssl
- ext-json
- ext-mbstring
- Composer
- 抖音开放平台开发者账号及已审核通过的小程序/应用

## 安装

```bash
composer require kode/pays
```

## 配置说明

抖音支付网关对应 `Kode\Pays\Gateway\Douyin\DouyinPayGateway`，配置类 `Kode\Pays\Config\DouyinConfig`。

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_id | string | 是 | 抖音应用 ID |
| merchant_id | string | 是 | 商户号 |
| salt | string | 是 | 签名盐值（商户后台获取） |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |

环境地址：

- 测试环境：`https://developer-sandbox.toutiao.com/`
- 生产环境：`https://developer.toutiao.com/`

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$douyin = Pay::douyin([
    'app_id'      => 'tt1234567890abcdef',
    'merchant_id' => '7100000000000',
    'salt'        => 'your-salt-value',
]);

// 创建支付订单
$result = $douyin->createOrder([
    'out_order_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 100,            // 单位：分
    'subject'      => '测试商品',
    'body'         => '测试商品描述',
    'valid_time'   => 600,            // 订单有效时间（秒）
    'notify_url'   => 'https://your-domain.com/notify/douyin',
]);

// 调起支付所需参数
$orderId = $result['order_id'] ?? '';
```

## API 方法列表

### 创建订单

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_order_no | string | 是 | 商户订单号 |
| total_amount | int | 是 | 订单金额（分） |
| subject | string | 是 | 商品标题 |
| body | string | 是 | 商品描述 |
| valid_time | int | 是 | 订单有效时间（秒） |
| notify_url | string | 否 | 异步通知地址 |
| disable_msg | int | 否 | 是否屏蔽支付成功页消息推送，0 不屏蔽（默认） |
| msg_page | string | 否 | 消息跳转页面路径 |
| expand_order_info | array | 否 | 订单扩展信息（JSON 编码后提交） |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 申请退款

```php
$gateway->refund(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_refund_no | string | 是 | 商户退款单号 |
| refund_amount | int | 是 | 退款金额（分） |
| reason | string | 是 | 退款原因 |
| out_order_no | string | 否 | 原商户订单号 |
| cp_extra | string | 否 | 商户自定义附加信息 |
| notify_url | string | 否 | 退款异步通知地址 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

### 关闭订单

```php
$gateway->closeOrder(string $orderId): array
```

> 抖音支付暂不支持主动关闭订单，调用该方法将抛出 `PayException`。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$douyin = Pay::douyin([
    'app_id'      => 'tt1234567890abcdef',
    'merchant_id' => '7100000000000',
    'salt'        => 'your-salt-value',
]);

if ($douyin->verifyNotify($data)) {
    // 验签通过，处理业务逻辑
    $orderId = $data['msg']['order_no'] ?? '';

    // 返回成功响应给抖音
    echo json_encode(['err_no' => 0, 'err_tips' => 'success']);
} else {
    echo json_encode(['err_no' => 1, 'err_tips' => 'verify failed']);
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('douyin');
```

或在配置中直接设置 `'sandbox' => true`：

```php
$douyin = Pay::douyin([
    'app_id'      => 'tt1234567890abcdef',
    'merchant_id' => '7100000000000',
    'salt'        => 'your-salt-value',
    'sandbox'     => true,
]);
```

### 2. 签名算法

抖音支付使用 MD5 签名：参数按 key 升序拼接为查询字符串，末尾拼接 `&salt={salt}` 后计算 `md5` 得到 `sign`。`sign` 字段本身与 `timestamp` 不参与签名。SDK 已通过 `Kode\Pays\Support\Signer` 自动处理。

### 3. 金额单位

抖音支付所有金额字段（`total_amount`、`refund_amount`）单位均为 **分**，请勿传入元。

### 4. 事件监听

```php
use Kode\Pays\Facade\Pay;
use Kode\Pays\Event\Events;

Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    // 支付成功后处理业务
});
```
