# Kode Pays SDK 架构详解

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

### 2.2 门面层 (Facade)

`Pay` 门面类职责：
- 提供各网关的静态工厂方法
- 管理全局 HTTP 客户端和事件分发器
- 提供快捷工具方法（批量创建、轮询、配置加载）

### 2.3 网关工厂层

`GatewayFactory` 职责：
- 维护网关类名到类路径的映射
- 维护配置类名到类路径的映射
- 统一创建网关实例，注入依赖

```php
$gateway = GatewayFactory::create('wechat', $config);
```

### 2.4 接口层

`GatewayInterface` 定义所有网关必须实现的方法：

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

`AbstractGateway` 提供通用实现：
- HTTP 请求封装（get/post）
- 响应解析
- 必填参数验证
- 沙箱 URL 自动切换
- 请求头处理

### 2.6 扩展层

| 组件 | 职责 |
|------|------|
| EventDispatcher | 支付生命周期事件分发 |
| Pipeline | 请求参数中间件处理 |
| Config DTO | 类型安全的只读配置 |
| 异常子类 | 精细化异常分类 |
| SandboxManager | 沙箱/生产环境统一管理 |

### 2.7 支持层

| 组件 | 职责 |
|------|------|
| HttpClient | PSR-18 兼容的 HTTP 客户端 |
| Signer | MD5/RSA/HMAC 签名工具 |
| Encryptor | 敏感数据加密 |
| Validator | 参数验证工具 |

### 2.8 插件层

插件基于 `PluginInterface` 接口，通过组合网关实现扩展功能：

```php
interface PluginInterface
{
    public function setGateway(GatewayInterface $gateway): void;
}
```

### 2.9 管理层

| 组件 | 职责 |
|------|------|
| WalletManager | 多账户钱包绑定与管理 |
| FundConstraintValidator | 资金操作限额与风控验证 |
| AutoSettlementPlugin | 支付后自动结算到钱包 |

## 3. 核心设计模式

### 3.1 门面模式

简化复杂子系统的使用，提供统一入口。

### 3.2 工厂模式

`GatewayFactory` 根据标识符创建对应网关实例，新增网关无需修改调用代码。

### 3.3 策略模式

各网关实现相同的 `GatewayInterface`，可互换使用。插件通过 `match` 表达式根据网关名称选择对应实现。

### 3.4 观察者模式

`EventDispatcher` 实现事件驱动，支付生命周期关键节点触发事件，解耦日志、监控、业务通知。

### 3.5 管道模式

`Pipeline` 将请求参数依次通过多个中间件处理，支持签名、日志、加密、限流等横切关注点。

### 3.6 依赖注入

配置 DTO、HTTP 客户端、Logger、EventDispatcher 均通过构造注入，便于测试和扩展。

## 4. 请求生命周期

```
1. 开发者调用
   Pay::wechat($config)->createOrder($params)

2. 门面创建网关
   GatewayFactory::create('wechat', $config)

3. 网关初始化
   AbstractGateway::__construct()
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

8. 事件触发
   EventDispatcher::emit(Events::PAYMENT_SUCCESS, $result)

9. 返回结果
   return $result
```

## 5. 扩展点

### 5.1 新增支付网关

1. 创建 `src/Gateway/Example/` 目录
2. 实现 `ExampleConfig.php`（readonly DTO）
3. 实现 `ExampleGateway.php`（继承 AbstractGateway）
4. 注册到 `GatewayFactory::$gateways`
5. 注册沙箱 URL 到 `SandboxManager`
6. 创建 `docs/example.md` 文档

### 5.2 新增插件

1. 创建 `src/Plugin/ExamplePlugin.php`
2. 通过构造函数接收 `GatewayInterface`
3. 使用 `match` 根据网关名称实现多网关支持
4. 在 `README.md` 添加使用示例

### 5.3 新增中间件

1. 实现 `Pipeline\Middleware\MiddlewareInterface`
2. 在 `Pipeline::through()` 中使用

## 6. 安全设计

- 密钥绝不硬编码，通过配置注入
- 敏感信息（密钥、证书）禁止日志输出（LogMiddleware 自动脱敏）
- 签名验证必须强制开启
- HTTPS 强制校验证书
- 资金操作通过 FundConstraintValidator 进行限额风控
