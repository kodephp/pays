<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Payoneer;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Payoneer 网关
 *
 * 支持 Payoneer 跨境支付、批量付款、收款等。
 * 覆盖全球 200+ 国家和地区。
 */
class PayoneerGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://api.sandbox.payoneer.com/v4/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://api.payoneer.com/v4/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key', 'api_secret', 'program_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('payoneer');
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
        $this->validateRequired($params, ['out_trade_no', 'amount', 'currency', 'payee_id']);

        $requestData = [
            'program_id' => $this->getConfig('program_id'),
            'payment_id' => $params['out_trade_no'],
            'payee_id' => $params['payee_id'],
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'description' => $params['description'] ?? '',
        ];

        if (isset($params['payment_date'])) {
            $requestData['payment_date'] = $params['payment_date'];
        }

        return $this->post('payments', $requestData, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->getConfig('api_key') . ':' . $this->getConfig('api_secret')),
        ]);
    }

    /**
     * 查询订单状态
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        return $this->get("payments/{$orderId}", [], [
            'Authorization' => 'Basic ' . base64_encode($this->getConfig('api_key') . ':' . $this->getConfig('api_secret')),
        ]);
    }

    /**
     * 取消订单
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        return $this->delete("payments/{$orderId}", [], [
            'Authorization' => 'Basic ' . base64_encode($this->getConfig('api_key') . ':' . $this->getConfig('api_secret')),
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
        $this->validateRequired($params, ['payment_id', 'amount']);

        return $this->post("payments/{$params['payment_id']}/cancel", [
            'amount' => $params['amount'],
            'reason' => $params['reason'] ?? '',
        ], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->getConfig('api_key') . ':' . $this->getConfig('api_secret')),
        ]);
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
        return $this->get("payments/{$refundId}", [], [
            'Authorization' => 'Basic ' . base64_encode($this->getConfig('api_key') . ':' . $this->getConfig('api_secret')),
        ]);
    }

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Payoneer Webhook 使用 HMAC 签名验证
        if (!isset($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        $payload = json_encode($data);
        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_secret'));

        return hash_equals($expected, $signature);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'payoneer';
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
            throw PayException::gatewayError('Payoneer 响应格式异常');
        }

        if (isset($data['error'])) {
            throw PayException::gatewayError(
                $data['error']['description'] ?? 'Payoneer 业务失败',
                $data['error']['code'] ?? '',
            );
        }

        return $data;
    }
}
