# Kode Pays SDK 架构详解

本文档描述 Kode Pays SDK 的分层架构、目录职责、核心设计模式与请求生命周期，便于开发者快速理解全貌并找到扩展点。

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
├─────────────────────────────────────────────────────────────┤
│   管理层：钱包管理器 / 资金约束验证器 / 自动结算               │
├─────────────────────────────────────────────────────────────┤
│   集成层：kode 系列组件适配器（DI/Cache/Event/Limiting...）   │
├─────────────────────────────────────────────────────────────┤
│   异步层：AsyncNotifyHandler（协程/多进程通知处理）            │
└─────────────────────────────────────────────────────────────┘
```

## 2. 分层说明

### 2.1 开发者调用层

开发者通过门面类 `Pay` 的静态方法快速创建网关实例：

```php
use Kode\Pays\Facade\Pay;

// 一行代码创建微信支付网关
$wechat = Pay::wechat(['app_id' => '...', 'mch_id' => '...', 'api_key' => '...']);

// 直接使用网关方法
$result = $wechat->createOrder($params);
```

SDK 提供两个入口：

- `Kode\Pays\Pay` —— 简化入口，仅提供 `create()` / `register()` 等基础方法
- `Kode\Pays\Facade\Pay` —— 完整门面，提供事件监听、批量创建、轮询、配置缓存、自定义 HTTP 客户端等能力

### 2.2 门面层 (Facade)

`Kode\Pays\Facade\Pay` 门面类职责：

- 通过 `__callStatic` 魔术方法支持 `Pay::wechat($config)`、`Pay::alipay($config)` 等任意网关的快捷调用
- 提供通用方法：`create()` / `createWithConfig()` / `createAutoConfig()`
- 管理全局 HTTP 客户端（`setHttpClient()`）和事件分发器（`setDispatcher()`）
- 提供事件监听注册：`on()` / `emit()`
- 提供快捷工具方法：
  - `registerConfig()` —— 预注册配置，后续无参快速创建
  - `batchCreate()` —— 批量创建支付订单
  - `poller()` —— 创建支付结果轮询器
  - `guard()` —— 创建幂等性保护器
  - `fromEnv()` / `fromFile()` / `fromEnvConfig()` —— 多种配置加载方式

### 2.3 网关工厂层

`Kode\Pays\Core\GatewayFactory` 职责：

- 维护网关类名到类路径的映射（`$gateways`）
- 维护配置 DTO 类名到类路径的映射（`$configs`）
- 统一创建网关实例，注入配置与 HTTP 客户端
- 支持配置 DTO 自动转换（`createAutoConfig()`）
- 支持自定义网关注册与注销（`register()` / `unregister()`）

```php
// 数组配置创建
$gateway = GatewayFactory::create('wechat', $config);

// 配置 DTO 创建
$gateway = GatewayFactory::createWithConfig('wechat', $wechatConfig);
```

### 2.4 接口层

`Kode\Pays\Contract` 目录定义 SDK 所有核心契约：

| 接口 | 职责 |
|------|------|
| `GatewayInterface` | 所有支付网关必须实现的方法 |
| `ConfigInterface` | 配置 DTO 必须实现的工厂方法 |
| `HttpClientInterface` | HTTP 客户端抽象（便于替换为自定义实现） |
| `PluginInterface` | 插件契约（`getName()` / `handle()`） |

`GatewayInterface` 定义的核心方法：

```php
interface GatewayInterface
{
    public function createOrder(array $params): array;
    public function queryOrder(string $orderId): array;
    public function refund(array $params): array;
    public function queryRefund(string $refundId): array;
    public function verifyNotify(array $data): bool;
    public function closeOrder(string $orderId): array;
    public static function getName(): string;
}
```

### 2.5 抽象层

`Kode\Pays\Core\AbstractGateway` 提供通用实现：

- HTTP 请求封装（`get()` / `post()`）
- 响应解析（`parseResponse()`，子类覆写）
- 必填参数验证（`validateRequired()`）
- 沙箱 URL 自动切换（结合 `SandboxManager`）
- 请求头处理与签名注入
- 配置注入与初始化钩子（`initialize()`）

子类只需实现 7 个核心方法 + `parseResponse()` 即可接入新网关。

### 2.6 扩展层

`Kode\Pays\Core` 与 `Kode\Pays\Event`、`Kode\Pays\Pipeline` 共同构成扩展层：

| 组件 | 目录 | 职责 |
|------|------|------|
| `EventDispatcher` | `Event/` | 支付生命周期事件分发，支持优先级 |
| `Events` | `Event/` | 事件常量定义（PAYMENT_SUCCESS 等） |
| `Pipeline` | `Pipeline/` | 请求参数中间件处理 |
| `SignMiddleware` | `Pipeline/Middleware/` | 自动签名（md5/rsa/rsa2/hmac_sha256） |
| `LogMiddleware` | `Pipeline/Middleware/` | 请求/响应日志（自动脱敏敏感字段） |
| `RetryMiddleware` | `Pipeline/Middleware/` | 失败自动重试 |
| `RateLimitMiddleware` | `Pipeline/Middleware/` | 限流保护 |
| Config DTO | `Config/` + `Gateway/*/Config` | 类型安全的只读配置（`readonly` + `fromArray()`） |
| 异常子类 | `Exception/` | 精细化异常分类（见下文异常体系） |
| `SandboxManager` | `Core/` | 沙箱/生产环境统一管理 |

### 2.7 支持层

`Kode\Pays\Support` 提供基础工具：

| 组件 | 职责 |
|------|------|
| `HttpClient` | 基于 Guzzle 的 PSR-18 风格 HTTP 客户端 |
| `Signer` | MD5/RSA/RSA2/HMAC-SHA256 签名工具 |
| `Encryptor` | 敏感数据加密 |
| `Validator` | 参数验证工具 |
| `QrCodeGenerator` | 二维码生成（基于 endroid/qr-code） |
| `ArrayUtil` / `StrUtil` / `DateUtil` | 通用工具 |

### 2.8 插件层

`Kode\Pays\Plugin` 目录包含 9 个内置插件：

| 插件 | 支持网关 | 核心功能 |
|------|----------|----------|
| `ProfitSharingPlugin` | 微信、支付宝、Stripe | 分账创建/查询/回退/解冻 |
| `TransferPlugin` | 微信、支付宝、Stripe | 单笔/批量转账、电子回单 |
| `RefundPlugin` | 微信、支付宝、Stripe、PayPal | 申请/查询/取消退款 |
| `RedPacketPlugin` | 微信、支付宝 | 普通/裂变红包、查询记录 |
| `SubscriptionPlugin` | Stripe、PayPal | 订阅计划与周期扣款管理 |
| `ReconciliationPlugin` | 微信、支付宝、Stripe | 对账单下载/解析/差异比对 |
| `PersonalReceivePlugin` | 微信、支付宝、Stripe | 个人收款码/记录查询/提现 |
| `AutoSettlementPlugin` | 微信、支付宝、Stripe、PayPal | 支付后自动结算到钱包 |
| `CryptoPlugin` | Coinbase | 加密货币订单/链上确认/汇率 |

插件通过组合（构造函数接收 `GatewayInterface`）而非继承扩展网关能力，使用 `match` 表达式按网关名称分发到具体实现：

```php
class ExamplePlugin
{
    public function __construct(
        protected GatewayInterface $gateway,
        protected ?FundConstraintValidator $validator = null,
    ) {
    }

    public function doSomething(array $params): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->doWechatSomething($params),
            'alipay' => $this->doAlipaySomething($params),
            default  => throw PayException::invalidArgument('当前网关不支持此功能'),
        };
    }
}
```

### 2.9 管理层

`Kode\Pays\Core` 中的管理类：

| 组件 | 职责 |
|------|------|
| `WalletManager` | 多账户钱包绑定与管理，支持结算方式配置 |
| `FundConstraintValidator` | 资金操作限额与风控验证（转账/红包约束） |
| `AutoSettlementPlugin` | 与 `WalletManager` 协作完成支付后自动结算 |
| `IdempotencyGuard` | 幂等性保护，防止重复创建订单 |
| `PaymentPoller` | 支付结果轮询器，定时查询订单状态 |
| `PayResponse` | 统一响应包装，简化业务判断 |

### 2.10 集成层

`Kode\Pays\Integration` 目录提供与 kode 系列组件的可选适配器，按需引入：

| 适配器 | 集成的扩展包 | 功能 |
|--------|--------------|------|
| `KodeToolsAdapter` | `kode/tools` | 二维码生成、图片处理 |
| `KodeToolsCryptoAdapter` | `kode/tools` | 加密货币辅助工具 |
| `KodeEventAdapter` | `kode/event` | 事件总线增强 |
| `KodeExceptionAdapter` | `kode/exception` | 异常链追踪与监控上报 |
| `KodeLimitingAdapter` | `kode/limiting` | 限流保护（令牌桶/漏桶/滑动窗口） |
| `KodeCacheAdapter` | `kode/cache` | 缓存与分布式锁 |
| `KodeFacadeAdapter` | `kode/facade` | 门面模式增强 |
| `KodeFiberAdapter` | `kode/fibers` | 协程异步处理 |
| `KodeProcessAdapter` | `kode/process` | 多进程处理 |
| `KodeParallelAdapter` | `kode/parallel` | 多线程并行 |

适配器均为可选依赖，未安装对应扩展包时不会影响核心功能。

### 2.11 异步层

`Kode\Pays\Async` 目录：

| 组件 | 职责 |
|------|------|
| `AsyncNotifyHandler` | 同步/协程两种模式处理支付网关异步通知 |

```php
use Kode\Pays\Async\AsyncNotifyHandler;

$handler = new AsyncNotifyHandler();

// 同步处理单条通知
$result = $handler->handle($gateway, $_POST, function ($data) {
    // 业务处理
    return true;
});

// 协程模式批量并发处理（需安装 kode/fibers 或 Swoole）
$results = $handler->handleConcurrent($tasks, $callback);
```

## 3. 目录结构

```
src/
├── Pay.php                    # 简化入口（基础工厂方法）
├── Facade/
│   └── Pay.php                # 完整门面（事件/批量/轮询/配置缓存）
├── Contract/                  # 接口定义
├── Core/                      # 核心抽象、工厂、异常基类、沙箱、钱包、约束
├── Config/                    # 通用配置 DTO 与 ConfigLoader
├── Event/                     # 事件系统
├── Pipeline/                  # 管道模式与中间件
├── Exception/                 # 具体业务异常子类
├── Gateway/                   # 各支付网关实现（含网关专属 Config）
├── Support/                   # 工具类、HTTP 客户端、签名、加密
├── Plugin/                    # 9 个内置扩展插件
├── Async/                     # 异步通知处理
└── Integration/               # kode 系列组件适配器
```

## 4. 核心设计模式

### 4.1 门面模式

简化复杂子系统的使用，提供统一入口。`Facade\Pay` 屏蔽了工厂、配置、HTTP、事件等子系统的细节。

### 4.2 工厂模式

`GatewayFactory` 根据标识符创建对应网关实例，新增网关无需修改调用代码，符合开闭原则。

### 4.3 策略模式

各网关实现相同的 `GatewayInterface`，可互换使用。插件通过 `match` 表达式根据网关名称选择对应实现。

### 4.4 观察者模式

`EventDispatcher` 实现事件驱动，支付生命周期关键节点触发事件，解耦日志、监控、业务通知。

### 4.5 管道模式

`Pipeline` 将请求参数依次通过多个中间件处理，支持签名、日志、加密、限流等横切关注点：

```php
use Kode\Pays\Pipeline\Pipeline;
use Kode\Pays\Pipeline\Middleware\SignMiddleware;
use Kode\Pays\Pipeline\Middleware\LogMiddleware;

$result = (new Pipeline())
    ->send($params)
    ->through([
        new SignMiddleware(['sign_type' => 'md5', 'key' => 'api_key']),
        new LogMiddleware($logger),
    ])
    ->then(function (array $params) {
        return $this->httpClient->post($url, $params);
    });
```

### 4.6 依赖注入

配置 DTO、HTTP 客户端、Logger、EventDispatcher 均通过构造注入，便于测试和扩展。网关构造函数支持传入自定义 `HttpClient` 以便 Mock 测试。

### 4.7 不可变对象

配置 DTO 优先使用 `readonly` 属性，通过 `fromArray()` 工厂方法创建，确保配置在生命周期内不可变，避免运行时被意外修改。

## 5. 请求生命周期

```
1. 开发者调用
   Pay::wechat($config)->createOrder($params)

2. 门面创建网关
   Facade\Pay::__callStatic('wechat', [$config])
   └── GatewayFactory::create('wechat', $config)

3. 网关初始化
   AbstractGateway::__construct($config, $httpClient)
   └── initialize() 验证配置

4. 参数处理
   createOrder($params)
   └── validateRequired() 验证必填

5. 管道处理（可选）
   Pipeline::send($params)->through([SignMiddleware, LogMiddleware])

6. HTTP 请求
   HttpClient::post($url, $params)

7. 响应解析
   parseResponse($rawResponse)
   └── 失败抛出 GatewayException

8. 事件触发
   EventDispatcher::dispatch(Events::PAYMENT_SUCCESS, $result)

9. 返回结果
   return $result
```

## 6. 扩展点

### 6.1 新增支付网关

1. 创建 `src/Gateway/Example/` 目录
2. 实现 `ExampleConfig.php`（readonly DTO，实现 `ConfigInterface`）
3. 实现 `ExampleGateway.php`（继承 `AbstractGateway`，实现 7 个核心方法）
4. 注册到 `GatewayFactory::$gateways` 与 `$configs`
5. 注册沙箱 URL 到 `SandboxManager`
6. 创建 `docs/example.md` 文档
7. 编写 `tests/Gateway/Example/ExampleGatewayTest.php`

详细步骤见 [开发指南](development.md)。

### 6.2 新增插件

1. 创建 `src/Plugin/ExamplePlugin.php`
2. 通过构造函数接收 `GatewayInterface`（可选注入 `FundConstraintValidator`）
3. 使用 `match` 根据网关名称实现多网关支持
4. 在 `docs/plugins.md` 与 `README.md` 添加使用示例

### 6.3 新增中间件

1. 在 `src/Pipeline/Middleware/` 创建 `ExampleMiddleware.php`
2. 实现 `MiddlewareInterface`
3. 在 `Pipeline::through()` 中使用

## 7. 异常体系

`Kode\Pays\Exception` 与 `Kode\Pays\Core\PayException` 共同构成异常体系：

| 异常类 | 场景 | 错误码 |
|--------|------|--------|
| `PayException` | 基类异常（位于 `Core/`） | 1000 |
| `ConfigException` | 配置缺失/错误 | 1001 |
| `NetworkException` | 网络请求失败 | 1002 |
| `SignException` | 签名验证失败 | 1003 |
| `InvalidArgumentException` | 业务参数错误 | 1004 |
| `GatewayException` | 网关返回业务错误 | 1005 |

所有网关异常均会被捕获并转换为 `PayException` 子类，业务层只需捕获 `PayException` 即可统一处理，也可分别捕获进行差异化处理。

```php
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

## 8. 安全设计

- **密钥绝不硬编码**，通过配置注入或环境变量加载（`ConfigLoader::fromEnv()`）
- **敏感信息禁止日志输出**，`LogMiddleware` 自动脱敏 api_key、private_key、cert_pwd 等字段
- **签名验证强制开启**，`verifyNotify()` 必须校验签名后才返回 `true`
- **HTTPS 强制校验证书**，生产环境关闭 `verify` 选项会触发警告
- **资金操作风控**，`FundConstraintValidator` 支持配置金额上下限、日限额、时段限制、黑名单
- **幂等性保护**，`IdempotencyGuard` 防止重复创建订单
