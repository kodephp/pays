<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Tests\TestCase;

/**
 * 全量能力矩阵（GatewayManifest::matrix）守护测试
 *
 * matrix() 是 capability-discovery 模式的横向收口：把「清单声明 ⟺ 接口实现」的二维视图
 * 一次性暴露给调用方。本测试锁定矩阵的结构完整性、已知网关的能力一致性，
 * 以及当前零漂移状态下矩阵整体应无不一致单元格。
 */
class GatewayManifestMatrixTest extends TestCase
{
    /**
     * 矩阵应覆盖每一个已登记网关
     */
    public function testMatrixCoversAllRegisteredGateways(): void
    {
        $matrix = GatewayManifest::matrix();
        $names = GatewayFactory::getNames();

        $this->assertSame(count($names), count($matrix), '矩阵行数应等于已登记网关数');

        foreach ($names as $name) {
            $this->assertArrayHasKey($name, $matrix, "矩阵应含网关 {$name}");
            $this->assertArrayHasKey('label', $matrix[$name]);
            $this->assertArrayHasKey('capabilities', $matrix[$name]);
            $this->assertIsArray($matrix[$name]['capabilities']);
        }
    }

    /**
     * 每个网关的单元格应覆盖全部 12 项扩展能力契约，且结构统一
     */
    public function testMatrixIncludesAllTwelveContracts(): void
    {
        $matrix = GatewayManifest::matrix();
        $contracts = array_keys(GatewayManifest::CAPABILITY_CONTRACTS);
        $this->assertCount(12, $contracts, '当前应有 12 项扩展能力契约');

        $first = reset($matrix);
        foreach ($contracts as $capability) {
            $this->assertArrayHasKey($capability, $first['capabilities'], "矩阵应含能力 {$capability}");
            $cell = $first['capabilities'][$capability];
            $this->assertArrayHasKey('declared', $cell);
            $this->assertArrayHasKey('actual', $cell);
            $this->assertArrayHasKey('consistent', $cell);
            $this->assertIsBool($cell['declared']);
            $this->assertIsBool($cell['actual']);
            $this->assertIsBool($cell['consistent']);
        }
    }

    /**
     * 微信声明并真实实现了转账能力（声明 ⟺ 实现）
     */
    public function testWechatDeclaresAndImplementsTransfer(): void
    {
        $cell = GatewayManifest::matrix()['wechat']['capabilities'][GatewayManifest::CAP_TRANSFER];

        $this->assertTrue($cell['declared']);
        $this->assertTrue($cell['actual']);
        $this->assertTrue($cell['consistent']);
    }

    /**
     * 微信未声明加密货币能力，且确实未实现对应接口
     */
    public function testWechatDoesNotDeclareCryptoAndNotImplemented(): void
    {
        $cell = GatewayManifest::matrix()['wechat']['capabilities'][GatewayManifest::CAP_CRYPTO];

        $this->assertFalse($cell['declared']);
        $this->assertFalse($cell['actual']);
        $this->assertTrue($cell['consistent']);
    }

    /**
     * Coinbase 声明并真实实现了加密货币能力（v2.24.0 收口）
     */
    public function testCoinbaseCryptoIsImplemented(): void
    {
        $cell = GatewayManifest::matrix()['coinbase']['capabilities'][GatewayManifest::CAP_CRYPTO];

        $this->assertTrue($cell['declared']);
        $this->assertTrue($cell['actual']);
        $this->assertTrue($cell['consistent']);
    }

    /**
     * 全量矩阵在当前零漂移状态下不应存在任何不一致单元格
     *
     * 锁定「声明⟺实现」的对称闭环在矩阵维度同样成立（与
     * CapabilityConformanceTest::testNoCapabilityDrift 互为横向印证）。
     */
    public function testMatrixReflectsZeroDrift(): void
    {
        $matrix = GatewayManifest::matrix();
        $inconsistent = [];

        foreach ($matrix as $name => $row) {
            foreach ($row['capabilities'] as $capability => $cell) {
                if (!$cell['consistent']) {
                    $inconsistent[] = "{$name}.{$capability}";
                }
            }
        }

        $this->assertSame([], $inconsistent, '全量能力矩阵当前应零漂移（声明⟺实现）');
    }
}
