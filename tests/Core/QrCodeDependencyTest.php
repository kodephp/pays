<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * 回归守护：endroid/qr-code 必须是可选依赖（suggest + require-dev），
 * 绝不能回到 require，否则会与 kode/tools 自带的 endroid ^6.0 产生主版本冲突
 * （kode/pays 的 QrCodeGenerator 使用 endroid v5 API，而 kode/tools 提供 v6 API）。
 */
class QrCodeDependencyTest extends TestCase
{
    /**
     * 读取并解析项目根目录的 composer.json
     *
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        self::assertFileExists($path, 'composer.json 缺失');

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, 'composer.json 解析失败');

        return $data;
    }

    public function testEndroidNotInRequire(): void
    {
        $data = $this->composer();
        self::assertArrayHasKey('require', $data);
        self::assertArrayNotHasKey(
            'endroid/qr-code',
            $data['require'],
            'endroid/qr-code 不能出现在 require 中，否则会与 kode/tools 的 endroid ^6.0 冲突',
        );
    }

    public function testEndroidInRequireDev(): void
    {
        $data = $this->composer();
        self::assertArrayHasKey('require-dev', $data);
        self::assertArrayHasKey(
            'endroid/qr-code',
            $data['require-dev'],
            'endroid/qr-code 必须留在 require-dev，否则 kode/pays 自身测试无法运行',
        );
    }

    public function testEndroidInSuggest(): void
    {
        $data = $this->composer();
        self::assertArrayHasKey('suggest', $data);
        self::assertArrayHasKey(
            'endroid/qr-code',
            $data['suggest'],
            'endroid/qr-code 应在 suggest 中告知消费方其为可选依赖',
        );
    }

    public function testKodeToolsNotInRequire(): void
    {
        $data = $this->composer();
        self::assertArrayHasKey('require', $data);
        self::assertArrayNotHasKey(
            'kode/tools',
            $data['require'],
            'kode/tools 不应出现在 require 中',
        );
    }
}
