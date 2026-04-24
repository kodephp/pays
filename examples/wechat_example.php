<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Pays\Pay;

/**
 * 微信支付接入示例
 */

// 1. 创建微信支付网关
$wechat = Pay::create('wechat', [
    'app_id'  => 'wx1234567890abcdef',
    'mch_id'  => '1234567890',
    'api_key' => 'your-api-key-here',
    'sandbox' => true, // 测试环境
]);

// 2. 创建支付订单（Native 扫码支付）
try {
    $result = $wechat->createOrder([
        'out_trade_no' => 'ORDER_' . date('YmdHis') . rand(1000, 9999),
        'total_fee'    => 100, // 金额：分
        'body'         => '测试商品-微信支付',
        'trade_type'   => 'NATIVE',
        'notify_url'   => 'https://your-domain.com/notify/wechat',
    ]);

    echo '支付二维码链接：' . ($result['code_url'] ?? '') . PHP_EOL;
} catch (\Kode\Pays\Core\PayException $e) {
    echo '支付失败：' . $e->getMessage() . PHP_EOL;
}

// 3. 查询订单
// $orderInfo = $wechat->queryOrder('ORDER_202404240001');

// 4. 申请退款
// $refundResult = $wechat->refund([
//     'out_trade_no' => 'ORDER_202404240001',
//     'out_refund_no' => 'REFUND_202404240001',
//     'total_fee' => 100,
//     'refund_fee' => 100,
// ]);

// 5. 异步通知验证示例
// $notifyData = xmlToArray(file_get_contents('php://input'));
// if ($wechat->verifyNotify($notifyData)) {
//     echo '<xml><return_code><![CDATA[SUCCESS]]></return_code></xml>';
// }
