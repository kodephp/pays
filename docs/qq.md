# QQ 支付接入文档

QQ 支付是腾讯公司推出的在线支付解决方案，支持 QQ 钱包、微信支付、银行卡等多种支付方式。

## 环境要求

- PHP 8.2+
- 有效的 QQ 支付商户账户
- 商户应用 ID（app_id）
- 商户号（mch_id）
- API 密钥（api_key）

## 安装

```bash
composer require kode/pays
```

## 配置说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_id | string | 是 | QQ 支付商户应用 ID |
| mch_id | string | 是 | QQ 支付商户号 |
| api_key | string | 是 | QQ 支付 API 密钥 |
| notify_url | string | 否 | 异步通知回调地址 |
| sandbox | bool | 否 | 是否沙箱模式，默认 false |

## 快速开始

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::create('qq', [
    'app_id' => 'your_qq_app_id',
    'mch_id' => 'your_qq_mch_id',
    'api_key' => 'your_qq_api_key',
    'notify_url' => 'https://example.com/notify',
]);

// 创建 JSAPI 支付订单
$result = $gateway->createOrder([
    'out_trade_no' => 'ORDER_' . date('YmdHis'),
    'total_amount' => 10000,
    'subject' => '商品购买',
    'trade_type' => 'JSAPI',
    'openid' => 'qq_openid',
]);

// 构造前端支付参数
$payParams = [
    'appId' => $gateway->config['app_id'],
    'timeStamp' => (string) time(),
    'nonceStr' => uniqid(),
    'package' => 'prepay_id=' . $result['prepay_id'],
    'signType' => 'MD5',
];

// 计算签名
ksort($payParams);
$string = http_build_query($payParams, '', '&', PHP_QUERY_RFC3986);
$string .= '&key=' . $gateway->config['api_key'];
$payParams['paySign'] = strtoupper(md5($string));

// 前端调用 QQ 支付
// wx.chooseWXPay({...payParams});
```

## API 方法列表

### createOrder(array $params): array

创建 QQ 支付订单。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| total_amount | int | 是 | 订单金额（单位：分） |
| subject | string | 否 | 订单标题 |
| description | string | 否 | 订单描述 |
| trade_type | string | 是 | 交易类型：JSAPI/NATIVE/APP |
| openid | string | 否 | JSAPI 支付需要 |
| notify_url | string | 否 | 异步通知回调地址 |

**返回值：**

| 字段 | 类型 | 说明 |
|------|------|------|
| out_trade_no | string | 商户订单号 |
| prepay_id | string | 预支付 ID |
| code_url | string | NATIVE 支付二维码链接 |
| trade_type | string | 交易类型 |

### queryOrder(string $orderId): array

查询订单状态。

**参数：**
- `orderId`：交易单号（transaction_id）或商户订单号（out_trade_no）

**返回值：**

| 字段 | 类型 | 说明 |
|------|------|------|
| transaction_id | string | 交易单号 |
| out_trade_no | string | 商户订单号 |
| total_amount | int | 订单金额 |
| trade_state | string | 交易状态 |
| trade_state_desc | string | 状态描述 |
| pay_time | string | 支付时间 |

### refund(array $params): array

发起退款。

**参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| out_trade_no | string | 是 | 商户订单号 |
| out_refund_no | string | 是 | 商户退款单号 |
| refund_fee | int | 是 | 退款金额（分） |
| total_fee | int | 是 | 订单总金额（分） |
| refund_desc | string | 否 | 退款原因 |

### queryRefund(string $refundId): array

查询退款状态。

### closeOrder(string $orderId): array

关闭订单。

### verifyNotify(array $data): bool

验证异步通知签名。

## 交易类型说明

| 交易类型 | 场景 | 特点 |
|----------|------|------|
| JSAPI | QQ 内 H5 支付 | 需要用户 openid |
| NATIVE | 扫码支付 | 返回二维码链接 |
| APP | 移动应用支付 | 适用于原生 App |

## 异步通知处理

```php
<?php

use Kode\Pays\Facade\Pay;

$gateway = Pay::create('qq', [
    'app_id' => 'your_qq_app_id',
    'mch_id' => 'your_qq_mch_id',
    'api_key' => 'your_qq_api_key',
]);

// 接收通知数据
$notifyData = json_decode(file_get_contents('php://input'), true);

// 验证签名
if (!$gateway->verifyNotify($notifyData)) {
    http_response_code(400);
    echo '签名验证失败';
    exit;
}

// 处理支付成功
if ($notifyData['trade_state'] === 'SUCCESS') {
    $orderId = $notifyData['out_trade_no'] ?? '';
    $transactionId = $notifyData['transaction_id'] ?? '';
    $amount = $notifyData['total_amount'] ?? 0;
    
    // 更新订单状态
    // ...
}

// 响应成功
http_response_code(200);
echo 'SUCCESS';
```

## 沙箱环境

```php
use Kode\Pays\Core\SandboxManager;

// 开启 QQ 支付沙箱
SandboxManager::enable('qq');

// 或全局开启沙箱
SandboxManager::enableGlobal();

// 创建网关
$gateway = Pay::create('qq', [
    'app_id' => 'sandbox_app_id',
    'mch_id' => 'sandbox_mch_id',
    'api_key' => 'sandbox_api_key',
]);
```

## 常见问题

**Q: 如何获取 QQ 支付的 openid？**
A: 通过 QQ 登录授权流程获取用户的 openid，或通过 QQ 支付的 OAuth 接口获取。

**Q: 签名验证失败怎么办？**
A: 检查以下几点：
- API 密钥是否正确
- 参数排序是否正确（按 key 升序）
- 编码是否正确（UTF-8）
- 签名算法是否正确（MD5 大写）

**Q: 支付回调通知重复怎么办？**
A: 实现幂等处理，以 transaction_id 作为唯一标识，避免重复处理。

**Q: 沙箱环境和生产环境的区别？**
A: 沙箱环境使用测试参数，不会产生真实交易，接口地址不同。
