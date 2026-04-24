<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Jd;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Support\Encryptor;
use Kode\Pays\Support\Signer;

/**
 * 京东支付网关
 *
 * 支持京东钱包支付、京东白条支付等场景。
 * 覆盖京东 App、京东小程序、PC 网页等渠道。
 */
class JdGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://uat-wg.jd.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://wg.jd.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['merchant_no', 'des_key', 'md5_key']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('jd');
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
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'subject', 'notify_url']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $params['out_trade_no'],
            'totalAmount' => $params['total_amount'],
            'subject' => $params['subject'],
            'notifyUrl' => $params['notify_url'],
            'tradeTime' => date('YmdHis'),
            'tradeType' => $params['trade_type'] ?? 'APP',
        ];

        if (isset($params['return_url'])) {
            $requestData['returnUrl'] = $params['return_url'];
        }

        if (isset($params['body'])) {
            $requestData['body'] = $params['body'];
        }

        if (isset($params['expire_time'])) {
            $requestData['expireTime'] = $params['expire_time'];
        }

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/unifiedOrder', $requestData);
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
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $orderId,
            'tradeTime' => date('YmdHis'),
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
        $this->validateRequired($params, ['out_trade_no', 'refund_amount']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $params['out_trade_no'],
            'refundAmount' => $params['refund_amount'],
            'outRefundNo' => $params['out_refund_no'] ?? uniqid('refund_', true),
            'tradeTime' => date('YmdHis'),
        ];

        if (isset($params['refund_reason'])) {
            $requestData['refundReason'] = $params['refund_reason'];
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
            'merchantNo' => $this->getConfig('merchant_no'),
            'outRefundNo' => $refundId,
            'tradeTime' => date('YmdHis'),
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
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $orderId,
            'tradeTime' => date('YmdHis'),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/closeOrder', $requestData);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'jd';
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
            throw PayException::gatewayError('京东响应格式异常');
        }

        if (($data['resultCode'] ?? '') !== '000000') {
            throw PayException::gatewayError(
                $data['resultMessage'] ?? '京东业务失败',
                $data['resultCode'] ?? '',
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

        $string .= 'key=' . $this->getConfig('md5_key');

        return strtoupper(md5($string));
    }
}
