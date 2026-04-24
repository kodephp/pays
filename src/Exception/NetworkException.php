<?php

declare(strict_types=1);

namespace Kode\Pays\Exception;

use Kode\Pays\Core\PayException;

/**
 * 网络异常
 *
 * 当 HTTP 请求超时、连接失败或响应异常时抛出。
 */
class NetworkException extends PayException
{
    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, self::ERROR_NETWORK, $previous);
    }
}
