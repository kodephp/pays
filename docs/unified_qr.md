# 统一收款码路由器

> UnifiedQrRouter + OrderMonitorDaemon：一个二维码兼容多支付通道的聚合收款方案。

## 1. 设计背景

### 1.1 个人收款码的局限

市面常见的"个人收款码"方案直接出示静态二维码（支付宝/微信个人收款码），
让用户扫码转账。这种方案的根本问题：

| 问题 | 说明 |
|------|------|
| 无订单关联 | 静态码不携带订单号，无法通过官方 API 查询收款结果 |
| 多用户混淆 | 多人同时扫码，无法区分哪笔款项对应哪个用户 |
| 风控限制 | 个人收款码频繁收款易触发反洗钱风控，被限额或封停 |
| 无法自动化 | 付款后需要人工核对账单，无法自动通知业务系统 |

### 1.2 统一收款码方案

本组件采用"**聚合入口码 + 动态下单 + 后台轮询**"的正规商户扫码方案：

```
┌──────────────────────────────────────────────────────────────────────┐
│  1. 商家出示统一收款码（一个二维码兼容多通道）                         │
│      ↓                                                                │
│  2. 用户扫码进入 H5，选择支付通道（微信/支付宝/...）                   │
│      ↓                                                                │
│  3. 后端 UnifiedQrRouter.route() 调用对应网关 createOrder 下动态订单   │
│      ↓                                                                │
│  4. 网关返回动态订单二维码（带订单号），用户扫码支付                    │
│      ↓                                                                │
│  5. OrderMonitorDaemon 后台进程持续 queryOrder 抓取状态                │
│      ↓                                                                │
│  6. 收款成功后：自动 markPaid + 触发业务回调（通知、发货、对账）       │
└──────────────────────────────────────────────────────────────────────┘
```

### 1.3 与个人收款码对比

| 维度 | 个人收款码（静态码） | 统一收款码（本方案） |
|------|----------------------|---------------------|
| 二维码类型 | 静态个人收款码 | 动态商户订单码 |
| 订单关联 | 无法关联 | out_trade_no 唯一关联 |
| API 收款结果 | 无法获取 | 通过 queryOrder 抓取 |
| 多用户区分 | 人工核对 | 自动匹配 |
| 商户资质要求 | 个人即可 | 需要商户号 |
| 风控风险 | 高（易封停） | 低（合规商户） |
| 异步通知 | 不支持 | 网关原生异步通知 |

## 2. 核心组件

### 2.1 UnifiedQrRouter 统一收款码路由器

`src/Core/UnifiedQrRouter.php` 负责：

1. **生成统一入口码**：`createEntry()` 生成 `router_id` 与入口 URL
2. **路由下单**：`route()` 根据用户选择的通道，调用对应网关 `createOrder` 生成动态订单码
3. **状态管理**：`markPaid()` / `markClosed()` / `getStatus()` / `getPendingEntries()`

入口状态机：

```
   ┌─────────┐ route()  ┌─────────┐ markPaid() ┌──────┐
   │ pending ├─────────►│ ordered ├───────────►│ paid │
   └─────────┘          └─────────┘            └──────┘
        │                    │
        │                    │ markClosed()
        └────────────────────►
                       ┌────────┐
                       │ closed │
                       └────────┘
```

### 2.2 OrderMonitorDaemon 订单监控守护进程

`src/Core/OrderMonitorDaemon.php` 是用户强调的"**另外进程一直获取状态**"核心组件：

- 在独立进程内持续轮询多笔订单的支付状态
- 避免每笔订单各自 fork 子进程导致资源浪费
- 支持前台 `run()` 与后台 `runInBackground()`（pcntl_fork）两种模式
- 收款成功后自动调用 `UnifiedQrRouter::markPaid()` 并触发业务回调

每个监控任务可独立配置：

| 选项 | 说明 | 默认值 |
|------|------|--------|
| `interval` | 轮询间隔（秒） | 5 |
| `timeout` | 单笔订单总超时（秒） | 600 |
| `max_attempts` | 最大查询次数 | 120 |
| `on_success` | 成功回调 `fn($paymentData, $routerId)` | - |
| `on_failure` | 失败回调 `fn($reason, $paymentData, $routerId)` | - |
| `on_timeout` | 超时回调 `fn($lastData, $routerId)` | - |

## 3. 快速开始

### 3.1 基本使用

```php
use Kode\Pays\Core\UnifiedQrRouter;
use Kode\Pays\Core\OrderMonitorDaemon;

// 1. 配置各通道网关
$router = new UnifiedQrRouter([
    'wechat' => ['app_id' => 'wx1', 'mch_id' => 'm1', 'api_key' => 'k1'],
    'alipay' => ['app_id' => 'a1', 'private_key' => '...', 'alipay_public_key' => '...'],
]);

// 2. 商家出示统一收款码（一个二维码）
$entry = $router->createEntry(['wechat', 'alipay'], 100, '商品付款');
// $entry['qr_content'] = 'https://pay.kodephp.com/r/UR20260627...'
// 渲染为二维码图片展示给用户

// 3. 用户扫码进入 H5 → 选择通道 → 后端路由下单
$order = $router->route($entry['router_id'], 'wechat');
// 返回: ['out_trade_no' => 'UO20260627...', 'code_url' => 'weixin://wxpay/...']
// 将 code_url 渲染为动态二维码让用户扫

// 4. 注册后台监控
$daemon = new OrderMonitorDaemon($router);
$daemon->register($entry['router_id'], 'wechat', [
    'out_trade_no' => $order['out_trade_no'],
    'total_fee' => 100,
], [
    'interval' => 5,
    'timeout' => 600,
    'on_success' => function ($paymentData, $routerId) {
        // 通知业务系统、自动发货、记录对账...
        file_put_contents('paid.log', json_encode($paymentData, JSON_UNESCAPED_UNICODE));
    },
    'on_failure' => function ($reason, $data, $routerId) {
        // 通知失败原因
    },
]);

// 5. 启动后台守护进程（推荐 supervisor 托管）
$pid = $daemon->runInBackground();
// 或前台阻塞：$daemon->run();
```

### 3.2 业务回调通知 API

收款成功后，业务方通常需要将结果响应给前端/客户端：

```php
$daemon->register($routerId, 'wechat', $order, [
    'on_success' => function ($paymentData, $routerId) {
        // 1. 写入业务订单表
        // 2. 推送 WebSocket / 长轮询通知前端
        // 3. 触发发货流程
        // 4. 写入对账记录
    },
]);

// 前端查询状态接口
$app->get('/api/pay/status/{routerId}', function ($routerId) use ($router) {
    $status = $router->getStatus($routerId);
    return json_encode([
        'status' => $status['status'] ?? 'unknown',
        'paid_at' => $status['paid_at'] ?? null,
    ]);
});
```

## 4. 架构原理

### 4.1 完整时序图

```
商家           用户           后端          网关API         监控守护进程
 │              │              │              │              │
 │ createEntry  │              │              │              │
 ├─────────────────────────────►              │              │
 │ ◄──router_id─┤              │              │              │
 │              │              │              │              │
 │  出示二维码   │              │              │              │
 ├─────────────►│              │              │              │
 │              │ 扫码进入H5    │              │              │
 │              ├─────────────►│              │              │
 │              │ 选择通道      │              │              │
 │              │              │ route()      │              │
 │              │              ├─────────────►│              │
 │              │              │ ◄──code_url──┤              │
 │              │ ◄──code_url──┤              │              │
 │              │              │              │              │
 │              │  扫动态码支付 │              │              │
 │              ├─────────────────────────────►│              │
 │              │              │              │              │
 │              │              │  register() │              │
 │              │              ├──────────────────────────────►
 │              │              │              │              │
 │              │              │              │ queryOrder()  │
 │              │              │              │ ◄─────────────┤
 │              │              │              │ (轮询直至终态) │
 │              │              │              │              │
 │              │              │  ◄──on_success(paymentData)─┤
 │              │              │ markPaid()   │              │
 │              │ ◄──状态推送──┤              │              │
```

### 4.2 防重放与数据一致性

`UnifiedQrRouter` 与 `OrderMonitorDaemon` 配合 `PersonalReceiveVerifier` 提供四维验证：

| 维度 | 实现 | 防御 |
|------|------|------|
| 签名验证 | `GatewayInterface::verifyNotify()` | 伪造通知 |
| 订单号匹配 | `out_trade_no` 比对 | 错单匹配 |
| 金额匹配 | `total_fee` / `total_amount` 比对 | 金额篡改 |
| 时间戳防重放 | `time_end` / `gmt_payment` 窗口校验 | 重放攻击 |

## 5. 生产部署

### 5.1 supervisor 托管守护进程

```ini
# /etc/supervisor/conf.d/pays-monitor.conf
[program:pays-monitor]
command=php /var/www/app/monitor.php
process_name=%(program_name)s
autostart=true
autorestart=true
startsecs=5
startretries=3
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/pays-monitor.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=10
```

`monitor.php` 脚本：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Pays\Core\UnifiedQrRouter;
use Kode\Pays\Core\OrderMonitorDaemon;

// 从 Redis 队列消费待监控订单
$router = new UnifiedQrRouter(require __DIR__ . '/config/gateways.php');
$daemon = new OrderMonitorDaemon($router);

// 持续运行，新订单从 Redis 注册
while (true) {
    $order = $redis->blPop('pays:pending_orders', 5);
    if ($order) {
        $daemon->register($order['router_id'], $order['channel'], $order['data']);
    }
    $daemon->scanOnce();
}
```

### 5.2 多进程扩展

进程内内存注册表仅适用于单进程。多进程场景需要替换为 Redis 实现：

```php
class RedisOrderMonitorDaemon extends OrderMonitorDaemon
{
    public function register(string $routerId, string $channel, array $order, array $options = []): void
    {
        // 写入 Redis hash + sorted set（按下次轮询时间排序）
        $this->redis->hset("monitor:$routerId", 'data', json_encode([...]));
        $this->redis->zadd('monitor:queue', time() + $options['interval'], $routerId);
    }

    public function scanOnce(?array &$stats = null): int
    {
        // 从 Redis zset 取出到期订单
        $dueIds = $this->redis->zrangebyscore('monitor:queue', 0, time());
        // ...
    }
}
```

## 6. 测试

`tests/Core/UnifiedQrRouterTest.php` 与 `tests/Core/OrderMonitorDaemonTest.php` 提供完整单元测试覆盖：

- 入口创建与参数校验
- 路由下单与多通道兼容（微信 code_url / 支付宝 qr_code / Stripe payment_link）
- 状态机流转（pending → ordered → paid/closed）
- 幂等保护（已下单入口重复调用）
- 守护进程注册/注销/查询
- scanOnce 终态处理（成功/失败/超时）
- interval 节流与多订单并发扫描
- 自动调用 `markPaid` 联动

```bash
vendor/bin/phpunit tests/Core/UnifiedQrRouterTest.php tests/Core/OrderMonitorDaemonTest.php
```

## 7. 风险与限制

### 7.1 商户资质要求

统一收款码方案要求每个通道都有正规商户号，**不支持个人收款码**：
- 微信支付需要商户号（个体户也可申请）
- 支付宝需要企业账户或个体工商户
- Stripe 需要 Business 账户

### 7.2 内存注册表

进程内 `$monitors` / `$entries` 仅适用于单进程。生产环境推荐：

- 使用 Redis hash 存储入口与监控状态
- 使用 Redis sorted set 按下次轮询时间排序
- 使用 Redis pub/sub 通知其他进程状态变更

### 7.3 网络异常处理

守护进程已内置容错：
- 网关返回"订单不存在"异常时继续等待（订单可能尚未生效）
- 其他异常记录到 `last_data` 但不中断守护进程
- 通过 `max_attempts` 与 `timeout` 双重保护避免无限轮询

## 8. 与 PersonalReceiveVerifier 的关系

| 组件 | 职责 |
|------|------|
| `UnifiedQrRouter` | 入口码生成、路由下单、状态管理 |
| `OrderMonitorDaemon` | 多订单并发轮询、终态处理、回调触发 |
| `PersonalReceiveVerifier` | 单订单一次性验证 / 进程内轮询验证（四维校验） |

三者协作模式：
- `UnifiedQrRouter` + `OrderMonitorDaemon`：聚合入口码方案（推荐）
- `PersonalReceiveVerifier`：单通道订单验证（独立使用）

详见 [personal_receive.md](personal_receive.md) 了解个人收款验证器细节。
