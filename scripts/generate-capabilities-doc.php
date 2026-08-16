<?php

declare(strict_types=1);

/**
 * 能力矩阵文档生成器
 *
 * 消费 {@see \Kode\Pays\Core\GatewayManifest::renderMatrix()} 的二维数据，
 * 把「网关 × 12 项扩展能力」对照表渲染为 Markdown 并落盘，作为仓库能力对照文档。
 *
 * 用法：
 *   php scripts/generate-capabilities-doc.php [输出路径]
 * 不传输出路径时默认写入仓库根 docs/capabilities.md。
 *
 * 该脚本由 `composer capabilities` 调用，每次发版可一键重新产出能力对照表，
 * 无需手动从 matrix() 复制表格。
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php 未找到，请先执行 composer install\n");
    exit(1);
}
require $autoload;

use Kode\Pays\Core\GatewayManifest;

$output = $argv[1] ?? __DIR__ . '/../docs/capabilities.md';

$matrix = GatewayManifest::renderMatrix('markdown');

$generatedAt = date('Y-m-d H:i:s');
$preamble = "<!-- 本文档由 composer capabilities 自动生成，请勿手动编辑。生成时间：{$generatedAt} -->\n";

if (file_put_contents($output, $preamble . $matrix) === false) {
    fwrite(STDERR, "写入失败：{$output}\n");
    exit(1);
}

fwrite(STDOUT, "已生成能力矩阵文档：{$output}\n");
exit(0);
