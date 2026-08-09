<?php

declare(strict_types=1);

namespace Kode\Pays\Facade;

use Kode\Pays\Config\ConfigLoader;
use Kode\Pays\Contract\ConfigInterface;
use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Core\IdempotencyGuard;
use Kode\Pays\Core\NotifyGuard;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\PaymentPoller;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Support\HttpClient;

/**
 * Kode Pays SDK 门面类
 *
 * 提供静态方法快速访问 SDK 核心能力，是开发者最常用的入口。
 * 支持链式配置、全局事件监听注册、配置缓存等高级特性。
 *
 * 示例：
 * ```php
 * use Kode\Pays\Facade\Pay;
 *
 * // 快速创建网关
 * $wechat = Pay::wechat([
 *     'app_id' => 'wx123456',
 *     'mch_id' => '123456',
 *     'api_key' => 'your-key',
 * ]);
 *
 * // 使用配置 DTO 创建
 * $config = WechatConfig::fromArray([...]);
 * $wechat = Pay::createWithConfig('wechat', $config);
 *
 * // 注册全局事件监听
 * Pay::on('pay.payment.success', function ($payload) {
 *     // 发送通知
 * });
 *
 * // 预注册配置，后续快速创建
 * Pay::registerConfig('wechat', $configArray);
 * $wechat = Pay::wechat(); // 无需再传配置
 * ```
 */
class Pay
{
    /**
     * 全局事件分发器实例
     */
    protected static ?EventDispatcher $dispatcher = null;

    /**
     * 全局默认 HTTP 客户端
     */
    protected static ?HttpClient $httpClient = null;

    /**
     * 预注册的配置缓存
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $configCache = [];

    /**
     * 网关实例缓存
     *
     * @var array<string, GatewayInterface>
     */
    protected static array $gatewayCache = [];

    /**
     * 魔术方法：通过门面快速创建网关
     *
     * 支持：Pay::wechat($config)、Pay::alipay($config) 等
     * 如果已预注册配置，可省略参数：Pay::wechat()
     *
     * @param string $name 网关标识
     * @param array<int, mixed> $arguments 参数列表，第一个为配置数组（可选）
     * @return GatewayInterface
     * @throws PayException
     */
    public static function __callStatic(string $name, array $arguments): GatewayInterface
    {
        $cacheKey = $name;

        // 检查实例缓存
        if (isset(self::$gatewayCache[$cacheKey])) {
            return self::$gatewayCache[$cacheKey];
        }

        // 获取配置
        if (!empty($arguments)) {
            $config = $arguments[0];
        } elseif (isset(self::$configCache[$name])) {
            $config = self::$configCache[$name];
        } else {
            throw PayException::configError("创建 {$name} 网关时必须传入配置参数，或先调用 Pay::registerConfig('{$name}', ...) 预注册配置");
        }

        $gateway = GatewayFactory::create($name, $config, self::$httpClient);

        // 自动注入全局事件分发器
        if (self::$dispatcher !== null && method_exists($gateway, 'setDispatcher')) {
            $gateway->setDispatcher(self::$dispatcher);
        }

        // 缓存实例
        self::$gatewayCache[$cacheKey] = $gateway;

        return $gateway;
    }

    /**
     * 通用创建网关方法
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $config 配置数组
     * @return GatewayInterface
     * @throws PayException
     */
    public static function create(string $gateway, array $config): GatewayInterface
    {
        return GatewayFactory::create($gateway, $config, self::$httpClient);
    }

    /**
     * 使用配置 DTO 创建网关
     *
     * @param string $gateway 网关标识
     * @param ConfigInterface $config 配置 DTO
     * @return GatewayInterface
     * @throws PayException
     */
    public static function createWithConfig(string $gateway, ConfigInterface $config): GatewayInterface
    {
        return GatewayFactory::createWithConfig($gateway, $config, self::$httpClient);
    }

    /**
     * 自动配置 DTO 转换后创建网关
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $config 原始配置数组
     * @return GatewayInterface
     * @throws PayException
     */
    public static function createAutoConfig(string $gateway, array $config): GatewayInterface
    {
        return GatewayFactory::createAutoConfig($gateway, $config, self::$httpClient);
    }

    /**
     * 预注册网关配置
     *
     * 注册后可通过 Pay::wechat() 无参快速创建网关
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $config 配置数组
     */
    public static function registerConfig(string $gateway, array $config): void
    {
        self::$configCache[$gateway] = $config;
    }

    /**
     * 获取预注册的配置
     *
     * @param string $gateway 网关标识
     * @return array<string, mixed>|null
     */
    public static function getConfig(string $gateway): ?array
    {
        return self::$configCache[$gateway] ?? null;
    }

    /**
     * 清除网关实例缓存
     *
     * @param string|null $gateway 指定网关标识，null 表示清除所有
     */
    public static function clearCache(?string $gateway = null): void
    {
        if ($gateway === null) {
            self::$gatewayCache = [];
        } else {
            unset(self::$gatewayCache[$gateway]);
        }
    }

    /**
     * 注册全局事件监听器
     *
     * @param string $eventName 事件名称，可使用 Kode\Pays\Event\Events 常量
     * @param callable $listener 监听器回调
     * @param int $priority 优先级（数值越大越先执行）
     */
    public static function on(string $eventName, callable $listener, int $priority = 0): void
    {
        self::getDispatcher()->listen($eventName, $listener, $priority);
    }

    /**
     * 触发全局事件
     *
     * @param string $eventName 事件名称
     * @param mixed $payload 事件载荷
     * @return mixed
     */
    public static function emit(string $eventName, mixed $payload = null): mixed
    {
        return self::getDispatcher()->dispatch($eventName, $payload);
    }

    /**
     * 设置全局默认 HTTP 客户端
     *
     * @param HttpClient $httpClient
     */
    public static function setHttpClient(HttpClient $httpClient): void
    {
        self::$httpClient = $httpClient;
    }

    /**
     * 设置全局事件分发器
     *
     * @param EventDispatcher $dispatcher
     */
    public static function setDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * 获取全局事件分发器
     */
    public static function getDispatcher(): EventDispatcher
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = new EventDispatcher();
        }

        return self::$dispatcher;
    }

    /**
     * 注册自定义网关
     *
     * @param string $name 网关标识
     * @param class-string<GatewayInterface> $class 网关类全限定名
     * @throws PayException
     */
    public static function register(string $name, string $class): void
    {
        GatewayFactory::register($name, $class);
    }

    /**
     * 获取所有支持的网关标识
     *
     * @return string[]
     */
    public static function getGateways(): array
    {
        return GatewayFactory::getNames();
    }

    /**
     * 判断是否支持某网关
     *
     * @param string $gateway 网关标识
     * @return bool
     */
    public static function has(string $gateway): bool
    {
        return GatewayFactory::has($gateway);
    }

    /**
     * 解析并返回强类型网关实例
     *
     * 优先使用预注册配置（{@see registerConfig}），其次使用传入配置；实例按标识缓存。
     * 返回的实例可调用标准接口方法，也可直接调用该平台的「特色方法」。
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed>|null $config 网关配置（可选，缺省使用预注册配置）
     * @return GatewayInterface
     * @throws PayException
     */
    public static function gateway(string $gateway, ?array $config = null): GatewayInterface
    {
        $cacheKey = $gateway;

        if (isset(self::$gatewayCache[$cacheKey])) {
            return self::$gatewayCache[$cacheKey];
        }

        if ($config !== null) {
            $resolved = $config;
        } elseif (isset(self::$configCache[$gateway])) {
            $resolved = self::$configCache[$gateway];
        } else {
            throw PayException::configError(
                "创建 {$gateway} 网关时必须传入配置参数，或先调用 Pay::registerConfig('{$gateway}', ...)",
            );
        }

        $instance = GatewayFactory::create($gateway, $resolved, self::$httpClient);

        if (self::$dispatcher !== null && method_exists($instance, 'setDispatcher')) {
            $instance->setDispatcher(self::$dispatcher);
        }

        self::$gatewayCache[$cacheKey] = $instance;

        return $instance;
    }

    /**
     * 统一入口：调用任意已接入平台的任意方法
     *
     * 无论是标准接口方法（createOrder/refund/...）还是各平台「特色方法」，
     * 均可通过本方法以统一方式调用，实现「接入哪个平台都可使用」的设计目标。
     *
     * @param string $gateway 网关标识
     * @param string $method 方法名（接口方法或平台特色方法）
     * @param mixed ...$args 方法参数
     * @return mixed 被调用方法的返回值
     * @throws PayException
     */
    public static function call(string $gateway, string $method, mixed ...$args): mixed
    {
        $instance = self::gateway($gateway);

        if (!method_exists($instance, $method)) {
            throw PayException::methodNotSupported($gateway, $method);
        }

        return $instance->$method(...$args);
    }

    /**
     * 统一创建订单
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function createOrder(string $gateway, array $params): array
    {
        return self::call($gateway, 'createOrder', $params);
    }

    /**
     * 统一查询订单
     *
     * @param string $gateway 网关标识
     * @param string $orderId 商户订单号或平台订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function queryOrder(string $gateway, string $orderId): array
    {
        return self::call($gateway, 'queryOrder', $orderId);
    }

    /**
     * 统一申请退款
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 退款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function refund(string $gateway, array $params): array
    {
        return self::call($gateway, 'refund', $params);
    }

    /**
     * 统一查询退款
     *
     * @param string $gateway 网关标识
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function queryRefund(string $gateway, string $refundId): array
    {
        return self::call($gateway, 'queryRefund', $refundId);
    }

    /**
     * 统一关闭订单
     *
     * @param string $gateway 网关标识
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function closeOrder(string $gateway, string $orderId): array
    {
        return self::call($gateway, 'closeOrder', $orderId);
    }

    /**
     * 统一发起分账
     *
     * 经统一入口动态派发到目标网关的 `createProfitSharing` 特色方法，
     * 支持微信 / 支付宝 / Stripe / 抖音 / 云闪付等已接入分账能力的平台。
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 分账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function profitSharingCreate(string $gateway, array $params): array
    {
        return self::call($gateway, 'createProfitSharing', $params);
    }

    /**
     * 统一查询分账结果
     *
     * @param string $gateway 网关标识
     * @param string $outOrderNo 商户分账订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function profitSharingQuery(string $gateway, string $outOrderNo): array
    {
        return self::call($gateway, 'queryProfitSharing', $outOrderNo);
    }

    /**
     * 统一发起分账回退
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 回退参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function profitSharingReturn(string $gateway, array $params): array
    {
        return self::call($gateway, 'returnProfitSharing', $params);
    }

    /**
     * 统一解冻未分账的剩余资金
     *
     * @param string $gateway 网关标识
     * @param string $transactionId 原支付订单号 / 交易流水号
     * @param string|null $outOrderNo 商户解冻单号（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function profitSharingUnfreeze(string $gateway, string $transactionId, ?string $outOrderNo = null): array
    {
        return self::call($gateway, 'unfreezeProfitSharing', $transactionId, $outOrderNo);
    }

    /**
     * 统一发起单笔转账
     *
     * 经统一入口动态派发到目标网关的 `singleTransfer` 特色方法，
     * 支持微信 / 支付宝 / Stripe 等已接入转账能力的平台。
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 转账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function transferSingle(string $gateway, array $params): array
    {
        return self::call($gateway, 'singleTransfer', $params);
    }

    /**
     * 统一发起批量转账
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 批量转账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function transferBatch(string $gateway, array $params): array
    {
        return self::call($gateway, 'batchTransfer', $params);
    }

    /**
     * 统一查询转账结果
     *
     * @param string $gateway 网关标识
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function transferQuery(string $gateway, string $outBizNo): array
    {
        return self::call($gateway, 'queryTransfer', $outBizNo);
    }

    /**
     * 统一查询转账电子回单
     *
     * @param string $gateway 网关标识
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function transferReceipt(string $gateway, string $outBizNo): array
    {
        return self::call($gateway, 'transferReceipt', $outBizNo);
    }

    /**
     * 统一发放普通红包
     *
     * 经统一入口动态派发到目标网关的 `sendRedPacket` 特色方法，
     * 支持微信支付、支付宝等已接入红包能力的平台。
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 红包参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function redPacketSend(string $gateway, array $params): array
    {
        return self::call($gateway, 'sendRedPacket', $params);
    }

    /**
     * 统一发放裂变红包（群红包）
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 裂变红包参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function redPacketGroup(string $gateway, array $params): array
    {
        return self::call($gateway, 'groupRedPacket', $params);
    }

    /**
     * 统一查询红包发放记录
     *
     * @param string $gateway 网关标识
     * @param string $mchBillNo 商户红包单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function redPacketQuery(string $gateway, string $mchBillNo): array
    {
        return self::call($gateway, 'queryRedPacket', $mchBillNo);
    }

    /**
     * 统一创建订阅计划
     *
     * 经统一入口动态派发到目标网关的 `createPlan` 特色方法，
     * 支持 Stripe、PayPal 等已接入订阅能力的平台。
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 计划参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function subscriptionCreatePlan(string $gateway, array $params): array
    {
        return self::call($gateway, 'createPlan', $params);
    }

    /**
     * 统一创建订阅
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 订阅参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function subscriptionCreate(string $gateway, array $params): array
    {
        return self::call($gateway, 'createSubscription', $params);
    }

    /**
     * 统一取消订阅
     *
     * @param string $gateway 网关标识
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function subscriptionCancel(string $gateway, string $subscriptionId): array
    {
        return self::call($gateway, 'cancelSubscription', $subscriptionId);
    }

    /**
     * 统一暂停订阅
     *
     * @param string $gateway 网关标识
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function subscriptionPause(string $gateway, string $subscriptionId): array
    {
        return self::call($gateway, 'pauseSubscription', $subscriptionId);
    }

    /**
     * 统一恢复订阅
     *
     * @param string $gateway 网关标识
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function subscriptionResume(string $gateway, string $subscriptionId): array
    {
        return self::call($gateway, 'resumeSubscription', $subscriptionId);
    }

    /**
     * 统一查询订阅详情
     *
     * @param string $gateway 网关标识
     * @param string $subscriptionId 订阅 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function subscriptionGet(string $gateway, string $subscriptionId): array
    {
        return self::call($gateway, 'getSubscription', $subscriptionId);
    }

    /**
     * 统一个人收款二维码入口
     *
     * 经 {@see self::call()} 派发到网关原生方法；网关未实现个人收款能力时抛「无此方法」。
     *
     * @param string $gateway 网关标识（如 wechat / alipay / stripe）
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     */
    public static function personalReceiveQrCode(string $gateway, array $params): array
    {
        return self::call($gateway, 'createQrCode', $params);
    }

    /**
     * 统一个人收款记录查询入口
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 查询参数
     * @return array<string, mixed>
     */
    public static function personalReceiveQueryRecords(string $gateway, array $params): array
    {
        return self::call($gateway, 'queryRecords', $params);
    }

    /**
     * 统一个人收款提现入口
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 提现参数
     * @return array<string, mixed>
     */
    public static function personalReceiveWithdraw(string $gateway, array $params): array
    {
        return self::call($gateway, 'withdraw', $params);
    }

    /**
     * 统一个人收款提现查询入口
     *
     * @param string $gateway 网关标识
     * @param string $outBizNo 商户提现单号
     * @return array<string, mixed>
     */
    public static function personalReceiveQueryWithdraw(string $gateway, string $outBizNo): array
    {
        return self::call($gateway, 'queryWithdraw', $outBizNo);
    }

    /**
     * 统一异步通知校验（安全入口）
     *
     * 先经过 {@see NotifyGuard} 做通用安全过滤（签名字段、防重放等），
     * 再委托给对应平台的 verifyNotify 进行平台级签名验证。
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $data 通知数据
     * @param array<string, mixed> $options NotifyGuard 校验选项（见 NotifyGuard::guard）
     * @return bool 验证结果
     * @throws PayException
     */
    public static function verify(string $gateway, array $data, array $options = []): bool
    {
        NotifyGuard::guard($data, $options);

        return self::call($gateway, 'verifyNotify', $data);
    }

    /**
     * 统一下载交易对账单入口
     *
     * 经 {@see self::call()} 派发到网关原生方法；网关未实现对账能力时抛「无此方法」。
     *
     * @param string $gateway 网关标识（如 wechat / alipay / stripe）
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed>
     */
    public static function reconciliationDownloadBill(string $gateway, array $params): array
    {
        return self::call($gateway, 'downloadBill', $params);
    }

    /**
     * 统一下载资金账单入口
     *
     * @param string $gateway 网关标识
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<string, mixed>
     */
    public static function reconciliationDownloadFundFlow(string $gateway, array $params): array
    {
        return self::call($gateway, 'downloadFundFlow', $params);
    }

    /**
     * 统一解析对账单入口
     *
     * @param string $gateway 网关标识
     * @param string $rawData 原始对账单数据（CSV / JSON）
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    public static function reconciliationParseBill(string $gateway, string $rawData): array
    {
        return self::call($gateway, 'parseBill', $rawData);
    }

    /* ==================== 退款能力统一入口 ==================== */

    /**
     * 申请退款（统一入口）
     *
     * @param string $gateway 网关标识（wechat/alipay/stripe/paypal）
     * @param array<string, mixed> $params 退款参数（out_refund_no/refund_fee 必填，
     *                                       out_trade_no 与 transaction_id 至少其一）
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function refundApply(string $gateway, array $params): array
    {
        return self::call($gateway, 'applyRefund', $params);
    }

    /**
     * 查询退款结果（统一入口）
     *
     * @param string $gateway 网关标识
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function refundQuery(string $gateway, string $outRefundNo): array
    {
        return self::call($gateway, 'queryRefund', $outRefundNo);
    }

    /**
     * 取消退款（统一入口，仅 Stripe 等部分网关支持）
     *
     * @param string $gateway 网关标识
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function refundCancel(string $gateway, string $outRefundNo): array
    {
        return self::call($gateway, 'cancelRefund', $outRefundNo);
    }

    /**
     * 创建法币定价的加密货币订单（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param array<string, mixed> $params 订单参数（out_trade_no / total_amount / currency 等）
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoCreateOrder(string $gateway, array $params): array
    {
        return self::call($gateway, 'createOrder', $params);
    }

    /**
     * 创建指定加密货币定价的订单（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param array<string, mixed> $params 订单参数（out_trade_no / crypto_amount / crypto_currency 等）
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoCreateCryptoOrder(string $gateway, array $params): array
    {
        return self::call($gateway, 'createCryptoOrder', $params);
    }

    /**
     * 查询加密货币订单（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param string $outTradeNo 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoQueryOrder(string $gateway, string $outTradeNo): array
    {
        return self::call($gateway, 'queryOrder', $outTradeNo);
    }

    /**
     * 发起加密货币退款（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param array<string, mixed> $params 退款参数（charge_id / refund_fee / currency 等）
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoRefund(string $gateway, array $params): array
    {
        return self::call($gateway, 'refund', $params);
    }

    /**
     * 获取加密货币支付地址（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param string $chargeId Charge ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoGetPaymentAddresses(string $gateway, string $chargeId): array
    {
        return self::call($gateway, 'getPaymentAddresses', $chargeId);
    }

    /**
     * 查询链上确认状态（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param string $chargeId Charge ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoGetOnChainStatus(string $gateway, string $chargeId): array
    {
        return self::call($gateway, 'getConfirmations', $chargeId);
    }

    /**
     * 查询加密货币实时汇率（统一入口）
     *
     * @param string $gateway 网关标识（如 coinbase）
     * @param string $cryptoCurrency 加密货币代码
     * @param string $fiatCurrency 法币代码
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function cryptoGetExchangeRate(string $gateway, string $cryptoCurrency, string $fiatCurrency = 'USD'): array
    {
        return self::call($gateway, 'getExchangeRate', $cryptoCurrency, $fiatCurrency);
    }

    /**
     * 一次登记新支付平台（统一扩展入口）
     *
     * 同时把平台元数据登记到 {@see GatewayManifest} 与 {@see GatewayFactory}，
     * 后续即可通过统一入口直接调用，满足「后续增加新的也要增加个」的扩展诉求。
     *
     * @param string $name 平台标识
     * @param array<string, mixed> $manifest 平台清单（见 GatewayManifest::register）
     * @param class-string<GatewayInterface>|null $gatewayClass 网关实现类（可选）
     * @param class-string<ConfigInterface>|null $configClass 配置 DTO 类（可选）
     * @throws PayException
     */
    public static function extend(
        string $name,
        array $manifest,
        ?string $gatewayClass = null,
        ?string $configClass = null,
    ): void {
        if ($gatewayClass !== null) {
            $manifest['gateway_class'] = $gatewayClass;
        }

        if ($configClass !== null) {
            $manifest['config_class'] = $configClass;
        }

        GatewayManifest::register($name, $manifest);

        if ($gatewayClass !== null) {
            GatewayFactory::register($name, $gatewayClass, $configClass);
        }
    }

    /**
     * 获取统一平台清单（全部或指定平台）
     *
     * @param string|null $name 平台标识，null 表示返回全部
     * @return array<string, mixed>|array<string, array<string, mixed>>
     * @throws PayException
     */
    public static function manifest(?string $name = null): array
    {
        if ($name === null) {
            return GatewayManifest::all();
        }

        return GatewayManifest::get($name);
    }

    /**
     * 获取平台能力开关集合
     *
     * @param string $name 平台标识
     * @return array<string, bool>
     * @throws PayException
     */
    public static function capabilities(string $name): array
    {
        return GatewayManifest::capabilities($name);
    }

    /**
     * 判断平台是否支持某项能力
     *
     * @param string $name 平台标识
     * @param string $capability 能力常量（见 GatewayManifest::CAP_*）
     * @throws PayException
     */
    public static function supports(string $name, string $capability): bool
    {
        return GatewayManifest::supports($name, $capability);
    }

    /**
     * 获取平台基础域名
     *
     * @param string $name 平台标识
     * @param bool $sandbox 是否沙箱域名
     * @throws PayException
     */
    public static function baseUrl(string $name, bool $sandbox = false): string
    {
        return GatewayManifest::baseUrl($name, $sandbox);
    }

    /**
     * 获取平台签名方案（提示性）
     *
     * @param string $name 平台标识
     * @throws PayException
     */
    public static function signatureScheme(string $name): string
    {
        return GatewayManifest::signatureScheme($name);
    }

    /**
     * 获取平台所属区域
     *
     * @param string $name 平台标识
     * @throws PayException
     */
    public static function region(string $name): string
    {
        return GatewayManifest::region($name);
    }

    /**
     * 批量创建支付订单
     *
     * 同时向多个网关发起支付请求，返回各网关结果。
     *
     * @param array<string, array<string, mixed>> $orders 网关 => 订单参数映射
     * @return array<string, array<string, mixed>> 网关 => 结果映射
     */
    public static function batchCreate(array $orders): array
    {
        $results = [];

        foreach ($orders as $gateway => $params) {
            try {
                $instance = self::__callStatic($gateway, []);
                $results[$gateway] = [
                    'success' => true,
                    'data' => $instance->createOrder($params),
                ];
            } catch (PayException $e) {
                $results[$gateway] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
            }
        }

        return $results;
    }

    /**
     * 创建支付结果轮询器
     *
     * @param string $gateway 网关标识
     * @param int $interval 轮询间隔（秒）
     * @param int $maxAttempts 最大轮询次数
     * @param int $timeout 总超时时间（秒）
     * @return PaymentPoller
     */
    public static function poller(
        string $gateway,
        int $interval = 3,
        int $maxAttempts = 20,
        int $timeout = 60,
    ): PaymentPoller {
        $instance = self::__callStatic($gateway, []);

        return new PaymentPoller($instance, $interval, $maxAttempts, $timeout);
    }

    /**
     * 创建幂等性保护器
     *
     * @param int $lockTtl 锁过期时间（秒）
     * @param int $resultTtl 结果缓存时间（秒）
     * @return IdempotencyGuard
     */
    public static function guard(int $lockTtl = 60, int $resultTtl = 86400): IdempotencyGuard
    {
        return new IdempotencyGuard($lockTtl, $resultTtl);
    }

    /**
     * 从环境变量加载并创建网关
     *
     * @param string $gateway 网关标识
     * @param string $envPrefix 环境变量前缀
     * @return GatewayInterface
     * @throws PayException
     */
    public static function fromEnv(string $gateway, string $envPrefix): GatewayInterface
    {
        $config = ConfigLoader::fromEnv($envPrefix);

        return self::create($gateway, $config);
    }

    /**
     * 从配置文件加载并创建网关
     *
     * @param string $gateway 网关标识
     * @param string $path 配置文件路径
     * @return GatewayInterface
     * @throws PayException
     */
    public static function fromFile(string $gateway, string $path): GatewayInterface
    {
        $config = ConfigLoader::fromFile($path);

        return self::create($gateway, $config);
    }

    /**
     * 从多环境配置加载并创建网关
     *
     * @param string $gateway 网关标识
     * @param string $basePath 配置目录
     * @param string|null $env 环境名称
     * @return GatewayInterface
     * @throws PayException
     */
    public static function fromEnvConfig(string $gateway, string $basePath, ?string $env = null): GatewayInterface
    {
        $config = ConfigLoader::loadForEnv($basePath, $gateway, $env);

        return self::create($gateway, $config);
    }
}
