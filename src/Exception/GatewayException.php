<?php

declare(strict_types=1);

namespace Kode\Pays\Exception;

use Kode\Pays\Core\PayException;

/**
 * 网关业务异常
 *
 * 当支付网关返回业务错误时抛出，如订单不存在、余额不足、重复支付等。
 */
class GatewayException extends PayException
{
    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param string|null $gatewayCode 网关原始错误码
     * @param string|null $gatewayMessage 网关原始错误信息
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct(
        string $message = '',
        ?string $gatewayCode = null,
        ?string $gatewayMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, self::ERROR_GATEWAY, $previous, $gatewayCode, $gatewayMessage);
    }
}
