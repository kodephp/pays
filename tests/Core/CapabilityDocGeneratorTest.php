<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\GatewayManifest;
use PHPUnit\Framework\TestCase;

/**
 * 能力矩阵文档生成器（composer capabilities）集中验证
 *
 * 锁定：scripts/generate-capabilities-doc.php 真实可独立执行、产出含标题/12 短码/全部网关的
 * Markdown 对照表；且 composer.json scripts 已登记 "capabilities" 入口指向该脚本。
 */
class CapabilityDocGeneratorTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = __DIR__ . '/../../scripts/generate-capabilities-doc.php';
    }

    public function testGeneratorScriptProducesDoc(): void
    {
        $tmp = sys_get_temp_dir() . '/kode-pays-capabilities-' . uniqid() . '.md';

        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script),
            escapeshellarg($tmp)
        );
        exec($cmd, $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertFileExists($tmp);

        $content = file_get_contents($tmp);

        // 自动生成注释 + 标题
        $this->assertStringContainsString('自动生成，请勿手动编辑', $content);
        $this->assertStringContainsString('# 支付网关能力矩阵', $content);

        // 12 个能力短码均出现在表头
        foreach (GatewayManifest::CAPABILITY_SHORT_CODES as $code) {
            $this->assertStringContainsString($code, $content);
        }

        // 每个网关 label 均出现在表格中
        foreach (GatewayManifest::matrix() as $row) {
            $this->assertStringContainsString($row['label'], $content);
        }

        unlink($tmp);
    }

    public function testComposerWiringPointsToGenerator(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertArrayHasKey('scripts', $composer);
        $this->assertArrayHasKey('capabilities', $composer['scripts']);
        $this->assertStringContainsString(
            'scripts/generate-capabilities-doc.php',
            $composer['scripts']['capabilities']
        );
    }
}
