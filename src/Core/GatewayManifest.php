<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\BalanceCapableInterface;
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
 *
 * 此外本清单还提供「能力发现」与「配置发现」：
 * - {@see GatewayManifest::inspect()} 返回统一的接入响应（元信息 + 能力 + 可调用操作 + 配置字段 + 缺失校验），
 *   让开发者一次调用即得某平台接入所需的全部契约信息；
 * - {@see GatewayManifest::configSchema()} / {@see GatewayManifest::CONFIG_SCHEMA} 暴露每个平台所需的
 *   配置字段（必填/可选），开发者可据此快速准备配置并校验缺漏。
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
     * 能力：余额查询（实时余额 / 日终余额）
     */
    public const CAP_BALANCE = 'balance';

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
        self::CAP_BALANCE => BalanceCapableInterface::class,
        self::CAP_RED_PACKET => RedPacketCapableInterface::class,
        self::CAP_PERSONAL_RECEIVE => PersonalReceiveCapableInterface::class,
        self::CAP_SETTLEMENT => SettlementCapableInterface::class,
        self::CAP_CRYPTO => CryptoCapableInterface::class,
    ];

    /**
     * 能力标签（中文可读名）
     *
     * 用于 inspect() 等统一响应中，把能力常量映射为开发者可读的中文描述。
     *
     * @var array<string, string>
     */
    public const CAPABILITY_LABELS = [
        self::CAP_CREATE_ORDER => '创建订单',
        self::CAP_QUERY_ORDER => '查询订单',
        self::CAP_REFUND => '申请退款',
        self::CAP_QUERY_REFUND => '查询退款',
        self::CAP_CLOSE_ORDER => '关闭订单',
        self::CAP_VERIFY_NOTIFY => '异步通知验签',
        self::CAP_TRANSFER => '企业付款/转账',
        self::CAP_PROFIT_SHARING => '分账',
        self::CAP_SUBSCRIPTION => '订阅/周期扣款',
        self::CAP_RECONCILIATION => '对账',
        self::CAP_BALANCE => '余额查询',
        self::CAP_QR => '二维码支付',
        self::CAP_RED_PACKET => '现金红包',
        self::CAP_PERSONAL_RECEIVE => '个人收款',
        self::CAP_SETTLEMENT => '自动结算',
        self::CAP_WEBHOOK => 'Webhook 事件订阅',
        self::CAP_CRYPTO => '加密货币支付',
    ];

    /**
     * 能力 → 可调用操作（接口方法名）映射
     *
     * 声明「某能力为 true」后，调用方可据此得知网关上实际可用的接口方法名，
     * 便于在不实例化网关的前提下做能力发现与文档生成。
     *
     * @var array<string, string[]>
     */
    public const CAPABILITY_OPERATIONS = [
        self::CAP_CREATE_ORDER => ['createOrder'],
        self::CAP_QUERY_ORDER => ['queryOrder'],
        self::CAP_REFUND => ['refund', 'applyRefund'],
        self::CAP_QUERY_REFUND => ['queryRefund'],
        self::CAP_CLOSE_ORDER => ['closeOrder'],
        self::CAP_VERIFY_NOTIFY => ['verifyNotify', 'verify'],
        self::CAP_TRANSFER => ['singleTransfer', 'batchTransfer', 'queryTransfer', 'transferReceipt'],
        self::CAP_PROFIT_SHARING => [
            'createProfitSharing', 'queryProfitSharing', 'returnProfitSharing',
            'queryProfitSharingReturn', 'unfreezeProfitSharing',
        ],
        self::CAP_SUBSCRIPTION => [
            'createPlan', 'createSubscription', 'cancelSubscription',
            'pauseSubscription', 'resumeSubscription', 'getSubscription',
        ],
        self::CAP_RECONCILIATION => ['downloadBill', 'downloadFundFlow', 'parseBill'],
        self::CAP_BALANCE => ['queryBalance', 'queryDayEndBalance'],
        self::CAP_QR => ['createQrCode'],
        self::CAP_RED_PACKET => ['sendRedPacket', 'groupRedPacket', 'queryRedPacket'],
        self::CAP_PERSONAL_RECEIVE => ['createQrCode', 'queryRecords', 'withdraw', 'queryWithdraw'],
        self::CAP_SETTLEMENT => ['settleToWallet', 'settleToBankCard', 'settleToPayout', 'querySettlement'],
        self::CAP_WEBHOOK => [],
        self::CAP_CRYPTO => ['createCryptoOrder', 'getExchangeRate', 'getPaymentAddresses', 'getConfirmations'],
    ];

    /**
     * 配置字段契约（配置发现）
     *
     * 每个内置平台声明其 Config DTO 所需的配置键：
     * - required：必填项，缺失将无法通过配置校验
     * - optional：可选项，缺省时使用网关内部默认值
     *
     * 作为「配置字段契约」的单一事实源，开发者可据此快速得知需要准备哪些配置；
     * 内置平台之外的自定义平台会通过 {@see GatewayManifest::configSchema()} 反射其
     * Config 类构造函数自动推导，无需在此手工登记。
     *
     * @var array<string, array{required: string[], optional: string[]}>
     */
    public const CONFIG_SCHEMA = [
        'wechat' => [
            'required' => ['app_id', 'mch_id', 'api_key'],
            'optional' => ['api_v3_key', 'cert_path', 'key_path', 'platform_cert_path', 'sandbox'],
        ],
        'wechat_v3' => [
            'required' => ['mch_id', 'serial_no', 'private_key', 'api_key'],
            'optional' => ['app_id', 'sandbox'],
        ],
        'alipay' => [
            'required' => ['app_id', 'private_key', 'public_key'],
            'optional' => ['app_auth_token', 'sandbox'],
        ],
        'unionpay' => [
            'required' => ['mer_id', 'cert_path', 'cert_pwd'],
            'optional' => ['sandbox'],
        ],
        'douyin' => [
            'required' => ['app_id', 'merchant_id', 'salt'],
            'optional' => ['sandbox'],
        ],
        'meituan' => [
            'required' => ['app_id', 'app_secret', 'merchant_id'],
            'optional' => ['sandbox'],
        ],
        'jd' => [
            'required' => ['merchant_no', 'des_key', 'md5_key', 'rsa_private_key', 'rsa_public_key'],
            'optional' => ['sandbox'],
        ],
        'kuaishou' => [
            'required' => ['app_id', 'app_secret', 'merchant_id'],
            'optional' => ['sandbox'],
        ],
        'qq' => [
            'required' => ['app_id', 'mch_id', 'api_key'],
            'optional' => ['notify_url', 'sandbox'],
        ],
        'paypal' => [
            'required' => ['client_id', 'client_secret'],
            'optional' => ['sandbox'],
        ],
        'stripe' => [
            'required' => ['secret_key'],
            'optional' => ['publishable_key', 'webhook_secret', 'api_version', 'sandbox'],
        ],
        'square' => [
            'required' => ['application_id', 'access_token'],
            'optional' => ['environment', 'api_version'],
        ],
        'adyen' => [
            'required' => ['api_key', 'merchant_account'],
            'optional' => ['client_key', 'environment'],
        ],
        'amazon' => [
            'required' => ['merchant_id', 'access_key', 'secret_key', 'client_id'],
            'optional' => ['region', 'sandbox'],
        ],
        'klarna' => [
            'required' => ['username', 'password'],
            'optional' => ['region', 'sandbox'],
        ],
        'afterpay' => [
            'required' => ['merchant_id', 'secret_key'],
            'optional' => ['region', 'sandbox'],
        ],
        'alipay_global' => [
            'required' => ['app_id', 'private_key', 'public_key'],
            'optional' => ['gateway_url', 'sign_type', 'sandbox'],
        ],
        'wise' => [
            'required' => ['api_key', 'profile_id'],
            'optional' => ['sandbox'],
        ],
        'payoneer' => [
            'required' => ['api_key', 'api_secret', 'program_id'],
            'optional' => ['sandbox'],
        ],
        'revolut' => [
            'required' => ['api_key', 'merchant_id'],
            'optional' => ['sandbox'],
        ],
        'apple' => [
            'required' => [
                'merchant_identifier', 'merchant_certificate',
                'merchant_certificate_key', 'apple_pay_merchant_id', 'domain_name',
            ],
            'optional' => ['sandbox'],
        ],
        'google' => [
            'required' => ['merchant_id', 'merchant_name', 'gateway_merchant_id'],
            'optional' => ['environment', 'sandbox'],
        ],
        'coinbase' => [
            'required' => ['api_key', 'webhook_secret'],
            'optional' => ['sandbox'],
        ],
        'hitpay' => [
            'required' => ['api_key', 'webhook_secret'],
            'optional' => ['sandbox'],
        ],
        'xendit' => [
            'required' => ['secret_key', 'public_key', 'callback_token'],
            'optional' => ['sandbox'],
        ],
        'aggregate' => [
            'required' => ['channels'],
            'optional' => [],
        ],
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
            // 回写缓存，避免后续调用重复反射
            self::$entries[$name][$key] = $url;
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
     * 获取能力的中文标签
     *
     * @param string $capability 能力常量，如 self::CAP_TRANSFER
     */
    public static function capabilityLabel(string $capability): string
    {
        return self::CAPABILITY_LABELS[$capability] ?? $capability;
    }

    /**
     * 获取能力对应的可调用操作（接口方法名）列表
     *
     * @param string $capability 能力常量，如 self::CAP_TRANSFER
     * @return string[]
     */
    public static function capabilityOperations(string $capability): array
    {
        return self::CAPABILITY_OPERATIONS[$capability] ?? [];
    }

    /**
     * 获取平台的配置字段契约
     *
     * 内置平台直接返回 {@see GatewayManifest::CONFIG_SCHEMA} 中的声明；未在清单中
     * 登记的自定义平台会反射其 Config 类构造函数，按「无默认值的形参=必填 / 有默认值的形参=可选」
     * 自动推导，从而保证 {@see GatewayManifest::inspect()} 对扩展平台同样可用。
     *
     * @param string $name 平台标识
     * @return array{required: string[], optional: string[]}
     * @throws PayException
     */
    public static function configSchema(string $name): array
    {
        $entry = self::get($name);

        return self::resolveConfigSchema($name, $entry);
    }

    /**
     * 平台能力 & 配置发现（统一响应）
     *
     * 一处调用即可获得开发者接入某平台所需的全部契约信息：平台元信息、能力开关、
     * 可调用操作（方法名）、配置字段契约（必填/可选）以及当前配置缺失项校验。
     * 适用于能力发现、文档生成、配置校验等场景，开发者无需逐个翻阅各网关实现。
     *
     * 返回的数组结构：
     * - name / label / region / signature / gateway_class / config_class：平台元信息
     * - capabilities：能力常量 => 是否支持（bool）的完整映射
     * - operations：仅列出已开启能力对应的可调用方法（含中文标签）
     * - config：required / optional 配置字段契约
     * - missing：传入配置相对必填项的缺漏键（空数组表示配置完整）
     * - valid：必填项是否全部满足
     *
     * @param string $name 平台标识
     * @param array<string, mixed> $config 当前已提供的配置（用于缺失校验，可省略）
     * @return array<string, mixed>
     * @throws PayException
     */
    public static function inspect(string $name, array $config = []): array
    {
        $entry = self::get($name);
        $capabilities = $entry['capabilities'] ?? [];

        $operations = [];
        foreach ($capabilities as $capability => $enabled) {
            if (!$enabled) {
                continue;
            }

            $methods = self::CAPABILITY_OPERATIONS[$capability] ?? [];
            if ($methods === []) {
                continue;
            }

            $operations[$capability] = [
                'label' => self::CAPABILITY_LABELS[$capability] ?? $capability,
                'methods' => array_values($methods),
            ];
        }

        $schema = self::configSchema($name);
        $required = $schema['required'] ?? [];

        $missing = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $config) || $config[$key] === '' || $config[$key] === null) {
                $missing[] = $key;
            }
        }

        return [
            'name' => $name,
            'label' => $entry['label'] ?? $name,
            'region' => $entry['region'] ?? self::REGION_INTERNATIONAL,
            'signature' => $entry['signature'] ?? self::SIGN_NONE,
            'gateway_class' => $entry['gateway_class'] ?? null,
            'config_class' => $entry['config_class'] ?? null,
            'capabilities' => $capabilities,
            'operations' => $operations,
            'config' => [
                'required' => array_values($required),
                'optional' => array_values($schema['optional'] ?? []),
            ],
            'missing' => $missing,
            'valid' => $missing === [],
        ];
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
            'config_schema' => self::resolveConfigSchema($name, $manifest),
            'gateway_class' => $gatewayClass,
            'config_class' => $configClass,
        ];
    }

    /**
     * 解析平台的配置字段契约
     *
     * 优先使用 {@see GatewayManifest::CONFIG_SCHEMA} 的显式声明；对未登记的内置平台
     * 之外（自定义扩展）的平台，回退到反射其 Config 类构造函数：
     * - 无默认值的形参 => 必填
     * - 有默认值的形参 => 可选
     * 形参名由驼峰转换为下划线风格键名（如 merchantNo => merchant_no）。
     *
     * @param string $name 平台标识
     * @param array<string, mixed> $manifest 原始清单（用于回退时读取 config_class）
     * @return array{required: string[], optional: string[]}
     */
    protected static function resolveConfigSchema(string $name, array $manifest): array
    {
        if (isset(self::CONFIG_SCHEMA[$name])) {
            return self::CONFIG_SCHEMA[$name];
        }

        $configClass = $manifest['config_class'] ?? null;
        if (is_string($configClass) && class_exists($configClass)) {
            $reflection = new \ReflectionClass($configClass);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null) {
                $required = [];
                $optional = [];
                foreach ($constructor->getParameters() as $param) {
                    $key = self::camelToSnake($param->getName());
                    if ($param->isOptional()) {
                        $optional[] = $key;
                    } else {
                        $required[] = $key;
                    }
                }

                return ['required' => $required, 'optional' => $optional];
            }
        }

        return ['required' => [], 'optional' => []];
    }

    /**
     * 驼峰命名转下划线命名
     *
     * @param string $name 驼峰风格属性名
     */
    protected static function camelToSnake(string $name): string
    {
        $snake = preg_replace('/(?<=\\w)(?=[A-Z])/u', '_', $name);

        return is_string($snake) ? strtolower($snake) : $name;
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
                    self::CAP_SUBSCRIPTION => true,
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
                    self::CAP_SUBSCRIPTION => true,
                    self::CAP_BALANCE => true,
                ]),
            ],
            'wechat_v3' => [
                'label' => '微信支付 V3',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_ECDSA,
                // 现金红包为 V2 专有接口，APIv3 无对应能力
                'capabilities' => [
                    self::CAP_CREATE_ORDER => true,
                    self::CAP_QUERY_ORDER => true,
                    self::CAP_CLOSE_ORDER => true,
                    self::CAP_VERIFY_NOTIFY => true,
                    self::CAP_TRANSFER => true,
                    self::CAP_RECONCILIATION => true,
                    self::CAP_BALANCE => true,
                    self::CAP_PROFIT_SHARING => true,
                    self::CAP_SETTLEMENT => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                    self::CAP_WEBHOOK => true,
                ],
            ],
            'unionpay' => [
                'label' => '云闪付',
                'region' => self::REGION_DOMESTIC,
                'signature' => self::SIGN_RSA,
                'capabilities' => [
                    self::CAP_QR => true,
                    self::CAP_WEBHOOK => true,
                    self::CAP_PROFIT_SHARING => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                ],
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
                'capabilities' => [
                    self::CAP_SUBSCRIPTION => true,
                    self::CAP_WEBHOOK => true,
                    self::CAP_SETTLEMENT => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                ],
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
                    self::CAP_BALANCE => true,
                ],
            ],
            'square' => [
                'label' => 'Square',
                'region' => self::REGION_INTERNATIONAL,
                'signature' => self::SIGN_NONE,
                'capabilities' => [
                    self::CAP_WEBHOOK => true,
                    self::CAP_SUBSCRIPTION => true,
                    self::CAP_PERSONAL_RECEIVE => true,
                ],
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
                    self::CAP_SUBSCRIPTION => true,
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
                    self::CAP_PERSONAL_RECEIVE => true,
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
