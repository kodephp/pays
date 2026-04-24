<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Pays\Pay;

/**
 * 聚合支付接入示例
 *
 * 配置多个支付渠道，自动按优先级路由，失败时自动切换
 */

// 创建聚合支付网关
$aggregate = Pay::create('aggregate', [
    'channels' => [
        [
            'gateway'  => 'wechat',
            'priority' => 1,
            'config'   => [
                'app_id'  => 'wx1234567890abcdef',
                'mch_id'  => '1234567890',
                'api_key' => 'your-wechat-api-key',
            ],
        ],
        [
            'gateway'  => 'alipay',
            'priority' => 2,
            'config'   => [
                'app_id'      => '2024XXXXXXXXXXXX',
                'private_key' => '-----BEGIN RSA PRIVATE KEY-----\n...',
                'public_key'  => '-----BEGIN PUBLIC KEY-----\n...',
            ],
        ],
    ],
]);

// 创建订单（SDK 会自动选择可用渠道）
try {
    $result = $aggregate->createOrder([
        'out_trade_no' => 'ORDER_' . date('YmdHis'),
        'total_fee'    => 100,
        'body'         => '测试商品-聚合支付',
    ]);

    echo '实际使用渠道：' . ($result['_channel'] ?? 'unknown') . PHP_EOL;

    if ($result['_channel'] === 'wechat') {
        echo '微信支付二维码：' . ($result['code_url'] ?? '') . PHP_EOL;
    } elseif ($result['_channel'] === 'alipay') {
        echo '支付宝跳转链接：' . ($result['url'] ?? '') . PHP_EOL;
    }
} catch (\Kode\Pays\Core\PayException $e) {
    echo '支付失败：' . $e->getMessage() . PHP_EOL;
}
