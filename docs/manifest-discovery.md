# 能力与配置发现（GatewayManifest）

> 本文档说明 kode/pays 的「平台清单（Manifest）」如何帮助开发者在不实例化网关的前提下，
> 快速获知「某平台支持哪些能力、对应哪些可调用方法、需要准备哪些配置字段、当前配置还缺哪些」。

## 背景与价值

聚合支付 SDK 内置 27+ 平台，每个平台的能力（分账、订阅、转账……）与配置字段各不相同。
过去开发者只能逐个翻阅网关源码才能知道「该传哪些配置」「这个平台能不能分账」。

`GatewayManifest` 把这些信息公开为**统一契约**，并提供 `Pay::inspect()` 一处调用的统一响应，
让开发者在接入前即可完成能力发现与配置校验，显著提升接入效率与正确性。

## 核心概念

- **能力开关（capability）**：各平台对外能力的标准化描述（`GatewayManifest::CAP_*` 常量），
  等价于「网关是否实现了对应的 `*CapableInterface`」（`CAPABILITY_CONTRACTS` 为单一事实源）。
- **配置字段契约（config schema）**：每个平台 Config DTO 所需的配置键，分 `required`（必填）
  与 `optional`（可选，缺省时使用网关内部默认值），由 `GatewayManifest::CONFIG_SCHEMA` 声明。
- **可调用操作（operations）**：某能力为 `true` 时，网关上实际可用的接口方法名
  （`CAPABILITY_OPERATIONS` 映射），便于文档生成与 IDE 提示。

## 能力发现：inspect()

`Pay::inspect($gateway, $config = [])` 一次调用返回接入某平台所需的全部契约信息：

```php
use Kode\Pays\Facade\Pay;

$info = Pay::inspect('wechat');

// 平台元信息
$info['name'];      // 'wechat'
$info['label'];     // '微信支付'
$info['region'];    // 'domestic'
$info['signature']; // 'md5'

// 能力开关（完整 bool 映射）
$info['capabilities'][Pay::CAP_TRANSFER]; // true

// 可调用操作：仅已开启能力，含中文标签与方法名
$info['operations']['transfer']['label'];   // '企业付款/转账'
$info['operations']['transfer']['methods']; // ['singleTransfer','batchTransfer','queryTransfer','transferReceipt']

// 配置字段契约
$info['config']['required']; // ['app_id','mch_id','api_key']
$info['config']['optional']; // ['api_v3_key','cert_path','key_path','platform_cert_path','sandbox']

// 缺失校验：传入配置相对必填项的缺漏键（空数组表示配置完整）
$info['missing']; // ['app_id','mch_id','api_key']（未传配置时）
$info['valid'];   // false
```

传入当前配置即可立即得到校验结果：

```php
$info = Pay::inspect('wechat', [
    'app_id' => 'wx123',
    'mch_id' => '123',
    'api_key' => 'key',
]);
$info['missing']; // []
$info['valid'];   // true
```

> 空字符串 `''` 与 `null` 均视为缺失，纳入 `missing` 校验，避免「字段存在但为空」被误判为已配置。

`inspect` 还会列出不在契约内的配置键（`unknown`，多为拼写错误，如 `appid` 误写），以及在
`config.fields` 中给出每个字段的类型、默认值与说明（由 Config 类反射得到，与 `fromArray()` 一致）：

```php
$info = Pay::inspect('wechat', [
    'app_id' => 'wx123',
    'mch_id' => '123',
    'api_key' => 'key',
    'appid' => 'typo', // 拼写错误，会被归入 unknown
]);
$info['unknown'];  // ['appid']
$info['config']['fields']['app_id'];
// ['type' => 'string', 'required' => true, 'default' => null, 'description' => '微信公众号/小程序/APP 的 APPID']
```

## 配置校验（validate）

若只需校验配置是否完整、有无拼写错误，可直接调用 `Pay::validate()` / `GatewayManifest::validate()`，
它返回结构化的校验结果，适合在应用启动或配置加载后一次性断言：

```php
use Kode\Pays\Facade\Pay;

$result = Pay::validate('wechat', $config);
// $result['valid']   必填项是否全部满足
// $result['missing'] 缺失的必填项
// $result['unknown'] 不在契约内的配置键（多为拼写错误）
// $result['errors']  面向开发者的可读错误信息

if (!$result['valid']) {
    throw new \RuntimeException(implode('; ', $result['errors']));
}
```

> `unknown` 仅作提示（不影响 `valid`），用于捕获 `app_id` 误写为 `appid` 这类常见笔误；
> 只有 `missing`（必填缺失）会使 `valid` 为 `false`。

## 细粒度查询

若只需其中某一部分，可直接调用 `GatewayManifest` 的静态方法：

```php
use Kode\Pays\Core\GatewayManifest;

// 平台是否支持某项能力
GatewayManifest::supports('alipay', GatewayManifest::CAP_PROFIT_SHARING); // true

// 能力 → 中文标签
GatewayManifest::capabilityLabel(GatewayManifest::CAP_TRANSFER); // '企业付款/转账'

// 能力 → 可调用方法名
GatewayManifest::capabilityOperations(GatewayManifest::CAP_TRANSFER);
// ['singleTransfer','batchTransfer','queryTransfer','transferReceipt']

// 配置字段契约（必填/可选）
GatewayManifest::configSchema('wechat');
// ['required' => ['app_id','mch_id','api_key'], 'optional' => ['api_v3_key', ...]]
```

## 扩展平台自动推导

`CONFIG_SCHEMA` 仅覆盖内置平台。通过 `Pay::extend()` / `GatewayManifest::register()` 登记的
**自定义平台**，其配置字段契约会通过反射其 `Config` 类构造函数自动推导：

- 构造函数中**无默认值的形参** ⇒ 必填（`required`）
- **有默认值的形参** ⇒ 可选（`optional`）

形参名按驼峰转下划线风格键名（如 `merchantNo` ⇒ `merchant_no`），与 `fromArray()` 读取的键一致。
因此自定义平台同样能享受 `inspect()` 的配置发现与缺失校验，无需手工登记。

## 设计要点

- **单一事实源**：能力与能力接口的一致性由 `CAPABILITY_CONTRACTS` 声明；配置字段契约由
  `CONFIG_SCHEMA`（内置）或构造函数反射（自定义）声明，避免与网关实现脱节。
- **无副作用**：所有查询方法均为只读，不创建网关实例、不发起网络请求。
- **可扩展**：新增平台只需登记 `gateway_class` / `config_class` 与差异化 `capabilities`，
  发现能力（inspect / configSchema）自动生效。
