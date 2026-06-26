# PersonalReceiveVerifier 个人收款验证器

## 概述

`PersonalReceiveVerifier` 是 Kode Pays SDK 提供的个人收款数据验证组件，定位为「在进程内或后台子进程中抓取/轮询收款数据，并验证收款是否与本地订单匹配」的轻量级守护层。

它解决个人/小微商户收款场景下的几个核心痛点：

- **到账通知真伪难辨**：网关异步回调可能被伪造、篡改或重放，必须经过签名、订单号、金额、时间戳四重校验后才可信任。
- **轮询确认繁琐**：扫码支付、聚合收款等场景下用户付款存在时延，需要持续轮询网关查询接口才能拿到最终状态。
- **重复处理风险**：同一笔订单可能被通知多次回调或被多个进程同时抓取，必须有幂等保护避免发货、入账被重复执行。
- **进程阻塞与 Web 响应冲突**：业务侧既需要后台持续监控收款，又希望 Web 请求立即响应客户端，二者需要解耦。

`PersonalReceiveVerifier` 通过统一的 API 同时覆盖上述场景，与 [`PersonalReceivePlugin`](plugins.md#个人收款插件-personalreceiveplugin) 配合使用：插件负责生成收款码、查询记录、提现，验证器负责把「抓到的收款数据」与「本地订单」做一致性校验并触发后续业务回调。

> 类位置：`Kode\Pays\Core\PersonalReceiveVerifier`
> 命名空间：`Kode\Pays\Core`

## 安装与依赖

### 环境要求

| 依赖项 | 版本 | 说明 |
|--------|------|------|
| PHP | 8.3+ | 使用 `readonly` 属性、`nullsafe`、union types 等语法 |
| `ext-json` | 内置 | 解析网关响应 |
| `ext-pcntl` | 可选 | 仅 `monitorInBackground()` 需要，未安装时自动降级 |
| `kode/pays` | 本包 | 主包 |
| `kode/cache`（可选） | - | `IdempotencyGuard` 启用时推荐安装，未安装时回退到进程内内存锁 |

### 依赖关系说明

- 验证器只依赖 `Kode\Pays\Contract\GatewayInterface`（必选）与 `Kode\Pays\Core\IdempotencyGuard`（可选）。
- `IdempotencyGuard` 内部通过 `Kode\Pays\Integration\KodeCacheAdapter` 适配 `kode/cache`；未安装 `kode/cache` 时退化为单进程内存锁，此时跨进程幂等无法保证，仅在单进程内有效。
- `pcntl` 扩展未编译进 PHP 时，`monitorInBackground()` 会返回 `false` 而非抛异常，调用方应据此降级。

### Composer 安装

```bash
composer require kode/pays
# 可选：分布式锁与跨进程幂等
composer require kode/cache
# 可选：后台进程监控
docker-php-ext-install pcntl
```

## 核心概念

### 验证维度

`verify()` 在一次性校验中按以下顺序执行四重检查，任一不通过即返回 `STATUS_FAILED`：

| 顺序 | 维度 | 校验内容 | 失败信息示例 |
|------|------|----------|--------------|
| 1 | 签名 | 调用 `GatewayInterface::verifyNotify()`，由网关实现具体算法（微信 HMAC-SHA256、支付宝 RSA2、Stripe Webhook 签名） | 签名验证失败 |
| 2 | 订单号 | `extractOrderNo()` 提取并比较本地订单与收款数据的 `out_trade_no` / `order_id` / `metadata.out_trade_no` / `partner_trade_no`，大小写不敏感 | 订单号不匹配：期望 X，实际 Y |
| 3 | 金额 | `extractAmount()` 统一为最小货币单位（分）后比较；本地订单未声明金额时跳过 | 金额不匹配：期望 X，实际 Y |
| 4 | 时间戳 | `checkTimestamp()` 比对通知时间与当前时间的差值是否在 `replayWindow`（默认 300 秒）内，防重放 | 通知时间戳超出有效窗口，可能为重放攻击 |

> 时间戳无法提取时（部分网关通知不携带时间字段）放行，不视为重放。

### 三种使用模式

| 模式 | 方法 | 行为 | 适用场景 |
|------|------|------|----------|
| 一次性校验 | `verify()` | 对给定的通知/查询数据做四重校验，立即返回结果 | 已收到网关异步通知、回调入口 |
| 进程内轮询 | `monitorInProcess()` | 阻塞当前进程，按设定间隔持续调用 `queryOrder()` 抓取收款状态，匹配后验证 | CLI 脚本、长连接服务、定时任务 |
| 后台进程 | `monitorInBackground()` | `pcntl_fork` 派生子进程在后台执行 `monitorInProcess()`，父进程立即返回 PID | Web 请求中需要立即响应、后台异步确认 |

三种模式返回值结构统一为：

```php
[
    'status'  => 'verified' | 'failed' | 'timeout',
    'message' => '验证通过' | '签名验证失败' | '监控超时' | ...,
    'data'    => [...], // 原始收款数据或失败上下文
]
```

## 快速开始

### 创建验证器实例

```php
<?php

declare(strict_types=1);

use Kode\Pays\Core\IdempotencyGuard;
use Kode\Pays\Core\PersonalReceiveVerifier;
use Kode\Pays\Facade\Pay;

// 1. 创建网关实例（这里以微信支付为例）
$gateway = Pay::wechat([
    'app_id'  => 'wx1234567890abcdef',
    'mch_id'  => '1234567890',
    'api_key' => 'your-32-byte-api-key',
    'sandbox' => false,
]);

// 2. 创建幂等保护器（可选，但强烈推荐）
$guard = new IdempotencyGuard(
    lockTtl: 60,      // 锁过期 60 秒
    resultTtl: 86400, // 结果缓存 1 天
);

// 3. 创建验证器实例
$verifier = new PersonalReceiveVerifier(
    gateway: $gateway,
    guard: $guard,
    replayWindow: 300, // 默认 5 分钟防重放窗口
);
```

### 验证异步通知（verify 示例）

适用于网关异步通知回调入口，对 `$_POST` 或解析后的通知报文做一次性校验：

```php
<?php

declare(strict_types=1);

use Kode\Pays\Core\PersonalReceiveVerifier;

// $verifier 由前置步骤创建
// $order 为本地存储的订单快照（必须包含 out_trade_no，推荐包含金额）

// 从本地数据库/缓存读取订单
$order = [
    'out_trade_no' => 'PERSONAL_20240425120000_1234',
    'total_fee'    => 100, // 单位：分
];

// 网关异步通知通常以 POST 形式到达，已由网关 SDK 解析为数组
$notifyData = $_POST;

// 一次性校验：签名 + 订单号 + 金额 + 时间戳
$result = $verifier->verify($order, $notifyData);

if ($result['status'] === PersonalReceiveVerifier::STATUS_VERIFIED) {
    // 校验通过，可安全执行业务回调（发货、入账等）
    echo '验证通过：' . $result['message'];
} else {
    // 校验失败，记录日志、返回失败响应给网关
    error_log('收款验证失败：' . $result['message']);
    echo '验证失败：' . $result['message'];
}
```

## 进程内监控收款

`monitorInProcess()` 阻塞当前进程，按设定间隔持续调用 `GatewayInterface::queryOrder()` 抓取最新收款状态。命中成功状态（`SUCCESS` / `TRADE_SUCCESS` / `succeeded` / `paid`）后自动触发 `verify()` 做数据一致性校验，命中终态失败状态（`CLOSED` / `REVOKED` / `PAYERROR` / `FAILED` / `canceled`）则立即返回失败。

### 完整示例

```php
<?php

declare(strict_types=1);

use Kode\Pays\Core\PersonalReceiveVerifier;

// $verifier 由前置步骤创建
$order = [
    'out_trade_no' => 'PERSONAL_20240425120000_1234',
    'total_fee'    => 100,
];

// 进程内轮询：每 2 秒查询一次，最多尝试 30 次，整体超时 60 秒
$result = $verifier->monitorInProcess(
    order: $order,
    options: [
        'interval'     => 2,
        'max_attempts' => 30,
        'timeout'      => 60,
    ],
    onSuccess: function (array $queryResult) use ($order) {
        // 收款成功且数据校验通过：发货、入账、发通知
        error_log(sprintf(
            '订单 %s 收款成功，交易号 %s',
            $order['out_trade_no'],
            $queryResult['transaction_id'] ?? '',
        ));
        // TODO: 调用业务发货逻辑
    },
    onFailure: function (string $reason, array $result) use ($order) {
        // $reason 取值：mismatch / max_attempts / 具体失败状态 / timeout
        error_log(sprintf(
            '订单 %s 收款监控失败：%s（%s）',
            $order['out_trade_no'],
            $reason,
            $result['message'],
        ));
    },
);

echo $result['status'] . PHP_EOL; // verified / failed / timeout
echo $result['message'] . PHP_EOL;
```

### options 参数说明

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `interval` | int | 3 | 每次轮询间隔（秒），最小为 1 |
| `max_attempts` | int | 20 | 最大轮询次数，最小为 1 |
| `timeout` | int | 60 | 总超时时间（秒），最小为 1；优先于 `max_attempts` |

> 同时设定 `max_attempts` 与 `timeout` 时，任一条件先达到即终止。`sleep(interval)` 仅在非末次循环中触发，最后一次查询后立即返回。

### 成功/失败回调签名

| 回调 | 参数 | 调用时机 |
|------|------|----------|
| `onSuccess(array $queryResult)` | 网关原始查询结果 | `queryOrder` 返回成功状态且 `verify()` 通过 |
| `onFailure(string $reason, array $result)` | `$reason` 为失败原因；`$result` 为验证器返回结构 | 状态不匹配、终态失败、达到最大次数、超时 |

`onFailure` 中 `$reason` 的常见取值：

- `mismatch`：查询到收款成功但与本地订单数据不匹配（签名/订单号/金额/时间戳校验未过）
- 终态失败状态字符串：如 `CLOSED`、`REVOKED`、`FAILED`、`canceled`
- `max_attempts`：达到 `max_attempts` 仍未收到成功状态
- `timeout`（常量 `PersonalReceiveVerifier::STATUS_TIMEOUT`）：总超时触发

### 订单不存在的处理

网关返回 `PayException` 且错误码为 `PayException::ERROR_ORDER_NOT_FOUND` 时，验证器视为「订单尚未在网关创建成功」，继续轮询；其他异常会向上抛出，需调用方自行捕获。

## 后台进程监控收款

`monitorInBackground()` 在 Web 请求场景下尤其有用：父进程立即返回子进程 PID，可以快速响应客户端「正在处理」，子进程在后台持续轮询并触发回调。

### 完整示例

```php
<?php

declare(strict_types=1);

use Kode\Pays\Core\PersonalReceiveVerifier;

// $verifier 由前置步骤创建
$order = [
    'out_trade_no' => 'PERSONAL_20240425120000_1234',
    'total_fee'    => 100,
];

// 派生后台子进程监控
$pid = $verifier->monitorInBackground(
    order: $order,
    options: [
        'interval'     => 3,
        'max_attempts' => 40,
        'timeout'      => 120,
    ],
    onSuccess: function (array $queryResult) use ($order) {
        // 此回调运行在子进程中，可写日志、写消息队列、回调业务系统
        file_put_contents(
            '/var/log/kode_pays/personal.log',
            sprintf("[%d] 订单 %s 收款成功\n", getmypid(), $order['out_trade_no']),
            FILE_APPEND,
        );
    },
    onFailure: function (string $reason, array $result) use ($order) {
        file_put_contents(
            '/var/log/kode_pays/personal.log',
            sprintf("[%d] 订单 %s 监控失败：%s\n", getmypid(), $order['out_trade_no'], $result['message']),
            FILE_APPEND,
        );
    },
);

if ($pid === false) {
    // pcntl 不可用或 fork 失败：降级到进程内轮询
    error_log('pcntl 不可用，降级为进程内轮询');
    $verifier->monitorInProcess($order, [], null, null);
    return;
}

// 父进程立即返回子进程 PID，可快速响应客户端
echo json_encode([
    'code' => 0,
    'msg'  => '正在后台确认收款',
    'pid'  => $pid,
]);
```

### pcntl 不可用时的降级处理

`monitorInBackground()` 在以下情况返回 `false`（**不抛异常**）：

1. 当前 PHP 未编译 `pcntl` 扩展（`function_exists('pcntl_fork')` 为 `false`）
2. `pcntl_fork()` 返回 `-1`（系统资源不足等）

推荐降级策略：

```php
<?php

use Kode\Pays\Core\PersonalReceiveVerifier;

$pid = $verifier->monitorInBackground($order, $options, $onSuccess, $onFailure);

if ($pid === false) {
    // 降级方案一：进程内轮询（阻塞当前请求，适合 CLI 或可容忍延迟的接口）
    $result = $verifier->monitorInProcess($order, $options, $onSuccess, $onFailure);

    // 降级方案二：写入待处理队列，由独立的常驻 worker 消费
    // Queue::push('personal_receive_verify', ['order' => $order, 'options' => $options]);
    return;
}
```

### 子进程回调与父进程交互

注意 `onSuccess` / `onFailure` 回调运行在子进程地址空间内，与父进程不共享内存：

- **不可** 通过全局变量、静态属性向父进程传递结果
- 推荐通过以下方式与父进程通信：
  - 文件 / 日志（最简单，单机场景）
  - 消息队列（Redis、RabbitMQ、Kafka）
  - 共享缓存（`kode/cache`、Redis）
  - 数据库更新订单状态
- 子进程异常会被捕获并 `exit(1)`，**不会** 影响父进程；正常退出为 `exit(0)`
- 父进程可通过 `pcntl_wait($status, WNOHANG)` 异步回收子进程，避免产生僵尸进程

```php
<?php

// 异步回收子进程，避免僵尸进程
declare(ticks=1);

pcntl_async_signals(true);
pcntl_signal(SIGCHLD, function () {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        // 子进程已回收
    }
});
```

## 验证并确认收款（幂等）

`verifyAndConfirm()` 在 `verify()` 基础上叠加 `IdempotencyGuard` 的幂等保护，是处理异步通知的推荐入口。

### 工作流程

1. 提取本地订单号，若 `IdempotencyGuard` 已注入：
   - 检查 `isSuccess()`：若已成功处理过，直接返回「订单已处理成功，无需重复确认」
   - 调用 `acquire()` 获取锁：若已被其他进程持有，返回「订单正在处理中」
2. 执行 `verify()` 四重校验
3. 验证通过：`markSuccess()` 记录成功结果 → 触发 `onSuccess` 回调
4. 验证失败：`markFailed()` 记录失败信息 → 触发 `onFailure` 回调
5. `finally` 中调用 `release()` 释放锁

### 示例

```php
<?php

declare(strict_types=1);

use Kode\Pays\Core\PersonalReceiveVerifier;

// $verifier 已注入 IdempotencyGuard
$order = [
    'out_trade_no' => 'PERSONAL_20240425120000_1234',
    'total_fee'    => 100,
];

$result = $verifier->verifyAndConfirm(
    order: $order,
    receivedData: $_POST,
    onSuccess: function (array $data) use ($order) {
        // 此回调仅在「本次调用验证通过且首次成功」时触发
        // 即使网关重复推送 100 次通知，本回调也只执行一次
        OrderService::ship($order['out_trade_no'], $data);
    },
    onFailure: function (string $message) use ($order) {
        // 验证失败或重放被拦截时触发
        OrderService::markFailed($order['out_trade_no'], $message);
    },
);

// 网关重复推送通知时的输出示例：
// 第一次：status=verified，message=验证通过
// 第二次：status=failed，message=订单已处理成功，无需重复确认
```

### 配合 IdempotencyGuard 防重复

`IdempotencyGuard` 通过两层防护实现幂等：

| 防护层 | 机制 | TTL 默认 | 作用 |
|--------|------|----------|------|
| 锁层 | `KodeCacheAdapter::lock()` 分布式锁 | 60 秒 | 防止并发处理同一订单（多进程同时收到通知） |
| 结果层 | `KodeCacheAdapter::set()` 缓存成功/失败结果 | 86400 秒 | 防止已处理订单被再次处理（重放通知） |

- 安装 `kode/cache` 时，锁与结果缓存跨进程可见，可在多机部署下生效
- 未安装 `kode/cache` 时回退到进程内内存，**仅单进程内有效**，多进程部署需自行实现外部存储

> 即便不使用 `verifyAndConfirm()`，业务侧自行调用 `verify()` 后也应在业务层做幂等处理；推荐直接使用 `verifyAndConfirm()`，避免遗漏。

## 金额单位约定

各网关对金额字段与单位约定不一致，`extractAmount()` 在内部统一为「最小货币单位整数（分）」后再做比较，调用方无需手工转换：

| 网关 | 字段 | 原始单位 | 转换规则 | 备注 |
|------|------|----------|----------|------|
| 微信支付 | `total_fee` | 分 | 直接使用 | 整数 |
| 支付宝 | `total_amount` | 元（字符串浮点） | `× 100` 并四舍五入 | 兼容 `"0.01"`、`1.00` 等 |
| Stripe | `amount` | 分 | 直接使用 | 整数 |
| 通用（部分通知） | `settlement_amount` | 分 | 直接使用 | 结算金额字段 |

### Stripe metadata.out_trade_no 订单号提取

Stripe Payment Link 默认不返回 `out_trade_no`，需在创建时将其写入 `metadata`：

```php
<?php

use Kode\Pays\Plugin\PersonalReceivePlugin;

$plugin = new PersonalReceivePlugin($stripeGateway);

// 创建 Payment Link 时显式写入 metadata.out_trade_no
$result = $plugin->createQrCode([
    'amount'      => 1000,
    'description' => 'Digital Goods',
    'attach'      => ['product_id' => 'P001'],
    // PersonalReceivePlugin 内部会自动追加 metadata.out_trade_no
    // 也可通过 attach 显式指定：
    // 'attach' => ['product_id' => 'P001', 'out_trade_no' => 'MY_ORDER_001'],
]);

// 后续查询/通知返回的数据中将包含 metadata.out_trade_no
// extractOrderNo() 会按以下顺序查找：
// 1. data['out_trade_no']
// 2. data['order_id']
// 3. data['metadata']['out_trade_no']   <-- Stripe 走此分支
// 4. data['partner_trade_no']
```

### 单位换算示例

```php
<?php

// 微信通知：total_fee=100（1 元）
// 支付宝通知：total_amount="1.00"（1 元）
// Stripe 通知：amount=100（1 美元，最小单位美分）
// 三者经过 extractAmount() 后均得到整数 100，可直接比较

$order = ['out_trade_no' => 'ORDER_001', 'total_fee' => 100];
$wechatNotify = ['out_trade_no' => 'ORDER_001', 'total_fee' => 100, 'time_end' => '20240425120000'];
$alipayNotify = ['out_trade_no' => 'ORDER_001', 'total_amount' => '1.00', 'gmt_payment' => '2024-04-25 12:00:00'];
$stripeNotify = ['metadata' => ['out_trade_no' => 'ORDER_001'], 'amount' => 100, 'created' => 1714035600];

// 三种通知都能通过 verify() 校验（前提：签名也通过）
```

## 完整业务流程示例

下面演示一个完整的个人收款流程：用 `PersonalReceivePlugin` 生成收款码 → 用 `PersonalReceiveVerifier` 进程内监控 → 验证通过后执行业务回调。

```php
<?php

declare(strict_types=1);

use Kode\Pays\Core\IdempotencyGuard;
use Kode\Pays\Core\PersonalReceiveVerifier;
use Kode\Pays\Facade\Pay;
use Kode\Pays\Plugin\PersonalReceivePlugin;

// 1. 初始化网关、插件、验证器
$gateway = Pay::wechat([
    'app_id'  => 'wx1234567890abcdef',
    'mch_id'  => '1234567890',
    'api_key' => 'your-32-byte-api-key',
]);

$plugin   = new PersonalReceivePlugin($gateway);
$guard    = new IdempotencyGuard();
$verifier = new PersonalReceiveVerifier($gateway, $guard);

// 2. 业务层创建本地订单（这里用内存数组模拟，实际应入库）
$order = [
    'out_trade_no' => 'PERSONAL_' . date('YmdHis') . '_' . random_int(1000, 9999),
    'total_fee'    => 100, // 1 元
    'subject'      => '个人收款测试',
];

// 3. 通过插件生成收款码
$qrCode = $plugin->createQrCode([
    'amount'      => $order['total_fee'],
    'description' => $order['subject'],
    'attach'      => ['product_id' => 'P001'],
    // 将生成的 out_trade_no 同步回本地订单（PersonalReceivePlugin 内部会生成新订单号）
]);

// 以插件返回的 out_trade_no 为准（确保与网关侧一致）
$order['out_trade_no'] = $qrCode['out_trade_no'];
echo "请扫码支付：{$qrCode['code_url']}\n";

// 4. 启动进程内监控，等待用户扫码付款
$result = $verifier->monitorInProcess(
    order: $order,
    options: [
        'interval'     => 2,
        'max_attempts' => 30,
        'timeout'      => 60,
    ],
    onSuccess: function (array $queryResult) use ($order, $guard) {
        // 5. 验证通过 + 幂等确认：执行业务回调
        //    guard.markSuccess 已在 verifyAndConfirm 内部完成
        //    这里仅做业务侧的发货、入账
        OrderService::ship($order['out_trade_no']);
        NotifyService::pushUser($order['out_trade_no'], '收款成功');
    },
    onFailure: function (string $reason, array $result) use ($order) {
        OrderService::markFailed($order['out_trade_no'], $result['message']);
    },
);

// 6. 处理监控结果
if ($result['status'] === PersonalReceiveVerifier::STATUS_VERIFIED) {
    echo "收款确认成功\n";
} elseif ($result['status'] === PersonalReceiveVerifier::STATUS_TIMEOUT) {
    echo "监控超时，请稍后到对账页面确认\n";
} else {
    echo "收款失败：{$result['message']}\n";
}

// 7. 若同时收到了网关异步通知，可再次调用 verifyAndConfirm 做幂等二次确认
//    已成功的订单会直接返回「订单已处理成功，无需重复确认」
if ($_POST !== []) {
    $verifier->verifyAndConfirm($order, $_POST);
}
```

## 常见问题

### pcntl 不可用怎么办？

`monitorInBackground()` 在 `pcntl` 扩展缺失或 `pcntl_fork()` 失败时返回 `false`，**不抛异常**。推荐降级方案：

1. **降级到进程内轮询**：直接调用 `monitorInProcess()`，适用于 CLI 脚本、定时任务或可容忍延迟的接口。
2. **降级到队列异步处理**：将订单写入待处理队列，由独立常驻 worker 进程消费调用 `monitorInProcess()`。该方案不依赖 `pcntl`，且能在多机部署下水平扩展。
3. **依赖异步通知**：仅信任网关异步通知，使用 `verify()` / `verifyAndConfirm()` 处理回调，放弃主动轮询。适用于通知到达及时、对账可延迟的场景。

检测 `pcntl` 可用性：

```php
<?php

if (!function_exists('pcntl_fork')) {
    error_log('当前环境未启用 pcntl 扩展，后台进程模式不可用');
}
```

### 重放攻击如何防护？

`verify()` 通过 `checkTimestamp()` 防重放，逻辑为：

1. `extractTimestamp()` 从通知数据中提取 Unix 时间戳，兼容：
   - 微信 `time_end`（格式 `yyyyMMddHHmmss`）
   - 通用 `timestamp`（Unix 秒）
   - Stripe `created`（Unix 秒）
   - 支付宝 `gmt_payment` / `notify_time` / `gmt_create`（格式 `Y-m-d H:i:s`）
2. 计算通知时间与当前时间差值的绝对值，若超过 `replayWindow`（构造函数参数，默认 300 秒）即判定为重放。
3. 若无法提取时间戳，放行（部分网关通知不携带时间字段）。

**强化建议**：

- 生产环境可缩短 `replayWindow` 至 60-120 秒，对重放更敏感
- 配合 `IdempotencyGuard` 的结果缓存层，已处理成功的订单即便被重放也会被拦截
- 在签名校验通过但时间戳缺失的场景，业务侧应额外记录「已处理通知 ID」做去重
- 部分网关（如微信 V3）通知体携带 `Wechatpay-Nonce` 与 `Wechatpay-Timestamp` HTTP 头，可在网关 `verifyNotify()` 实现中纳入校验

### 幂等如何保证？

`verifyAndConfirm()` 内部的幂等链路：

```
收到通知
   │
   ▼
guard.isSuccess(orderNo)?  ─── 是 ──▶ 返回「订单已处理成功，无需重复确认」
   │
   否
   ▼
guard.acquire(orderNo)?     ─── 否 ──▶ 返回「订单正在处理中」
   │
   是（持有分布式锁）
   ▼
verify(order, receivedData) ─── 失败 ──▶ guard.markFailed() + onFailure()
   │
   通过
   ▼
guard.markSuccess(orderNo, data) + onSuccess()
   │
   ▼
finally: guard.release(orderNo)
```

幂等的两层保障：

| 层级 | 数据结构 | 默认 TTL | 防护场景 |
|------|----------|----------|----------|
| 锁 | `kode_pays_idem:lock:{orderNo}` | 60 秒 | 防止多进程同时处理同一订单 |
| 结果 | `kode_pays_idem:status:{orderNo}` | 86400 秒 | 防止已处理订单被重放通知再次触发 |

**关键点**：

- 幂等键为订单号 `out_trade_no`，因此本地订单号必须全局唯一且与网关侧一致
- `acquire()` 与 `isSuccess()` 联合判断：先查结果缓存（已成功则直接拒绝），再抢锁（已被并发持有也拒绝）
- `markFailed()` 同样会写入结果缓存，防止恶意失败请求反复触发业务回调
- 如需手动重置订单状态（如人工干预后重新处理），调用 `IdempotencyGuard::clear($orderNo)`

### 多网关兼容（微信/支付宝/Stripe）

`PersonalReceiveVerifier` 通过 `extractOrderNo()` / `extractAmount()` / `extractStatus()` / `extractTimestamp()` 四个提取方法实现多网关兼容，无需调用方针对不同网关写适配代码：

| 维度 | 微信 | 支付宝 | Stripe |
|------|------|--------|--------|
| 订单号字段 | `out_trade_no` | `out_trade_no` | `metadata.out_trade_no` |
| 金额字段 | `total_fee`（分） | `total_amount`（元） | `amount`（分） |
| 成功状态 | `SUCCESS` | `TRADE_SUCCESS` | `succeeded` / `paid` |
| 失败状态 | `CLOSED` / `REVOKED` / `PAYERROR` | - | `canceled` / `FAILED` |
| 时间字段 | `time_end`（`YmdHis`） | `gmt_payment` / `notify_time`（`Y-m-d H:i:s`） | `created`（Unix 秒） |

切换网关只需更换 `PersonalReceiveVerifier` 构造时传入的 `GatewayInterface` 实例：

```php
<?php

use Kode\Pays\Core\PersonalReceiveVerifier;
use Kode\Pays\Facade\Pay;

// 微信
$wechatVerifier = new PersonalReceiveVerifier(Pay::wechat($wechatConfig));

// 支付宝
$alipayVerifier = new PersonalReceiveVerifier(Pay::alipay($alipayConfig));

// Stripe
$stripeVerifier = new PersonalReceiveVerifier(Pay::stripe($stripeConfig));

// 三者的 verify / monitorInProcess / verifyAndConfirm 调用方式完全一致
```

> 若网关返回的字段命名不在上述兼容列表中（如自研聚合网关），可继承 `PersonalReceiveVerifier` 并重写对应的 `extractXxx()` 方法扩展兼容性。
