# Kode Pays SDK

Kode Pays 是一个面向 PHP 8.2+ 的企业级多平台聚合支付 SDK，支持微信、支付宝、云闪付、抖音支付、美团支付、京东支付、快手支付、支付宝国际版、PayPal、Stripe、Square、Adyen、Amazon Pay、Klarna、Wise、Revolut、Payoneer、Apple Pay、Google Pay 等国内外主流支付渠道。采用事件驱动、管道中间件、门面模式等现代架构设计，让开发者能够快速、安全、可扩展地接入各种支付能力。

## 特性

- **多平台支持**：微信、支付宝、云闪付、抖音支付、美团支付、京东支付、快手支付、支付宝国际版、PayPal、Stripe、Square、Adyen、Amazon Pay、Klarna、Wise、Revolut、Payoneer、Apple Pay、Google Pay、聚合支付
- **统一接口**：所有网关实现同一接口，切换渠道对业务代码完全无感知
- **聚合路由**：支持多渠道配置，自动优先级路由和失败切换
- **门面模式**：`Pay::wechat($config)` 一行代码创建网关
- **沙箱管理**：全局/按网关独立控制沙箱环境，测试不扣真实资金
- **事件驱动**：支付生命周期各阶段触发事件，解耦日志/监控/通知
- **管道中间件**：请求参数通过中间件栈处理，支持签名、日志、加密等
- **类型安全**：充分利用 PHP 8.2+ 特性（`readonly`、`match`、`enum`）
- **异常细分**：6 种具体异常子类，便于精确捕获和差异化处理
- **中文注释**：所有代码和文档均为中文，降低国内开发者学习成本
- **生态兼容**：预留 kode 系列扩展点（二维码、协程、缓存、数据库等）

## 环境要求

- PHP >= 8.2
- ext-json
- ext-openssl
- Composer

## 安装

```bash
composer require kode/pays
```

### 可选扩展（推荐）

```bash
# 支付二维码生成
composer require kode/tools

# 依赖注入容器
composer require kode/di

# 订单缓存与分布式锁
composer require kode/cache

# 订单持久化与分库分表
composer require kode/database

# 协程支持
composer require kode/fibers

# 多进程支持
composer require kode/process

# 多线程支持
composer require kode/parallel

# 限流保护
composer require kode/limiting

# 异常处理增强
composer require kode/exception

# 门面模式增强
composer require kode/facade

# 日志记录（PSR-3 兼容）
composer require monolog/monolog
```

## 快速开始

### 门面模式快速接入

```php
<?php

use Kode\Pays\Facade\Pay;

// 微信支付
$wechat = Pay::wechat([
    'app_id'  => 'wx1234567890abcdef',
    'mch_id'  => '1234567890',
    'api_key' => 'your-api-key-here',
]);

$result = $wechat->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_fee'    => 100,
    'body'         => '测试商品',
    'trade_type'   => 'NATIVE',
    'notify_url'   => 'https://your-domain.com/notify/wechat',
]);

// 获取支付二维码链接
$codeUrl = $result['code_url'] ?? '';
```

### 支付宝

```php
<?php

use Kode\Pays\Facade\Pay;

$alipay = Pay::alipay([
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => '-----BEGIN RSA PRIVATE KEY-----\n...',
    'public_key'  => '-----BEGIN PUBLIC KEY-----\n...',
]);

$result = $alipay->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '0.01',
    'subject'      => '测试商品',
    'notify_url'   => 'https://your-domain.com/notify/alipay',
    'return_url'   => 'https://your-domain.com/return',
]);

// 跳转到支付宝收银台
header('Location: ' . $result['url']);
```

### PayPal

```php
<?php

use Kode\Pays\Facade\Pay;

$paypal = Pay::paypal([
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
]);

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
```

### Stripe

```php
<?php

use Kode\Pays\Facade\Pay;

$stripe = Pay::stripe([
    'secret_key' => 'sk_test_...',
]);

// PaymentIntent 方式
$result = $stripe->createOrder([
    'amount'   => 1000,
    'currency' => 'usd',
    'metadata' => ['order_id' => 'ORDER_001'],
]);

// Checkout Session 方式
$session = $stripe->createCheckoutSession([
    'line_items' => [[
        'price_data' => [
            'currency' => 'usd',
            'product_data' => ['name' => '测试商品'],
            'unit_amount'  => 1000,
        ],
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => 'https://your-domain.com/success',
    'cancel_url'  => 'https://your-domain.com/cancel',
]);
```

### Square

```php
<?php

use Kode\Pays\Facade\Pay;

$square = Pay::square([
    'access_token' => 'EAAA...',
    'environment'  => 'sandbox',
]);

$result = $square->createOrder([
    'amount'   => 100,
    'currency' => 'USD',
    'note'     => '测试商品',
    'source_id' => 'cnon:card-nonce-ok',
]);
```

### Adyen

```php
<?php

use Kode\Pays\Facade\Pay;

$adyen = Pay::adyen([
    'api_key'        => 'AQE1hmfxJ...',
    'merchant_account' => 'YourMerchantAccount',
    'environment'    => 'test',
]);

$result = $adyen->createOrder([
    'amount' => [
        'value'    => 1000,
        'currency' => 'USD',
    ],
    'reference'       => 'ORDER_001',
    'returnUrl'       => 'https://your-domain.com/return',
    'countryCode'     => 'US',
]);
```

### 美团支付

```php
<?php

use Kode\Pays\Facade\Pay;

$meituan = Pay::meituan([
    'app_id'      => 'mt123456',
    'app_secret'  => 'your-app-secret',
    'merchant_id' => 'M123456',
]);

$result = $meituan->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_fee'    => 100,
    'body'         => '美团外卖订单',
    'notify_url'   => 'https://your-domain.com/notify/meituan',
    'trade_type'   => 'APP',
]);

$payUrl = $result['pay_url'] ?? '';
```

### 京东支付

```php
<?php

use Kode\Pays\Facade\Pay;

$jd = Pay::jd([
    'merchant_no' => 'JD123456',
    'des_key'     => 'your-des-key',
    'md5_key'     => 'your-md5-key',
]);

$result = $jd->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '10.00',
    'subject'      => '京东商品',
    'notify_url'   => 'https://your-domain.com/notify/jd',
    'trade_type'   => 'APP',
]);
```

### 快手支付

```php
<?php

use Kode\Pays\Facade\Pay;

$kuaishou = Pay::kuaishou([
    'app_id'      => 'ks123456',
    'app_secret'  => 'your-app-secret',
    'merchant_id' => 'M123456',
]);

$result = $kuaishou->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 100,
    'subject'      => '快手小店商品',
    'notify_url'   => 'https://your-domain.com/notify/kuaishou',
    'trade_type'   => 'MINI_PROGRAM',
]);
```

### Apple Pay

```php
<?php

use Kode\Pays\Facade\Pay;

$apple = Pay::apple([
    'merchant_identifier'      => 'merchant.com.yourdomain',
    'merchant_certificate'     => file_get_contents('/path/to/cert.pem'),
    'merchant_certificate_key' => file_get_contents('/path/to/key.pem'),
    'apple_pay_merchant_id'    => 'your_apple_merchant_id',
    'domain_name'              => 'your-domain.com',
]);

$result = $apple->createOrder([
    'out_trade_no'  => 'ORDER_' . date('YmdHis'),
    'total_amount'  => '10.00',
    'currency'      => 'CNY',
    'payment_token' => $frontendPaymentToken,
]);
```

### Google Pay

```php
<?php

use Kode\Pays\Facade\Pay;

$google = Pay::google([
    'merchant_id'         => 'BCR2DN4T7ZTLKJ3H',
    'merchant_name'       => 'Your Store Name',
    'gateway_merchant_id' => 'your_gateway_merchant_id',
    'environment'         => 'TEST',
]);

$result = $google->createOrder([
    'out_trade_no'  => 'ORDER_' . date('YmdHis'),
    'total_amount'  => '10.00',
    'currency'      => 'USD',
    'payment_token' => $frontendPaymentToken,
]);
```

### Amazon Pay

```php
<?php

use Kode\Pays\Facade\Pay;

$amazon = Pay::amazon([
    'merchant_id' => 'A2QEXAMPLE123',
    'access_key'  => 'AKIA...',
    'secret_key'  => 'your-secret-key',
    'client_id'   => 'amzn1.application-oa2-client.xxx',
    'region'      => 'na',
]);

$result = $amazon->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '10.00',
    'currency'     => 'USD',
    'amazon_order_reference_id' => 'S01-1234567-1234567',
]);
```

### Klarna

```php
<?php

use Kode\Pays\Facade\Pay;

$klarna = Pay::klarna([
    'username' => 'PK12345_abc123...',
    'password' => 'your-api-password',
    'region'   => 'eu',
]);

$result = $klarna->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 100.00,
    'currency'     => 'EUR',
    'country'      => 'DE',
    'items' => [
        ['name' => 'T-Shirt', 'quantity' => 1, 'price' => 50.00],
        ['name' => 'Jeans', 'quantity' => 1, 'price' => 50.00],
    ],
]);
```

### 支付宝国际版

```php
<?php

use Kode\Pays\Facade\Pay;

$alipayGlobal = Pay::alipayGlobal([
    'app_id'      => '2024xxxxxx',
    'private_key' => file_get_contents('/path/to/private_key.pem'),
    'public_key'  => file_get_contents('/path/to/public_key.pem'),
    'sign_type'   => 'RSA2',
]);

$result = $alipayGlobal->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => '10.00',
    'currency'     => 'USD',
    'subject'      => '跨境商品',
    'notify_url'   => 'https://your-domain.com/notify/alipay_global',
]);
```

### Wise

```php
<?php

use Kode\Pays\Facade\Pay;

$wise = Pay::wise([
    'api_key'    => 'your-api-key',
    'profile_id' => '12345678',
]);

$result = $wise->createOrder([
    'out_trade_no'    => 'ORDER_' . date('YmdHis'),
    'source_currency' => 'GBP',
    'target_currency' => 'EUR',
    'amount'          => 100.00,
    'recipient'       => [
        'currency' => 'EUR',
        'type'     => 'iban',
        'accountHolderName' => 'John Doe',
        'details'  => [
            'iban' => 'DE89370400440532013000',
        ],
    ],
]);
```

### Revolut

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
]);
```

### Payoneer

```php
<?php

use Kode\Pays\Facade\Pay;

$payoneer = Pay::payoneer([
    'api_key'    => 'your-api-key',
    'api_secret' => 'your-api-secret',
    'program_id' => 'your-program-id',
]);

$result = $payoneer->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'amount'       => 100.00,
    'currency'     => 'USD',
    'payee_id'     => 'payee_123456',
    'description'  => 'Payoneer 付款',
]);
```

### 聚合支付（多渠道自动切换）

```php
<?php

use Kode\Pays\Facade\Pay;

$aggregate = Pay::aggregate([
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

// 自动选择可用渠道
$result = $aggregate->createOrder([
    'out_trade_no' => 'ORDER_001',
    'total_fee'    => 100,
    'body'         => '测试商品',
]);

// 返回结果包含实际使用的渠道
$channel = $result['_channel']; // wechat 或 alipay
```

## 沙箱环境管理

```php
<?php

use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Facade\Pay;

// 全局开启沙箱（所有网关）
SandboxManager::enableGlobal();

// 仅对微信开启沙箱
SandboxManager::enable('wechat');

// 检查当前环境
if (SandboxManager::isSandbox('wechat')) {
    echo '当前为微信沙箱环境' . PHP_EOL;
}

// 获取沙箱 URL
$url = SandboxManager::getBaseUrl('wechat');

// 沙箱模式下创建订单不会扣真实资金
$wechat = Pay::wechat([...]);
$result = $wechat->createOrder([...]);
```

## 事件系统

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Event\Events;

// 注册支付成功监听器
Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    $orderId = $payload['order_id'];
    $amount  = $payload['amount'];

    // 发送支付成功通知（邮件/短信/站内信）
    // 与支付逻辑完全解耦
});

// 注册异常监听器
Pay::on(Events::EXCEPTION_OCCURRED, function (array $payload) {
    $exception = $payload['exception'];

    // 上报监控（Sentry/钉钉/企业微信）
});

// 注册请求日志监听器
Pay::on(Events::REQUEST_SENDING, function (array $payload) {
    // 记录请求参数
    return $payload;
});
```

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

// 获取通知数据
$data = $_POST;

// 创建对应网关
$gateway = Pay::wechat([
    'app_id'  => 'wx123456',
    'mch_id'  => '123456',
    'api_key' => 'your-api-key',
]);

// 验证签名
if ($gateway->verifyNotify($data)) {
    // 处理业务逻辑
    $orderId = $data['out_trade_no'];

    // 返回成功响应
    echo '<xml><return_code><![CDATA[SUCCESS]]></return_code></xml>';
} else {
    echo '<xml><return_code><![CDATA[FAIL]]></return_code></xml>';
}
```

## 协程并发处理（需安装 kode/fibers 或 Swoole）

```php
<?php

use Kode\Pays\Async\AsyncNotifyHandler;
use Kode\Pays\Facade\Pay;

$handler = new AsyncNotifyHandler();

// 批量并发处理通知
$tasks = [
    ['gateway' => Pay::wechat($config1), 'data' => $notify1],
    ['gateway' => Pay::alipay($config2), 'data' => $notify2],
    ['gateway' => Pay::paypal($config3), 'data' => $notify3],
];

$results = $handler->handleConcurrent($tasks, function ($data) {
    // 处理业务逻辑
    return true;
});
```

## 异常处理

```php
<?php

use Kode\Pays\Exception\ConfigException;
use Kode\Pays\Exception\NetworkException;
use Kode\Pays\Exception\SignException;
use Kode\Pays\Exception\GatewayException;
use Kode\Pays\Exception\InvalidArgumentException;

try {
    $result = $gateway->createOrder($params);
} catch (ConfigException $e) {
    // 配置缺失或错误，需检查配置文件
} catch (NetworkException $e) {
    // 网络超时或连接失败，可重试
} catch (SignException $e) {
    // 签名验证失败，可能密钥错误或数据被篡改
} catch (InvalidArgumentException $e) {
    // 业务参数错误，需返回给用户
} catch (GatewayException $e) {
    // 网关返回业务错误
    echo '网关错误码：' . ($e->getGatewayCode() ?? '无') . PHP_EOL;
    echo '网关错误信息：' . ($e->getGatewayMessage() ?? '无') . PHP_EOL;
}
```

## 支持的支付网关

| 网关 | 标识 | 支持场景 |
|------|------|----------|
| 微信支付 | `wechat` | JSAPI、Native、App、H5、小程序 |
| 支付宝 | `alipay` | 电脑网站、手机网站、App、小程序、当面付 |
| 云闪付 | `unionpay` | App、H5、小程序、二维码 |
| 抖音支付 | `douyin` | App、小程序 |
| 美团支付 | `meituan` | App、外卖、小程序 |
| 京东支付 | `jd` | App、网页、白条 |
| 快手支付 | `kuaishou` | App、小程序 |
| 支付宝国际版 | `alipay_global` | 跨境支付、Alipay+ |
| PayPal | `paypal` | Checkout、订阅 |
| Stripe | `stripe` | PaymentIntent、Checkout Session、退款 |
| Square | `square` | 在线支付、订单管理 |
| Adyen | `adyen` | 全球 200+ 国家、250+ 支付方式 |
| Amazon Pay | `amazon` | 亚马逊账户支付 |
| Klarna | `klarna` | 先买后付、分期付款 |
| Apple Pay | `apple` | iOS App、网页、手表 |
| Google Pay | `google` | Android App、网页 |
| Wise | `wise` | 跨境汇款、50+ 货币 |
| Revolut | `revolut` | 数字银行支付、卡支付、Apple Pay、Google Pay |
| Payoneer | `payoneer` | 跨境 B2B 支付、200+ 国家 |
| 聚合支付 | `aggregate` | 多渠道自动路由、失败切换 |

## 架构设计

```
┌─────────────────────────────────────────────────────────────┐
│                      开发者调用层                             │
│         Pay::wechat($config)->createOrder()                  │
├─────────────────────────────────────────────────────────────┤
│                      门面层 (Facade)                          │
│                      Pay 静态类                               │
├─────────────────────────────────────────────────────────────┤
│                      网关工厂层                               │
│                   GatewayFactory::create()                   │
├─────────────────────────────────────────────────────────────┤
│   接口层        │   抽象层         │   具体网关实现            │
│ GatewayInterface │ AbstractGateway │ Wechat/Alipay/Union...  │
├─────────────────────────────────────────────────────────────┤
│   扩展层：事件系统 / 管道中间件 / 配置DTO / 异常子类 / 沙箱管理  │
├─────────────────────────────────────────────────────────────┤
│   支持层：HTTP客户端 / 签名器 / 加密器 / 工具类               │
├─────────────────────────────────────────────────────────────┤
│   插件层：支付 / 退款 / 分账 / 对账 / 转账 / 订阅             │
└─────────────────────────────────────────────────────────────┘
```

## 开发规范

本项目遵循 PSR-12 代码规范，使用 `declare(strict_types=1)` 严格模式。

```bash
# 代码检查
composer run phpcs

# 静态分析
composer run phpstan

# 运行测试
composer run test
```

## 生态扩展

Kode Pays SDK 预留了与 kode 系列组件的集成扩展点：

| 扩展包 | 功能 | 集成方式 |
|--------|------|----------|
| `kode/tools` | 二维码生成、图片处理 | 支付码生成 |
| `kode/di` | 依赖注入容器 | 网关/中间件自动注入 |
| `kode/cache` | 缓存、分布式锁 | 订单防重、缓存证书 |
| `kode/database` | ORM、分库分表 | 订单持久化 |
| `kode/fibers` | 协程支持 | 异步通知并发处理 |
| `kode/process` | 多进程支持 | 批量任务处理 |
| `kode/parallel` | 多线程支持 | 并行计算 |
| `kode/exception` | 异常处理增强 | 异常链追踪、分布式监控上报 |
| `kode/facade` | 门面模式增强 | 静态代理 |
| `kode/limiting` | 限流保护 | 支付接口限流（令牌桶/漏桶/滑动窗口） |
| `kode/event` | 事件总线增强 | 增强事件分发能力 |
| `monolog/monolog` | PSR-3 日志 | 支付日志记录 |

## License

Apache-2.0 License
