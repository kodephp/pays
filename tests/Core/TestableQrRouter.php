<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\UnifiedQrRouter;

/**
 * 可注入假网关的路由器（覆盖 createGateway，避免发起真实 HTTP）
 */
class TestableQrRouter extends UnifiedQrRouter
{
    public FakeGateway $fake;

    protected function createGateway(string $channel): GatewayInterface
    {
        return $this->fake;
    }
}
