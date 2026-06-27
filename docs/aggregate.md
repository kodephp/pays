# 聚合支付接入文档

## 环境要求

- PHP >= 8.3
- ext-json
- ext-openssl
- Composer
- 至少一个已配置的底层支付渠道（如微信、支付宝等）

## 安装

```bash
composer require kode/pays
```

## 概述

聚合支付网关 `Kode\Pays\Gateway\Aggregate\AggregateGateway` 封装多家支付渠道，根据配置自动路由到最优渠道，并提供失败自动切换能力。**它不使用配置 DTO**，而是在构造时直接传入 `channels` 渠道列表数组，每个渠道通过 `Kode\Pays\Core\GatewayFactory` 动态创建底层网关实例。

适用场景：

- 多渠道容灾：主渠道失败自动切换备用渠道
- 渠道路由：按优先级选择成本最低或成功率最高的渠道
- 统一入口：业务层只对接一个网关，无需感知底层渠道差异

## 配置说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| channels | array | 是 | 渠道配置列表，每项描述一个底层渠道 |

`channels` 每项结构：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| gateway | string | 是 | 底层网关标识（如 `wechat`、`alipay`） |
| config | array | 是 | 该网关的配置数组，与直接创建该网关时一致 |
| priority | int | 否 | 优先级，数字越小优先级越高，默认 999 |
| no_retry_codes | array | 否 | 不可重试错误码列表，命中则立即抛出不再切换 |

> 渠道会按 `priority` 升序排序后使用，相同优先级按配置顺序。

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;
use Kode\Pays\Core\PayException;

// 创建聚合支付网关（微信 + 支付宝双渠道）
$aggregate = Pay::create('aggregate', [
    'channels' => [
        [
            'gateway'  => 'wechat',
            'priority' => 1,
            'config'   => [
                'app_id'  => 'wx1234567890abcdef',
                'mch_id'  => '1234567890',
                'api_key' => 'your-wechat-api-key',
            ],
            'no_retry_codes' => ['1001'], // 配置类错误不切换
        ],
        [
            'gateway'  => 'alipay',
            'priority' => 2,
            'config'   => [
                'app_id'      => '2024XXXXXXXXXXXX',
                'private_key' => '-----BEGIN RSA PRIVATE KEY-----' . PHP_EOL . '...' . PHP_EOL . '-----END RSA PRIVATE KEY-----',
                'public_key'  => '-----BEGIN PUBLIC KEY-----' . PHP_EOL . '...' . PHP_EOL . '-----END PUBLIC KEY-----',
            ],
        ],
    ],
]);

// 创建订单（SDK 按优先级尝试各渠道）
try {
    $result = $aggregate->createOrder([
        'out_trade_no' => 'ORDER_' . date('YmdHis'),
        'total_fee'    => 100,
        'body'         => '测试商品-聚合支付',
    ]);

    // 实际使用的渠道会附加在 _channel 字段
    echo '实际使用渠道：' . ($result['_channel'] ?? 'unknown') . PHP_EOL;

    if (($result['_channel'] ?? '') === 'wechat') {
        echo '微信支付二维码：' . ($result['code_url'] ?? '') . PHP_EOL;
    } elseif (($result['_channel'] ?? '') === 'alipay') {
        echo '支付宝跳转链接：' . ($result['url'] ?? '') . PHP_EOL;
    }
} catch (PayException $e) {
    // 所有渠道均失败
    echo '聚合支付失败：' . $e->getMessage() . PHP_EOL;
}
```

## API 方法列表

### 创建订单

```php
$gateway->createOrder(array $params): array
```

- 按优先级依次尝试每个渠道的 `createOrder`
- 任一渠道成功即返回，并在响应中附加 `_channel` 字段标识实际使用的网关标识
- 若某渠道配置了 `no_retry_codes` 且抛出的异常错误码命中，立即抛出不再切换
- 所有渠道均失败时抛出 `PayException`，消息包含最后一个渠道的错误信息

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

使用排序后的第一个渠道（优先级最高的渠道）查询。

### 申请退款

```php
$gateway->refund(array $params): array
```

使用第一个渠道发起退款。建议在退款参数中带上原订单实际使用的渠道信息（需业务方自行持久化 `_channel` 字段），以保证退款走与支付相同的渠道。

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

使用第一个渠道查询退款。

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

遍历所有渠道尝试验证签名，任一渠道验证通过即返回 `true`；全部失败或异常则返回 `false`。

### 关闭订单

```php
$gateway->closeOrder(string $orderId): array
```

使用第一个渠道关闭订单。

## 失败自动切换机制

聚合支付的核心能力是 `createOrder` 时的失败自动切换，流程如下：

```
按 priority 升序排序后的渠道列表
        │
        ▼
   尝试渠道 A.createOrder
        │
   ┌────┴────┐
 成功         失败（PayException）
  │            │
  │      错误码 ∈ no_retry_codes？
  │        ┌───┴───┐
  │       是       否
  │        │       │
  │     抛出异常  继续尝试渠道 B
  │                │
  ▼           … 直到成功或耗尽
返回结果 + _channel
                   │
              全部失败 → 抛出聚合 PayException
```

使用建议：

1. **优先级**：将成功率高、成本低的渠道排在前面（`priority` 小）
2. **不可重试错误码**：对配置错误、签名错误等非瞬时性故障配置 `no_retry_codes`，避免无意义的渠道切换
3. **结果持久化**：务必将 `createOrder` 返回的 `_channel` 持久化到订单表，后续查询、退款、关闭订单时显式使用同一渠道（可单独通过 `Pay::wechat()` 等方式创建对应网关）
4. **统一参数**：不同渠道的 `createOrder` 参数字段不同（如微信用 `out_trade_no`/`total_fee`，云闪服用 `orderId`/`txnAmt`），聚合模式下建议业务方传入第一个主渠道所需的参数，或自行做参数适配层

## 异步通知处理

聚合支付的异步通知通过 `verifyNotify` 遍历所有渠道验签，因此通知地址可统一指向聚合网关的处理端点：

```php
<?php

use Kode\Pays\Facade\Pay;

$aggregate = Pay::create('aggregate', [
    'channels' => [
        ['gateway' => 'wechat',  'priority' => 1, 'config' => [/* ... */]],
        ['gateway' => 'alipay',  'priority' => 2, 'config' => [/* ... */]],
    ],
]);

$data = array_merge($_GET, $_POST);

if ($aggregate->verifyNotify($data)) {
    // 验签通过，根据数据特征判断来自哪个渠道
    if (isset($data['out_trade_no'])) {
        // 微信通知处理
    } elseif (isset($data['trade_no'])) {
        // 支付宝通知处理
    }

    echo 'success';
} else {
    echo 'fail';
}
```

> 由于各渠道通知数据结构差异较大，生产环境推荐为每个渠道单独配置通知地址并使用对应网关验签，聚合 `verifyNotify` 适用于无法区分渠道的统一入口场景。

## 常见问题

### 1. 如何为不同渠道配置不同优先级

`priority` 数值越小越优先。下面示例中微信优先于支付宝：

```php
'channels' => [
    ['gateway' => 'wechat', 'priority' => 1, 'config' => [/* ... */]],
    ['gateway' => 'alipay', 'priority' => 2, 'config' => [/* ... */]],
],
```

### 2. 退款如何走与支付相同的渠道

聚合网关的 `refund` 默认使用第一个渠道。为避免退款走错渠道，建议：

```php
// 支付成功后保存 _channel
$channel = $result['_channel'];

// 退款时显式使用同一渠道
$gateway = Pay::create($channel, $channelConfig);
$gateway->refund($refundParams);
```

### 3. 配置错误如何排查

- `聚合支付必须配置 channels 渠道列表`：未传入 `channels` 或类型非数组
- `聚合支付渠道必须配置 gateway 标识`：某渠道缺少 `gateway` 字段
- `聚合支付未配置任何渠道`：渠道列表为空，调用查询/退款等方法时抛出

### 4. 事件监听

```php
use Kode\Pays\Facade\Pay;
use Kode\Pays\Event\Events;

Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    // 支付成功后处理业务
});
```
