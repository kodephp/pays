<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Pipeline;

use Closure;
use Kode\Pays\Core\PayException;
use Kode\Pays\Pipeline\Middleware\CircuitBreakerMiddleware;
use Kode\Pays\Tests\TestCase;

/**
 * 熔断器中间件单元测试
 */
class CircuitBreakerMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // 每个用例前重置熔断状态，避免相互干扰
        (new CircuitBreakerMiddleware(['key' => 'ut']))->reset('ut');
    }

    /**
     * 闭合态下请求正常放行
     */
    public function testClosedPassesThrough(): void
    {
        $breaker = new CircuitBreakerMiddleware(['key' => 'ut', 'failure_threshold' => 2]);
        $next = fn (array $p): array => ['ok' => true];

        $result = $breaker->handle(['x' => 1], $next);
        $this->assertSame(['ok' => true], $result);
        $this->assertSame(CircuitBreakerMiddleware::STATE_CLOSED, $breaker->getState('ut'));
    }

    /**
     * 达到失败阈值后熔断为打开态
     */
    public function testOpensAfterThreshold(): void
    {
        $breaker = new CircuitBreakerMiddleware(['key' => 'ut', 'failure_threshold' => 2]);
        $fail = static function (array $p): array {
            throw PayException::gatewayError('boom');
        };

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->handle(['x' => 1], $fail);
            } catch (PayException) {
            }
        }

        $this->assertSame(CircuitBreakerMiddleware::STATE_OPEN, $breaker->getState('ut'));
    }

    /**
     * 打开态立即拒绝请求
     */
    public function testOpenRejectsImmediately(): void
    {
        $breaker = new CircuitBreakerMiddleware([
            'key' => 'ut',
            'failure_threshold' => 1,
            'cooldown_ms' => 60000,
        ]);
        $fail = static function (array $p): array {
            throw PayException::gatewayError('boom');
        };

        try {
            $breaker->handle(['x' => 1], $fail);
        } catch (PayException) {
        }

        $this->assertSame(CircuitBreakerMiddleware::STATE_OPEN, $breaker->getState('ut'));

        $this->expectException(PayException::class);
        $breaker->handle(['x' => 1], static fn (array $p): array => ['ok' => true]);
    }

    /**
     * 冷却结束后进入半开态并探测恢复
     */
    public function testHalfOpenRecoversAfterCooldown(): void
    {
        $breaker = new CircuitBreakerMiddleware([
            'key' => 'ut',
            'failure_threshold' => 1,
            'cooldown_ms' => 0, // 立即冷却结束，便于测试
        ]);
        $fail = static function (array $p): array {
            throw PayException::gatewayError('boom');
        };
        $ok = static fn (array $p): array => ['ok' => true];

        // 触发熔断
        try {
            $breaker->handle(['x' => 1], $fail);
        } catch (PayException) {
        }
        $this->assertSame(CircuitBreakerMiddleware::STATE_OPEN, $breaker->getState('ut'));

        // 冷却结束 -> 半开 -> 成功 -> 闭合
        $result = $breaker->handle(['x' => 1], $ok);
        $this->assertSame(['ok' => true], $result);
        $this->assertSame(CircuitBreakerMiddleware::STATE_CLOSED, $breaker->getState('ut'));
    }

    /**
     * 半开态探测失败则重新打开
     */
    public function testHalfOpenReopensOnFailure(): void
    {
        $breaker = new CircuitBreakerMiddleware([
            'key' => 'ut',
            'failure_threshold' => 1,
            'cooldown_ms' => 0,
        ]);
        $fail = static function (array $p): array {
            throw PayException::gatewayError('boom');
        };

        try {
            $breaker->handle(['x' => 1], $fail);
        } catch (PayException) {
        }
        $this->assertSame(CircuitBreakerMiddleware::STATE_OPEN, $breaker->getState('ut'));

        // 半开态再次失败 -> 重新打开
        try {
            $breaker->handle(['x' => 1], $fail);
        } catch (PayException) {
        }
        $this->assertSame(CircuitBreakerMiddleware::STATE_OPEN, $breaker->getState('ut'));
    }

    /**
     * 测试手动重置
     */
    public function testManualReset(): void
    {
        $breaker = new CircuitBreakerMiddleware([
            'key' => 'ut',
            'failure_threshold' => 1,
            'cooldown_ms' => 60000,
        ]);
        $fail = static function (array $p): array {
            throw PayException::gatewayError('boom');
        };
        try {
            $breaker->handle(['x' => 1], $fail);
        } catch (PayException) {
        }

        $breaker->reset('ut');
        $this->assertSame(CircuitBreakerMiddleware::STATE_CLOSED, $breaker->getState('ut'));
    }
}
