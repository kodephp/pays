<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\UnionPay;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * 云闪付网关
 *
 * 支持 App、H5、小程序、二维码等支付场景
 */
class UnionPayGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://gateway.test.95516.com/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://gateway.95516.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['mer_id', 'cert_path', 'cert_pwd']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
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
        $this->validateRequired($params, ['orderId', 'txnAmt', 'currency']);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'txnType' => '01',
            'txnSubType' => '01',
            'bizType' => '000201',
            'signMethod' => '01',
            'channelType' => '08',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $params['orderId'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => $params['txnAmt'],
            'currencyCode' => $params['currency'],
            'frontUrl' => $params['frontUrl'] ?? '',
            'backUrl' => $params['backUrl'] ?? '',
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/frontTransReq.do', $requestData);
    }

    /**
     * 查询订单
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => '000000',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $orderId,
            'txnTime' => date('YmdHis'),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/queryTrans.do', $requestData);
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
        $this->validateRequired($params, ['orderId', 'origQryId', 'txnAmt']);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '04',
            'txnSubType' => '00',
            'bizType' => '000201',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $params['orderId'],
            'origQryId' => $params['origQryId'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => $params['txnAmt'],
            'backUrl' => $params['backUrl'] ?? '',
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/backTransReq.do', $requestData);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        return $this->queryOrder($refundId);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        return $this->verify($data, $signature);
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
        throw PayException::gatewayError('云闪付暂不支持主动关闭订单');
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'unionpay';
    }

    /**
     * 解析响应
     *
     * @param string $response JSON 响应字符串
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw PayException::gatewayError('云闪付响应格式异常');
        }

        if (!isset($data['respCode'])) {
            throw PayException::gatewayError('云闪付响应缺少状态码');
        }

        if ($data['respCode'] !== '00') {
            throw PayException::gatewayError(
                $data['respMsg'] ?? '云闪付业务失败',
                $data['respCode'],
            );
        }

        return $data;
    }

    /**
     * 签名
     *
     * @param array<string, mixed> $params 待签名参数
     * @return string 签名结果
     * @throws PayException
     */
    protected function sign(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $string = implode('&', $pairs);

        $privateKey = file_get_contents($this->getConfig('cert_path'));
        if ($privateKey === false) {
            throw PayException::configError('无法读取云闪付证书文件');
        }

        openssl_sign($string, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 验签
     *
     * @param array<string, mixed> $params 待验证参数
     * @param string $signature 签名值
     * @return bool
     * @throws PayException
     */
    protected function verify(array $params, string $signature): bool
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $string = implode('&', $pairs);

        $publicKey = file_get_contents($this->getConfig('cert_path'));
        if ($publicKey === false) {
            throw PayException::configError('无法读取云闪付证书文件');
        }

        return openssl_verify($string, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
