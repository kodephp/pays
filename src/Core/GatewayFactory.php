<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\ConfigInterface;
use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Support\HttpClient;

/**
 * 网关工厂类
 *
 * 负责根据网关标识自动实例化对应的支付网关，支持配置 DTO 自动转换。
 * 新增网关时只需在 $gateways 映射数组中注册即可，遵循开闭原则。
 */
class GatewayFactory
{
    /**
     * 网关类映射表
     *
     * key 为开发者调用时使用的标识，value 为对应网关类全限定名
     *
     * @var array<string, class-string<GatewayInterface>>
     */
    protected static array $gateways = [
        // 国内主流支付
        'wechat' => \Kode\Pays\Gateway\Wechat\WechatPayGateway::class,
        'alipay' => \Kode\Pays\Gateway\Alipay\AlipayGateway::class,
        'unionpay' => \Kode\Pays\Gateway\UnionPay\UnionPayGateway::class,
        'douyin' => \Kode\Pays\Gateway\Douyin\DouyinPayGateway::class,

        // 微信支付 V3
        'wechat_v3' => \Kode\Pays\Gateway\Wechat\WechatPayV3Gateway::class,

        // 国际支付
        'paypal' => \Kode\Pays\Gateway\Paypal\PaypalGateway::class,
        'stripe' => \Kode\Pays\Gateway\Stripe\StripeGateway::class,
        'square' => \Kode\Pays\Gateway\Square\SquareGateway::class,
        'adyen' => \Kode\Pays\Gateway\Adyen\AdyenGateway::class,

        // 生活服务支付
        'meituan' => \Kode\Pays\Gateway\Meituan\MeituanGateway::class,

        // 电商支付
        'jd' => \Kode\Pays\Gateway\Jd\JdGateway::class,

        // 短视频支付
        'kuaishou' => \Kode\Pays\Gateway\Kuaishou\KuaishouGateway::class,
        'qq' => \Kode\Pays\Gateway\QQ\QQGateway::class,

        // 国际支付国际钱包支付
        'apple' => \Kode\Pays\Gateway\Apple\AppleGateway::class,
        'google' => \Kode\Pays\Gateway\Google\GoogleGateway::class,

        // 国际电商支付
        'amazon' => \Kode\Pays\Gateway\Amazon\AmazonGateway::class,
        'klarna' => \Kode\Pays\Gateway\Klarna\KlarnaGateway::class,

        // 跨境支付
        'alipay_global' => \Kode\Pays\Gateway\AlipayGlobal\AlipayGlobalGateway::class,

        // 跨境汇款
        'wise' => \Kode\Pays\Gateway\Wise\WiseGateway::class,
        'payoneer' => \Kode\Pays\Gateway\Payoneer\PayoneerGateway::class,

        // 数字银行支付
        'revolut' => \Kode\Pays\Gateway\Revolut\RevolutGateway::class,

        // 加密货币支付
        'coinbase' => \Kode\Pays\Gateway\Coinbase\CoinbaseGateway::class,

        // 先买后付
        'afterpay' => \Kode\Pays\Gateway\Afterpay\AfterpayGateway::class,

        // 聚合支付（多家渠道聚合）
        'aggregate' => \Kode\Pays\Gateway\Aggregate\AggregateGateway::class,
    ];

    /**
     * 配置 DTO 映射表
     *
     * key 为网关标识，value 为对应配置 DTO 类全限定名
     *
     * @var array<string, class-string<ConfigInterface>>
     */
    protected static array $configs = [
        'wechat' => \Kode\Pays\Config\WechatConfig::class,
        'wechat_v3' => \Kode\Pays\Config\WechatV3Config::class,
        'alipay' => \Kode\Pays\Config\AlipayConfig::class,
        'unionpay' => \Kode\Pays\Config\UnionPayConfig::class,
        'douyin' => \Kode\Pays\Config\DouyinConfig::class,
        'paypal' => \Kode\Pays\Config\PaypalConfig::class,
        'stripe' => \Kode\Pays\Gateway\Stripe\StripeConfig::class,
        'square' => \Kode\Pays\Gateway\Square\SquareConfig::class,
        'adyen' => \Kode\Pays\Gateway\Adyen\AdyenConfig::class,
        'meituan' => \Kode\Pays\Gateway\Meituan\MeituanConfig::class,
        'jd' => \Kode\Pays\Gateway\Jd\JdConfig::class,
        'kuaishou' => \Kode\Pays\Gateway\Kuaishou\KuaishouConfig::class,
        'qq' => \Kode\Pays\Gateway\QQ\QQConfig::class,
        'apple' => \Kode\Pays\Gateway\Apple\AppleConfig::class,
        'google' => \Kode\Pays\Gateway\Google\GoogleConfig::class,
        'amazon' => \Kode\Pays\Gateway\Amazon\AmazonConfig::class,
        'klarna' => \Kode\Pays\Gateway\Klarna\KlarnaConfig::class,
        'alipay_global' => \Kode\Pays\Gateway\AlipayGlobal\AlipayGlobalConfig::class,
        'wise' => \Kode\Pays\Gateway\Wise\WiseConfig::class,
        'payoneer' => \Kode\Pays\Gateway\Payoneer\PayoneerConfig::class,
        'revolut' => \Kode\Pays\Gateway\Revolut\RevolutConfig::class,
        'coinbase' => \Kode\Pays\Gateway\Coinbase\CoinbaseConfig::class,
        'afterpay' => \Kode\Pays\Gateway\Afterpay\AfterpayConfig::class,
    ];

    /**
     * 创建网关实例（数组配置）
     *
     * @param string $name 网关标识，如 wechat、alipay
     * @param array<string, mixed> $config 网关配置数组
     * @param HttpClient|null $httpClient 自定义 HTTP 客户端（测试用）
     * @return GatewayInterface 网关实例
     * @throws PayException
     */
    public static function create(string $name, array $config, ?HttpClient $httpClient = null): GatewayInterface
    {
        if (!isset(self::$gateways[$name])) {
            throw PayException::configError("不支持的支付网关：{$name}");
        }

        $class = self::$gateways[$name];

        if (!class_exists($class)) {
            throw PayException::configError("网关类不存在：{$class}");
        }

        $instance = new $class($config, $httpClient);

        if (!$instance instanceof GatewayInterface) {
            throw PayException::configError("网关类必须实现 GatewayInterface：{$class}");
        }

        return $instance;
    }

    /**
     * 创建网关实例（配置 DTO）
     *
     * @param string $name 网关标识
     * @param ConfigInterface $config 配置 DTO 对象
     * @param HttpClient|null $httpClient 自定义 HTTP 客户端
     * @return GatewayInterface 网关实例
     * @throws PayException
     */
    public static function createWithConfig(string $name, ConfigInterface $config, ?HttpClient $httpClient = null): GatewayInterface
    {
        $configArray = self::configToArray($config);

        return self::create($name, $configArray, $httpClient);
    }

    /**
     * 从数组自动转换为配置 DTO 后创建网关
     *
     * @param string $name 网关标识
     * @param array<string, mixed> $config 原始配置数组
     * @param HttpClient|null $httpClient 自定义 HTTP 客户端
     * @return GatewayInterface 网关实例
     * @throws PayException
     */
    public static function createAutoConfig(string $name, array $config, ?HttpClient $httpClient = null): GatewayInterface
    {
        if (isset(self::$configs[$name])) {
            $dtoClass = self::$configs[$name];

            if (class_exists($dtoClass) && method_exists($dtoClass, 'fromArray')) {
                $dto = $dtoClass::fromArray($config);
                $config = self::configToArray($dto);
            }
        }

        return self::create($name, $config, $httpClient);
    }

    /**
     * 注册自定义网关
     *
     * @param string $name 网关标识
     * @param class-string<GatewayInterface> $class 网关类全限定名
     * @param class-string<ConfigInterface>|null $configClass 配置 DTO 类（可选）
     * @throws PayException
     */
    public static function register(string $name, string $class, ?string $configClass = null): void
    {
        if (isset(self::$gateways[$name])) {
            throw PayException::configError("网关标识已存在：{$name}");
        }

        if (!is_subclass_of($class, GatewayInterface::class)) {
            throw PayException::configError("网关类必须实现 GatewayInterface：{$class}");
        }

        self::$gateways[$name] = $class;

        if ($configClass !== null) {
            if (!is_subclass_of($configClass, ConfigInterface::class)) {
                throw PayException::configError("配置类必须实现 ConfigInterface：{$configClass}");
            }

            self::$configs[$name] = $configClass;
        }
    }

    /**
     * 注销网关
     *
     * @param string $name 网关标识
     */
    public static function unregister(string $name): void
    {
        unset(self::$gateways[$name], self::$configs[$name]);
    }

    /**
     * 获取所有已注册网关标识
     *
     * @return string[] 网关标识列表
     */
    public static function getNames(): array
    {
        return array_keys(self::$gateways);
    }

    /**
     * 判断网关是否已注册
     *
     * @param string $name 网关标识
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$gateways[$name]);
    }

    /**
     * 获取网关对应的配置 DTO 类名
     *
     * @param string $name 网关标识
     * @return class-string<ConfigInterface>|null
     */
    public static function getConfigClass(string $name): ?string
    {
        return self::$configs[$name] ?? null;
    }

    /**
     * 将配置 DTO 转换为数组
     *
     * @param ConfigInterface $config
     * @return array<string, mixed>
     */
    protected static function configToArray(ConfigInterface $config): array
    {
        $result = [];

        $reflection = new \ReflectionClass($config);

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            $value = $property->getValue($config);

            // 将驼峰命名转换为下划线命名
            $snakeName = self::camelToSnake($name);
            $result[$snakeName] = $value;
        }

        return $result;
    }

    /**
     * 驼峰命名转下划线命名
     *
     * @param string $input
     * @return string
     */
    protected static function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
    }
}
