# Kode Pays SDK 文档导航

> Kode Pays - 企业级多平台聚合支付 SDK 官方文档

## 快速开始

- [快速开始指南](../README.md) - 5 分钟接入支付
- [安装与配置](#) - Composer 安装与基础配置

## 架构设计

- [架构详解](architecture.md) - 分层架构、设计模式、核心流程
- [开发指南](development.md) - 如何新增网关、插件、中间件

## 支付网关

### 国内支付

| 网关 | 文档 | 标识 |
|------|------|------|
| 微信支付 | [wechat.md](wechat.md) | `wechat` |
| 支付宝 | [alipay.md](alipay.md) | `alipay` |
| 云闪付 | [unionpay.md](unionpay.md) | `unionpay` |
| 抖音支付 | [douyin.md](douyin.md) | `douyin` |
| 美团支付 | [meituan.md](meituan.md) | `meituan` |
| 京东支付 | [jd.md](jd.md) | `jd` |
| 快手支付 | [kuaishou.md](kuaishou.md) | `kuaishou` |

### 国际支付

| 网关 | 文档 | 标识 |
|------|------|------|
| PayPal | [paypal.md](paypal.md) | `paypal` |
| Stripe | [stripe.md](stripe.md) | `stripe` |
| Square | [square.md](square.md) | `square` |
| Adyen | [adyen.md](adyen.md) | `adyen` |
| Amazon Pay | [amazon.md](amazon.md) | `amazon` |
| Klarna | [klarna.md](klarna.md) | `klarna` |
| Afterpay/Clearpay | [afterpay.md](afterpay.md) | `afterpay` |

### 跨境支付

| 网关 | 文档 | 标识 |
|------|------|------|
| Wise | [wise.md](wise.md) | `wise` |
| Revolut | [revolut.md](revolut.md) | `revolut` |
| Payoneer | [payoneer.md](payoneer.md) | `payoneer` |
| 支付宝国际版 | [alipay_global.md](alipay_global.md) | `alipay_global` |

### 数字钱包

| 网关 | 文档 | 标识 |
|------|------|------|
| Apple Pay | [apple.md](apple.md) | `apple` |
| Google Pay | [google.md](google.md) | `google` |

### 加密货币

| 网关 | 文档 | 标识 |
|------|------|------|
| Coinbase Commerce | [coinbase.md](coinbase.md) | `coinbase` |

### 聚合支付

| 网关 | 文档 | 标识 |
|------|------|------|
| 聚合支付 | [aggregate.md](aggregate.md) | `aggregate` |

## 插件体系

> 以下插件专题文档为规划中，暂未编写，可先参考 [插件总览](plugins.md)。

- [插件总览](plugins.md) - 全部插件功能一览
- 分账插件（规划中） - 微信/支付宝/Stripe 分账
- 转账插件（规划中） - 企业付款/批量转账
- 退款插件（规划中） - 统一退款管理
- 红包插件（规划中） - 现金红包/裂变红包
- 订阅插件（规划中） - 订阅计划与周期扣款
- 对账插件（规划中） - 对账单下载与差异比对
- 个人收款插件（规划中） - 个人收款码/提现
- 自动结算插件（规划中） - 支付后自动提现
- 加密货币插件（规划中） - 加密货币通用管理

## 核心组件

> 以下核心组件专题文档为规划中，暂未编写。

- 门面 Facade（规划中） - Pay 静态类使用指南
- 事件系统（规划中） - EventDispatcher 事件驱动
- 管道中间件（规划中） - Pipeline 中间件栈
- 配置 DTO（规划中） - 只读配置对象
- 异常体系（规划中） - 异常分类与处理
- 沙箱管理（规划中） - 沙箱/生产环境切换
- 钱包管理器（规划中） - 多账户钱包管理
- 资金约束验证器（规划中） - 操作限额与风控

## 生态集成

> 以下生态集成文档为规划中，暂未编写。

- kode/tools 集成（规划中） - 二维码生成
- kode/event 集成（规划中） - 事件系统对接
- kode/exception 集成（规划中） - 异常对接
- kode/limiting 集成（规划中） - 限流器
- kode/cache 集成（规划中） - 缓存与分布式锁

## 高级主题

> 以下高级主题文档为规划中，暂未编写。

- 安全规范（规划中） - 密钥管理、签名、HTTPS
- 性能优化（规划中） - 连接池、缓存、异步
- 测试指南（规划中） - 单元测试、Mock、覆盖率
- 部署运维（规划中） - 监控、日志、告警

## 更新日志

- [CHANGELOG](../CHANGELOG.md) - 版本更新记录
