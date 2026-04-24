<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Core\PayException;

/**
 * kode/exception 集成适配器
 *
 * 当项目中安装了 kode/exception 时，自动启用其增强的异常处理能力，
 * 包括链路追踪、统一错误码、多格式报告、分布式追踪等。
 * 未安装时完全回退到原生 PayException，零依赖侵入。
 *
 * 使用示例：
 * ```php
 * KodeExceptionAdapter::init();
 * ```
 */
class KodeExceptionAdapter
{
    /**
     * 是否已初始化
     */
    private static bool $initialized = false;

    /**
     * 初始化 kode/exception 集成
     *
     * @param bool $isProduction 是否为生产环境
     * @param string $serviceName 服务名称
     */
    public static function init(bool $isProduction = false, string $serviceName = 'kode-pays'): void
    {
        if (self::$initialized) {
            return;
        }

        if (!class_exists(\Kode\Exception\KodeException::class)) {
            return;
        }

        \Kode\Exception\KodeException::init(
            isProduction: $isProduction,
            serviceName: $serviceName,
        );

        self::$initialized = true;
    }

    /**
     * 将 PayException 转换为 kode/exception 格式
     *
     * @param PayException $exception 支付异常
     * @return array<string, mixed> 标准化响应数组
     */
    public static function format(PayException $exception): array
    {
        if (class_exists(\Kode\Exception\KodeException::class)) {
            return \Kode\Exception\KodeException::format($exception);
        }

        return [
            'code' => $exception->getCode(),
            'msg' => $exception->getMessage(),
            'gateway_code' => $exception->getGatewayCode(),
            'gateway_message' => $exception->getGatewayMessage(),
        ];
    }

    /**
     * 获取 kode/exception 管理器
     *
     * @return \Kode\Exception\ExceptionManager|null
     */
    public static function manager(): ?\Kode\Exception\ExceptionManager
    {
        if (class_exists(\Kode\Exception\KodeException::class)) {
            return \Kode\Exception\KodeException::manager();
        }

        return null;
    }

    /**
     * 获取 kode/exception 追踪器
     *
     * @return \Kode\Exception\Tracer\DistributedTracer|null
     */
    public static function tracer(): ?\Kode\Exception\Tracer\DistributedTracer
    {
        if (class_exists(\Kode\Exception\KodeException::class)) {
            return \Kode\Exception\KodeException::tracer();
        }

        return null;
    }

    /**
     * 上报异常到 kode/exception 监控系统
     *
     * @param PayException $exception 支付异常
     * @param array<string, mixed> $context 上下文信息
     */
    public static function report(PayException $exception, array $context = []): void
    {
        if (!class_exists(\Kode\Exception\KodeException::class)) {
            return;
        }

        $extra = array_merge([
            'gateway_code' => $exception->getGatewayCode(),
            'gateway_message' => $exception->getGatewayMessage(),
            'service' => 'kode-pays',
        ], $context);

        \Kode\Exception\KodeException::report($exception, $extra);
    }

    /**
     * 判断 kode/exception 是否可用
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Kode\Exception\KodeException::class);
    }
}
