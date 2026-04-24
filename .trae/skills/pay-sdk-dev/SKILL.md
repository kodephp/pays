---
name: "pay-sdk-dev"
description: "Kode Pays SDK 支付包开发规范与架构指南。当用户需要新增支付网关、修改核心逻辑、添加插件或编写文档时调用。"
---

# Kode Pays SDK 开发规范

## 1. 架构总览

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

## 2. 核心设计模式

### 2.1 门面模式（Facade）

通过 `Kode\Pays\Facade\Pay` 提供静态方法快速创建网关：

```php
use Kode\Pays\Facade\Pay;

// 快速创建网关
$wechat = Pay::wechat(['app_id' => '...', 'mch_id' => '...', 'api_key' => '...']);
$alipay = Pay::alipay(['app_id' => '...', 'private_key' => '...', 'public_key' => '...']);

// 注册全局事件
Pay::on('pay.payment.success', function ($payload) {
    // 发送通知
});

// 触发事件
Pay::emit('pay.payment.success', ['order_id' => 'ORDER_001']);
```

门面还支持注入自定义 HTTP 客户端和事件分发器：

```php
Pay::setHttpClient($customHttpClient);
Pay::setDispatcher($customEventDispatcher);
```

### 2.2 沙箱管理（SandboxManager）

统一管理沙箱/生产环境切换：

```php
use Kode\Pays\Core\SandboxManager;

// 全局开启沙箱
SandboxManager::enableGlobal();

// 仅对微信开启沙箱
SandboxManager::enable('wechat');

// 判断是否沙箱
if (SandboxManager::isSandbox('wechat')) {
    $url = SandboxManager::getBaseUrl('wechat');
}

// 注册新网关的沙箱 URL
SandboxManager::registerUrl('stripe', 'https://api.stripe.com/', 'https://api.stripe.com/test/');
```

### 2.3 事件驱动（EventDispatcher）

```php
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Event\Events;

$dispatcher = new EventDispatcher();

$dispatcher->listen(Events::REQUEST_SENDING, function (array $payload) {
    // 记录请求日志
    return $payload;
});

$dispatcher->listen(Events::PAYMENT_SUCCESS, function (array $payload) {
    // 发送支付成功通知
    return $payload;
});
```

支持的事件常量定义在 `Kode\Pays\Event\Events` 中：
- `REQUEST_SENDING` - 请求发送前
- `REQUEST_SENT` - 请求发送后
- `RESPONSE_PARSED` - 响应解析后
- `PAYMENT_SUCCESS` - 支付成功
- `PAYMENT_FAILED` - 支付失败
- `NOTIFY_RECEIVED` - 异步通知接收
- `NOTIFY_VERIFIED` - 异步通知验证通过
- `EXCEPTION_OCCURRED` - 异常发生
- `REFUND_SUCCESS` - 退款成功

### 2.4 管道中间件（Pipeline）

```php
use Kode\Pays\Pipeline\Pipeline;
use Kode\Pays\Pipeline\Middleware\SignMiddleware;
use Kode\Pays\Pipeline\Middleware\LogMiddleware;

$pipeline = new Pipeline();

$result = $pipeline
    ->send($params)
    ->through([
        new SignMiddleware(['sign_type' => 'md5', 'key' => 'api_key']),
        new LogMiddleware($logger),
    ])
    ->then(function (array $params) {
        return $this->httpClient->post($url, $params);
    });
```

内置中间件：
- `SignMiddleware` - 自动签名（支持 md5/rsa/rsa2/hmac_sha256）
- `LogMiddleware` - 请求/响应日志（自动脱敏敏感字段）

### 2.5 配置 DTO（readonly）

```php
use Kode\Pays\Config\WechatConfig;

// 方式1：直接构造
$config = new WechatConfig(
    appId: 'wx123456',
    mchId: '123456',
    apiKey: 'your-key',
);

// 方式2：从数组创建（推荐）
$config = WechatConfig::fromArray([
    'app_id' => 'wx123456',
    'mch_id' => '123456',
    'api_key' => 'your-key',
]);
```

### 2.6 异常体系

| 异常类 | 场景 | 错误码 |
|--------|------|--------|
| `PayException` | 基类异常 | 1000 |
| `ConfigException` | 配置缺失/错误 | 1001 |
| `NetworkException` | 网络请求失败 | 1002 |
| `SignException` | 签名验证失败 | 1003 |
| `InvalidArgumentException` | 业务参数错误 | 1004 |
| `GatewayException` | 网关返回业务错误 | 1005 |

```php
use Kode\Pays\Exception\ConfigException;
use Kode\Pays\Exception\NetworkException;
use Kode\Pays\Exception\GatewayException;

try {
    $result = $gateway->createOrder($params);
} catch (ConfigException $e) {
    // 配置问题
} catch (NetworkException $e) {
    // 网络问题，可重试
} catch (GatewayException $e) {
    // 网关业务错误
    echo $e->getGatewayCode();
    echo $e->getGatewayMessage();
}
```

## 3. 新增支付网关步骤

### 3.1 创建目录和文件

```
src/Gateway/Example/
  ExampleGateway.php    # 主网关类
  ExampleConfig.php     # 配置 DTO（readonly）
```

### 3.2 实现配置 DTO

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Example;

use Kode\Pays\Contract\ConfigInterface;

readonly class ExampleConfig implements ConfigInterface
{
    public function __construct(
        public string $appId,
        public string $apiKey,
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            appId: $config['app_id'] ?? '',
            apiKey: $config['api_key'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'example';
    }
}
```

### 3.3 实现网关类

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Example;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;
use Kode\Pays\Exception\InvalidArgumentException;

class ExampleGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'api_key']);
    }

    protected function getBaseUrl(): string
    {
        // 优先使用 SandboxManager 的 URL
        $url = SandboxManager::getBaseUrl('example');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox
            ? 'https://sandbox.example.com/'
            : 'https://api.example.com/';
    }

    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount']);

        $response = $this->post('v1/pay', $requestData);

        return $response;
    }

    public function queryOrder(string $orderId): array { /* ... */ }
    public function refund(array $params): array { /* ... */ }
    public function queryRefund(string $refundId): array { /* ... */ }
    public function verifyNotify(array $data): bool { /* ... */ }
    public function closeOrder(string $orderId): array { /* ... */ }

    public static function getName(): string
    {
        return 'example';
    }

    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new GatewayException('响应格式异常');
        }

        if (($data['code'] ?? '') !== 'SUCCESS') {
            throw new GatewayException(
                $data['message'] ?? '业务失败',
                $data['code'] ?? '',
            );
        }

        return $data;
    }
}
```

### 3.4 注册到工厂

在 `src/Core/GatewayFactory.php` 的 `$gateways` 映射中添加：

```php
'example' => \Kode\Pays\Gateway\Example\ExampleGateway::class,
```

### 3.5 注册沙箱 URL（可选）

在 `src/Core/SandboxManager.php` 的 `$urlMap` 中添加：

```php
'example' => [
    'prod' => 'https://api.example.com/',
    'sandbox' => 'https://sandbox.example.com/',
],
```

## 4. HTTP 客户端规范

- 默认使用 `Support\HttpClient`（基于 Guzzle，实现 `HttpClientInterface`）
- 支持注入自定义客户端（如带重试、熔断、代理的客户端）
- 所有请求默认超时 30 秒，连接超时 10 秒
- 生产环境强制 HTTPS 证书校验

## 5. 签名规范

- 统一使用 `Support\Signer` 工具类
- 支持 MD5、RSA、RSA2、HMAC-SHA256
- 签名前参数按 key 升序排序
- 空值和签名字段本身不参与签名
- 可通过 `SignMiddleware` 自动附加签名

## 6. 生态扩展

Kode Pays 预留了与 kode 系列组件的集成扩展点：

| 扩展包 | 功能 | 集成方式 |
|--------|------|----------|
| `kode/tools` | 二维码生成、图片处理 | 支付码生成 |
| `kode/di` | 依赖注入容器 | 网关/中间件自动注入 |
| `kode/cache` | 缓存、分布式锁 | 订单防重、缓存证书 |
| `kode/database` | ORM、分库分表 | 订单持久化 |
| `monolog/monolog` | PSR-3 日志 | 支付日志记录 |

## 7. 文档规范

每新增一个网关，必须在 `docs/` 下创建对应文档：

```
docs/
  wechat.md      # 微信支付接入文档
  alipay.md      # 支付宝接入文档
  ...
```

文档必须包含：
1. 环境要求
2. 安装方法
3. 配置说明（含 DTO 字段列表）
4. 快速开始示例
5. API 方法列表
6. 异步通知处理
7. 常见问题

## 8. 测试规范

每个网关必须配套测试：

```
tests/
  Gateway/
    Example/
      ExampleGatewayTest.php
```

测试要求：
- Mock HTTP 响应（注入自定义 HttpClient）
- 覆盖正常流程和异常流程
- 签名验证必须测试正反例
- 配置 DTO 测试 `fromArray()` 边界情况
- 沙箱模式测试 URL 切换
