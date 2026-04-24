<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline\Middleware;

use Closure;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Signer;

/**
 * 签名中间件
 *
 * 自动为请求参数附加签名，支持 MD5、RSA、RSA2、HMAC-SHA256 算法。
 *
 * 配置示例：
 * ```php
 * [
 *     'sign_type' => 'md5',      // md5 | rsa | rsa2 | hmac_sha256
 *     'key'       => 'api_key',  // MD5/HMAC 密钥
 *     'private_key' => '...',    // RSA 私钥（rsa/rsa2 时使用）
 *     'sign_field' => 'sign',    // 签名字段名
 *     'exclude_fields' => ['sign'], // 不参与签名的字段
 * ]
 * ```
 */
class SignMiddleware
{
    /**
     * 中间件配置
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 签名配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 处理请求
     *
     * @param array<string, mixed> $payload 请求参数
     * @param Closure $next 下一个中间件
     * @return array<string, mixed>
     * @throws PayException
     */
    public function __invoke(array $payload, Closure $next): array
    {
        $signed = $this->sign($payload);

        return $next($signed);
    }

    /**
     * 对参数进行签名
     *
     * @param array<string, mixed> $params 原始参数
     * @return array<string, mixed> 附加签名后的参数
     * @throws PayException
     */
    protected function sign(array $params): array
    {
        $signType = $this->config['sign_type'] ?? 'md5';
        $signField = $this->config['sign_field'] ?? 'sign';
        $excludeFields = $this->config['exclude_fields'] ?? [$signField];

        $sign = match ($signType) {
            'md5' => Signer::md5($params, $this->config['key'] ?? '', true),
            'rsa' => Signer::rsa($params, $this->config['private_key'] ?? ''),
            'rsa2' => Signer::rsa2($params, $this->config['private_key'] ?? ''),
            'hmac_sha256' => Signer::hmacSha256($params, $this->config['key'] ?? '', true),
            default => throw PayException::configError("不支持的签名类型：{$signType}"),
        };

        $params[$signField] = $sign;

        return $params;
    }
}
