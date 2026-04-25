# Kode Pays SDK 插件总览

Kode Pays 提供丰富的插件体系，覆盖支付业务的完整生命周期。

## 插件列表

| 插件 | 类名 | 支持网关 | 核心功能 |
|------|------|----------|----------|
| 分账插件 | `ProfitSharingPlugin` | 微信、支付宝、Stripe | 创建分账、查询分账、分账回退、解冻资金 |
| 转账插件 | `TransferPlugin` | 微信、支付宝、Stripe | 单笔转账、批量转账、查询转账、电子回单 |
| 退款插件 | `RefundPlugin` | 微信、支付宝、Stripe、PayPal | 申请退款、查询退款、取消退款 |
| 红包插件 | `RedPacketPlugin` | 微信、支付宝 | 普通红包、裂变红包、查询红包 |
| 订阅插件 | `SubscriptionPlugin` | Stripe、PayPal | 订阅计划、订阅管理、暂停/恢复/取消 |
| 对账插件 | `ReconciliationPlugin` | 微信、支付宝、Stripe | 下载对账单、解析对账单、差异比对 |
| 个人收款插件 | `PersonalReceivePlugin` | 微信、支付宝、Stripe | 收款码、查询记录、提现到银行卡 |
| 自动结算插件 | `AutoSettlementPlugin` | 微信、支付宝、Stripe、PayPal | 支付后自动提现到钱包 |
| 加密货币插件 | `CryptoPlugin` | Coinbase | 加密货币订单、链上确认、汇率查询 |

## 插件架构

所有插件遵循统一的设计模式：

```php
class ExamplePlugin
{
    protected GatewayInterface $gateway;

    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function doSomething(array $params): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->doWechatSomething($params),
            'alipay' => $this->doAlipaySomething($params),
            default => throw PayException::invalidArgument('当前网关不支持此功能'),
        };
    }
}
```

## 插件使用示例

### 分账插件

```php
use Kode\Pays\Plugin\ProfitSharingPlugin;

$plugin = new ProfitSharingPlugin($wechatGateway);

// 创建分账
$plugin->create([
    'transaction_id' => '4200000000000000',
    'out_order_no' => 'SHARE_001',
    'receivers' => [
        ['type' => 'MERCHANT_ID', 'account' => '123456', 'amount' => 100],
    ],
]);

// 查询分账
$plugin->query('SHARE_001');

// 分账回退
$plugin->return(['out_order_no' => 'SHARE_001', 'out_return_no' => 'RETURN_001', 'return_amount' => 100]);

// 解冻剩余资金
$plugin->unfreeze('4200000000000000');
```

### 转账插件

```php
use Kode\Pays\Plugin\TransferPlugin;

$plugin = new TransferPlugin($wechatGateway);

// 单笔转账
$plugin->single([
    'out_biz_no' => 'TRANSFER_001',
    'amount' => 100,
    'recipient' => ['type' => 'openid', 'account' => 'openid_xxx', 'name' => '张三'],
]);

// 批量转账
$plugin->batch([
    'out_biz_no' => 'BATCH_001',
    'transfer_detail_list' => [
        ['out_detail_no' => 'D001', 'amount' => 100, 'recipient' => [...]],
        ['out_detail_no' => 'D002', 'amount' => 200, 'recipient' => [...]],
    ],
]);
```

### 对账插件

```php
use Kode\Pays\Plugin\ReconciliationPlugin;

$plugin = new ReconciliationPlugin($wechatGateway);

// 下载对账单
$bill = $plugin->downloadBill(['bill_date' => '20240425', 'bill_type' => 'ALL']);

// 解析对账单
$records = $plugin->parseBill($rawCsvData);

// 差异比对
$diff = $plugin->diff($systemOrders, $records);
```

### 自动结算插件

```php
use Kode\Pays\Core\WalletManager;
use Kode\Pays\Plugin\AutoSettlementPlugin;

$walletManager = new WalletManager();
$walletManager->bind('user_001', 'wechat_wallet', [
    'account' => 'openid_xxx',
    'auto_settlement' => true,
    'min_amount' => 100,
]);

$plugin = new AutoSettlementPlugin($wechatGateway, $walletManager);

// 支付成功后自动结算
$plugin->settle('user_001', [
    'transaction_id' => 'T001',
    'amount' => 1000,
    'out_biz_no' => 'SETTLE_001',
]);
```

### 加密货币插件

```php
use Kode\Pays\Plugin\CryptoPlugin;

$plugin = new CryptoPlugin($coinbaseGateway);

// 创建法币定价订单
$plugin->createOrder(['out_trade_no' => 'ORDER_001', 'total_amount' => 10000, 'currency' => 'USD']);

// 创建 USDC 定价订单
$plugin->createCryptoOrder(['out_trade_no' => 'ORDER_002', 'crypto_amount' => '50.00', 'crypto_currency' => 'USDC']);

// 查询链上确认
$plugin->getOnChainStatus($chargeId);

// 检查是否达到安全确认数
$plugin->isConfirmed($chargeId, 6);
```

## 插件与约束验证

转账插件支持注入 `FundConstraintValidator` 进行风控验证：

```php
use Kode\Pays\Core\FundConstraintValidator;
use Kode\Pays\Plugin\TransferPlugin;

$validator = new FundConstraintValidator();
$validator->setTransferConstraints([
    'min_amount' => 100,
    'max_amount' => 200000,
    'daily_limit' => 1000000,
    'daily_count_limit' => 100,
    'allowed_hours' => [9, 22],
]);

$plugin = new TransferPlugin($gateway, $validator);
```

## 开发自定义插件

参考现有插件实现，核心要点：

1. 构造函数接收 `GatewayInterface`
2. 使用 `match` 表达式根据网关名称分发
3. 通过反射获取网关配置（`getGatewayConfig`）
4. 抛出 `PayException` 处理不支持的场景
5. 提供中文注释和完整的使用示例
