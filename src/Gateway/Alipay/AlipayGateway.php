<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Alipay;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Signer;

/**
 * 支付宝网关
 *
 * 支持电脑网站、手机网站、App、小程序、当面付等支付场景
 */
class AlipayGateway extends AbstractGateway
{
    /**
     * 沙箱环境基础 URL
     */
    protected const string SANDBOX_BASE_URL = 'https://openapi.alipaydev.com/gateway.do';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://openapi.alipay.com/gateway.do';

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
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PROD_BASE_URL;
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
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'subject']);

        $bizContent = [
            'out_trade_no' => $params['out_trade_no'],
            'total_amount' => $params['total_amount'],
            'subject' => $params['subject'],
            'product_code' => $params['product_code'] ?? 'FAST_INSTANT_TRADE_PAY',
        ];

        if (isset($params['notify_url'])) {
            $bizContent['notify_url'] = $params['notify_url'];
        }

        if (isset($params['return_url'])) {
            $bizContent['return_url'] = $params['return_url'];
        }

        $requestParams = $this->buildRequestParams('alipay.trade.page.pay', $bizContent);

        // 支付宝页面支付返回表单 HTML，直接返回给前端跳转
        return [
            'method' => 'GET',
            'url' => $this->getBaseUrl() . '?' . http_build_query($requestParams),
        ];
    }

    /**
     * 查询订单
     *
     * @param string $orderId 商户订单号或支付宝订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $bizContent = [];

        if (str_starts_with($orderId, '20')) {
            $bizContent['trade_no'] = $orderId;
        } else {
            $bizContent['out_trade_no'] = $orderId;
        }

        $requestParams = $this->buildRequestParams('alipay.trade.query', $bizContent);

        return $this->post('', $requestParams);
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

        $bizContent = [
            'out_trade_no' => $params['out_trade_no'],
            'refund_amount' => $params['refund_amount'],
        ];

        if (isset($params['out_request_no'])) {
            $bizContent['out_request_no'] = $params['out_request_no'];
        }

        if (isset($params['refund_reason'])) {
            $bizContent['refund_reason'] = $params['refund_reason'];
        }

        $requestParams = $this->buildRequestParams('alipay.trade.refund', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款请求号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $bizContent = [
            'out_request_no' => $refundId,
        ];

        $requestParams = $this->buildRequestParams('alipay.trade.fastpay.refund.query', $bizContent);

        return $this->post('', $requestParams);
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
        $signType = $data['sign_type'] ?? 'RSA2';
        $algo = $signType === 'RSA' ? 'SHA1' : 'SHA256';

        unset($data['sign'], $data['sign_type']);

        return Signer::verifyRsa($data, $this->getConfig('public_key'), $sign, false, $algo);
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
        $bizContent = [
            'out_trade_no' => $orderId,
        ];

        $requestParams = $this->buildRequestParams('alipay.trade.close', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'alipay';
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
            throw PayException::gatewayError('支付宝响应格式异常');
        }

        // 支付宝响应 key 为接口名 + _response
        $responseKey = array_keys($data)[0] ?? '';
        $responseData = $data[$responseKey] ?? [];

        if (!isset($responseData['code'])) {
            throw PayException::gatewayError('支付宝响应缺少状态码');
        }

        if ($responseData['code'] !== '10000') {
            throw PayException::gatewayError(
                $responseData['msg'] ?? '支付宝业务失败',
                $responseData['code'],
                $responseData['sub_msg'] ?? '',
            );
        }

        return $responseData;
    }

    /**
     * 构建请求参数
     *
     * @param string $method API 方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @return array<string, mixed>
     */
    protected function buildRequestParams(string $method, array $bizContent): array
    {
        $params = [
            'app_id' => $this->getConfig('app_id'),
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
        ];

        if ($this->sandbox) {
            $params['app_auth_token'] = $this->getConfig('app_auth_token');
        }

        $params['sign'] = Signer::rsa2($params, $this->getConfig('private_key'));

        return $params;
    }
}
