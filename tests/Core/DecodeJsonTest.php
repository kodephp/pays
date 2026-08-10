<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 验证 {@see AbstractGateway::decodeJson()} 对非法 JSON 响应的健壮性：
 * 网关 HTTP 通道已关闭 http_errors，4xx/5xx 仍会返回原始 body，
 * 若响应非合法 JSON（如 5xx HTML 错误页、空 body、截断报文），
 * 必须抛出明确的 {@see PayException::gatewayError}，而非让 null 透传
 * 导致下游 TypeError 或静默被当作「成功」。
 */
class DecodeJsonTest extends TestCase
{
    private function decode(string $response): array
    {
        $gateway = (new ReflectionClass(SquareGateway::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AbstractGateway::class, 'decodeJson');
        $method->setAccessible(true);

        return $method->invoke($gateway, $response);
    }

    public function testValidJsonReturnsArray(): void
    {
        $result = $this->decode('{"a":1,"b":"x"}');

        $this->assertSame(['a' => 1, 'b' => 'x'], $result);
    }

    public function testMalformedJsonThrowsGatewayError(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('网关响应非合法 JSON');

        $this->decode('not json');
    }

    public function testEmptyResponseThrowsGatewayError(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('网关响应非合法 JSON');

        $this->decode('');
    }

    public function testTruncatedJsonThrowsGatewayError(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('网关响应非合法 JSON');

        $this->decode('{"a":1');
    }
}
