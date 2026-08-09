<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\ConfigInterface;
use Kode\Pays\Contract\CryptoCapableInterface;
use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;

/**
 * 支付平台统一清单（Manifest）注册中心
 *
 * 把每一个支付平台的「域名(base URL)、沙箱域名、签名方案、能力开关、所属区域」
 * 集中声明在同一个 registry 中，调用方与其他模块只需查询本清单即可，无需关心各
 * 网关内部的实现细节。新增平台时通过 {@see GatewayManifest::register()} 或
 * {@see \Kode\Pays\Facade\Pay::extend()} 一次登记即可，遵循开闭原则。
 *
 * 内置平台会在首次访问时自动登记（{@see registerBuiltins()}），其域名优先读取
 * 网关类中声明的 PROD_BASE_URL / SANDBOX_BASE_URL 常量（反射回退），以便在不改动
 * 既有网关类的前提下，让域名信息也收敛到统一清单中查询。
 *
 * 能力开关（capability）为各平台对外能力的标准化描述，便于调用方在不实际创建
 * 网关实例的情况下判断「某平台是否支持某项功能」（如分账、订阅、转账、二维码等）。
 */
class GatewayManifest
{
    /**
     * 区域：国内支付
     */
    public const REGION_DOMESTIC = 'domestic';

    /**
     * 区域：国际支付
     */
    public const REGION_INTERNATIONAL = 'international';

    /**
     * 区域：跨境支付
     */
    public const REGION_CROSS_BORDER = 'cross_border';

    /**
     * 区域：数字钱包
     */
    public const REGION_WALLET = 'wallet';

    /**
     * 区域：加密货币
     */
    public const REGION_CRYPTO = 'crypto';

    /**
     * 区域：区域/本地支付
     */
    public const REGION_REGIONAL = 'regional';

    /**
     * 签名方案：MD5
     */
    public const SIGN_MD5 = 'md5';

    /**
     * 签名方案：RSA(SHA1)
     */
    public const SIGN_RSA = 'rsa';

    /**
     * 签名方案：RSA2(SHA256)
     */
    public const SIGN_RSA2 = 'rsa2';

    /**
     * 签名方案：HMAC-SHA256
     */
    public const SIGN_HMAC_SHA256 = 'hmac_sha256';

    /**
     * 签名方案：ECDSA
     */
    public const SIGN_ECDSA = 'ecdsa';

    /**
     * 签名方案：无（如 OAuth / Bearer Token 体系）
     */
    public const SIGN_NONE = 'none';

    /**
     * 能力：创建订单
     */
    public const CAP_CREATE_ORDER = 'create_order';

    /**
     * 能力：查询订单
     */
    public const CAP_QUERY_ORDER = 'query_order';

    /**
     * 能力：申请退款
     */
    public const CAP_REFUND = 'refund';

    /**
     * 能力：查询退款
     */
    public const CAP_QUERY_REFUND = 'query_refund';

    /**
     * 能力：关闭订单
     */
    public const CAP_CLOSE_ORDER = 'close_order';

    /**
     * 能力：异步通知验签
     */
    public const CAP_VERIFY_NOTIFY = 'verify_notify';

    /**
     * 能力：企业付款/转账
     */
    public const CAP_TRANSFER = 'transfer';

    /**
     * 能力：分账
     */
    public const CAP_PROFIT_SHARING = 'profit_sharing';

    /**
     * 能力：订阅/周期性扣款
     */
    public const CAP_SUBSCRIPTION = 'subscription';

    /**
     * 能力：对账
     */
    public const CAP_RECONCILIATION = 'reconciliation';

    /**
     * 能力：二维码支付
     */
    public const CAP_QR = 'qr';

    /**
     * 能力：现金红包
     */
    public const CAP_RED_PACKET = 'red_packet';

    /**
     * 能力：个人收款（收款码 / 记录查询 / 提现）
     */
    public const CAP_PERSONAL_RECEIVE = 'personal_receive';

    /**
     * 能力：自动结算（钱包余额 / 银行卡 / 外部账户 Payout）
     */
    public const CAP_SETTLEMENT = 'settlement';

    /**
     * 能力：Webhook 事件订阅
     */
    public const CAP_WEBHOOK = 'webhook';

    /**
     * 能力：加密货币支付（Coinbase 等）
     */
    public const CAP_CRYPTO = 'crypto';

    /**
     * 扩展能力与能力接口的契约映射
     *
     * 声明「某能力为 true」等价于「网关实现了对应的 CapableInterface」，是二者一致性的单一事实源。
     * 未列入此表的能力（如 create_order / refund / verify_notify）由 {@see GatewayInterface}
     * 基础契约覆盖，所有网关天然具备，不参与契约核对。
     *
     * @var array<string, class-string>
     */
    public const CAPABILITY_CONTRACTS = [
        self::CAP_TRANSFER => TransferCapableInterface::class,
        self::CAP_PROFIT_SHARING => ProfitSharingCapableInterface::class,
        self::CAP_SUBSCRIPTION => SubscriptionCapableInterface::class,
        self::CAP_RECONCILIATION => ReconciliationCapableInterface::class,
        self::CAP_RED_PACKET => RedPacketCapableInterface::class,
        self::CAP_PERSONAL_RECEIVE => PersonalReceiveCapableInterface::class,
        self::CAP_SETTLEMENT => SettlementCapableInterface::class,
        self::CAP_CRYPTO => CryptoCapableInterface::class,
    ];

    /**
     * 平台清单
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $entries = [];

    /**
     * 是否已执行内置平台自动登记
     */
    protected static bool $bootstrapped = false;

    /**
     * 注册一个支付平台清单
     *
     * @param string $name 平台标识，如 wechat、alipay
     * @param array<string, mixed> $manifest 清单数据，支持字段：
     *        label, region, base_url, sandbox_url, signature, capabilities,
     *        gateway_class, config_class
     * @throws PayException
     */
    public static function register(string $name, array $manifest): void
    {
        if ($name === '') {
            throw PayException::configError('平台标识不能为空');
        }

        self::$entries[$name] = self::normalize($name, $manifest);
    }

    /**
     * 批量注册平台清单
     *
     * @param array<string, array<string, mixed>> $entries
     * @throws PayException
     */
    public static function registerMany(array $entries): void
    {
        foreach ($entries as $name => $manifest) {
            self::register($name, $manifest);
        }
    }

    /**
     * 注销平台清单（不影响网关工厂注册）
     *
     * @param string $name 平台标识
     */
    public static function unregister(string $name): void
    {
        unset(self::$entries[$name]);
    }

    /**
     * 获取指定平台清单
     *
     * @param string $name 平台标识
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function get(string $name): array
    {
        self::bootstrap();

        if (!isset(self::$entries[$name])) {
            throw PayException::configError("未注册的平台：{$name}");
        }

        return self::$entries[$name];
    }

    /**
     * 判断平台是否已登记
     *
     * @param string $name 平台标识
     */
    public static function has(string $name): bool
    {
        self::bootstrap();

        return isset(self::$entries[$name]);
    }

    /**
     * 获取全部平台清单
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        self::bootstrap();

        return self::$entries;
    }

    /**
     * 获取全部已登记平台标识
     *
     * @return string[]
     */
    public static function names(): array
    {
        self::bootstrap();

        return array_keys(self::$entries);
    }

    /**
     * 判断平台是否支持某项能力
     *
     * @param string $name 平台标识
     * @param string $capability 能力常量，如 self::CAP_PROFIT_SHARING
     */
    public static function supports(string $name, string $capability): bool
    {
        $caps = self::capabilities($name);

        return (bool) ($caps[$capability] ?? false);
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
        $entry = self::get($name);

        return $entry['capabilities'] ?? [];
    }

    /**
     * 获取平台基础域名
     *
     * 优先返回清单中显式声明的域名；若未声明则通过网关类常量
     * PROD_BASE_URL / SANDBOX_BASE_URL 反射回退。
     *
     * @param string $name 平台标识
     * @param bool $sandbox 是否返回沙箱域名
     * @throws PayException
     */
    public static function baseUrl(string $name, bool $sandbox = false): string
    {
        $entry = self::get($name);

        $key = $sandbox ? 'sandbox_url' : 'base_url';
        $url = $entry[$key] ?? '';

        if ($url === '' && is_string($entry['gateway_class'] ?? null)) {
            $url = self::resolveBaseUrlFromClass($entry['gateway_class'], $sandbox);
        }

        return $url;
    }

    /**
     * 获取平台签名方案（提示性）
     *
     * @param string $name 平台标识
     * @throws PayException
     */
    public static function signatureScheme(string $name): string
    {
        $entry = self::get($name);

        return $entry['signature'] ?? self::SIGN_NONE;
    }

    /**
     * 获取平台所属区域
     *
     * @param string $name 平台标识
     * @throws PayException
     */
    public static function region(string $name): string
    {
        $entry = self::get($name);

        return $entry['region'] ?? self::REGION_INTERNATIONAL;
    }

    /**
     * 规范化清单数据，补齐默认值
     *
     * @param string $name 平台标识
     * @param array<string, mixed> $manifest 原始清单
     * @return array<string, mixed>
     */
    protected static function normalize(string $name, array $manifest): array
    {
        $gatewayClass = $manifest['gateway_class'] ?? null;
        $configClass = $manifest['config_class'] ?? null;

        if ($gatewayClass !== null && !is_subclass_of($gatewayClass, GatewayInterface::class)) {
            throw PayException::configError("平台 {$name} 的 gateway_class 必须实现 GatewayInterface：{$gatewayClass}");
        }

        if ($configClass !== null && !is_subclass_of($configClass, ConfigInterface::class)) {
            throw PayException::configError("平台 {$name} 的 config_class 必须实现 ConfigInterface：{$configClass}");
        }

        return [
            'name' => $name,
            'label' => $manifest['label'] ?? $name,
            'region' => $manifest['region'] ?? self::REGION_INTERNATIONAL,
            'base_url' => $manifest['base_url'] ?? '',
            'sandbox_url' => $manifest['sandbox_url'] ?? '',
            'signature' => $manifest['signature'] ?? self::SIGN_NONE,
            'capabilities' => array_merge(self::defaultCapabilities(), $manifest['capabilities'] ?? []),
            'gateway_class' => $gatewayClass,
            'config_class' => $configClass,
        ];
    }

    /**
     * 默认能力开关：标准接口方法默认开启，增值能力默认关闭
     *
     * @return array<string, bool>
     */
    protected static function defaultCapabilities(): array
    {
        return [
            self::CAP_CREATE_ORDER => true,
            self::CAP_QUERY_ORDER => true,
            self::CAP_REFUND => true,
            self::CAP_QUERY_REFUND => true,
            self::CAP_CLOSE_ORDER => true,
            self::CAP_VERIFY_NOTIFY => true,
            self::CAP_TRANSFER => false,
            self::CAP_PROFIT_SHARING => false,
            self::CAP_SUBSCRIPTION => false,
            self::CAP_RECONCILIATION => false,
            self::CAP_QR => false,
            self::CAP_RED_PACKET => false,
            self::CAP_PERSONAL_RECEIVE => false,
            self::CAP_WEBHOOK => false,
            self::CAP_CRYPTO => false,
            self::CAP_SETTLEMENT => false,
        ];
    }

    /**
     * 通过网关类常量反射解析基础域名
     *
     * 使用反射读取 PROD_BASE_URL / SANDBOX_BASE_URL 常量（不受常量可见性影响）。
     *
     * @param string $class 网关类全限定名
     * @param bool $sandbox 是否沙箱
     */
    protected static function resolveBaseUrlFromClass(string $class, bool $sandbox): string
    {
        if (!class_exists($class)) {
            return '';
        }

        $constant = $sandbox ? 'SANDBOX_BASE_URL' : 'PROD_BASE_URL';

        $reflection = new \ReflectionClass($class);

        if (!$reflection->hasConstant($constant)) {
            return '';
        }

        $value = $reflection->getConstant($constant);

        return is_string($value) ? $value : '';
    }

    /**
     * 首次访问时自动登记内置平台
     */
    protected static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        self::registerBuiltins();
    }

    /**
     * 登记内置平台清单
     *
     * 域名优先由 GatewayManifest::baseUrl() 在查询时通过网关类常量反射获取；
     * 此处仅集中声明区域、签名方案与差异化能力开关，使「域名/方法/能力」收敛到统一清单。
     */
    protected static function registerBuiltins(): void
    {
        $map = self::builtinMeta();

        foreach (GatewayFactory::getNames() as $name) {
            // 跳过已通过 extend() 等方式先行登记的项目，避免被内置元数据覆盖
            if (isset(self::$entries[$name])) {
                continue;
            }

            $meta = $map[$name] ?? [];

            self::register($name, [
                'label' => $meta['label'] ?? $name,
                'region' => $meta['region'] ?? self::REGION_INTERNATIONAL,
                'signature' => $meta['signature'] ?? self::SIGN_RSA2,
                'gateway_class' => GatewayFactory::getGatewayClass($name),
                'config_class' => GatewayFactory::getConfigClass($name),
                'capabilities' => $meta['capabilities'] ?? [],
            ]);
        }
    }

    /**
     * 内置平台的区域/签名/能力元数据
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function builtinMeta(): array
    {
        $domesticFeatures = [
            self::CAP_PROFIT_SHARING => true,
            self::CAP_QR => true,
            self::CAP_WEBHOOK => true,
        ];

        return [
            'wechat' => [
                'label' => '微信支付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_MD5,
                'capabilities' => array_merge($domesticFeatures, [
                    self::CAP_TRANSFER => true,
                    self::CAP_RED_PACKET => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                    self::CAP_SETTLEMENT => true,
                ]),
            ],
            'alipay' => [
                'label' => '支付宝',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_RSA2,
                'capabilities' => array_merge($domesticFeatures, [
                    self::CAP_TRANSFER => true,
                    self::CAP_RED_PACKET => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                    self::CAP_SETTLEMENT => true,
                ]),
            ],
            'wechat_v3' => [
                'label' => '微信支付 V3',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_ECDSA,
                // 现金红包与个人收款为 V2 专有接口，APIv3 无对应能力；分账亦未实现
                'capabilities' => [
                    self::CAP_CREATE_ORDER => true,
                    self::CAP_QUERY_ORDER => true,
                    self::CAP_CLOSE_ORDER => true,
                    self::CAP_VERIFY_NOTIFY => true,
                    self::CAP_TRANSFER => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_WEBHOOK => true,
                ],
            ],
            'unionpay' => [
                'label' => '云闪付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_RSA,
                'capabilities' => [self::CAP_QR => true, self::CAP_WEBHOOK => true, self::CAP_PROFIT_SHARING => true],
            ],
            'douyin' => [
                'label' => '抖音支付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_MD5,
                'capabilities' => [self::CAP_QR => true, self::CAP_PROFIT_SHARING => true],
            ],
            'meituan' => [
                'label' => '美团支付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_MD5,
                'capabilities' => [
                    self::CAP_TRANSFER => true,
                    self::CAP_PROFIT_SHARING => true,
                    self::CAP_RED_PACKET => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_SETTLEMENT => true,
                ],
            ],
            'jd' => [
                'label' => '京东支付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_MD5,
                'capabilities' => [
                    self::CAP_QR => true,
                    self::CAP_TRANSFER => true,
                    self::CAP_PROFIT_SHARING => true,
                    self::CAP_RED_PACKET => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_SETTLEMENT => true,
                ],
            ],
            'kuaishou' => [
                'label' => '快手支付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_MD5,
            ],
            'qq' => [
                'label' => 'QQ 支付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_MD5,
                'capabilities' => [self::CAP_QR => true],
            ],
            'paypal' => [
                'label' => 'PayPal',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [self::CAP_SUBSCRIPTION => true, self::CAP_WEBHOOK => true, self::CAP_SETTLEMENT => true],
            ],
            'stripe' => [
                'label' => 'Stripe',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_HMAC_SHA256,
                'capabilities' => [
                    self::CAP_SUBSCRIPTION => true,
                    self::CAP_TRANSFER => true,
                    self::CAP_WEBHOOK => true,
                    self::CAP_QR => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                    self::CAP_PROFIT_SHARING => true,
                    self::CAP_SETTLEMENT => true,
                    self::CAP_RECONCILIATION => true,
                ],
            ],
            'square' => [
                'label' => 'Square',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [self::CAP_WEBHOOK => true],
            ],
            'adyen' => [
                'label' => 'Adyen',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [
                    self::CAP_WEBHOOK => true,
                    self::CAP_TRANSFER => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_SETTLEMENT => true,
                ],
            ],
            'amazon' => [
                'label' => 'Amazon Pay',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
            ],
            'klarna' => [
                'label' => 'Klarna',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [self::CAP_WEBHOOK => true],
            ],
            'afterpay' => [
                'label' => 'Afterpay / Clearpay',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
            ],
            'alipay_global' => [
                'label' => '支付宝国际版',
                'region' => self::REGION_CROSS_BORDER,
                'signature' => self::SIGN_RSA2,
                'capabilities' => [self::CAP_WEBHOOK => true],
            ],
            'wise' => [
                'label' => 'Wise',
                'region' => self::REGION_CROSS_BORDER,
                'signature' => self::SIGN_NONE,
            ],
            'revolut' => [
                'label' => 'Revolut',
                'region' => self::REGION_CROSS_BORDER,
                'signature' => self::SIGN_NONE,
                'capabilities' => [
                    self::CAP_TRANSFER => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_SETTLEMENT => true,
                ],
            ],
            'payoneer' => [
                'label' => 'Payoneer',
                'region' => self::REGION_CROSS_BORDER,
                'signature' => self::SIGN_NONE,
            ],
            'apple' => [
                'label' => 'Apple Pay',
                'region' => self::REGION_WALLET,
                'signature' => self::SIGN_NONE,
            ],
            'google' => [
                'label' => 'Google Pay',
                'region' => self::REGION_WALLET,
                'signature' => self::SIGN_NONE,
            ],
            'coinbase' => [
                'label' => 'Coinbase',
                'region' => self::REGION_CRYPTO,
                'signature' => self::SIGN_NONE,
                'capabilities' => [self::CAP_WEBHOOK => true, self::CAP_CRYPTO => true],
            ],
            'hitpay' => [
                'label' => 'HitPay',
                'region' => self::REGION_REGIONAL,
                'signature' => self::SIGN_HMAC_SHA256,
                'capabilities' => [self::CAP_QR => true, self::CAP_WEBHOOK => true],
            ],
            'xendit' => [
                'label' => 'Xendit',
                'region' => self::REGION_REGIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [self::CAP_WEBHOOK => true],
            ],
            'aggregate' => [
                'label' => '聚合支付',
                'region' => self::REGION_REGIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [self::CAP_QR => true, self::CAP_WEBHOOK => true],
            ],
        ];
    }
}
