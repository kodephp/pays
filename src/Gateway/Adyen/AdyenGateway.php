<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Adyen;

use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;

/**
 * Adyen 网关
 *
 * 支持 Adyen Payments API，覆盖全球 200+ 个国家/地区，支持 250+ 种支付方式。
 * 提供统一的全球支付、本地支付、订阅支付能力。
 */
class AdyenGateway extends AbstractGateway implements TransferCapableInterface, ReconciliationCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://pal-test.adyen.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://pal-live.adyen.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key', 'merchant_account']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $env = $this->getConfig('environment', 'test');

        return $env === 'live' ? self::PROD_BASE_URL : self::TEST_BASE_URL;
    }

    /**
     * 创建支付会话（Sessions API，推荐）
     *
     * @param array<string, mixed> $params 会话参数
     * @return array<string, mixed> 会话响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['amount', 'currency', 'reference']);

        $requestData = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'amount' => [
                'value' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'reference' => $params['reference'],
            'returnUrl' => $params['return_url'] ?? '',
        ];

        if (isset($params['country_code'])) {
            $requestData['countryCode'] = $params['country_code'];
        }

        if (isset($params['shopper_email'])) {
            $requestData['shopperEmail'] = $params['shopper_email'];
        }

        if (isset($params['shopper_reference'])) {
            $requestData['shopperReference'] = $params['shopper_reference'];
        }

        if (isset($params['line_items'])) {
            $requestData['lineItems'] = $params['line_items'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('checkout/v70/sessions', $requestData, $headers);
    }

    /**
     * 查询订单（Payment Details）
     *
     * @param string $orderId 支付会话 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post('checkout/v70/payments/details', [
            'paymentData' => $orderId,
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
        $this->validateRequired($params, ['original_reference', 'amount', 'currency']);

        $requestData = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'originalReference' => $params['original_reference'],
            'amount' => [
                'value' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'reference' => $params['reference'] ?? uniqid('adyen_refund_', true),
        ];

        $headers = $this->buildAuthHeaders();

        return $this->post('pal/servlet/Payment/v68/refund', $requestData, $headers);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款 PSP 参考号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post('pal/servlet/Payment/v68/refundWithData', [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'originalReference' => $refundId,
        ], $headers);
    }

    /**
     * 验证异步通知签名（Webhook）
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Adyen 通知签名：对通知内容做 HMAC-SHA256，使用独立的 HMAC 密钥（非 api_key）
        $hmacKey = $this->getConfig('hmac_key', '');
        $sig = $data['hmacSignature'] ?? '';
        $payload = $data['payload'] ?? '';
        if ($hmacKey === '' || $sig === '' || $payload === '') {
            return false;
        }

        // Adyen HMAC key 为十六进制字符串，需先做 hex-to-binary 转换得到原始字节密钥
        $keyBytes = pack('H*', $hmacKey);
        $expected = hash_hmac('sha256', $payload, $keyBytes);

        return hash_equals($expected, strtolower($sig));
    }

    /**
     * 关闭订单（取消支付）
     *
     * @param string $orderId 支付 PSP 参考号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $headers = $this->buildAuthHeaders();

        return $this->post('pal/servlet/Payment/v68/cancel', [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'originalReference' => $orderId,
        ], $headers);
    }

    /**
     * 创建支付请求（Payments API，直接支付）
     *
     * @param array<string, mixed> $params 支付参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createPayment(array $params): array
    {
        $this->validateRequired($params, ['amount', 'currency', 'reference', 'payment_method']);

        $requestData = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'amount' => [
                'value' => $params['amount'],
                'currency' => $params['currency'],
            ],
            'reference' => $params['reference'],
            'paymentMethod' => $params['payment_method'],
            'returnUrl' => $params['return_url'] ?? '',
        ];

        if (isset($params['shopper_interaction'])) {
            $requestData['shopperInteraction'] = $params['shopper_interaction'];
        }

        if (isset($params['recurring'])) {
            $requestData['recurring'] = $params['recurring'];
        }

        $headers = $this->buildAuthHeaders();

        return $this->post('checkout/v70/payments', $requestData, $headers);
    }

    /* ==================== 转账能力（TransferCapableInterface） ==================== */

    /**
     * 发起单笔转账（Adyen Transfers API）
     *
     * 对齐 Adyen 真实「转账 / 出款」规范：POST /pal/servlet/Transfer/v68/transfer。
     * amount.value 以最小货币单位（分）传递，与 Adyen 金额规范一致。
     *
     * @param array<string, mixed> $params 转账参数（out_biz_no / amount / recipient 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function singleTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['account']);

        $category = ($recipient['type'] ?? '') === 'card' ? 'card' : 'bank';
        $counterparty = [];
        if ($category === 'card') {
            $counterparty['cardAccount'] = [
                'number' => (string) $recipient['account'],
                'holderName' => $recipient['name'] ?? '',
            ];
        } else {
            $counterparty['bankAccount'] = [
                'iban' => (string) $recipient['account'],
                'holderName' => $recipient['name'] ?? '',
            ];
        }

        $requestData = [
            'amount' => [
                'currency' => strtoupper((string) ($params['currency'] ?? 'EUR')),
                'value' => (int) $params['amount'],
            ],
            'reference' => $params['out_biz_no'],
            'category' => $category,
            'counterparty' => $counterparty,
            'description' => $params['description'] ?? '',
        ];

        $balanceAccountId = $params['balance_account_id'] ?? $this->getConfig('balance_account_id', '');
        if ($balanceAccountId !== '') {
            $requestData['balanceAccount'] = $balanceAccountId;
        }

        return $this->post('pal/servlet/Transfer/v68/transfer', $requestData, $this->buildAuthHeaders());
    }

    /**
     * 批量转账（Adyen 无原生批量转账，逐笔调用 singleTransfer 聚合）
     *
     * @param array<string, mixed> $params 批量转账参数（out_biz_no / transfer_detail_list 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function batchTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        $list = $params['transfer_detail_list'];
        if (!is_array($list) || empty($list)) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        $results = [];
        foreach ($list as $item) {
            $results[] = $this->singleTransfer([
                'out_biz_no' => $item['out_detail_no'] ?? uniqid('adyen_tf_', true),
                'amount' => $item['amount'],
                'currency' => $item['currency'] ?? 'EUR',
                'recipient' => $item['recipient'],
                'description' => $item['remark'] ?? '',
                'balance_account_id' => $params['balance_account_id'] ?? null,
            ]);
        }

        return [
            'out_biz_no' => $params['out_biz_no'],
            'transfers' => $results,
            'count' => count($results),
        ];
    }

    /**
     * 查询转账结果（按商户单号 reference 过滤）
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryTransfer(string $outBizNo): array
    {
        return $this->get('pal/servlet/Transfer/v68/transfer', [
            'reference' => $outBizNo,
        ], $this->buildAuthHeaders());
    }

    /**
     * 查询转账电子回单
     *
     * Adyen 不提供电子回单能力，调用即报「无此方法」（与 Stripe 一致）。
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function transferReceipt(string $outBizNo): array
    {
        throw PayException::methodNotSupported('adyen', 'transferReceipt');
    }

    /* ==================== 对账能力（ReconciliationCapableInterface） ==================== */

    /**
     * 下载交易对账单（Adyen Settlement details report）
     *
     * 对齐 Adyen 真实「报告 API」：POST /pal/servlet/Reports/v68/getReport 生成报表，
     * 取响应 url 后下载 CSV，解析为 records。
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填，格式 YYYYMMDD）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadBill(array $params): array
    {
        return $this->downloadAdyenReport($params, 'Settlement detail report', 'settlement_detail_report');
    }

    /**
     * 下载资金账单（Adyen Payment accounting report）
     *
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadFundFlow(array $params): array
    {
        return $this->downloadAdyenReport($params, 'Payment accounting report', 'payment_accounting_report');
    }

    /**
     * 生成并下载 Adyen 报表（两步：生成报表取 url → 下载 CSV）
     *
     * @param array<string, mixed> $params 对账参数
     * @param string $reportType Adyen 报表类型
     * @param string $billType 归一化账单类型
     * @return array<string, mixed>
     * @throws PayException
     */
    private function downloadAdyenReport(array $params, string $reportType, string $billType): array
    {
        $this->validateRequired($params, ['bill_date']);

        $requestData = [
            'companyDetails' => [
                'merchantAccount' => $this->getConfig('merchant_account'),
            ],
            'reportType' => $reportType,
            'date' => $this->formatReportDate((string) $params['bill_date']),
        ];

        $response = $this->post('pal/servlet/Reports/v68/getReport', $requestData, $this->buildAuthHeaders());
        $reportUrl = (string) ($response['url'] ?? '');
        if ($reportUrl === '') {
            throw PayException::gatewayError('Adyen 对账单生成失败：未返回下载地址');
        }

        // 报表内容为 CSV，使用原始 HTTP 客户端下载（绕过 JSON 解析）
        $csv = $this->httpClient->get($reportUrl, [], $this->buildAuthHeaders());

        return [
            'bill_date' => $params['bill_date'],
            'bill_type' => $billType,
            'raw_data' => $csv,
            'records' => $this->parseBill($csv),
        ];
    }

    /**
     * 将 YYYYMMDD 格式对账日期转为 Adyen 报表所需的 YYYY-MM-DD
     *
     * @param string $billDate
     * @return string
     */
    private function formatReportDate(string $billDate): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Ymd', $billDate);
        return $dt !== false ? $dt->format('Y-m-d') : $billDate;
    }

    /**
     * 解析对账单原始数据（CSV 文本，首行为表头）
     *
     * @param string $rawData 原始对账单 CSV 文本
     * @return array<int, array<int|string, string>>
     */
    #[\Override]
    public function parseBill(string $rawData): array
    {
        return $this->parseCsvBill($rawData);
    }

    /**
     * 解析对账单 CSV 文本为记录列表
     *
     * @param string $rawData 原始对账单 CSV 文本
     * @return array<int, array<int|string, string>>
     */
    private function parseCsvBill(string $rawData): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawData));
        if ($lines === false || count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        if ($headerLine === null) {
            return [];
        }
        /** @var array<int, string> $header */
        $header = str_getcsv($headerLine);

        $records = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            /** @var array<int, string> $columns */
            $columns = str_getcsv($line);
            if (count($columns) !== count($header)) {
                continue;
            }

            /** @var array<int|string, string> $record */
            $record = array_combine($header, $columns);
            $records[] = $record;
        }

        return $records;
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'adyen';
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
            throw PayException::gatewayError('Adyen 响应格式异常');
        }

        // Adyen 错误响应：status 为 HTTP 整数状态码（如 401/422/500）。
        // 注意：成功响应 status 为字符串枚举（如 "received"），不可与整数比较，
        // 否则在 PHP 8.x 下非数字字符串 >= 400 会误判为错误（'received' >= 400 === true）。
        if (isset($data['status']) && is_int($data['status']) && $data['status'] >= 400) {
            throw PayException::gatewayError(
                $data['message'] ?? 'Adyen 业务失败',
                (string) ($data['errorCode'] ?? $data['status']),
            );
        }

        // Adyen 支付拒绝响应
        if (isset($data['resultCode']) && in_array($data['resultCode'], ['Refused', 'Error'], true)) {
            throw PayException::gatewayError(
                $data['refusalReason'] ?? 'Adyen 支付被拒绝',
                $data['resultCode'],
            );
        }

        return $data;
    }

    /**
     * 构建认证请求头
     *
     * @return array<string, string>
     */
    protected function buildAuthHeaders(): array
    {
        return [
            'X-API-Key' => $this->getConfig('api_key'),
            'Content-Type' => 'application/json',
        ];
    }
}
