<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Pays\Pay;

/**
 * 支付宝接入示例
 */

// 1. 创建支付宝网关
$alipay = Pay::create('alipay', [
    'app_id'      => '2024XXXXXXXXXXXX',
    'private_key' => '-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----',
    'public_key'  => '-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----',
    'sandbox'     => true,
]);

// 2. 创建支付订单（电脑网站支付）
try {
    $result = $alipay->createOrder([
        'out_trade_no' => 'ORDER_' . date('YmdHis'),
        'total_amount' => '0.01',
        'subject'      => '测试商品-支付宝',
        'product_code' => 'FAST_INSTANT_TRADE_PAY',
        'notify_url'   => 'https://your-domain.com/notify/alipay',
        'return_url'   => 'https://your-domain.com/return',
    ]);

    // 跳转到支付宝收银台
    header('Location: ' . $result['url']);
} catch (\Kode\Pays\Core\PayException $e) {
    echo '支付失败：' . $e->getMessage() . PHP_EOL;
}

// 3. 查询订单
// $orderInfo = $alipay->queryOrder('ORDER_202404240001');

// 4. 申请退款
// $refundResult = $alipay->refund([
//     'out_trade_no' => 'ORDER_202404240001',
//     'refund_amount' => '0.01',
//     'out_request_no' => 'REFUND_202404240001',
// ]);

// 5. 异步通知验证示例
// $notifyData = $_POST;
// if ($alipay->verifyNotify($notifyData)) {
//     echo 'success';
// } else {
//     echo 'fail';
// }
