<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Facade;

use Kode\Pays\Core\CapabilityAuditor;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Facade\Pay;
use Kode\Pays\Tests\TestCase;

/**
 * 能力自检 / 全量矩阵公开入口守护测试
 *
 * Pay::audit() / Pay::matrix() 是 capability-discovery 模式对调用方暴露的运行时自检入口，
 * 本测试锁定二者正确委托到底层能力一致性审计器与清单矩阵，使调用方在测试套件之外
 * 也能做零漂移自检与能力对照表生成。
 */
class PayCapabilityDiscoveryTest extends TestCase
{
    /**
     * Pay::audit() 应与 CapabilityAuditor::audit() 结果完全一致
     */
    public function testAuditDelegatesToAuditor(): void
    {
        $this->assertSame(CapabilityAuditor::audit(), Pay::audit());
    }

    /**
     * Pay::matrix() 应与 GatewayManifest::matrix() 结果完全一致
     */
    public function testMatrixDelegatesToManifest(): void
    {
        $expected = GatewayManifest::matrix();
        $actual = Pay::matrix();

        $this->assertSame(array_keys($expected), array_keys($actual));
        $this->assertSame($expected, $actual);
    }

    /**
     * 运行时自检入口当前应报告零漂移
     */
    public function testAuditReportsZeroDrift(): void
    {
        $this->assertSame([], Pay::audit(), 'Pay::audit() 当前应无能力漂移');
    }
}
