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

        // 当前零漂移状态下同时出现「支持」与「不支持」两类单元格
        $this->assertStringContainsString('✔', $md);
        $this->assertStringContainsString('✗', $md);

        // 标题与图例
        $this->assertStringContainsString('# 支付网关能力矩阵', $md);
        $this->assertStringContainsString('图例：', $md);
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
    }
}
