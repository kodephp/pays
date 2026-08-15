<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Payoneer;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Payoneer 网关
 *
 * 支持 Payoneer 跨境支付、批量付款、收款等。
 * 覆盖全球 200+ 国家和地区。
 */
class PayoneerGateway extends AbstractGateway implements BalanceCapableInterface, WebhookCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://api.sandbox.payoneer.com/v4/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api.payoneer.com/v4/';

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

        $payload = json_encode($data) ?: '';
        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_secret'));

        return hash_equals($expected, $signature);
    }

    /**
     * 验证 Webhook 原始请求签名（与运行时解耦版本）
     *
     * Payoneer 以 `X-Payoneer-Signature` 请求头传递对「原始请求体」的 HMAC-SHA256 签名，
     * 故需对原始报文（而非重新编码的数组）做 HMAC 校验，比 {@see verifyNotify()} 的
     * 重编码方式更贴近真实规范。
     *
     * @param string $payload 原始请求体（JSON）
     * @param array<string, string> $headers 请求头（含 X-Payoneer-Signature）
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        if ($payload === '') {
            return false;
        }

        $signature = $this->webhookHeader($headers, 'X-Payoneer-Signature');
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_secret'));

        return hash_equals($expected, $signature);
    }

    /**
     * 解析 Webhook 原始请求体为统一事件结构
     *
     * @param string $payload 原始请求体（JSON）
     * @return array<string, mixed>
     */
    public function parseWebhook(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];

        return [
            'gateway' => 'payoneer',
            'event_id' => $data['event_id'] ?? $data['id'] ?? null,
            'event_type' => $data['event_type'] ?? 'unknown',
            'data' => $data,
            'raw' => $payload,
        ];
    }

    /* ==================== 余额查询能力（BalanceCapableInterface） ==================== */

    /**
     * 查询项目账户实时余额
     *
     * 对齐 Payoneer 真实余额规范：项目（Program）余额接口为
     * `GET /v2/programs/{programId}/balance`，使用 Basic(api_key:api_secret) 认证。
     * 注意：网关基础域为 `/v4/`（与付款/退款接口一致），而项目余额端点位于 `/v2/programs/` 下，
     * 故此处直接拼接完整 URL（不依赖 getBaseUrl 前缀）。
     *
     * Payoneer 余额响应可能以多种形态返回（`balance` 数值 / `available_balance` 字符串 /
     * 嵌套 `balance` 对象），本方法做健壮提取，并将金额换算为最小货币单位（分）。
     *
     * @param array<string, mixed> $params 可选参数（Payoneer 项目余额接口无需额外业务参数，program_id 取自配置）
     * @return array<string, mixed> 含 balance_id / available_amount（分）/ pending_amount / currency / raw
     * @throws PayException
     */
    #[\Override]
    public function queryBalance(array $params = []): array
    {
        $url = str_replace(
            '/v4/',
            '/v2/programs/' . $this->getConfig('program_id') . '/',
            $this->getBaseUrl(),
        ) . 'balance';

        $headers = [
            'Authorization' => 'Basic ' . base64_encode(
                $this->getConfig('api_key') . ':' . $this->getConfig('api_secret'),
            ),
        ];

        // 直接走原生 HTTP 通道（端点不在 getBaseUrl 前缀下），复用 parseResponse 做错误校验
        $data = $this->parseResponse($this->httpClient->get($url, [], $headers));

        $balanceNode = is_array($data['balance'] ?? null) ? $data['balance'] : $data;
        $amount = $balanceNode['amount'] ?? $balanceNode['available_balance'] ?? $balanceNode['balance'] ?? 0;
        $currency = $balanceNode['currency'] ?? 'USD';

        return [
            'balance_id' => $balanceNode['id'] ?? null,
            'available_amount' => (int) round((float) $amount * 100),
            'pending_amount' => 0,
            'currency' => $currency,
            'raw' => $data,
        ];
    }

    /**
     * 查询日终余额
     *
     * Payoneer 未提供按日期的「日终余额」接口，项目余额 `/balance` 仅返回实时余额，故本方法不支持；
     * 如需历史资金快照请结合 `downloadBill` 对账。
     *
     * @param string $date 对账日期，格式 YYYY-MM-DD
     * @param array<string, mixed> $params 可选参数
     * @throws PayException
     */
    #[\Override]
    public function queryDayEndBalance(string $date, array $params = []): array
    {
        throw PayException::methodNotSupported('payoneer', 'queryDayEndBalance');
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
        $data = $this->decodeJson($response);

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
