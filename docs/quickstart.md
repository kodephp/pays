# Kode Pays SDK 快速开始

## 环境要求

- PHP >= 8.2
- ext-json
- ext-openssl
- Composer

## 安装

```bash
composer require kode/pays
```

## 快速接入示例

### 微信支付

```php
<?php

use Kode\Pays\Pay;

// 创建微信支付网关
$wechat = Pay::create('wechat', [
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

use Kode\Pays\Pay;

// 创建支付宝网关
$alipay = Pay::create('alipay', [
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

use Kode\Pays\Pay;

// 创建 PayPal 网关
$paypal = Pay::create('paypal', [
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

use Kode\Pays\Pay;

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

## 异步通知处理

```php
<?php

use Kode\Pays\Pay;
use Kode\Pays\Core\PayException;

// 获取通知数据
$data = $_POST;

// 创建对应网关
$gateway = Pay::create('wechat', [
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

// 验证签名
if ($gateway->verifyNotify($data)) {
    // 处理业务逻辑
    $orderId = $data['out_trade_no'];
    // ...

    // 返回成功响应
    echo '<xml><return_code><![CDATA[SUCCESS]]></return_code></xml>';
} else {
    echo '<xml><return_code><![CDATA[FAIL]]></return_code></xml>';
}
```

## 统一异常处理

```php
<?php

use Kode\Pays\Pay;
use Kode\Pays\Core\PayException;

try {
    $gateway = Pay::create('wechat', $config);
    $result = $gateway->createOrder($params);
} catch (PayException $e) {
    // 统一异常捕获
    echo '错误码：' . $e->getCode() . PHP_EOL;
    echo '错误信息：' . $e->getMessage() . PHP_EOL;
    echo '网关错误码：' . ($e->getGatewayCode() ?? '无') . PHP_EOL;
    echo '网关错误信息：' . ($e->getGatewayMessage() ?? '无') . PHP_EOL;
}
```

## 注册自定义网关

```php
<?php

use Kode\Pays\Pay;
use Kode\Pays\Contract\GatewayInterface;

// 自定义网关类
class MyGateway extends \Kode\Pays\Core\AbstractGateway
{
    public function createOrder(array $params): array { /* ... */ }
    public function queryOrder(string $orderId): array { /* ... */ }
    public function refund(array $params): array { /* ... */ }
    public function queryRefund(string $refundId): array { /* ... */ }
    public function verifyNotify(array $data): bool { /* ... */ }
    public function closeOrder(string $orderId): array { /* ... */ }
    public static function getName(): string { return 'mygateway'; }
    protected function getBaseUrl(): string { return 'https://api.example.com/'; }
    protected function parseResponse(string $response): array { return json_decode($response, true); }
}

// 注册到 SDK
Pay::register('mygateway', MyGateway::class);

// 使用自定义网关
$gateway = Pay::create('mygateway', ['key' => 'value']);
```
