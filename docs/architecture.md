# Kode Pays SDK 架构说明

## 整体架构

```
┌─────────────────────────────────────────────────────────────┐
│                      开发者调用层                             │
│              Pay::create('wechat')->createOrder()            │
├─────────────────────────────────────────────────────────────┤
│                      网关工厂层                               │
│                   GatewayFactory::create()                   │
├─────────────────────────────────────────────────────────────┤
│   接口层        │   抽象层         │   具体网关实现            │
│ GatewayInterface │ AbstractGateway │ Wechat/Alipay/Union...  │
├─────────────────────────────────────────────────────────────┤
│   支持层：HTTP客户端 / 签名器 / 加密器 / 工具类               │
├─────────────────────────────────────────────────────────────┤
│   插件层：支付 / 退款 / 分账 / 对账 / 转账 / 订阅             │
└─────────────────────────────────────────────────────────────┘
```

## 核心模块说明

### 1. 接口层 (Contract)

定义所有网关必须遵守的契约：

- `GatewayInterface`：支付网关核心接口，包含创建订单、查询、退款、通知验证等方法
- `PluginInterface`：插件接口，用于扩展分账、对账等功能
- `ConfigInterface`：配置接口，规范配置类结构

### 2. 核心层 (Core)

提供基础能力和通用逻辑：

- `AbstractGateway`：抽象网关基类，封装 HTTP 请求、配置读取、参数验证等通用能力
- `GatewayFactory`：网关工厂，负责根据标识自动实例化对应网关，支持动态注册
- `PayException`：统一异常类，包含错误码、网关原始错误信息，便于调用方统一处理

### 3. 网关层 (Gateway)

各支付平台的具体实现：

| 目录 | 说明 | 支持场景 |
|------|------|----------|
| `Wechat/` | 微信支付 | JSAPI、Native、App、H5、小程序 |
| `Alipay/` | 支付宝 | 电脑网站、手机网站、App、小程序、当面付 |
| `UnionPay/` | 云闪付 | App、H5、小程序、二维码 |
| `Douyin/` | 抖音支付 | App、小程序 |
| `Paypal/` | PayPal | Checkout、订阅 |
| `Aggregate/` | 聚合支付 | 多渠道自动路由、失败切换 |

### 4. 支持层 (Support)

通用工具类：

- `HttpClient`：基于 Guzzle 的 HTTP 封装，支持超时、SSL、JSON/表单等
- `Signer`：签名工具，支持 MD5、RSA、RSA2、HMAC-SHA256

### 5. 插件层 (Plugin)

扩展功能（预留）：

- 分账（Profit Sharing）
- 对账（Reconciliation）
- 转账（Transfer）
- 订阅（Subscription）

## 设计原则

### 面向接口编程

所有网关必须实现 `GatewayInterface`，确保调用方无感知切换渠道。

### 开闭原则

新增网关只需：
1. 在 `src/Gateway/` 下新建目录和类
2. 继承 `AbstractGateway` 实现 `GatewayInterface`
3. 在 `GatewayFactory::$gateways` 中注册

无需修改任何已有代码。

### 依赖注入

HTTP 客户端、配置均通过构造函数注入，便于单元测试时 Mock。

### 异常隔离

所有网关内部异常统一转换为 `PayException`，保留原始错误信息便于排查。

### 类型安全

严格使用 PHP 8.2+ 类型特性：
- `readonly` 属性用于不可变配置
- `nullsafe` 运算符
- Union Types
- `enum`（后续扩展）

## 扩展指南

### 新增支付网关

参考 `.trae/skills/pay-sdk-dev/SKILL.md` 中的详细步骤。

### 新增插件

实现 `PluginInterface`：

```php
class ProfitSharingPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'profit_sharing';
    }

    public function handle(GatewayInterface $gateway, array $params): array
    {
        // 调用网关分账 API
    }
}
```

## 安全规范

1. **密钥管理**：所有密钥通过配置注入，禁止硬编码
2. **敏感信息**：密钥、证书等禁止输出到日志
3. **签名验证**：所有通知必须验证签名
4. **HTTPS 强制**：生产环境强制校验 SSL 证书
