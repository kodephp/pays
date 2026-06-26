# Kode Pays SDK 文档导航

> Kode Pays - 企业级多平台聚合支付 SDK 官方文档

## 快速开始

- [快速开始指南](quickstart.md) - 5 分钟接入支付
- [安装与配置](quickstart.md#安装) - Composer 安装与基础配置

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
| QQ 支付 | [qq.md](qq.md) | `qq` |

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

### 东南亚区域支付

| 网关 | 文档 | 标识 |
|------|------|------|
| HitPay | [hitpay.md](hitpay.md) | `hitpay` |
| Xendit | [xendit.md](xendit.md) | `xendit` |

### 聚合支付

| 网关 | 文档 | 标识 |
|------|------|------|
| 聚合支付 | [aggregate.md](aggregate.md) | `aggregate` |

## 插件体系

- [插件总览](plugins.md) - 全部插件功能一览与使用示例
- [分账插件](plugins.md#分账插件-profitsharingplugin) - 微信/支付宝/Stripe 分账
- [转账插件](plugins.md#转账插件-transferplugin) - 企业付款/批量转账
- [退款插件](plugins.md#退款插件-refundplugin) - 统一退款管理
- [红包插件](plugins.md#红包插件-redpacketplugin) - 现金红包/裂变红包
- [订阅插件](plugins.md#订阅插件-subscriptionplugin) - 订阅计划与周期扣款
- [对账插件](plugins.md#对账插件-reconciliationplugin) - 对账单下载与差异比对
- [个人收款插件](plugins.md#个人收款插件-personalreceiveplugin) - 个人收款码/提现
- [自动结算插件](plugins.md#自动结算插件-autosettlementplugin) - 支付后自动提现
- [加密货币插件](plugins.md#加密货币插件-cryptoplugin) - 加密货币订单管理

## 核心组件

- [门面 Facade](architecture.md#22-门面层-facade) - `Pay` 静态类使用指南
- [事件系统](architecture.md#26-扩展层) - `EventDispatcher` 事件驱动
- [管道中间件](architecture.md#45-管道模式) - `Pipeline` 中间件栈
- [配置 DTO](development.md#2-实现配置-dto) - 只读配置对象
- [异常体系](architecture.md#7-异常体系) - 异常分类与处理
- [沙箱管理](architecture.md#26-扩展层) - `SandboxManager` 沙箱/生产环境切换
- [钱包管理器](architecture.md#29-管理层) - `WalletManager` 多账户钱包管理
- [资金约束验证器](architecture.md#29-管理层) - `FundConstraintValidator` 操作限额与风控

## 生态集成

> 以下生态集成通过 `src/Integration/` 目录适配，可按需启用。

- [kode/tools 集成](architecture.md#210-集成层) - 二维码生成与图片处理
- [kode/event 集成](architecture.md#210-集成层) - 事件系统对接
- [kode/exception 集成](architecture.md#210-集成层) - 异常对接
- [kode/limiting 集成](architecture.md#210-集成层) - 限流器
- [kode/cache 集成](architecture.md#210-集成层) - 缓存与分布式锁

## 高级主题

- [安全规范](architecture.md#8-安全设计) - 密钥管理、签名、HTTPS
- [性能优化](architecture.md#5-请求生命周期) - 连接池、缓存、异步
- [测试指南](development.md#测试规范) - 单元测试、Mock、覆盖率
- [部署运维](development.md#版本发布) - 监控、日志、告警

## 更新日志

请参阅 Git 提交历史与版本标签（`git tag -l`）查看各版本变更。
