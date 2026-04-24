<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Klarna;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Klarna 网关
 *
 * 支持 Klarna 先买后付（Pay Later）、分期付款（Pay in 3/4）等场景。
 * 覆盖欧洲、美国、澳大利亚等市场。
 */
class KlarnaGateway extends AbstractGateway
{
    /**
     * 区域基础 URL 映射
     */
    protected const array REGION_URLS = [
        'eu' => [
            'prod' => 'https://api.klarna.com/',
            'sandbox' => 'https://api.playground.klarna.com/',
        ],
        'us' => [
            'prod' => 'https://api-na.klarna.com/',
            'sandbox' => 'https://api-na.playground.klarna.com/',
        ],
        'oc' => [
            'prod' => 'https://api-oc.klarna.com/',
            'sandbox' => 'https://api-oc.playground.klarna.com/',
        ],
    ];

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['username', 'password']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('klarna');
        if ($url !== null) {
            return $url;
        }

        $region = $this->getConfig('region') ?? 'eu';
        $env = $this->sandbox ? 'sandbox' : 'prod';

        return self::REGION_URLS[$region][$env] ?? self::REGION_URLS['eu'][$env];
    }

    /**
     * 创建支付会话
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency', 'items']);

        $requestData = [
            'purchase_country' => $params['country'] ?? 'DE',
            'purchase_currency' => $params['currency'],
            'merchant_reference1' => $params['out_trade_no'],
            'order_amount' => (int) ($params['total_amount'] * 100),
            'order_lines' => $this->buildOrderLines($params['items']),
        ];

        if (isset($params['shipping_address'])) {
            $requestData['shipping_address'] = $params['shipping_address'];
        }

        if (isset($params['customer'])) {
            $requestData['customer'] = $params['customer'];
        }

        return $this->post('payments/v1/sessions', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 创建订单并授权
     *
     * @param string $authorizationToken 授权令牌
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function authorize(string $authorizationToken, array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency']);

        $requestData = [
            'purchase_country' => $params['country'] ?? 'DE',
            'purchase_currency' => $params['currency'],
            'merchant_reference1' => $params['out_trade_no'],
            'order_amount' => (int) ($params['total_amount'] * 100),
            'order_lines' => $this->buildOrderLines($params['items'] ?? []),
        ];

        return $this->post("payments/v1/authorizations/{$authorizationToken}/order", $requestData, [
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
        return $this->get("ordermanagement/v1/orders/{$orderId}");
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
        $this->validateRequired($params, ['order_id', 'refund_amount']);

        $requestData = [
            'refunded_amount' => (int) ($params['refund_amount'] * 100),
        ];

        if (isset($params['description'])) {
            $requestData['description'] = $params['description'];
        }

        return $this->post("ordermanagement/v1/orders/{$params['order_id']}/refunds", $requestData, [
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
        return $this->get("ordermanagement/v1/refunds/{$refundId}");
    }

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Klarna 通知包含 event_type 和 order_id
        return isset($data['event_type']) && isset($data['order_id']);
    }

    /**
     * 关闭订单（取消授权）
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        return $this->post("ordermanagement/v1/orders/{$orderId}/cancel", [], [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'klarna';
    }

    /**
     * 构建订单行项目
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    protected function buildOrderLines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = [
                'name' => $item['name'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => (int) (($item['price'] ?? 0) * 100),
                'total_amount' => (int) (($item['price'] ?? 0) * ($item['quantity'] ?? 1) * 100),
            ];
        }

        return $lines;
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
            throw PayException::gatewayError('Klarna 响应格式异常');
        }

        if (isset($data['error_code'])) {
            throw PayException::gatewayError(
                $data['error_messages'] ?? 'Klarna 业务失败',
                $data['error_code'] ?? '',
            );
        }

        return $data;
    }
}
