# 熔断器中间件 CircuitBreakerMiddleware

`Kode\Pays\Pipeline\Middleware\CircuitBreakerMiddleware` 基于「断路器」模式保护不稳定的支付通道：当连续失败次数达到阈值时，熔断器打开（OPEN），在冷却时间内直接拒绝请求，避免对故障网关的雪崩式调用；冷却结束后进入半开（HALF_OPEN）态放行少量探测请求，成功则闭合（CLOSED），失败则重新打开。

它与 [`RetryMiddleware`](architecture.md#45-管道模式)（重试）、[`RateLimitMiddleware`](architecture.md#45-管道模式)（限流）职责互补：限流是「主动节流」，重试是「瞬时容错」，熔断是「故障隔离」。

## 三态说明

| 状态 | 行为 |
|------|------|
| `CLOSED`（闭合） | 正常放行请求，统计连续失败次数 |
| `OPEN`（打开） | 直接拒绝请求并抛出 `PayException::gatewayError()`，进入冷却计时 |
| `HALF_OPEN`（半开） | 冷却结束后放行单个探测请求，成功则闭合，失败则重新打开 |

## 使用示例

```php
use Kode\Pays\Pipeline\Middleware\CircuitBreakerMiddleware;

$breaker = new CircuitBreakerMiddleware([
    'failure_threshold' => 5,   // 连续失败达到该值后熔断（默认 5）
    'cooldown_ms' => 30000,      // 熔断后冷却时间（毫秒，默认 30000）
    'success_threshold' => 1,    // 半开态成功几次后闭合（默认 1）
    'key' => 'wechat',           // 熔断维度标识（默认 'global'）
    'on_open_message' => '支付通道熔断中，请稍后重试',
]);

$result = $breaker->handle($payload, function (array $payload) {
    return $gateway->createOrder($payload);
});
```

## 配置项

| 配置 | 默认值 | 说明 |
|------|--------|------|
| `failure_threshold` | `5` | 连续失败阈值，达到后由 CLOSED 切到 OPEN |
| `cooldown_ms` | `30000` | OPEN 态冷却时长，结束后进入 HALF_OPEN |
| `success_threshold` | `1` | HALF_OPEN 态成功次数达到后恢复 CLOSED |
| `key` | `'global'` | 熔断维度标识，不同 key 独立计数 |
| `on_open_message` | `'支付通道熔断中，请稍后重试'` | 熔断拒绝时抛出的错误信息 |

## 运行时 API

- `getState(string $key = 'global')`：读取当前熔断状态（见 `STATE_*` 常量）。
- `reset(string $key = 'global')`：手动将指定维度恢复为 CLOSED，用于运维手动恢复或测试。

> 熔断状态为进程内存储（静态数组），多实例部署时各进程独立计数；如需集群级熔断，可结合 `kode/cache` 等共享存储自行扩展。
