<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Exception;
use Throwable;

/**
 * 支付 SDK 统一异常类
 *
 * 所有网关内部异常均转换为此异常抛出，便于调用方统一捕获处理
 */
class PayException extends Exception
{
    /**
     * 错误码：通用未知错误
     */
    public const ERROR_UNKNOWN = 1000;

    /**
     * 错误码：配置错误
     */
    public const ERROR_CONFIG = 1001;

    /**
     * 错误码：网络请求失败
     */
    public const ERROR_NETWORK = 1002;

    /**
     * 错误码：签名验证失败
     */
    public const ERROR_SIGN = 1003;

    /**
     * 错误码：业务参数错误
     */
    public const ERROR_PARAM = 1004;

    /**
     * 错误码：网关返回业务错误
     */
    public const ERROR_GATEWAY = 1005;

    /**
     * 错误码：订单不存在
     */
    public const ERROR_ORDER_NOT_FOUND = 1006;

    /**
     * 错误码：退款失败
     */
    public const ERROR_REFUND = 1007;

    /**
     * 错误码列表
     *
     * @var array<int, string>
     */
    protected static array $errorMessages = [
        self::ERROR_UNKNOWN => '未知错误',
        self::ERROR_CONFIG => '配置错误',
        self::ERROR_NETWORK => '网络请求失败',
        self::ERROR_SIGN => '签名验证失败',
        self::ERROR_PARAM => '业务参数错误',
        self::ERROR_GATEWAY => '网关业务错误',
        self::ERROR_ORDER_NOT_FOUND => '订单不存在',
        self::ERROR_REFUND => '退款失败',
    ];

    /**
     * 业务错误码（网关返回的原始错误码）
     */
    protected ?string $gatewayCode;

    /**
     * 业务错误信息（网关返回的原始错误信息）
     */
    protected ?string $gatewayMessage;

    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param int $code 错误码
     * @param Throwable|null $previous 上游异常
     * @param string|null $gatewayCode 网关原始错误码
     * @param string|null $gatewayMessage 网关原始错误信息
     */
    public function __construct(
        string $message = '',
        int $code = self::ERROR_UNKNOWN,
        ?Throwable $previous = null,
        ?string $gatewayCode = null,
        ?string $gatewayMessage = null,
    ) {
        if ($message === '' && isset(self::$errorMessages[$code])) {
            $message = self::$errorMessages[$code];
        }

        parent::__construct($message, $code, $previous);

        $this->gatewayCode = $gatewayCode;
        $this->gatewayMessage = $gatewayMessage;
    }

    /**
     * 获取网关原始错误码
     */
    public function getGatewayCode(): ?string
    {
        return $this->gatewayCode;
    }

    /**
     * 获取网关原始错误信息
     */
    public function getGatewayMessage(): ?string
    {
        return $this->gatewayMessage;
    }

    /**
     * 快速创建配置错误异常
     */
    public static function configError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::ERROR_CONFIG, $previous);
    }

    /**
     * 快速创建网络错误异常
     */
    public static function networkError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::ERROR_NETWORK, $previous);
    }

    /**
     * 快速创建签名错误异常
     */
    public static function signError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::ERROR_SIGN, $previous);
    }

    /**
     * 快速创建参数错误异常
     */
    public static function paramError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::ERROR_PARAM, $previous);
    }

    /**
     * 快速创建无效参数异常
     */
    public static function invalidArgument(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::ERROR_PARAM, $previous);
    }

    /**
     * 快速创建网关业务错误异常
     */
    public static function gatewayError(
        string $message,
        ?string $gatewayCode = null,
        ?string $gatewayMessage = null,
        ?Throwable $previous = null,
    ): self {
        return new self($message, self::ERROR_GATEWAY, $previous, $gatewayCode, $gatewayMessage);
    }
}
