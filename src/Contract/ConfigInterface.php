<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 配置接口
 *
 * 所有网关配置类建议实现此接口，便于统一获取网关标识和从数组创建
 */
interface ConfigInterface
{
    /**
     * 从数组创建配置对象
     *
     * @param array<string, mixed> $config
     * @return static
     */
    public static function fromArray(array $config): static;

    /**
     * 获取网关标识
     *
     * @return string 对应网关名称
     */
    public function getGateway(): string;
}
