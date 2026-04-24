<?php

declare(strict_types=1);

namespace Kode\Pays\Exception;

use Kode\Pays\Core\PayException;

/**
 * 配置异常
 *
 * 当网关配置缺失、格式错误或加载失败时抛出。
 */
class ConfigException extends PayException
{
    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, self::ERROR_CONFIG, $previous);
    }
}
