# 更新日志

本项目所有重要变更均会记录在本文件中。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，并遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.15.0] - 2026-06-25

### 修复

- 修复 25 个 Config DTO `fromArray()` 返回类型与 `ConfigInterface` 不兼容的致命错误（`: self` → `: static`，`new self` → `new static`）
- 修复 `WechatPayGateway` V2 在 `createOrder`/`queryOrder`/`refund`/`queryRefund`/`closeOrder` 中对已解析数组二次调用 `parseResponse()` 导致的 `TypeError`
- 修复 `AggregateGateway` 未实现 `GatewayInterface` 的 `setDispatcher()`/`setHttpClient()` 方法导致的类加载致命错误
- `AggregateGateway` 构造函数增加空 `channels` 数组的快速失败校验
- 将 `AbstractGateway` 的 HTTP 请求方法（`get`/`post`/`put`/`delete`/`postRaw`）由 `protected` 改为 `public`，使 8 个插件（退款/转账/分账/对账/红包/订阅/个人收款/自动结算）可正常复用网关 HTTP 通道

### 新增

- 新增 7 个测试文件共 63 个测试方法，覆盖 Config DTO、Pipeline、EventDispatcher、Signer、WechatPayGateway、AggregateGateway、RefundPlugin
- 补充云闪付、抖音支付、PayPal、聚合支付网关接入文档（`docs/unionpay.md`、`docs/douyin.md`、`docs/paypal.md`、`docs/aggregate.md`）
- 恢复项目根目录 `CHANGELOG.md`（项目规则要求每次 PR 更新）

### 文档

- 修正 `docs/index.md` 中的失效链接，对尚未编写的文档标注「（规划中）」

## [1.14.1] - 2026-05-06

### 修复

- 对齐 `QrCodeGenerator` 与 `endroid/qr-code` v5 的实际 API（使用枚举常量），修复因 API 变更导致的二维码生成异常

## [1.14.0] - 2026-05-06

### 新增

- 将 `endroid/qr-code` 加入 `require` 依赖
- 增强 `Support\QrCodeGenerator`，统一支付二维码生成能力

## [1.13.0] - 2026-05-06

### 变更

- 移除 `CHANGELOG.md`（本次版本后续恢复）
- 增强 `composer.json` 关键字（keywords），覆盖更多支付场景关键词

## [1.12.0] - 2026-05-06

### 新增

- 新增 HitPay 网关（`Gateway\HitPay`）
- 新增 Xendit 网关（`Gateway\Xendit`）

### 修复

- 修复 PHP 8.2 兼容性问题

## [1.11.0] - 2026-04-26

### 新增

- 新增 QQ 支付网关（`Gateway\QQ`）

## [1.10.0] - 2026-04-26

### 新增

- 新增 `CryptoPlugin` 加密货币通用管理插件
- 增强 `Support\Encryptor` 加密工具
- 完善全局文档

## [1.9.0] - 2026-04-25

### 新增

- 新增 Coinbase Commerce 网关（`Gateway\Coinbase`）
- 新增 Afterpay / Clearpay 网关（`Gateway\Afterpay`）

## [1.8.0] - 2026-04-25

### 新增

- 新增 `Core\WalletManager` 多账户钱包管理器
- 新增 `Plugin\AutoSettlementPlugin` 支付后自动结算插件
- 新增 `Core\FundConstraintValidator` 资金约束验证器（操作限额与风控）

## [1.7.0] - 2026-04-25

### 新增

- 新增 `Plugin\TransferPlugin` 转账插件（企业付款 / 批量转账）
- 新增 `Plugin\ReconciliationPlugin` 对账插件（对账单下载与差异比对）
- 新增 `Plugin\RefundPlugin` 统一退款管理插件
- 新增 `Plugin\RedPacketPlugin` 红包插件（现金红包 / 裂变红包）
- 新增 `Plugin\PersonalReceivePlugin` 个人收款插件

## [1.6.0] - 2026-04-25

### 新增

- 新增 `Plugin\ProfitSharingPlugin` 分账插件，支持微信 / 支付宝 / Stripe 分账

## [1.5.0] - 2026-04-25

### 新增

- 新增 Wise 网关（`Gateway\Wise`）
- 新增 Revolut 网关（`Gateway\Revolut`）
- 新增 Payoneer 网关（`Gateway\Payoneer`）
- 新增 `Plugin\SubscriptionPlugin` 订阅插件（订阅计划与周期扣款）

## [1.4.0] - 2026-04-25

### 新增

- 新增 Amazon Pay 网关（`Gateway\Amazon`）
- 新增 Klarna 网关（`Gateway\Klarna`）
- 新增支付宝国际版网关（`Gateway\AlipayGlobal`）

## [1.3.0] - 2026-04-25

### 新增

- 新增京东支付网关（`Gateway\Jd`）
- 新增快手支付网关（`Gateway\Kuaishou`）
- 新增 Apple Pay 网关（`Gateway\Apple`）
- 新增 Google Pay 网关（`Gateway\Google`）

## [1.2.0] - 2026-04-25

### 新增

- 增强 `Support\HttpClient`：统一超时、HTTPS 证书校验、可注入自定义客户端
- 新增 `Core\IdempotencyGuard` 幂等保护器
- 新增 `Core\PaymentPoller` 支付结果轮询器
- 新增 `Config\ConfigLoader` 配置加载器（环境变量 / 文件 / 多环境）
- 新增 `Integration\KodeCacheAdapter` 缓存与分布式锁适配器
- 增强 `Facade\Pay` 门面：预注册配置、实例缓存、批量下单、轮询器、幂等保护

## [1.1.0] - 2026-04-25

### 新增

- 新增 Stripe 网关（`Gateway\Stripe`）
- 新增 Square 网关（`Gateway\Square`）
- 新增 Adyen 网关（`Gateway\Adyen`）
- 新增美团支付网关（`Gateway\Meituan`）
- 集成 kode 生态组件（tools / di / cache / database 等适配器）
- 架构健壮性增强：异常体系、事件系统、管道中间件、沙箱管理

[Unreleased]: https://github.com/kodephp/pays/compare/v1.15.0...HEAD
[1.15.0]: https://github.com/kodephp/pays/releases/tag/v1.15.0
[1.14.1]: https://github.com/kodephp/pays/releases/tag/v1.14.1
[1.14.0]: https://github.com/kodephp/pays/releases/tag/v1.14.0
[1.13.0]: https://github.com/kodephp/pays/releases/tag/v1.13.0
[1.12.0]: https://github.com/kodephp/pays/releases/tag/v1.12.0
[1.11.0]: https://github.com/kodephp/pays/releases/tag/v1.11.0
[1.10.0]: https://github.com/kodephp/pays/releases/tag/v1.10.0
[1.9.0]: https://github.com/kodephp/pays/releases/tag/v1.9.0
[1.8.0]: https://github.com/kodephp/pays/releases/tag/v1.8.0
[1.7.0]: https://github.com/kodephp/pays/releases/tag/v1.7.0
[1.6.0]: https://github.com/kodephp/pays/releases/tag/v1.6.0
[1.5.0]: https://github.com/kodephp/pays/releases/tag/v1.5.0
[1.4.0]: https://github.com/kodephp/pays/releases/tag/v1.4.0
[1.3.0]: https://github.com/kodephp/pays/releases/tag/v1.3.0
[1.2.0]: https://github.com/kodephp/pays/releases/tag/v1.2.0
[1.1.0]: https://github.com/kodephp/pays/releases/tag/v1.1.0
