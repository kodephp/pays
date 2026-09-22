# 微信小程序虚拟支付（wechat_virtual）接入文档

> 网关标识：`wechat_virtual` ｜ 网关类：`Kode\Pays\Gateway\Wechat\WechatVirtualGateway`
> 对接微信开放平台 xpay 服务端 API（`https://api.weixin.qq.com/xpay/*`）。
> 用途：小程序内**虚拟商品**（会员、课程、代币、数字内容）支付——iOS 侧过审必须走该通道，
> 不能再用普通 JSAPI 微信支付。

## 与 wechat / wechat_v3 的区别

| 维度 | wechat / wechat_v3 | wechat_virtual |
|------|--------------------|----------------|
| 凭据体系 | 商户号 mch_id + API 密钥 | 小程序 app_id/app_secret + 虚拟支付 AppKey |
| 支付对象 | 实物/服务 | 虚拟商品（代币/道具/内容） |
| 发起端 | 服务端统一下单拿 prepay_id | **客户端** `wx.requestVirtualPayment`，服务端只产出签名参数 |
| 发货 | 无 | 必须 `notify_provide_goods` 或推送回包确认 |

## 配置字段（WechatVirtualConfig）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_id | string | 是 | 小程序 APPID |
| app_secret | string | 是 | 小程序 AppSecret（换取 access_token） |
| offer_id | string | 是 | 虚拟支付 OfferId（MP 后台「虚拟支付 → 基础配置」） |
| app_key | string | 是 | 现网 AppKey（env=0 支付签名） |
| sandbox_app_key | string | 否 | 沙箱 AppKey（env=1 支付签名） |
| env | int | 否 | 0 现网（默认）/ 1 沙箱；下单可请求级覆盖 |
| session_key | string | 否 | 用户 session_key（code2session 获得），用于用户态签名；下单也可请求级覆盖 |
| openid | string | 否 | 默认用户 openid（查询/退款/代币接口需要） |
| user_ip | string | 否 | 默认用户 IP（代币类接口需要） |
| message_token | string | 否 | 消息推送 Token，用于 `verifyNotify()` 验签；未配置时诚实返回 false |
| access_token | string | null | 显式注入则跳过自动换取（如业务侧已有 token 缓存，推荐注入） |
| base_url | string | 否 | 默认 `https://api.weixin.qq.com` |

> session_key / openid 的取法与多 appid 场景约束见
> [微信支付与 kode/miniapp 集成](wechat-integrate-miniapp.md)——**appid 必须与 openid/session_key 同源**。

## 三签名体系（核心）

微信侧对 `wx.requestVirtualPayment` 校验三样东西，全部由 `createOrder()` 一次产出：

1. **signData**：业务参数 JSON（`mode=short_series_goods` 时由 SDK 按官方字段组装；
   代币模式可传 `sign_data` 自行组装，SDK 只负责签名）；
2. **paySig**（服务端签名）：`HMAC-SHA256(uri + "&" + signData, AppKey)`
   ——uri 固定为 `requestVirtualPayment`，AppKey 按本次 env 与现网/沙箱自动配对；
3. **signature**（用户态签名）：`HMAC-SHA256(signData, session_key)`。

服务端 xpay 接口的调用签名则按接口分级（SDK 内部处理）：`none`（仅 access_token）/
`pay`（pay_sig）/ `both`（pay_sig + 用户 signature）。

## 快速使用

```php
use Kode\Pays\Facade\Pay;

// 注意：session_key/openid 是**用户级**配置。Pay::gateway() 按网关名缓存实例，
// 常驻进程（webman/Swoole）下会把第一个用户的会话复用给所有人，
// 因此虚拟支付必须用 Pay::create()（每次新建、不缓存）。
$gateway = Pay::create('wechat_virtual', [
    'app_id'     => 'wx...',
    'app_secret' => '...',
    'offer_id'   => '...',
    'app_key'    => '...',      // 现网
    'sandbox_app_key' => '...', // 沙箱
    'env'        => 0,
    'openid'     => $openid,        // 来自 kode/miniapp 会话
    'session_key'=> $sessionKey,    // 来自 code2session
]);

// ① 下单：产出客户端 requestVirtualPayment 所需的全部参数（不落微信，纯本地签名）
$params = $gateway->createOrder([
    'mode'        => 'short_series_goods', // 道具直购（默认）
    'out_trade_no' => 'ORDER20260922001',  // 商户单号（8-32 位）
    'product_id'  => 'SKU_001',            // MP 后台「虚拟支付 → 商品管理」的道具ID
    'goods_price' => 100,                  // 单位：分
    'buy_quantity' => 1,
    'attach'      => '会员月卡',           // 透传字段，回调原样返回
]);
// → 返回 signData / paySig / signature / mode / env / offerId
// → 原样传给小程序端 wx.requestVirtualPayment($params)
```

支付完成后微信异步推送 `xpay_goods_deliver_notify`；收到推送（或轮询确认）后：

```php
// ② 查单（推送丢失时兜底）
$order = $gateway->queryVirtualOrder('ORDER20260922001');

// ③ 确认发货（虚拟商品必须，否则订单挂起、资金不结算）
$gateway->notifyProvideGoods(['order_id' => 'ORDER20260922001']);

// ④ 退款（refund_order 仅启动退款任务，最终状态以 query_order 追踪为准）
$gateway->refundVirtualOrder([
    'refund_order_id' => 'REFUND20260922001',
    'order_id'        => 'ORDER20260922001',
    'refund_fee'      => 100,
    'left_fee'        => 100,   // 原单剩余可退，需先查单确认
    'refund_reason'   => '3',   // 0-5 枚举，见 WechatVirtualGateway::REFUND_REASONS
    'req_from'        => '2',   // 1-3 枚举，见 WechatVirtualGateway::REFUND_SOURCES
]);
```

## 代币（游戏币）模式

```php
// 代币充值下单：mode=short_series_coin + 自行组装 sign_data（SDK 负责签名）
// 扣币（用户用代币购买道具）：
$gateway->deductTokens([
    'openid' => $openid, 'amount' => 10,
    'order_id' => 'COIN20260922001',
    'payitem' => [['productid' => 'x', 'unit_price' => 1, 'quantity' => 10]],
    'remark'  => '解锁课程',
]);
$gateway->refundTokens(['pay_order_id' => 'COIN...', 'order_id' => '...', 'amount' => 5]);
$gateway->giftTokens(['order_id' => '...', 'amount' => 1]); // 赠送（无需签名）
$gateway->queryTokenBalance($openid);
```

## 对账与回调

- 账单下载：`downloadVirtualBill(['begin_ds' => 20260901, 'end_ds' => 20260922])`。
- 回调验签 `verifyNotify($data)`：复用小程序消息推送通道，
  明文 `signature = sha1(sort([token, timestamp, nonce]))`，
  AES `msg_signature = sha1(sort([token, timestamp, nonce, encrypt]))`。
  **未配置 message_token 时返回 false（诚实失败）**——此时业务侧必须以
  `queryVirtualOrder()` 回查兜底，不要伪造验签通过。

## 契约映射说明

- 基础契约 `queryOrder()/refund()/queryRefund()` 委托给虚拟支付专用方法；
  `queryRefund` 以退款单号查单（xpay 侧退款单同为 order_type=1 订单）。
- `closeOrder()` **诚实抛「无此方法」**：微信不提供虚拟支付关单接口。
- 能力矩阵列 `VPR`（VirtualPayCapableInterface）：仅 wechat_virtual 实现。

## 沙箱联调

`env=1` + `sandbox_app_key` 走沙箱通道；下单签名 AppKey 与 env 严格配对
（`effectiveAppKey()` 自动按 env 选钥匙），取错密钥微信侧验签会直接失败。

## 错误处理

所有 xpay 响应中非零 `errcode` 由 SDK 统一转为 `GatewayException`，
message 携带原始 `[errcode] errmsg`，异常 code 为字符串 errcode，
业务侧可据此区分「签名无效 / session_key 过期 / 订单不存在」等具体失败原因
（对照微信官方 xpay 错误码表），`-1` 视为系统繁忙可重试。
