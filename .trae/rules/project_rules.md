# Kode Pays SDK 项目规则

## 项目定位
- PHP 8.2+ 多平台支付聚合 SDK Composer 包
- 命名空间：`Kode\Pays`
- 包名：`kode/pays`
- 许可证：Apache-2.0
- 不与 PHP 原生类/函数/常量命名冲突

## 命名规范
- 类名：大驼峰（`WechatPayGateway`）
- 接口名：大驼峰 + `Interface` 后缀（`GatewayInterface`）
- 抽象类：大驼峰 + `Abstract` 前缀（`AbstractGateway`）
- 方法名：小驼峰（`createOrder`）
- 属性名：小驼峰（`$apiKey`）
- 常量：大写下划线（`VERSION`）
- 中文注释：所有类、方法、复杂逻辑必须写中文注释

## 目录结构
```
src/
  Contract/          # 接口定义
  Core/              # 核心抽象、配置、异常基类、沙箱管理
  Config/            # 各网关配置 DTO（readonly）
  Event/             # 事件系统（EventDispatcher + 事件常量）
  Pipeline/          # 管道模式（中间件栈）
  Exception/         # 具体业务异常子类
  Facade/            # 门面类（Pay 统一入口）
  Gateway/           # 各支付网关实现
    Wechat/          # 微信支付
    Alipay/          # 支付宝
    UnionPay/        # 云闪付
    Douyin/          # 抖音支付
    Paypal/          # PayPal
    Aggregate/       # 聚合支付（多家）
  Support/           # 工具类、HTTP、签名、加密
  Plugin/            # 扩展插件（分账、退款、对账等）
tests/               # 单元测试
docs/                # 开发文档
examples/            # 示例代码
```

## 开发原则
1. **面向接口编程**：所有网关必须实现 `GatewayInterface`，HTTP 客户端实现 `HttpClientInterface`
2. **单一职责**：每个类只做一件事（配置 DTO、请求构造、响应解析、签名工具分离）
3. **开闭原则**：新增网关不修改已有代码，通过配置/工厂自动加载
4. **依赖注入**：配置 DTO、HTTP 客户端、Logger、EventDispatcher 均通过构造注入
5. **异常隔离**：所有网关异常统一转换为 `PayException`，按场景使用具体子类
6. **类型安全**：严格使用 PHP 8.2+ 类型声明（`readonly`、`nullsafe`、`union types`、`enum`）
7. **不可变对象**：配置类优先使用 `readonly` 属性，通过 `fromArray()` 工厂方法创建
8. **事件驱动**：支付生命周期关键节点触发事件（请求前/后、支付成功/失败、通知接收等），解耦日志/监控/业务通知
9. **管道中间件**：请求参数通过 Pipeline 处理，支持签名、日志、加密、限流等横切关注点
10. **门面模式**：提供 `Pay::wechat($config)` 等静态方法，降低开发者使用门槛
11. **沙箱隔离**：`SandboxManager` 统一管理沙箱/生产环境，支持全局和按网关独立控制

## 代码风格
- 遵循 PSR-12
- 使用 `declare(strict_types=1);`
- 禁止在库代码中使用 `echo`、`var_dump`、`die`
- 所有对外方法必须标注 `@throws`
- 复杂数组结构使用 DTO 代替

## 安全规范
- 密钥绝不硬编码，必须通过配置注入
- 敏感信息（密钥、证书）禁止日志输出（`LogMiddleware` 自动脱敏）
- 签名验证必须强制开启
- HTTPS 强制校验证书

## 测试要求
- 所有网关核心方法必须有单元测试
- 使用 PHPUnit 10+
- Mock HTTP 请求，不发起真实支付请求

## 版本管理
- 语义化版本（SemVer）
- 每次 PR 必须更新 CHANGELOG.md

## 生态扩展
- 预留 `kode/tools` 二维码生成集成点
- 预留 `kode/di` 依赖注入容器集成点
- 预留 `kode/cache` 缓存与分布式锁集成点
- 预留 `kode/database` 订单持久化集成点
- 预留 Fiber/协程异步通知处理扩展点
