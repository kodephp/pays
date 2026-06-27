# Kode Pays SDK 快速开始

本文档帮助你在 5 分钟内完成第一个支付订单。如需深入了解架构与扩展，请阅读 [架构详解](architecture.md) 与 [开发指南](development.md)。

## 环境要求

- PHP >= 8.3
- ext-json
- ext-openssl
- Composer

## 安装

```bash
composer require kode/pays
```

### 入口选择

SDK 提供两个入口类，按需选用：

| 入口 | 命名空间 | 适用场景 |
|------|----------|----------|
| 简化入口 | `Kode\Pays\Pay` | 仅需创建网关，调用核心方法 |
| 门面入口 | `Kode\Pays\Facade\Pay` | 需要事件监听、批量创建、轮询、配置缓存、自定义 HTTP 客户端等高级能力 |

下文示例统一使用功能更完整的门面入口 `Kode\Pays\Facade\Pay`，简化入口用法可参考 [README](../README.md)。

## 快速接入示例

### 微信支付

```php
<?php

use Kode\Pays\Facade\Pay;

// 创建微信支付网关
$wechat = Pay::wechat([
    'app_id'  => 'wx1234567890abcdef',
    'mch_id'  => '1234567890',
    'api_key' => 'your-api-key-here',
    'sandbox' => false, // 沙箱模式
]);

// 创建订单
$result = $wechat->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis') . rand(1000, 9999),
    'total_fee'    => 100, // 金额：分
    'body'         => '测试商品',
    'trade_type'   => 'NATIVE', // JSAPI、NATIVE、APP、H5、MWEB
    'notify_url'   => 'https://your-domain.com/notify/wechat',
]);

// 获取支付二维码链接（NATIVE）
$codeUrl = $result['code_url'] ?? '';
```

### 支付宝

```php
<?php

use Kode\Pays\Facade\Pay;

// 创建支付宝网关
$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => '-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----',
    'public_key'  => '-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----',
    'sandbox'     => false,
]);

// 创建订单（返回跳转 URL）
$result = $alipay->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '0.01',
    'subject'      => '测试商品',
    'product_code' => 'FAST_INSTANT_TRADE_PAY',
    'notify_url'   => 'https://your-domain.com/notify/alipay',
    'return_url'   => 'https://your-domain.com/return',
]);

// 前端跳转支付
header('Location: ' . $result['url']);
```

### PayPal

```php
<?php

use Kode\Pays\Facade\Pay;

// 创建 PayPal 网关
$paypal = Pay::paypal([
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'sandbox'       => true,
]);

// 创建订单
$result = $paypal->createOrder([
    'intent' => 'CAPTURE',
    'purchase_units' => [
        [
            'amount' => [
                'currency_code' => 'USD',
                'value' => '10.00',
            ],
        ],
    ],
]);

$orderId = $result['id'];
```

### 聚合支付

```php
<?php

use Kode\Pays\Facade\Pay;

// 创建聚合支付网关（自动按优先级切换渠道）
$aggregate = Pay::create('aggregate', [
    'channels' => [
        [
            'gateway'  => 'wechat',
            'priority' => 1,
            'config'   => [
                'app_id'  => 'wx123456',
                'mch_id'  => '123456',
                'api_key' => 'key',
            ],
        ],
        [
            'gateway'  => 'alipay',
            'priority' => 2,
            'config'   => [
                'app_id'      => '2024...',
                'private_key' => '...',
                'public_key'  => '...',
            ],
        ],
    ],
]);

// 创建订单（自动选择可用渠道）
$result = $aggregate->createOrder([
    'out_trade_no' => 'ORDER_001',
    'total_fee'    => 100,
    'body'         => '测试商品',
]);

// 返回结果包含实际使用的渠道标识
$channel = $result['_channel']; // wechat 或 alipay
```

## 核心场景

### 1. 查询订单

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat($config);

// 通过商户订单号查询
$result = $wechat->queryOrder('ORDER_202404250001');

// 判断订单状态
if (isset($result['trade_state']) && $result['trade_state'] === 'SUCCESS') {
    echo '订单已支付';
}
```

### 2. 关闭订单

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat($config);

// 关闭未支付订单（用户超时未支付时调用）
$result = $wechat->closeOrder('ORDER_202404250001');
```

### 3. 申请退款

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Exception\GatewayException;

$wechat = Pay::wechat($config);

try {
    $result = $wechat->refund([
        'out_trade_no'  => 'ORDER_202404250001',
        'out_refund_no' => 'REFUND_' . date('YmdHis'),
        'total_fee'     => 100,
        'refund_fee'    => 50,
        'refund_desc'   => '商品质量问题',
    ]);

    echo '退款单号：' . ($result['refund_id'] ?? '') . PHP_EOL;
} catch (GatewayException $e) {
    // 网关业务错误（如余额不足、订单已退款）
    echo '退款失败：' . $e->getMessage() . PHP_EOL;
}
```

### 4. 查询退款

```php
<?php

use Kode\Pays\Facade\Pay;

$wechat = Pay::wechat($config);

$result = $wechat->queryRefund('REFUND_202404250001');
$status = $result['refund_status'] ?? ''; // SUCCESS / PROCESSING / FAIL
```

### 5. 验证异步通知

```php
<?php

use Kode\Pays\Facade\Pay;

$data = $_POST;

$gateway = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

if ($gateway->verifyNotify($data)) {
    // 验签通过，处理业务逻辑
    $orderId = $data['out_trade_no'];

    // 注意：处理前应做幂等性检查，避免重复通知造成重复发货
    // ...

    // 返回成功响应（不同网关格式不同，请参考对应网关文档）
    echo '<xml><return_code><![CDATA[SUCCESS]]></return_code></xml>';
} else {
    // 验签失败，禁止返回 SUCCESS
    echo '<xml><return_code><![CDATA[FAIL]]></return_code></xml>';
}
```

## 沙箱配置

沙箱模式可避免开发阶段扣真实资金，支持全局开启和按网关单独开启。

```php
<?php

use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Facade\Pay;

// 方式 1：全局开启沙箱（所有网关）
SandboxManager::enableGlobal();

// 方式 2：仅对指定网关开启沙箱
SandboxManager::enable('wechat');
SandboxManager::enable('alipay');

// 方式 3：在配置中直接设置 sandbox 标志
$wechat = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
    'sandbox' => true,
]);

// 判断当前是否沙箱
if (SandboxManager::isSandbox('wechat')) {
    echo '当前为微信沙箱环境' . PHP_EOL;
}

// 获取当前环境基础 URL
$url = SandboxManager::getBaseUrl('wechat');
```

## 事件监听

通过事件系统可将支付生命周期与日志、监控、业务通知解耦。

```php
<?php

use Kode\Pays\Event\Events;
use Kode\Pays\Facade\Pay;

// 支付成功时发送通知
Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    $orderId = $payload['order_id'] ?? '';
    $amount  = $payload['amount'] ?? 0;
    // 发送短信、邮件、站内信等
});

// 支付失败时告警
Pay::on(Events::PAYMENT_FAILED, function (array $payload) {
    // 上报到钉钉/企业微信/Sentry
});

// 请求发送前记录日志
Pay::on(Events::REQUEST_SENDING, function (array $payload) {
    // 注意：可对敏感字段（如 api_key）脱敏后再记录
    return $payload;
});

// 异常发生时统一上报
Pay::on(Events::EXCEPTION_OCCURRED, function (array $payload) {
    $exception = $payload['exception'] ?? null;
    // 记录异常到日志系统
});

// 异步通知验证通过
Pay::on(Events::NOTIFY_VERIFIED, function (array $payload) {
    // 触发业务流程
});
```

支持的事件常量详见 `Kode\Pays\Event\Events`：

| 常量 | 说明 |
|------|------|
| `REQUEST_SENDING` | 请求发送前 |
| `REQUEST_SENT` | 请求发送后 |
| `RESPONSE_PARSED` | 响应解析后 |
| `PAYMENT_SUCCESS` | 支付成功 |
| `PAYMENT_FAILED` | 支付失败 |
| `NOTIFY_RECEIVED` | 异步通知接收 |
| `NOTIFY_VERIFIED` | 异步通知验证通过 |
| `EXCEPTION_OCCURRED` | 异常发生 |
| `REFUND_SUCCESS` | 退款成功 |

## 统一异常处理

```php
<?php

use Kode\Pays\Exception\ConfigException;
use Kode\Pays\Exception\GatewayException;
use Kode\Pays\Exception\InvalidArgumentException;
use Kode\Pays\Exception\NetworkException;
use Kode\Pays\Exception\SignException;
use Kode\Pays\Facade\Pay;

try {
    $gateway = Pay::wechat($config);
    $result = $gateway->createOrder($params);
} catch (ConfigException $e) {
    // 配置缺失或错误，需检查配置文件
    echo '配置错误：' . $e->getMessage() . PHP_EOL;
} catch (NetworkException $e) {
    // 网络超时或连接失败，可重试
    echo '网络异常：' . $e->getMessage() . PHP_EOL;
} catch (SignException $e) {
    // 签名验证失败，可能密钥错误或数据被篡改
    echo '签名失败：' . $e->getMessage() . PHP_EOL;
} catch (InvalidArgumentException $e) {
    // 业务参数错误，需返回给前端
    echo '参数错误：' . $e->getMessage() . PHP_EOL;
} catch (GatewayException $e) {
    // 网关返回业务错误
    echo '错误码：' . $e->getCode() . PHP_EOL;
    echo '错误信息：' . $e->getMessage() . PHP_EOL;
    echo '网关错误码：' . ($e->getGatewayCode() ?? '无') . PHP_EOL;
    echo '网关错误信息：' . ($e->getGatewayMessage() ?? '无') . PHP_EOL;
}
```

## 使用 PayResponse 包装结果

`Kode\Pays\Core\PayResponse` 提供统一的响应包装，简化业务层判断：

```php
<?php

use Kode\Pays\Core\PayResponse;
use Kode\Pays\Facade\Pay;

$gateway = Pay::wechat($config);
$response = new PayResponse($gateway->createOrder($params));

if ($response->isSuccess()) {
    $payUrl  = $response->getPayUrl();
    $orderNo = $response->getOutTradeNo();
    echo '下单成功：' . $orderNo . PHP_EOL;
} else {
    echo '下单失败：' . $response->getErrorMessage() . PHP_EOL;
}
```

## 注册自定义网关

```php
<?php

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Facade\Pay;

// 自定义网关类
class MyGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key']);
    }

    protected function getBaseUrl(): string
    {
        return $this->sandbox ? 'https://sandbox.example.com/' : 'https://api.example.com/';
    }

    public function createOrder(array $params): array { /* ... */ }
    public function queryOrder(string $orderId): array { /* ... */ }
    public function refund(array $params): array { /* ... */ }
    public function queryRefund(string $refundId): array { /* ... */ }
    public function verifyNotify(array $data): bool { /* ... */ }
    public function closeOrder(string $orderId): array { /* ... */ }

    public static function getName(): string
    {
        return 'mygateway';
    }

    protected function parseResponse(string $response): array
    {
        return json_decode($response, true) ?: [];
    }
}

// 注册到 SDK
Pay::register('mygateway', MyGateway::class);

// 使用自定义网关
$gateway = Pay::create('mygateway', ['api_key' => 'value']);
```

详细的网关开发流程请参考 [开发指南](development.md)。

## 下一步

- [架构详解](architecture.md) - 了解分层架构与设计模式
- [开发指南](development.md) - 新增网关、插件、中间件
- [插件总览](plugins.md) - 分账、退款、转账等扩展能力
- 各网关接入文档 - 见 [文档导航](index.md)
