<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\NotifyGuard;
use Kode\Pays\Core\PayException;
use Kode\Pays\Tests\TestCase;

/**
 * 异步回调安全校验层（NotifyGuard）单元测试
 */
class NotifyGuardTest extends TestCase
{
    /**
     * 携带签名字段的合法通知应通过校验
     */
    public function testWellFormedWithSignPasses(): void
    {
        NotifyGuard::guard(['sign' => 'abc123']);
        $this->expectNotToPerformAssertions();
    }

    /**
     * 缺少必填字段应抛出参数异常
     */
    public function testMissingRequiredFieldThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填字段');

        NotifyGuard::guard(['sign' => 'x'], ['require_fields' => ['out_trade_no']]);
    }

    /**
     * 缺少签名字段应抛出签名异常
     */
    public function testMissingSignFieldThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少签名字段');

        NotifyGuard::guard(['foo' => 'bar']);
    }

    /**
     * 过期时间戳应判定为重放攻击
     */
    public function testStaleTimestampThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('重放攻击');

        NotifyGuard::guard(['sign' => 'x'], ['timestamp' => time() - 1000, 'max_age' => 300]);
    }

    /**
     * 未来时间戳同样视为异常
     */
    public function testFutureTimestampThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('重放攻击');

        NotifyGuard::guard(['sign' => 'x'], ['timestamp' => time() + 1000]);
    }

    /**
     * 已使用过的 nonce 应判定为重放攻击
     */
    public function testReusedNonceThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('重放攻击');

        NotifyGuard::guard(['sign' => 'x'], ['nonce' => 'n1', 'seen_nonces' => ['n1']]);
    }

    /**
     * 时间窗口内且未使用过的 nonce 应通过校验
     */
    public function testFreshNoncePasses(): void
    {
        NotifyGuard::guard(
            ['sign' => 'x'],
            ['timestamp' => time(), 'nonce' => 'n2', 'seen_nonces' => ['n1']],
        );

        $this->expectNotToPerformAssertions();
    }

    /**
     * 允许重放（allow_replay）时跳过时间与 nonce 校验
     */
    public function testAllowReplaySkipsChecks(): void
    {
        NotifyGuard::guard(
            ['sign' => 'x'],
            ['timestamp' => 1, 'nonce' => 'n1', 'seen_nonces' => ['n1'], 'allow_replay' => true],
        );

        $this->expectNotToPerformAssertions();
    }
}
