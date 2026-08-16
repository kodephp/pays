<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Facade\Pay;
use PHPUnit\Framework\TestCase;

/**
 * 能力矩阵文档渲染（capability-discovery 文档化收口）集中验证
 *
 * 锁定：renderMatrix() 真实消费 matrix() 的二维数据，渲染出「网关 × 12 能力」对照表；
 * 单元格三态（✔/✗/⚠ 或 [x]/[ ]/[!]）由 declared/actual/consistent 推导；
 * 当前零漂移状态下产物不应出现 ⚠ / 漂移告警段；Pay::renderMatrix 与 GatewayManifest 一致。
 */
class GatewayManifestRenderMatrixTest extends TestCase
{
    /** @var array<string,array{label:string,capabilities:array<string,array{declared:bool,actual:bool,consistent:bool}}}> */
    private array $matrix;

    protected function setUp(): void
    {
        $this->matrix = GatewayManifest::matrix();
    }

    public function testRenderMarkdownContainsAllGatewaysAndShortCodes(): void
    {
        $md = GatewayManifest::renderMatrix('markdown');

        // 每个网关 label 都出现在表格中
        foreach ($this->matrix as $row) {
            $this->assertStringContainsString($row['label'], $md);
        }

        // 12 个能力短码均出现在表头
        foreach (GatewayManifest::CAPABILITY_SHORT_CODES as $code) {
            $this->assertStringContainsString($code, $md);
        }

        // 6 个核心能力短码（ORD/QRY/RFO/QRF/VRF/CLO）均出现在表头
        foreach (GatewayManifest::CAPABILITY_CORE_SHORT_CODES as $code) {
            $this->assertStringContainsString($code, $md);
        }

        // 当前零漂移状态下同时出现「支持」与「不支持」两类单元格
        $this->assertStringContainsString('✔', $md);
        $this->assertStringContainsString('✗', $md);

        // 标题与图例（含核心能力列说明）
        $this->assertStringContainsString('# 支付网关能力矩阵', $md);
        $this->assertStringContainsString('图例：', $md);
        $this->assertStringContainsString('核心能力列', $md);
    }

    public function testRenderMarkdownContainsCoreColumns(): void
    {
        $md = GatewayManifest::renderMatrix('markdown');
        $headerLine = '';
        foreach (explode("\n", $md) as $line) {
            if (str_starts_with($line, '| 网关')) {
                $headerLine = $line;
                break;
            }
        }
        // 表头顺序：网关 | 12 扩展短码 | 6 核心短码
        $this->assertStringContainsString('ORD', $headerLine);
        $this->assertStringContainsString('CLO', $headerLine);
        // 核心列在扩展列之后：RFD（末个扩展）位置应小于 CLO（首个核心）
        $this->assertLessThan(strpos($headerLine, 'CLO'), strpos($headerLine, 'RFD'));
    }

    public function testCoreCapabilitiesReturnsImplementedMap(): void
    {
        // 取一个真实网关，核心方法应全部已实现
        $core = GatewayManifest::coreCapabilities('alipay');
        $this->assertCount(count(GatewayManifest::CAPABILITY_CORE_METHODS), $core);
        foreach (GatewayManifest::CAPABILITY_CORE_METHODS as $capability => $method) {
            $this->assertArrayHasKey($capability, $core);
            // 全部 26 网关均实现 6 个基础接口方法（反射抽样已知）
            $this->assertTrue($core[$capability], "alipay 未实现核心方法 {$method}");
        }
    }

    public function testRenderTextFormat(): void
    {
        $txt = GatewayManifest::renderMatrix('text');

        $this->assertStringContainsString('支付网关能力矩阵', $txt);
        // 纯文本用 [x]/[ ] 三态
        $this->assertStringContainsString('[x]', $txt);
        $this->assertStringContainsString('[ ]', $txt);

        foreach ($this->matrix as $row) {
            $this->assertStringContainsString($row['label'], $txt);
        }
    }

    public function testCleanStateHasNoDriftSection(): void
    {
        // 当前矩阵零漂移（由 CapabilityConformanceTest::testNoCapabilityDrift 守护），
        // 渲染产物不应含漂移**单元格**（| ⚠ |）与告警段（图例中解释 ⚠ 属正常说明，不计漂移）
        $md = GatewayManifest::renderMatrix('markdown');
        $this->assertStringNotContainsString('| ⚠ |', $md);
        $this->assertStringNotContainsString('## 漂移告警', $md);

        $txt = GatewayManifest::renderMatrix('text');
        $this->assertStringNotContainsString('[!]', $txt);
        $this->assertStringNotContainsString('漂移告警', $txt);
    }

    public function testShortCodeOrderMatchesContracts(): void
    {
        $md = GatewayManifest::renderMatrix('markdown');
        $codes = array_values(GatewayManifest::CAPABILITY_SHORT_CODES);

        $firstPos = strpos($md, '| ' . $codes[0]);
        $lastPos = strpos($md, $codes[count($codes) - 1]);
        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($lastPos);
        $this->assertGreaterThan($firstPos, $lastPos);
    }

    public function testPayDelegatesRenderMatrix(): void
    {
        $this->assertSame(
            GatewayManifest::renderMatrix('text'),
            Pay::renderMatrix('text')
        );
        $this->assertSame(
            GatewayManifest::renderMatrix('markdown'),
            Pay::renderMatrix('markdown')
        );
        $this->assertSame(
            GatewayManifest::coreCapabilities('alipay'),
            Pay::coreCapabilities('alipay')
        );
    }
}
