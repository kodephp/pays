<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Meituan;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Support\Signer;

/**
 * 美团支付网关
 *
 * 支持美团智能支付、美团闪付等支付场景。
 * 覆盖美团 App、美团外卖、美团小程序等渠道。
 */
class MeituanGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://open-api-test.meituan.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://open-api.meituan.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'app_secret', 'merchant_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('meituan');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
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
        $this->validateRequired($params, ['out_trade_no', 'total_fee', 'body', 'notify_url']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_trade_no' => $params['out_trade_no'],
            'total_fee' => $params['total_fee'],
            'body' => $params['body'],
            'notify_url' => $params['notify_url'],
            'timestamp' => (string) time(),
            'nonce_str' => $this->generateNonceStr(),
        ];

        if (isset($params['attach'])) {
            $requestData['attach'] = $params['attach'];
        }

        if (isset($params['expire_time'])) {
            $requestData['expire_time'] = $params['expire_time'];
        }

        if (isset($params['trade_type'])) {
            $requestData['trade_type'] = $params['trade_type'];
        }

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/createOrder', $requestData);
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
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_trade_no' => $orderId,
            'timestamp' => (string) time(),
            'nonce_str' => $this->generateNonceStr(),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/queryOrder', $requestData);
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
        $this->validateRequired($params, ['out_trade_no', 'refund_fee']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_trade_no' => $params['out_trade_no'],
            'refund_fee' => $params['refund_fee'],
            'out_refund_no' => $params['out_refund_no'] ?? uniqid('refund_', true),
            'timestamp' => (string) time(),
            'nonce_str' => $this->generateNonceStr(),
        ];

        if (isset($params['refund_desc'])) {
            $requestData['refund_desc'] = $params['refund_desc'];
        }

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/refund', $requestData);
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
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_refund_no' => $refundId,
            'timestamp' => (string) time(),
            'nonce_str' => $this->generateNonceStr(),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/queryRefund', $requestData);
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
        unset($data['sign']);

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
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_trade_no' => $orderId,
            'timestamp' => (string) time(),
            'nonce_str' => $this->generateNonceStr(),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/closeOrder', $requestData);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'meituan';
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
            throw PayException::gatewayError('美团响应格式异常');
        }

        if (($data['status'] ?? '') !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['message'] ?? '美团业务失败',
                $data['error_code'] ?? '',
            );
        }

        return $data;
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
            if ($value === '' || $value === null) {
                continue;
            }
            $string .= $key . '=' . $value . '&';
        }

        $string .= 'key=' . $this->getConfig('app_secret');

        return strtoupper(md5($string));
    }

    /**
     * 生成随机字符串
     *
     * @return string
     */
    protected function generateNonceStr(): string
    {
        return bin2hex(random_bytes(16));
    }
}
