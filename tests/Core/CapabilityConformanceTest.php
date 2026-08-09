<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\CapabilityAuditor;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Tests\TestCase;

/**
 * 网关能力一致性守护测试
 *
 * 清单能力开关是对外承诺，调用方据此做功能门控。本测试锁定
 * 「声明具备某能力 ⟺ 网关实现对应能力接口」这一不变量，
 * 防止新增网关或调整清单时出现「声明支持但调用即抛无此方法」的漂移。
 */
class CapabilityConformanceTest extends TestCase
{
    /**
     * 全部内置平台的能力声明与接口实现零漂移
     */
    public function testNoCapabilityDrift(): void
    {
        $drifts = CapabilityAuditor::audit();

        $this->assertSame([], $drifts, PHP_EOL . CapabilityAuditor::format($drifts));
    }

    /**
     * 契约映射覆盖全部扩展能力接口，且映射目标均为真实接口
     */
    public function testCapabilityContractsAreValidInterfaces(): void
    {
        $this->assertNotEmpty(GatewayManifest::CAPABILITY_CONTRACTS);

        foreach (GatewayManifest::CAPABILITY_CONTRACTS as $capability => $contract) {
            $this->assertIsString($capability);
            $this->assertTrue(
                interface_exists($contract),
                "能力 {$capability} 映射的契约 {$contract} 不是有效接口",
            );
        }
    }

    /**
     * 审计器可报告人为制造的虚报声明
     */
    public function testAuditorDetectsOverclaimedCapability(): void
    {
        GatewayFactory::register('driftgw', DriftFakeGateway::class);
        GatewayManifest::register('driftgw', [
            'label' => '漂移测试网关',
            'region' => GatewayManifest::REGION_DOMESTIC,
            'capabilities' => [GatewayManifest::CAP_TRANSFER => true],
        ]);

        try {
            $drifts = CapabilityAuditor::audit();
            $mine = array_values(array_filter(
                $drifts,
                static fn (array $d): bool => $d['gateway'] === 'driftgw',
            ));

            $this->assertCount(1, $mine);
            $this->assertSame(CapabilityAuditor::DRIFT_OVERCLAIMED, $mine[0]['type']);
            $this->assertSame(GatewayManifest::CAP_TRANSFER, $mine[0]['capability']);
            $this->assertStringContainsString('[虚报] driftgw', CapabilityAuditor::format($mine));
        } finally {
            GatewayManifest::unregister('driftgw');
            GatewayFactory::unregister('driftgw');
        }
    }

    /**
     * 审计器可报告已实现却未声明的能力
     */
    public function testAuditorDetectsUndeclaredCapability(): void
    {
        GatewayFactory::register('driftgw2', DriftCapableFakeGateway::class);
        GatewayManifest::register('driftgw2', [
            'label' => '漏报测试网关',
            'region' => GatewayManifest::REGION_DOMESTIC,
            'capabilities' => [],
        ]);

        try {
            $mine = array_values(array_filter(
                CapabilityAuditor::audit(),
                static fn (array $d): bool => $d['gateway'] === 'driftgw2',
            ));

            $this->assertCount(1, $mine);
            $this->assertSame(CapabilityAuditor::DRIFT_UNDECLARED, $mine[0]['type']);
            $this->assertSame(GatewayManifest::CAP_TRANSFER, $mine[0]['capability']);
            $this->assertStringContainsString('[漏报] driftgw2', CapabilityAuditor::format($mine));
        } finally {
            GatewayManifest::unregister('driftgw2');
            GatewayFactory::unregister('driftgw2');
        }
    }

    /**
     * 真实能力集以接口实现为准
     */
    public function testActualCapabilitiesReflectImplementation(): void
    {
        $wechat = CapabilityAuditor::actualCapabilities('wechat');

        $this->assertContains(GatewayManifest::CAP_TRANSFER, $wechat);
        $this->assertContains(GatewayManifest::CAP_PROFIT_SHARING, $wechat);
        $this->assertContains(GatewayManifest::CAP_SETTLEMENT, $wechat);

        $v3 = CapabilityAuditor::actualCapabilities('wechat_v3');

        $this->assertContains(GatewayManifest::CAP_TRANSFER, $v3);
        $this->assertContains(GatewayManifest::CAP_RECONCILIATION, $v3);
        $this->assertNotContains(GatewayManifest::CAP_RED_PACKET, $v3);
    }

    /**
     * 未注册网关实现的平台不产生漂移噪音
     */
    public function testUnknownGatewayYieldsNoCapabilities(): void
    {
        $this->assertSame([], CapabilityAuditor::actualCapabilities('not-registered-gateway'));
    }

    /**
     * 清单声明为真的扩展能力，均可在真实能力集中找到
     */
    public function testDeclaredExtendedCapabilitiesAreAllImplemented(): void
    {
        foreach (GatewayManifest::all() as $name => $meta) {
            if (GatewayFactory::getGatewayClass($name) === null) {
                continue;
            }

            $capabilities = is_array($meta['capabilities'] ?? null) ? $meta['capabilities'] : [];
            $actual = CapabilityAuditor::actualCapabilities($name);

            foreach (array_keys(GatewayManifest::CAPABILITY_CONTRACTS) as $capability) {
                if (($capabilities[$capability] ?? false) === true) {
                    $this->assertContains($capability, $actual, "{$name} 声明的 {$capability} 未落实到接口实现");
                }
            }
        }
    }
}

/**
 * 未实现任何扩展能力的测试网关
 */
abstract class DriftFakeGateway extends \Kode\Pays\Tests\Core\FakeGateway
{
}

/**
 * 实现转账能力的测试网关
 */
abstract class DriftCapableFakeGateway extends \Kode\Pays\Tests\Core\FakeGateway implements TransferCapableInterface
{
}
