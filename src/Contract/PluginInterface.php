<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 插件接口
 *
 * 用于扩展支付网关功能，如分账、转账、对账等
 */
interface PluginInterface
{
    /**
     * 获取插件名称
     *
     * @return string 插件唯一标识
     */
    public function getName(): string;

    /**
     * 执行插件逻辑
     *
     * @param GatewayInterface $gateway 当前支付网关实例
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed> 执行结果
     */
    public function handle(GatewayInterface $gateway, array $params): array;
}
