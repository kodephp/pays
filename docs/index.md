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

- [插件总览](plugins.md) - 全部插件功能一览
- [分账插件](profit_sharing.md) - 微信/支付宝/Stripe 分账
- [转账插件](transfer.md) - 企业付款/批量转账
- [退款插件](refund.md) - 统一退款管理
- [红包插件](red_packet.md) - 现金红包/裂变红包
- [订阅插件](subscription.md) - 订阅计划与周期扣款
- [对账插件](reconciliation.md) - 对账单下载与差异比对
- [个人收款插件](personal_receive.md) - 个人收款码/提现
- [自动结算插件](auto_settlement.md) - 支付后自动提现
- [加密货币插件](crypto.md) - 加密货币通用管理

## 核心组件

- [门面 Facade](facade.md) - Pay 静态类使用指南
- [事件系统](events.md) - EventDispatcher 事件驱动
- [管道中间件](pipeline.md) - Pipeline 中间件栈
- [配置 DTO](config.md) - 只读配置对象
- [异常体系](exception.md) - 异常分类与处理
- [沙箱管理](sandbox.md) - 沙箱/生产环境切换
- [钱包管理器](wallet_manager.md) - 多账户钱包管理
- [资金约束验证器](fund_constraint.md) - 操作限额与风控

## 生态集成

- [kode/tools 集成](integration_tools.md) - 二维码生成
- [kode/event 集成](integration_event.md) - 事件系统对接
- [kode/exception 集成](integration_exception.md) - 异常对接
- [kode/limiting 集成](integration_limiting.md) - 限流器
- [kode/cache 集成](integration_cache.md) - 缓存与分布式锁

## 高级主题

- [安全规范](security.md) - 密钥管理、签名、HTTPS
- [性能优化](performance.md) - 连接池、缓存、异步
- [测试指南](testing.md) - 单元测试、Mock、覆盖率
- [部署运维](operations.md) - 监控、日志、告警

## 更新日志

- [CHANGELOG](../CHANGELOG.md) - 版本更新记录
