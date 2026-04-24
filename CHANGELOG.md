# 更新日志

所有版本更新均遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.2.0] - 2026-04-25

### 新增

- **核心架构增强**
  - `HttpClient` 增强：支持重试策略（`setRetry`）、请求日志（`setLogger`）、超时配置链式设置
  - `ConfigLoader` 配置加载器：支持环境变量、JSON/PHP 文件、多环境配置自动加载
  - `IdempotencyGuard` 订单幂等性保护器：分布式锁 + 结果缓存，防止重复支付
  - `PaymentPoller` 支付结果轮询器：支持同步/异步轮询，自动提取状态

- **门面 Pay 增强**
  - `batchCreate()` 批量创建多网关订单
  - `poller()` 快速创建支付轮询器
  - `guard()` 快速创建幂等性保护器
  - `fromEnv()` / `fromFile()` / `fromEnvConfig()` 多来源配置加载

- **kode 生态集成**
  - `KodeCacheAdapter` 缓存集成适配器：支持缓存读写、分布式锁（对接 kode/cache）

- **接口完善**
  - `GatewayInterface` 新增 `setDispatcher()` 和 `setHttpClient()` 声明

## [1.1.0] - 2026-04-25

### 新增

- **国际支付网关**
  - Stripe（PaymentIntent、Checkout Session、退款、Webhook）
  - Square（Payments API、Orders API、退款）
  - Adyen（Sessions API、Payments API、全球 250+ 支付方式）

- **生活服务支付网关**
  - 美团支付（App、外卖、小程序）

- **kode 生态集成增强**
  - `KodeEventAdapter` 事件系统对接 kode/event，自动注入门面
  - `KodeExceptionAdapter` 增强异常上报、链路追踪、分布式监控
  - `KodeLimitingAdapter` 限流器对接 kode/limiting（令牌桶/漏桶/滑动窗口/固定窗口）
  - `KodeToolsCryptoAdapter` 加解密对接 kode/tools（AES/RSA/3DES）

- **架构健壮性增强**
  - `Validator` 链式参数校验器（必填/类型/范围/枚举/正则/URL/邮箱/日期/自定义）
  - `PayResponse` 增强通用字段解析（金额/状态/时间/买家/退款）
  - `AbstractGateway` 新增 `validator()` 和 `wrapResponse()` 便捷方法

- **文档**
  - `docs/stripe.md` Stripe 接入文档
  - `docs/square.md` Square 接入文档
  - `docs/adyen.md` Adyen 接入文档
  - `docs/meituan.md` 美团支付接入文档

## [1.0.0] - 2024-01-01

### 新增

- **多平台支付网关支持**
  - 微信支付（JSAPI、Native、App、H5、小程序）
  - 支付宝（电脑网站、手机网站、App、小程序、当面付）
  - 云闪付（App、H5、小程序、二维码）
  - 抖音支付（App、小程序）
  - PayPal（Checkout、订阅）
  - 聚合支付（多渠道自动路由、失败切换）

- **核心架构**
  - 面向接口编程：所有网关实现 `GatewayInterface`
  - 抽象网关基类 `AbstractGateway`：统一 HTTP 请求、事件触发、管道中间件
  - 网关工厂 `GatewayFactory`：支持配置 DTO 自动转换、动态注册
  - 门面模式 `Facade\Pay`：静态方法快速创建网关，支持配置缓存

- **配置 DTO（readonly）**
  - `WechatConfig`、`AlipayConfig`、`UnionPayConfig`
  - `DouyinConfig`、`PaypalConfig`
  - 统一 `fromArray()` 工厂方法

- **事件驱动**
  - `EventDispatcher` 事件分发器
  - `Events` 常量定义（请求前/后、支付成功/失败、通知接收等）

- **管道中间件**
  - `Pipeline` 管道模式
  - `SignMiddleware` 自动签名（MD5/RSA/RSA2/HMAC-SHA256）
  - `LogMiddleware` 请求/响应日志（自动脱敏敏感字段）

- **异常体系**
  - `PayException` 基类异常
  - `ConfigException`、`NetworkException`、`SignException`
  - `InvalidArgumentException`、`GatewayException`

- **沙箱管理**
  - `SandboxManager` 统一管理沙箱/生产环境
  - 支持全局和按网关独立控制

- **插件系统**
  - `ProfitSharingPlugin` 分账插件接口
  - `TransferPlugin` 转账插件接口
  - `ReconciliationPlugin` 对账插件接口

- **工具类**
  - `ArrayUtil` 数组工具（排序、过滤、键名转换、路径访问）
  - `StrUtil` 字符串工具（随机生成、掩码、订单号生成、金额转换）
  - `DateUtil` 日期时间工具（格式化、过期计算、对账日期）
  - `Signer` 签名工具（MD5/RSA/RSA2/HMAC-SHA256）

- **kode 生态集成适配器**
  - `KodeExceptionAdapter` 异常链路追踪
  - `KodeFacadeAdapter` 门面静态代理
  - `KodeFiberAdapter` 协程批量并发（支持 kode/fibers、Swoole、PHP Fiber）
  - `KodeProcessAdapter` 多进程并行处理
  - `KodeParallelAdapter` 多线程并行处理
  - `KodeToolsAdapter` 二维码生成（支持 kode/tools、endroid/qr-code）

- **异步通知处理**
  - `AsyncNotifyHandler` 异步通知处理器
  - 支持并发批量处理通知

- **测试基础设施**
  - `MockHttpClient` Mock HTTP 客户端
  - `TestCase` 测试基类

### 文档

- 完整的 `README.md` 使用文档
- `docs/architecture.md` 架构说明
- `docs/quickstart.md` 快速开始指南
- `.trae/rules/project_rules.md` 项目规则
- `.trae/skills/pay-sdk-dev/SKILL.md` 开发规范

### 许可证

- Apache-2.0 License
