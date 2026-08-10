<?php

/**
 * 支付 SDK 热路径微基准（压测数据）
 *
 * 使用 Guzzle MockHandler 模拟零网络延迟的响应，仅测量 SDK 自身的
 * 请求分发、签名/验签、响应解析与清单反射等热路径开销。
 *
 * 运行：composer bench   或   php scripts/bench.php
 *
 * 说明：基准结果为相对量级参考，绝对值随机器/负载波动，请以本机实测为准。
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Support\HttpClient;
use Kode\Pays\Support\Signer;

/**
 * @param callable():void $fn
 * @return array{ops: float, avg_ms: float, total_ms: float}
 */
function bench(string $name, int $iters, callable $fn): array
{
    // 预热
    $fn();
    $fn();

    $start = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }
    $totalNs = hrtime(true) - $start;

    $totalMs = $totalNs / 1_000_000;
    $avgMs = $totalMs / $iters;
    $ops = $iters / ($totalMs / 1000);

    printf("  %-28s %10s iters  %10.3f ms/op  %12s ops/s\n", $name, number_format($iters), $avgMs, number_format((int) $ops));

    return ['ops' => $ops, 'avg_ms' => $avgMs, 'total_ms' => $totalMs];
}

echo "=== Kode\\Pays 热路径压测 ===\n";
echo "PHP " . PHP_VERSION . " | " . (function_exists('openssl_sign') ? 'openssl on' : 'openssl off') . "\n\n";

// ---- 1. HttpClient 请求分发（MockHandler 零网络）----
$mock = new MockHandler();
$http = new HttpClient(['handler' => HandlerStack::create($mock)]);
$jsonBody = json_encode(['code' => 'SUCCESS', 'data' => ['id' => '1']]);
bench('http.get (mock, no net)', 20000, static function () use ($mock, $http, $jsonBody): void {
    $mock->append(new Response(200, [], $jsonBody));
    $http->get('https://api.test/t');
});

// ---- 2. MD5 验签（回调验签热路径）----
$key = 'bench-secret-key';
$params = ['appid' => 'wx123', 'mch_id' => 'm1', 'out_trade_no' => 'O1', 'total_fee' => 100];
$sign = Signer::md5($params, $key);
$params['sign'] = $sign;
bench('signer.verifyMd5', 50000, static fn () => Signer::verifyMd5($params, $key));

// ---- 3. RSA2 签名（2048 位，出票/退款热路径）----
$keyRes = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($keyRes !== false) {
    @openssl_pkey_export($keyRes, $priv);
    bench('signer.rsa2 (2048-bit)', 1000, static fn () => Signer::rsa2($params, $priv));
} else {
    echo "  signer.rsa2 跳过（环境不支持 openssl_pkey_new）\n";
}

// ---- 4. GatewayManifest baseUrl：冷启动（反射）vs 缓存命中 ----
$refClass = new ReflectionClass(GatewayManifest::class);
$clearCache = static function () use ($refClass): void {
    $entries = $refClass->getStaticPropertyValue('entries');
    unset($entries['wechat']['base_url'], $entries['wechat']['sandbox_url']);
    $refClass->setStaticPropertyValue('entries', $entries);
};

// 冷：每次迭代清缓存，强制走反射解析
$cold = bench('manifest.baseUrl (cold/reflection)', 5000, static function () use ($clearCache): void {
    $clearCache();
    GatewayManifest::baseUrl('wechat');
});
$reflectionColdMs = $cold['avg_ms'];

// 暖：缓存已建立，纯查表
$warm = bench('manifest.baseUrl (warm/cached)', 50000, static fn () => GatewayManifest::baseUrl('wechat'));

if ($warm['avg_ms'] > 0 && $reflectionColdMs > 0) {
    $speedup = $reflectionColdMs / $warm['avg_ms'];
    printf("\n  缓存加速比（冷/热）≈ %.1fx\n", $speedup);
}

echo "\n完成。\n";
