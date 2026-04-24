<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline\Middleware;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 日志中间件
 *
 * 记录请求参数和响应结果，便于调试和审计。
 * 自动脱敏敏感字段（如密钥、证书、密码等）。
 */
class LogMiddleware
{
    /**
     * 日志实例
     */
    protected LoggerInterface $logger;

    /**
     * 需要脱敏的字段名（大小写不敏感）
     *
     * @var string[]
     */
    protected array $sensitiveFields = [
        'api_key', 'appsecret', 'private_key', 'public_key',
        'cert_path', 'cert_pwd', 'client_secret', 'salt',
        'sign', 'signature', 'password', 'token',
    ];

    /**
     * 构造函数
     *
     * @param LoggerInterface|null $logger PSR-3 日志接口
     * @param string[] $sensitiveFields 额外需要脱敏的字段
     */
    public function __construct(?LoggerInterface $logger = null, array $sensitiveFields = [])
    {
        $this->logger = $logger ?? new NullLogger();
        $this->sensitiveFields = array_merge($this->sensitiveFields, $sensitiveFields);
    }

    /**
     * 处理请求
     *
     * @param array<string, mixed> $payload 请求参数
     * @param Closure $next 下一个中间件
     * @return array<string, mixed>
     */
    public function __invoke(array $payload, Closure $next): array
    {
        $this->logger->info('Kode Pays 请求发送', [
            'params' => $this->mask($payload),
        ]);

        $response = $next($payload);

        $this->logger->info('Kode Pays 响应接收', [
            'response' => $this->mask($response),
        ]);

        return $response;
    }

    /**
     * 对敏感字段进行脱敏处理
     *
     * @param array<string, mixed> $data 原始数据
     * @return array<string, mixed> 脱敏后的数据
     */
    protected function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask($value);
                continue;
            }

            if (in_array(strtolower($key), $this->sensitiveFields, true)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
