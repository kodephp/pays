# 性能与压测数据（Performance Benchmarks）

本文件记录支付 SDK 热路径的压测数据与运行方式，用于回归对比与容量评估。

## 运行方式

```bash
composer bench
# 或
php scripts/bench.php
```

基准使用 Guzzle `MockHandler` 模拟**零网络延迟**的响应，仅测量 SDK 自身的请求分发、签名/验签、响应解析与清单反射等热路径开销，因此结果反映的是 SDK 单点吞吐上限，不含真实网络 RTT。

> ⚠️ 基准结果为相对量级参考，绝对值随机器、PHP 版本、OPcache、负载波动。请以**本机实测**为准，CI 中仅用于捕捉显著回退（regression）。

## 测量指标

- `ms/op`：单次操作平均耗时（毫秒）
- `ops/s`：每秒可完成的操作数
- 每个场景在计时前均执行 2 次预热，消除首次加载/JIT 影响

## 测试环境（示例）

| 项目 | 值 |
| --- | --- |
| PHP | 8.3.33 |
| OpenSSL | on |
| 签名算法 | RSA2 = RSA-SHA256 2048-bit |
| 网络 | MockHandler 零延迟 |

## 压测数据

| 场景 | 迭代次数 | 平均耗时 | 吞吐 |
| --- | --- | --- | --- |
| `HttpClient::get`（Mock 零网络） | 20,000 | 0.012 ms/op | ~83,700 ops/s |
| `Signer::verifyMd5`（回调验签） | 50,000 | 0.001 ms/op | ~1,330,000 ops/s |
| `Signer::rsa2`（RSA-SHA256 2048-bit 签名） | 1,000 | 0.674 ms/op | ~1,480 ops/s |
| `GatewayManifest::baseUrl`（冷启动 / 反射） | 5,000 | 0.001 ms/op | ~1,870,000 ops/s |
| `GatewayManifest::baseUrl`（缓存命中） | 50,000 | 0.000 ms/op | ~10,500,000 ops/s |

**`GatewayManifest::baseUrl` 反射结果缓存加速比：冷 / 热 ≈ 5.6x**

## 性能优化要点（对应实现）

1. **HTTP 连接池保留**：`HttpClient::setTimeout/setConnectTimeout` 不再重建 Guzzle `Client`（旧实现会丢弃 curl keep-alive 连接池，导致每次请求重做 TCP+TLS 握手）。超时改为请求级选项覆盖，连接池得以复用。见 `src/Support/HttpClient.php`。
2. **清单反射结果缓存**：`GatewayManifest::baseUrl()` 首次通过反射解析网关类常量后，将结果回写 `entries` 缓存，后续调用直接查表，避免重复 `ReflectionClass`。见 `src/Core/GatewayManifest.php`。
3. **银联证书懒加载缓存**：`UnionPayGateway` 的签名/验签私钥对象（`OpenSSLAsymmetricKey`）仅在首次使用时解析一次并缓存，避免每次请求读盘 + 解析 PEM。见 `src/Gateway/UnionPay/UnionPayGateway.php`。
4. **非法 JSON 防护**：`AbstractGateway::decodeJson()` 在响应体非合法 JSON 时统一抛 `PayException::gatewayError`，避免下游 `TypeError` 或静默成功。见 `src/Core/AbstractGateway.php`。

## 重试与背压策略（健壮性）

`HttpClient` 重试行为：

- 仅对**连接异常（ConnectException，即请求未到达服务端、无副作用）**或**幂等安全方法（GET/HEAD/OPTIONS/TRACE）**重试，非幂等 POST（下单/退款/转账）在连接未建立之外不会盲目重试，避免重复下单/退款。
- 退避采用**指数退避 + 随机抖动**（`delay = retryDelay × 2^i + random(0, retryDelay/2)`），避免重试风暴。
- 新增 `setMaxResponseBytes()` 响应体大小上限保护，防止对账单等超大响应耗尽内存。

## 回归对比建议

每次发布前运行 `composer bench`，与上表对比：

- `Signer::rsa2` 不应出现数量级回退（受 CPU/OpenSSL 版本影响，波动属正常）。
- `GatewayManifest::baseUrl` 缓存命中吞吐应显著高于冷启动（≥ 3x 为健康）。
- 若 `HttpClient::get` 吞吐明显下降，优先排查是否误重建了 `Client`（连接池失效）。
