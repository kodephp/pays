<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\QQ;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;
use Kode\Pays\Exception\SignException;

/**
 * QQ 支付网关
 *
 * 支持 QQ 钱包扫码支付、JSAPI 支付、APP 支付等。
 * 基于 QQ 支付 v3 API 实现。
 *
 * 使用示例：
 * ```php
 * $gateway = Pay::qq([
 *     'app_id' => 'your_qq_app_id',
 *     'mch_id' => 'your_qq_mch_id',
 *     'api_key' => 'your_qq_api_key',
 *     'notify_url' => 'https://example.com/notify',
 * ]);
 *
 * // 创建 QQ 支付订单
 * $result = $gateway->createOrder([
 *     'out_trade_no' => 'ORDER_001',
 *     'total_amount' => 10000,
 *     'subject' => '商品购买',
 *     'trade_type' => 'JSAPI',
 *     'openid' => 'qq_openid',
 * ]);
 * ```
 */
class QQGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'mch_id', 'api_key']);
    }

    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('qq');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox
            ? 'https://sandbox.api.qpay.qq.com/'
            : 'https://api.qpay.qq.com/';
    }

    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'trade_type']);

        $requestData = [
            'appid' => $this->config['app_id'],
            'mchid' => $this->config['mch_id'],
            'out_trade_no' => $params['out_trade_no'],
            'total_amount' => $params['total_amount'],
            'subject' => $params['subject'] ?? $params['description'] ?? '商品购买',
            'trade_type' => $params['trade_type'],
            'notify_url' => $params['notify_url'] ?? $this->config['notify_url'] ?? '',
        ];

        // 不同交易类型的必填参数
        switch ($params['trade_type']) {
            case 'JSAPI':
                if (empty($params['openid'])) {
                    throw PayException::invalidArgument('JSAPI 支付需要 openid');
                }
                $requestData['openid'] = $params['openid'];
                break;
            case 'NATIVE':
                // NATIVE 支付不需要额外参数
                break;
            case 'APP':
                // APP 支付不需要额外参数
                break;
        }

        $response = $this->post('v3/pay/transaction/jsapi', $requestData);

        return [
            'out_trade_no' => $params['out_trade_no'],
            'prepay_id' => $response['prepay_id'] ?? '',
            'code_url' => $response['code_url'] ?? '',
            'trade_type' => $params['trade_type'],
            'mch_id' => $this->config['mch_id'],
            'app_id' => $this->config['app_id'],
        ];
    }

    public function queryOrder(string $orderId): array
    {
        // QQ 支付支持通过 transaction_id 或 out_trade_no 查询
        if (strlen($orderId) > 32) {
            // transaction_id
            $response = $this->get("v3/pay/transaction/id/{$orderId}");
        } else {
            // out_trade_no
            $response = $this->get("v3/pay/transaction/out-trade-no/{$orderId}");
        }

        return [
            'transaction_id' => $response['transaction_id'] ?? '',
            'out_trade_no' => $response['out_trade_no'] ?? '',
            'total_amount' => $response['amount']['total'] ?? 0,
            'trade_state' => $response['trade_state'] ?? '',
            'trade_state_desc' => $response['trade_state_desc'] ?? '',
            'pay_time' => $response['success_time'] ?? '',
            'mch_id' => $response['mchid'] ?? '',
            'app_id' => $response['appid'] ?? '',
        ];
    }

    public function refund(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'out_refund_no', 'refund_fee', 'total_fee']);

        $requestData = [
            'out_trade_no' => $params['out_trade_no'],
            'out_refund_no' => $params['out_refund_no'],
            'refund_fee' => $params['refund_fee'],
            'total_fee' => $params['total_fee'],
            'reason' => $params['refund_desc'] ?? '',
        ];

        $response = $this->post('v3/refund/domestic/refunds', $requestData);

        return [
            'refund_id' => $response['refund_id'] ?? '',
            'out_refund_no' => $params['out_refund_no'],
            'out_trade_no' => $params['out_trade_no'],
            'refund_fee' => $params['refund_fee'],
            'total_fee' => $params['total_fee'],
            'status' => $response['status'] ?? '',
        ];
    }

    public function queryRefund(string $refundId): array
    {
        $response = $this->get("v3/refund/domestic/refunds/{$refundId}");

        return [
            'refund_id' => $response['refund_id'] ?? '',
            'out_refund_no' => $response['out_refund_no'] ?? '',
            'out_trade_no' => $response['out_trade_no'] ?? '',
            'refund_fee' => $response['amount']['refund'] ?? 0,
            'total_fee' => $response['amount']['total'] ?? 0,
            'status' => $response['status'] ?? '',
            'success_time' => $response['success_time'] ?? '',
        ];
    }

    public function closeOrder(string $orderId): array
    {
        $response = $this->post("v3/pay/transaction/out-trade-no/{$orderId}/close", []);

        return [
            'out_trade_no' => $orderId,
            'result' => 'SUCCESS',
            'message' => '订单已关闭',
        ];
    }

    public function verifyNotify(array $data): bool
    {
        // 验证 QQ 支付异步通知签名
        $signature = $data['sign'] ?? '';
        if ($signature === '') {
            return false;
        }

        // 移除 sign 字段
        $dataWithoutSign = $data;
        unset($dataWithoutSign['sign']);

        // 按 key 排序
        ksort($dataWithoutSign);

        // 拼接参数
        $string = http_build_query($dataWithoutSign, '', '&', PHP_QUERY_RFC3986);
        $string .= '&key=' . $this->config['api_key'];

        // 计算签名
        $computed = strtoupper(md5($string));

        return hash_equals($computed, $signature);
    }

    public static function getName(): string
    {
        return 'qq';
    }

    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new GatewayException('响应格式异常');
        }

        if (isset($data['code']) && $data['code'] !== 'SUCCESS') {
            throw new GatewayException(
                $data['message'] ?? '业务失败',
                $data['code'] ?? '',
            );
        }

        return $data;
    }

    protected function resolveHeader(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
