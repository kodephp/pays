<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\AlipayGlobal;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Support\Signer;

/**
 * 支付宝国际版网关
 *
 * 支持 Alipay+ 跨境支付、Alipay Global 海外商户收单。
 * 覆盖东南亚、欧洲、中东等市场，支持多币种结算。
 */
class AlipayGlobalGateway extends AbstractGateway
{
    /**
     * 测试环境网关地址
     */
    protected const string TEST_GATEWAY_URL = 'https://globalmapi.alipay.com/gateway.do';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'private_key', 'public_key']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('alipay_global');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox ? self::TEST_GATEWAY_URL : $this->getConfig('gateway_url');
    }

    /**
     * 创建支付订单
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency', 'subject']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'method' => 'alipay.acquire.precreate',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => $this->getConfig('sign_type') ?? 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $params['notify_url'] ?? '',
            'biz_content' => json_encode([
                'out_trade_no' => $params['out_trade_no'],
                'total_amount' => $params['total_amount'],
                'currency' => $params['currency'],
                'subject' => $params['subject'],
                'body' => $params['body'] ?? '',
                'product_code' => $params['product_code'] ?? 'OVERSEAS_MBARCODE',
                'merchant_id' => $params['merchant_id'] ?? '',
                'extend_params' => [
                    'secondary_merchant_name' => $params['merchant_name'] ?? '',
                    'secondary_merchant_industry' => $params['merchant_industry'] ?? '',
                    'store_name' => $params['store_name'] ?? '',
                ],
            ]),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('gateway.do', $requestData);
    }

    /**
     * 查询订单状态
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'method' => 'alipay.acquire.query',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => $this->getConfig('sign_type') ?? 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_trade_no' => $orderId,
            ]),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('gateway.do', $requestData);
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function refund(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'refund_amount']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'method' => 'alipay.acquire.refund',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => $this->getConfig('sign_type') ?? 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_trade_no' => $params['out_trade_no'],
                'refund_amount' => $params['refund_amount'],
                'refund_reason' => $params['refund_reason'] ?? '',
                'out_request_no' => $params['out_refund_no'] ?? uniqid('refund_', true),
            ]),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('gateway.do', $requestData);
    }

    /**
     * 查询退款状态
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'method' => 'alipay.acquire.refund.query',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => $this->getConfig('sign_type') ?? 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_request_no' => $refundId,
            ]),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('gateway.do', $requestData);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['sign'])) {
            return false;
        }

        $sign = $data['sign'];
        unset($data['sign'], $data['sign_type']);

        return $this->sign($data) === $sign;
    }

    /**
     * 关闭订单
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'method' => 'alipay.acquire.close',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => $this->getConfig('sign_type') ?? 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_trade_no' => $orderId,
            ]),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('gateway.do', $requestData);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'alipay_global';
    }

    /**
     * 生成签名
     *
     * @param array<string, mixed> $params 待签名参数
     * @return string
     */
    protected function sign(array $params): string
    {
        ksort($params);

        $string = '';
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || $key === 'sign') {
                continue;
            }
            $string .= $key . '=' . $value . '&';
        }

        $string = rtrim($string, '&');

        $privateKey = $this->getConfig('private_key');
        $signType = $this->getConfig('sign_type') ?? 'RSA2';

        if ($signType === 'RSA2') {
            openssl_sign($string, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($string, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        }

        return base64_encode($signature);
    }

    /**
     * 解析响应内容
     *
     * @param string $response JSON 响应字符串
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw PayException::gatewayError('支付宝国际版响应格式异常');
        }

        $method = array_key_first($data);
        $responseKey = str_replace('.', '_', $method) . '_response';

        if (!isset($data[$responseKey])) {
            throw PayException::gatewayError('支付宝国际版响应结构异常');
        }

        $bizData = $data[$responseKey];

        if (($bizData['code'] ?? '') !== '10000') {
            throw PayException::gatewayError(
                $bizData['msg'] ?? '支付宝国际版业务失败',
                $bizData['code'] ?? '',
            );
        }

        return $bizData;
    }
}
