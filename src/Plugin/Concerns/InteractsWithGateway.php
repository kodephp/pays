<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin\Concerns;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Core\PayException;

/**
 * 插件与网关协作的公共能力
 *
 * 插件需要借用网关已配置好的 HTTP 通道（含签名、证书、中间件、事件）发起扩展接口调用，
 * 该 trait 提供统一的能力断言，在构造阶段就暴露"网关不支持 HTTP 通道"的问题，
 * 避免运行到实际调用时才抛出难以定位的致命错误。
 */
trait InteractsWithGateway
{
    /**
     * 断言网关具备 HTTP 通道能力
     *
     * @param GatewayInterface $gateway 待检查的网关
     * @throws PayException 当网关未实现 HttpCapableInterface 时抛出
     *
     * @phpstan-assert HttpCapableInterface $gateway
     */
    protected static function assertHttpCapable(GatewayInterface $gateway): void
    {
        if (!$gateway instanceof HttpCapableInterface) {
            throw PayException::paramError(sprintf(
                '网关 %s 未实现 %s，无法被插件复用 HTTP 通道；请继承 %s 或自行实现该接口',
                $gateway::class,
                HttpCapableInterface::class,
                \Kode\Pays\Core\AbstractGateway::class,
            ));
        }
    }
}
