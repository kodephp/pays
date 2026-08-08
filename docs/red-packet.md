# 现金红包（Red Packet）

> 本文档说明 kode/pays 的红包能力设计：红包逻辑如何下沉到各网关原生方法、插件与统一入口
> 如何复用、以及不支持的能力如何优雅报「无此方法」。

## 设计原则

红包遵循本 SDK 的统一架构：**各平台的红包逻辑集合在各自网关类内部**（继承 `AbstractGateway`，
复用基类配置、签名与 HTTP 通道），通过统一入口 `Pay::call()` 动态派发调用。

- 平台特色方法由网关类直接实现，并声明 `RedPacketCapableInterface`：
  - `sendRedPacket(array $params): array`（普通红包）
  - `groupRedPacket(array $params): array`（裂变红包 / 群红包）
  - `queryRedPacket(string $mchBillNo): array`（查询红包发放记录）
- `RedPacketPlugin` 退化为「参数校验 + 类型安全转发」层，不重复承载平台组装逻辑。
- 不支持某方法时统一抛 `PayException::methodNotSupported`
  （`ERROR_METHOD_NOT_SUPPORTED`，文案含「无此方法」）。

## 支持平台与方法映射

| 平台 | `sendRedPacket` | `groupRedPacket` | `queryRedPacket` | 说明 |
|------|-----------------|------------------|------------------|------|
| 微信支付 | ✅ 现金红包 | ✅ 裂变红包（`total_num >= 3`） | ✅ | 金额单位为分；投产前请接入 `Signer::md5` 与 `arrayToXml` |
| 支付宝 | ✅ 现金红包（单笔） | ✅ 群红包（`GROUP_RED_PACKET`） | ✅ | 金额单位为分；复用 `buildRequestParams` 标准 RSA2 签名 |

> 能力开关：微信 / 支付宝在 `GatewayManifest` 中声明 `CAP_RED_PACKET => true`。
> 调用前可用 `GatewayManifest::supports('wechat', GatewayManifest::CAP_RED_PACKET)` 判断。

## 统一入口

```php
use Kode\Pays\Facade\Pay;

// 语义化快捷方法（内部经 Pay::call 派发）
Pay::redPacketSend('wechat', [
    'mch_billno'   => 'REDPACK_' . date('YmdHis'),
    'send_name'    => '某某公司',
    're_openid'    => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'total_amount' => 100,
    'total_num'    => 1,
    'wishing'      => '恭喜发财',
    'act_name'     => '新年活动',
    'remark'       => '参与活动领取红包',
]);
Pay::redPacketGroup('alipay', [/* mch_billno + total_num >= 3 + ... */]);
Pay::redPacketQuery('wechat', 'REDPACK_20240425000001');

// 等价：直接派发网关原生方法
Pay::call('wechat', 'sendRedPacket', $params);
```

## 插件调用

```php
use Kode\Pays\Plugin\RedPacketPlugin;

$plugin = new RedPacketPlugin($wechatGateway);

// 发放普通红包
$result = $plugin->send([
    'mch_billno'   => 'REDPACK_' . date('YmdHis'),
    'send_name'    => '某某公司',
    're_openid'    => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'total_amount' => 100,
    'total_num'    => 1,
    'wishing'      => '恭喜发财',
    'act_name'     => '新年活动',
    'remark'       => '参与活动领取红包',
]);

// 发放裂变红包（微信要求 total_num >= 3；支付宝对应 GROUP_RED_PACKET 场景）
$result = $plugin->group([
    'mch_billno'   => 'GROUP_' . date('YmdHis'),
    'send_name'    => '某某公司',
    're_openid'    => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
    'total_amount' => 300,
    'total_num'    => 3,
    'wishing'      => '裂变红包',
    'act_name'     => '分享活动',
    'remark'       => '分享给好友领取',
]);

// 查询红包记录
$result = $plugin->query('REDPACK_20240425000001');
```

插件只做参数校验与转发；平台组装逻辑在网关内部。网关未实现 `RedPacketCapableInterface`
（或不支持某方法）时，统一抛「无此方法」。

## 生产联调提示

- **微信现金红包**：当前实现沿用既有插件构造（请求数组经 `post` 直发）。投产前如需严格合规，
  建议在网关 `sendRedPacket` / `groupRedPacket` / `queryRedPacket` 内接入
  `Signer::md5` 与 `arrayToXml`，并按官方要求配置证书（apiclient_cert.pem 等）。
- **支付宝现金红包**：已复用 `buildRequestParams` 标准 RSA2 签名，金额按分（`total_amount / 100`，
  两位小数）。方法枚举：`alipay.fund.coupon.order.app.pay`（发放）、
  `alipay.fund.coupon.order.query`（查询）。
- **金额单位**：微信 / 支付宝红包金额统一以「分」为单位传入（`total_amount`）。
