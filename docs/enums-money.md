# 枚举与金额值对象

Kode Pays 在 `Kode\Pays\Enum` 与 `Kode\Pays\Support` 命名空间下提供一组不可变（immutable）的类型安全工具，用于消除支付业务中最易出错的「字符串状态」与「浮点金额」两类隐患。

## 目录

- [货币枚举 Currency](#货币枚举-currency)
- [交易状态枚举 TradeStatus](#交易状态枚举-tradestatus)
- [交易类型枚举 TradeType](#交易类型枚举-tradetype)
- [金额值对象 Money](#金额值对象-money)

## 货币枚举 Currency

以 ISO 4217 为标准，封装货币代码、数字代码、最小货币单位（小数位）与符号。

```php
use Kode\Pays\Enum\Currency;

$cny = Currency::fromCode('CNY');        // Currency::CNY
$cny = Currency::fromCode('cny');        // 大小写不敏感，同样返回 CNY
$code = Currency::CNY->numericCode();    // '156'
$units = Currency::CNY->minorUnits();    // 2（最小货币单位小数位）
$symbol = Currency::CNY->symbol();       // '¥'
$zeroDecimal = Currency::JPY->isZeroDecimal(); // true（日元无小数位）
```

常用币种已内置：`CNY`、`USD`、`EUR`、`GBP`、`JPY`、`HKD`、`MOP`、`TWD`、`KRW`、`SGD`、`AUD`、`CAD`、`CHF`、`MYR`、`THB`、`PHP`、`IDR`、`INR`、`RUB`、`BRL`。
`fromCode()` 无法识别时返回 `null`，`fromCodeOrFail()` 会抛出 `InvalidArgumentException`。

## 交易状态枚举 TradeStatus

将微信、支付宝、银联、Stripe 等各渠道的状态字符串归一化为统一枚举，便于业务侧做无差别判断。

```php
use Kode\Pays\Enum\TradeStatus;

$status = TradeStatus::fromRaw('TRADE_SUCCESS'); // TradeStatus::SUCCESS
$status = TradeStatus::fromRaw('NOTPAY');        // TradeStatus::PENDING
$status = TradeStatus::fromRaw('FAIL');          // TradeStatus::FAILED

$status->isTerminal();  // 是否为终态（成功/失败/关闭/撤销/退款完成）
$status->isSuccess();   // 是否成功
```

`fromRaw()` 同时支持枚举名与常见别名（如 `TRADE_SUCCESS`、`FINISHED`、`PAID`、`WAIT_BUYER_PAY`），大小写不敏感；无法识别返回 `null`。

## 交易类型枚举 TradeType

统一聚合支付各渠道的下单场景标识（APP、JSAPI、NATIVE、MICROPAY、H5/MWEB 等）。

```php
use Kode\Pays\Enum\TradeType;

$type = TradeType::fromRaw('OFFICIAL');  // TradeType::JSAPI
$type = TradeType::fromRaw('QRCODE');    // TradeType::NATIVE
$type = TradeType::fromRaw('MINIPROGRAM'); // TradeType::MINI
```

## 金额值对象 Money

以「最小货币单位（分）」的整数存储金额，从根本上规避浮点精度问题；所有算术运算均返回新的 `Money` 实例，保持不可变语义。优先使用 `bcmath` 扩展保证乘除法精度，未安装时回退浮点计算。

```php
use Kode\Pays\Support\Money;

$price = Money::fromMajor(99.90, 'CNY'); // 9990 分
$tax = $price->multiply(0.06);           // 599 分（四舍五入）
$total = $price->add($tax);               // 10589 分
$total->getAmount();                       // '105.89'（字符串，无浮点误差）
$total->format();                          // '¥105.89'
$total->equals(Money::fromMinor(10589, 'CNY')); // true
```

### 主要方法

| 方法 | 说明 |
|------|------|
| `fromMinor(int, Currency\|string)` | 由最小单位（分）创建 |
| `fromMajor(int\|float\|string, Currency\|string)` | 由主单位（元）按币种小数位换算 |
| `add(Money)` / `subtract(Money)` | 加减，返回新实例（币种须一致） |
| `multiply(factor)` | 按因子缩放并四舍五入 |
| `compareTo(Money)` | 返回 -1/0/1 |
| `equals(Money)` | 金额与币种是否完全一致 |
| `isZero()` / `isPositive()` / `isNegative()` | 符号判断 |
| `getAmount()` | 主单位金额字符串 |
| `getMinorAmount()` | 最小单位整数（分） |
| `format()` | 带符号的展示字符串 |
| `zero(Currency\|string)` | 零值金额工厂 |
| `allocate(array $ratios)` | 按比例分账，返回等长的 `Money` 分片（余数归入末片） |
| `distribute(int $parts)` | 近似均分为 N 份（余数从前往后逐份 +1） |
| `absolute()` / `negate()` | 取绝对值 / 取反，返回新实例 |
| `min(Money)` / `max(Money)` | 取较小 / 较大者（币种须一致） |

跨币种运算（如 CNY 与 USD 相加）会抛出 `InvalidArgumentException`，强制在编译期暴露币种不一致问题。

### 分账与均分

`allocate()` 适用于分账、佣金拆分等按比例分配场景，`distribute()` 适用于红包、代金券等近似均分场景，二者均保证分片之和严格等于原金额。

```php
use Kode\Pays\Support\Money;

// 100 元按比例 3:7 分账
$parts = Money::fromMajor(100, 'CNY')->allocate([3, 7]);
// [¥30.00, ¥70.00]

// 100 分均分为 3 份（34/33/33）
$parts = Money::fromMinor(100, 'CNY')->distribute(3);
```

## 响应层类型化访问器

`PayResponse` 在 v1.21.0 起直接提供枚举与 `Money` 访问器，免去业务侧手工解析与换算：

```php
use Kode\Pays\Enum\Currency;
use Kode\Pays\Enum\TradeType;
use Kode\Pays\Support\Money;

$resp = $gateway->query($params);

$resp->getCurrencyEnum();   // ?Currency（读 currency / fee_type）
$resp->getTradeTypeEnum();  // ?TradeType（读 trade_type，已归一化别名）
$resp->getAmountMoney();    // ?Money（自动识别 total_fee/amount/total_amount 与币种）
$resp->getRefundAmountMoney(); // ?Money（读 refund_fee / refund_amount）

// 也可显式指定币种（用于响应未携带币种字段时）
$resp->getAmountMoney(Currency::JPY);
```

金额字段换算规则：含小数点的字符串或浮点视为「主单位（元）」，整数视为「最小单位（分）」，再按币种小数位换算为 `Money` 的最小单位整数。`getAmount()` / `getRefundAmount()` 的返回类型已放宽至 `int|float|string|null`，以兼容支付宝 `total_amount` 等字符串金额字段。
