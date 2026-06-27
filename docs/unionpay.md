# 云闪付接入文档

## 环境要求

- PHP >= 8.3
- ext-openssl
- ext-json
- Composer
- 云闪付商户证书（.pfx 格式或对应私钥/公钥 PEM 文件）

## 安装

```bash
composer require kode/pays
```

## 配置说明

云闪付网关对应 `Kode\Pays\Gateway\UnionPay\UnionPayGateway`，配置类 `Kode\Pays\Config\UnionPayConfig`。配置通过数组传入，SDK 内部完成校验与 DTO 转换。

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| mer_id | string | 是 | 银联商户号 |
| cert_path | string | 是 | 商户证书路径（用于签名与验签） |
| cert_pwd | string | 是 | 商户证书密码 |
| sandbox | bool | 否 | 是否使用沙箱环境，默认 false |

环境地址：

- 测试环境：`https://gateway.test.95516.com/`
- 生产环境：`https://gateway.95516.com/`

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$unionpay = Pay::unionpay([
    'mer_id'    => '777290058110001',
    'cert_path' => '/path/to/cert.pfx',
    'cert_pwd'  => 'your-cert-password',
]);

// 创建支付订单
$result = $unionpay->createOrder([
    'orderId'  => 'ORDER_' . date('YmdHis'),
    'txnAmt'   => 100,          // 单位：分
    'currency' => '156',        // 156 = 人民币
    'frontUrl' => 'https://your-domain.com/return/unionpay',
    'backUrl'  => 'https://your-domain.com/notify/unionpay',
]);

// 前端跳转地址（网关返回的 tn / 表单）
$payUrl = $result['tn'] ?? '';
```

## API 方法列表

### 创建订单

```php
$gateway->createOrder(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| orderId | string | 是 | 商户订单号 |
| txnAmt | int | 是 | 订单金额（分） |
| currency | string | 是 | 币种代码，人民币为 156 |
| frontUrl | string | 否 | 前端跳转地址 |
| backUrl | string | 否 | 异步通知地址 |

### 查询订单

```php
$gateway->queryOrder(string $orderId): array
```

### 申请退款

```php
$gateway->refund(array $params): array
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| orderId | string | 是 | 退款订单号 |
| origQryId | string | 是 | 原交易的查询流水号 |
| txnAmt | int | 是 | 退款金额（分） |
| backUrl | string | 否 | 退款异步通知地址 |

### 查询退款

```php
$gateway->queryRefund(string $refundId): array
```

> 云闪付退款查询复用订单查询接口，传入退款单号即可。

### 验证异步通知

```php
$gateway->verifyNotify(array $data): bool
```

### 关闭订单

```php
$gateway->closeOrder(string $orderId): array
```

> 云闪付暂不支持主动关闭订单，调用该方法将抛出 `PayException`。

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$data = $_POST;

$unionpay = Pay::unionpay([
    'mer_id'    => '777290058110001',
    'cert_path' => '/path/to/cert.pfx',
    'cert_pwd'  => 'your-cert-password',
]);

if ($unionpay->verifyNotify($data)) {
    // 验签通过，处理业务逻辑
    $orderId = $data['orderId'];
    $txnAmt  = $data['txnAmt'];

    // 返回成功响应给银联
    echo 'ok';
} else {
    // 验签失败
    echo 'fail';
}
```

## 常见问题

### 1. 沙箱环境使用

```php
use Kode\Pays\Core\SandboxManager;

SandboxManager::enable('unionpay');
```

或在配置中直接设置 `'sandbox' => true`：

```php
$unionpay = Pay::unionpay([
    'mer_id'    => '777290058110001',
    'cert_path' => '/path/to/test-cert.pfx',
    'cert_pwd'  => 'your-cert-password',
    'sandbox'   => true,
]);
```

### 2. 证书文件读取失败

`cert_path` 必须指向可读的证书文件，SDK 通过 `file_get_contents` 读取并用于 `openssl_sign` / `openssl_verify`。请确认：

- 路径正确且 PHP 进程有读权限
- 证书未过期
- 证书密码与商户号匹配

### 3. 签名算法

云闪付网关使用 `OPENSSL_ALGO_SHA256` 算法签名。签名前参数按 key 升序排序，空值与签名字段本身不参与签名，最终结果 Base64 编码后作为 `signature` 字段提交。

### 4. 事件监听

```php
use Kode\Pays\Facade\Pay;
use Kode\Pays\Event\Events;

Pay::on(Events::PAYMENT_SUCCESS, function (array $payload) {
    // 支付成功后处理业务
});
```
