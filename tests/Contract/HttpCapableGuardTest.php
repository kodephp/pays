<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Contract;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;
use Kode\Pays\Support\HttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * HttpCapableInterface 守卫（InteractsWithGateway）单元测试
 *
 * 通过最小桩类验证：仅实现 GatewayInterface 的网关会触发异常，
 * 同时实现 HttpCapableInterface 的网关可通过断言。
 */

/** 仅实现 GatewayInterface 的桩网关（不具备 HTTP 通道能力） */
class PlainGatewayStub implements GatewayInterface
{
    public function createOrder(array $params): array { return []; }
    public function queryOrder(string $orderId): array { return []; }
    public function refund(array $params): array { return []; }
    public function queryRefund(string $refundId): array { return []; }
    public function verifyNotify(array $data): bool { return true; }
    public function closeOrder(string $orderId): array { return []; }
    public static function getName(): string { return 'stub'; }
    public function setDispatcher(EventDispatcher $dispatcher): void {}
    public function setHttpClient(HttpClient $httpClient): void {}
}

/** 同时实现 HttpCapableInterface 的桩网关 */
class CapableGatewayStub extends PlainGatewayStub implements HttpCapableInterface
{
    public function post(string $endpoint, array $data = [], array $headers = []): array { return []; }
    public function postRaw(string $endpoint, string $body, array $headers = []): array { return []; }
    public function get(string $endpoint, array $query = [], array $headers = []): array { return []; }
    public function put(string $endpoint, array $data = [], array $headers = []): array { return []; }
    public function delete(string $endpoint, array $query = [], array $headers = []): array { return []; }
}

/** 暴露受保护断言的测试载体 */
class HttpCapableGuardTester
{
    use InteractsWithGateway;

    public static function guard(GatewayInterface $gateway): void
    {
        self::assertHttpCapable($gateway);
    }
}

class HttpCapableGuardTest extends TestCase
{
    /**
     * 不具备 HTTP 能力的网关应抛出 PayException
     */
    public function testGuardThrowsForPlainGateway(): void
    {
        $this->expectException(PayException::class);
        HttpCapableGuardTester::guard(new PlainGatewayStub());
    }

    /**
     * 具备 HTTP 能力的网关应通过断言
     */
    public function testGuardPassesForCapableGateway(): void
    {
        $thrown = false;
        try {
            HttpCapableGuardTester::guard(new CapableGatewayStub());
        } catch (PayException) {
            $thrown = true;
        }
        $this->assertFalse($thrown, '具备 HTTP 能力的网关不应触发异常');
    }
}
