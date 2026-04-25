<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wise;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Wise 网关
 *
 * 支持 Wise（原 TransferWise）跨境汇款、多币种转账。
 * 覆盖全球 80+ 国家，支持 50+ 种货币。
 */
class WiseGateway extends AbstractGateway
{
    /**
     * 测试环境基础 URL
     */
    protected const string TEST_BASE_URL = 'https://api.sandbox.transferwise.tech/';

    /**
     * 生产环境基础 URL
     */
    protected const string PROD_BASE_URL = 'https://api.transferwise.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key', 'profile_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('wise');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * 创建转账订单
     *
     * @param array<string, mixed> $params 转账参数
     * @return array<string, mixed> 转账响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'source_currency', 'target_currency', 'amount']);

        // 创建报价
        $quote = $this->createQuote(
            $params['source_currency'],
            $params['target_currency'],
            $params['amount'],
        );

        // 创建收款人账户
        $recipient = $this->createRecipient($params['recipient'] ?? []);

        // 创建转账
        $transfer = $this->createTransfer(
            $quote['id'],
            $recipient['id'],
            $params['out_trade_no'],
        );

        return $transfer;
    }

    /**
     * 创建报价
     *
     * @param string $sourceCurrency 源货币
     * @param string $targetCurrency 目标货币
     * @param float $amount 金额
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createQuote(string $sourceCurrency, string $targetCurrency, float $amount): array
    {
        return $this->post('v3/profiles/' . $this->getConfig('profile_id') . '/quotes', [
            'sourceCurrency' => $sourceCurrency,
            'targetCurrency' => $targetCurrency,
            'sourceAmount' => $amount,
        ], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 创建收款人账户
     *
     * @param array<string, mixed> $recipientData 收款人信息
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createRecipient(array $recipientData): array
    {
        return $this->post('v1/accounts', $recipientData, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 创建转账
     *
     * @param int|string $quoteId 报价 ID
     * @param int|string $recipientId 收款人 ID
     * @param string $referenceId 商户参考号
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function createTransfer(int|string $quoteId, int|string $recipientId, string $referenceId): array
    {
        return $this->post('v1/transfers', [
            'targetAccount' => (int) $recipientId,
            'quoteUuid' => $quoteId,
            'customerTransactionId' => $referenceId,
            'details' => [
                'reference' => $referenceId,
            ],
        ], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 查询转账状态
     *
     * @param string $orderId 转账 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        return $this->get("v1/transfers/{$orderId}", [], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 取消转账
     *
     * @param string $orderId 转账 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        return $this->put("v1/transfers/{$orderId}/cancel", [], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 申请退款（Wise 不支持直接退款，返回提示）
     *
     * @param array<string, mixed> $params 退款参数
     * @return array<string, mixed>
     */
    public function refund(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Wise 不支持直接退款，请通过创建反向转账实现退款',
        ];
    }

    /**
     * 查询退款（Wise 不支持）
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     */
    public function queryRefund(string $refundId): array
    {
        return [
            'success' => false,
            'message' => 'Wise 不支持退款查询',
        ];
    }

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Wise Webhook 使用签名验证
        if (!isset($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        $payload = json_encode($data);
        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_key'));

        return hash_equals($expected, $signature);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'wise';
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
            throw PayException::gatewayError('Wise 响应格式异常');
        }

        if (isset($data['errors'])) {
            $error = $data['errors'][0] ?? [];
            throw PayException::gatewayError(
                $error['message'] ?? 'Wise 业务失败',
                $error['code'] ?? '',
            );
        }

        return $data;
    }
}
