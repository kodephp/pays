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

跨币种运算（如 CNY 与 USD 相加）会抛出 `InvalidArgumentException`，强制在编译期暴露币种不一致问题。
