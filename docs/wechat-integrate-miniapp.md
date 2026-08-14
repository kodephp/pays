# 微信支付与 kode/miniapp 集成（openid 注入与多 appid 场景）

微信支付与微信身份体系（公众号 / 小程序 / App / 开放平台）在**商户后台绑定**，但代码上分属两个包：

- `kode/miniapp`：负责「你是谁」——OAuth 授权、拿 `openid` / `session_key` / `access_token`、JS-SDK 票据。
- `pay_open`（本包）：负责「收钱」——统一下单、JSAPI 调起、回调验签、退款、分账等。

两者通过**数据**衔接：`miniapp` 在运行期产出 `openid`，`pay_open` 在下单时消费它。

## 关键约束：appid 必须与 openid 同源

一个微信支付商户可同时绑定**多个 appid**（小程序、公众号、App、开放平台主体）。
JSAPI 调起支付时，下单请求里的 `appid` 与 `openid` **必须来自同一 appid 的授权**，否则微信会报 `appid 与 openid 不匹配`。

> `openid` 由 `kode/miniapp` 的 OAuth 流程产出，天然绑定到某个 appid。因此 `pay_open` 下单时要显式指定**该 openid 对应的绑定 appid**。

## pay_open 提供的支持

`createOrder` / `buildJsApiConfig` 均支持按请求指定绑定 appid，覆盖多 appid 商户：

| 能力 | V2（WechatPayGateway） | V3（WechatPayV3Gateway） |
|------|------------------------|--------------------------|
| 指定绑定 appid 下单 | `createOrder(['trade_type'=>'JSAPI','openid'=>..., 'app_id'=>'wxMiniAppid'])` | `createOrder(['trade_type'=>'jsapi','openid'=>..., 'app_id'=>'wxMiniAppid'])` |
| JSAPI 二次签名用指定 appId | `buildJsApiConfig($prepayId, 'wxMiniAppid')` | `buildJsApiConfig($prepayId, 'wxMiniAppid')` |

- `app_id`（或 `appid`）随请求传入即覆盖配置里的 `app_id`，用于匹配 openid 来源；未传则回落到配置值。
- 服务商模式下，`sub_appid` / `sp_appid` 仍按既有契约从配置或参数注入，与多 appid 覆盖互不冲突。

### 用 `jsapi_app_id` 固化 JSAPI 默认绑定 appid（推荐）

若一个商户**固定**用某一个绑定 appid（例如只做小程序支付），可把它配成一级配置项 `jsapi_app_id`：
JSAPI / 小程序下单与 `buildJsApiConfig` 的二次签名会**默认**使用该 appid，无需每次在请求里传 `app_id`。

- 优先级：`请求级 app_id` > `配置 jsapi_app_id`（仅 JSAPI 场景） > `基础 app_id`。
- `NATIVE` / `APP` / `H5` 等非 JSAPI 场景仍使用基础 `app_id`，不受 `jsapi_app_id` 影响。
- 既可通过 `Pay::configExample('wechat')` 生成含 `jsapi_app_id` 的模板，也可在 `inspect()` 的 `notes` 里看到该约束提示。

```php
// config 中声明（WechatConfig / WechatV3Config 均支持）
$config = [
    'app_id'       => 'wxMainAppid',        // 商户主 appid（NATIVE/APP/H5 等场景用）
    'mch_id'       => '1900000109',
    'api_key'      => '...',
    'jsapi_app_id' => 'wxMiniProgramAppid', // JSAPI 默认用「小程序绑定 appid」
];

// 下单与二次签名自动用 jsapi_app_id，与 openid 同源
$order = Pay::make('wechat', $config)->createOrder([
    'trade_type' => 'JSAPI', 'openid' => $openid, /* ... */
]);
$jsConfig = Pay::make('wechat', $config)->buildJsApiConfig($order['prepay_id']);
// $jsConfig['appId'] === 'wxMiniProgramAppid'
```



## 典型调用流程

```php
use Kode\Pays\Facade\Pay;

// 1) 由 kode/miniapp 完成 OAuth，拿到 openid（此处 openid 来自「小程序」授权）
$openid = $miniProgramOauth->getOpenid($code); // 来自 kode/miniapp

// 2) 用「小程序绑定 appid」下单（appid 与 openid 同源）
$order = Pay::make('wechat')->createOrder([
    'out_trade_no' => 'ORDER_' . time(),
    'total_fee'    => 100,            // 单位：分（V2）
    'body'         => '示例商品',
    'trade_type'   => 'JSAPI',
    'openid'       => $openid,
    'app_id'       => 'wxMiniProgramAppid', // 该 openid 对应的小程序绑定 appid
]);

// 3) 用同一个 appid 生成 JSAPI 调起参数（前端 wx.requestPayment 使用）
$jsConfig = Pay::make('wechat')->buildJsApiConfig($order['prepay_id'], 'wxMiniProgramAppid');
// => ['appId'=>'wxMiniProgramAppid', 'timeStamp'=>..., 'nonceStr'=>..., 'package'=>'prepay_id=...', 'signType'=>'MD5', 'paySign'=>...]
```

> V3 接口签名算法为 RSA，但 `app_id` 覆盖与 `buildJsApiConfig($prepayId, $appId)` 的调用方式完全一致。

## 多场景 appid 选择速查

| 支付场景 | trade_type | 所需 openid 来源 | 传入的 app_id |
|----------|-----------|------------------|---------------|
| 小程序内支付 | `JSAPI` / `miniprogram` | 小程序 `wx.login` + code2session（kode/miniapp） | 小程序 appid |
| 公众号内支付（JSAPI） | `JSAPI` | 公众号网页授权（kode/miniapp） | 公众号 appid |
| 服务商模式（子商户） | `JSAPI` | 子商户对应 appid 的授权 | 配置 `sub_appid` 或随请求指定 |

> 一句话：openid 从哪个 appid 授权来，下单与二次签名就用哪个 appid。
