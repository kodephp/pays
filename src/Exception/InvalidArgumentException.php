<?php

declare(strict_types=1);

namespace Kode\Pays\Exception;

use Kode\Pays\Core\PayException;

/**
 * 参数异常
 *
 * 当业务参数缺失、格式错误或验证不通过时抛出。
 */
class InvalidArgumentException extends PayException
{
    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, self::ERROR_PARAM, $previous);
    }
}
