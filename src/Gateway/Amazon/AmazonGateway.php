<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Amazon;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Amazon Pay 网关
 *
 * 支持 Amazon Pay 网页支付和应用内支付。
 * 覆盖北美、欧洲、日本等区域。
 */
class AmazonGateway extends AbstractGateway
{
    /**
     * 区域基础 URL 映射
     */
    protected const array REGION_URLS = [
        'na' => [
            'prod' => 'https://pay-api.amazon.com/',
            'sandbox' => 'https://pay-api.amazon.com/',
        ],
        'eu' => [
            'prod' => 'https://pay-api.amazon.eu/',
            'sandbox' => 'https://pay-api.amazon.eu/',
        ],
        'jp' => [
            'prod' => 'https://pay-api.amazon.jp/',
            'sandbox' => 'https://pay-api.amazon.jp/',
        ],
    ];

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['merchant_id', 'access_key', 'secret_key', 'client_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('amazon');
        if ($url !== null) {
            return $url;
        }

        $region = $this->getConfig('region') ?? 'na';
        $env = $this->sandbox ? 'sandbox' : 'prod';

        return self::REGION_URLS[$region][$env] ?? self::REGION_URLS['na'][$env];
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
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency', 'amazon_order_reference_id']);

        $requestData = [
            'merchant_id' => $this->getConfig('merchant_id'),
            'amazon_order_reference_id' => $params['amazon_order_reference_id'],
            'amount' => $params['total_amount'],
            'currency_code' => $params['currency'],
            'seller_order_id' => $params['out_trade_no'],
        ];

        if (isset($params['description'])) {
            $requestData['seller_note'] = $params['description'];
        }

        return $this->post('live/v2/setOrderReferenceDetails', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 确认订单并授权
     *
     * @param string $orderReferenceId Amazon 订单引用 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function confirmOrder(string $orderReferenceId): array
    {
        return $this->post('live/v2/confirmOrderReference', [
            'merchant_id' => $this->getConfig('merchant_id'),
            'amazon_order_reference_id' => $orderReferenceId,
        ], [
            'Content-Type' => 'application/json',
        ]);
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
        return $this->post('live/v2/getOrderReferenceDetails', [
            'merchant_id' => $this->getConfig('merchant_id'),
            'seller_order_id' => $orderId,
        ], [
            'Content-Type' => 'application/json',
        ]);
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
        $this->validateRequired($params, ['amazon_capture_id', 'refund_amount']);

        return $this->post('live/v2/refund', [
            'merchant_id' => $this->getConfig('merchant_id'),
            'amazon_capture_id' => $params['amazon_capture_id'],
            'refund_amount' => $params['refund_amount'],
            'currency_code' => $params['currency'] ?? 'USD',
            'seller_refund_note' => $params['refund_reason'] ?? '',
        ], [
            'Content-Type' => 'application/json',
        ]);
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
        return $this->post('live/v2/getRefundDetails', [
            'merchant_id' => $this->getConfig('merchant_id'),
            'amazon_refund_id' => $refundId,
        ], [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 验证异步通知（IPN）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['Signature'])) {
            return false;
        }

        // Amazon Pay IPN 使用 HMAC-SHA256 签名验证
        $signature = $data['Signature'];
        unset($data['Signature']);

        $string = json_encode($data);
        $expected = base64_encode(hash_hmac('sha256', $string, $this->getConfig('secret_key'), true));

        return hash_equals($expected, $signature);
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
        return $this->post('live/v2/closeOrderReference', [
            'merchant_id' => $this->getConfig('merchant_id'),
            'seller_order_id' => $orderId,
        ], [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'amazon';
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
            throw PayException::gatewayError('Amazon Pay 响应格式异常');
        }

        if (isset($data['Error'])) {
            throw PayException::gatewayError(
                $data['Error']['Message'] ?? 'Amazon Pay 业务失败',
                $data['Error']['Code'] ?? '',
            );
        }

        return $data;
    }
}
