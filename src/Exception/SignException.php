<?php

declare(strict_types=1);

namespace Kode\Pays\Exception;

use Kode\Pays\Core\PayException;

/**
 * 签名异常
 *
 * 当签名生成失败、签名验证不通过或密钥加载失败时抛出。
 */
class SignException extends PayException
{
    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, self::ERROR_SIGN, $previous);
    }
}
