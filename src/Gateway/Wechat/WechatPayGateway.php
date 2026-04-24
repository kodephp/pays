<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Signer;

/**
 * 微信支付网关
 *
 * 支持 JSAPI、Native、H5、App、小程序等支付场景
 */
class WechatPayGateway extends AbstractGateway
{
    /**
     * 沙箱环境基础 URL
     */
    protected const string SANDBOX_BASE_URL = 'https://api.mch.weixin.qq.com/sandboxnew/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://api.mch.weixin.qq.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'mch_id', 'api_key']);
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
        $this->validateRequired($params, ['out_trade_no', 'total_fee', 'body', 'trade_type']);

        $params['appid'] = $this->getConfig('app_id');
        $params['mch_id'] = $this->getConfig('mch_id');
        $params['nonce_str'] = $this->generateNonceStr();
        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/unifiedorder', $xml, ['Content-Type' => 'text/xml']);

        return $this->parseResponse($response);
    }

    /**
     * 查询订单
     *
     * @param string $orderId 商户订单号或微信订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $params = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
        ];

        // 优先使用微信订单号查询，否则使用商户订单号
        if (str_starts_with($orderId, 'wx')) {
            $params['transaction_id'] = $orderId;
        } else {
            $params['out_trade_no'] = $orderId;
        }

        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/orderquery', $xml, ['Content-Type' => 'text/xml']);

        return $this->parseResponse($response);
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
        $this->validateRequired($params, ['out_refund_no', 'total_fee', 'refund_fee']);

        $params['appid'] = $this->getConfig('app_id');
        $params['mch_id'] = $this->getConfig('mch_id');
        $params['nonce_str'] = $this->generateNonceStr();
        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('secapi/pay/refund', $xml, ['Content-Type' => 'text/xml']);

        return $this->parseResponse($response);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $params = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_refund_no' => $refundId,
        ];

        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/refundquery', $xml, ['Content-Type' => 'text/xml']);

        return $this->parseResponse($response);
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

        return Signer::verifyMd5($data, $this->getConfig('api_key'));
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
        $params = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'out_trade_no' => $orderId,
            'nonce_str' => $this->generateNonceStr(),
        ];

        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/closeorder', $xml, ['Content-Type' => 'text/xml']);

        return $this->parseResponse($response);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'wechat';
    }

    /**
     * 解析响应
     *
     * @param string $response XML 响应字符串
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function parseResponse(string $response): array
    {
        $data = $this->xmlToArray($response);

        if (!isset($data['return_code'])) {
            throw PayException::gatewayError('微信支付响应格式异常');
        }

        if ($data['return_code'] !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['return_msg'] ?? '微信支付通信失败',
                $data['return_code'],
            );
        }

        if (isset($data['result_code']) && $data['result_code'] !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['err_code_des'] ?? '微信支付业务失败',
                $data['err_code'] ?? '',
            );
        }

        // 验证响应签名
        if (isset($data['sign']) && !Signer::verifyMd5($data, $this->getConfig('api_key'))) {
            throw PayException::signError('微信支付响应签名验证失败');
        }

        return $data;
    }

    /**
     * 数组转 XML
     *
     * @param array<string, mixed> $data
     * @return string
     */
    protected function arrayToXml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            $xml .= is_numeric($val) ? "<{$key}>{$val}</{$key}>" : "<{$key}><![CDATA[{$val}]]></{$key}>";
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * XML 转数组
     *
     * @param string $xml
     * @return array<string, mixed>
     */
    protected function xmlToArray(string $xml): array
    {
        $xml = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);

        return json_decode(json_encode($xml), true) ?: [];
    }

    /**
     * 生成随机字符串
     *
     * @param int $length 长度
     * @return string
     */
    protected function generateNonceStr(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
