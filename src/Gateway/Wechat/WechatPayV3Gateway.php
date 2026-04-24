<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Encryptor;

/**
 * 微信支付 V3 网关
 *
 * 支持微信支付 APIv3 协议，使用 RSA 签名和 AES-GCM 加密。
 * 与 V2 版本相比，V3 提供更强的安全性和更简洁的接口。
 */
class WechatPayV3Gateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://api.mch.weixin.qq.com/v3/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://api.mch.weixin.qq.com/v3/';

    /**
     * 平台证书（用于响应加密）
     */
    protected ?string $platformCertificate = null;

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['mch_id', 'serial_no', 'private_key', 'api_key']);
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
        $this->validateRequired($params, ['out_trade_no', 'description', 'amount', 'notify_url']);

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mchid' => $this->getConfig('mch_id'),
            'out_trade_no' => $params['out_trade_no'],
            'description' => $params['description'],
            'notify_url' => $params['notify_url'],
            'amount' => [
                'total' => $params['amount'],
                'currency' => $params['currency'] ?? 'CNY',
            ],
        ];

        if (isset($params['time_expire'])) {
            $requestData['time_expire'] = $params['time_expire'];
        }

        if (isset($params['attach'])) {
            $requestData['attach'] = $params['attach'];
        }

        // 根据场景添加不同参数
        $requestData = match ($params['trade_type'] ?? 'native') {
            'jsapi', 'miniprogram' => array_merge($requestData, [
                'payer' => ['openid' => $params['openid']],
            ]),
            'h5' => array_merge($requestData, [
                'scene_info' => $params['scene_info'] ?? [],
            ]),
            'native' => $requestData,
            default => $requestData,
        };

        $headers = $this->buildV3Headers('POST', 'pay/transactions/' . ($params['trade_type'] ?? 'native'));

        return $this->post('pay/transactions/' . ($params['trade_type'] ?? 'native'), $requestData, $headers);
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
        $headers = $this->buildV3Headers('GET', "pay/transactions/out-trade-no/{$orderId}?mchid=" . $this->getConfig('mch_id'));

        return $this->get("pay/transactions/out-trade-no/{$orderId}", ['mchid' => $this->getConfig('mch_id')], $headers);
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
        $headers = $this->buildV3Headers('POST', "pay/transactions/out-trade-no/{$orderId}/close");

        return $this->post("pay/transactions/out-trade-no/{$orderId}/close", [
            'mchid' => $this->getConfig('mch_id'),
        ], $headers);
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
        $this->validateRequired($params, ['out_refund_no', 'out_trade_no', 'amount']);

        $requestData = [
            'out_refund_no' => $params['out_refund_no'],
            'out_trade_no' => $params['out_trade_no'],
            'reason' => $params['reason'] ?? '',
            'notify_url' => $params['notify_url'] ?? '',
            'amount' => [
                'refund' => $params['amount']['refund'],
                'total' => $params['amount']['total'],
                'currency' => $params['amount']['currency'] ?? 'CNY',
            ],
        ];

        $headers = $this->buildV3Headers('POST', 'refund/domestic/refunds');

        return $this->post('refund/domestic/refunds', $requestData, $headers);
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
        $headers = $this->buildV3Headers('GET', "refund/domestic/refunds/{$refundId}");

        return $this->get("refund/domestic/refunds/{$refundId}", [], $headers);
    }

    /**
     * 验证异步通知签名（V3 使用 RSA 验签）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['signature'], $data['timestamp'], $data['nonce'], $data['serial'])) {
            return false;
        }

        $message = $data['timestamp'] . "\n" . $data['nonce'] . "\n" . ($data['body'] ?? '') . "\n";

        return Encryptor::rsaVerify($message, $data['signature'], $this->getPlatformCertificate($data['serial']), 'sha256');
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'wechat_v3';
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
            throw PayException::gatewayError('微信支付 V3 响应格式异常');
        }

        // V3 错误响应
        if (isset($data['code'])) {
            throw PayException::gatewayError(
                $data['message'] ?? '微信支付 V3 业务失败',
                $data['code'],
            );
        }

        return $data;
    }

    /**
     * 构建 V3 请求头
     *
     * @param string $method HTTP 方法
     * @param string $url 请求路径
     * @return array<string, string>
     * @throws PayException
     */
    protected function buildV3Headers(string $method, string $url): array
    {
        $timestamp = (string) time();
        $nonce = StrUtil::random(32);
        $body = '';
        $serialNo = $this->getConfig('serial_no');

        $message = $method . "\n" . $url . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = Encryptor::rsaSign($message, $this->getConfig('private_key'), 'sha256');

        return [
            'Authorization' => sprintf(
                'WECHATPAY2-SHA256-RSA2048 mchid="%s",serial_no="%s",timestamp="%s",nonce_str="%s",signature="%s"',
                $this->getConfig('mch_id'),
                $serialNo,
                $timestamp,
                $nonce,
                $signature,
            ),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * 获取平台证书
     *
     * @param string $serial 证书序列号
     * @return string PEM 格式证书
     */
    protected function getPlatformCertificate(string $serial): string
    {
        // 实际实现应从微信支付证书下载接口获取并缓存
        return $this->platformCertificate ?? '';
    }
}
