<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Revolut;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Contract\QrCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;

/**
 * Revolut 网关
 *
 * 支持 Revolut 商户支付、卡支付、Apple Pay、Google Pay 等。
 * 覆盖欧洲、英国、美国、澳大利亚等市场。
 */
class RevolutGateway extends AbstractGateway implements
    TransferCapableInterface,
    ReconciliationCapableInterface,
    RefundCapableInterface,
    SettlementCapableInterface,
    PersonalReceiveCapableInterface,
    BalanceCapableInterface,
    WebhookCapableInterface,
    QrCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://sandbox-merchant.revolut.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://merchant.revolut.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['api_key', 'merchant_id']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('revolut');
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
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'currency']);

        $requestData = [
            'amount' => (int) ($params['total_amount'] * 100),
            'currency' => $params['currency'],
            'description' => $params['description'] ?? '',
            'merchant_order_ext_ref' => $params['out_trade_no'],
            'capture_mode' => $params['capture_mode'] ?? 'automatic',
        ];

        if (isset($params['customer_email'])) {
            $requestData['customer_email'] = $params['customer_email'];
        }

        if (isset($params['redirect_url'])) {
            $requestData['redirect_url'] = $params['redirect_url'];
        }

        return $this->post('api/orders', $requestData, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
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
        return $this->get("api/orders/{$orderId}", [], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 捕获授权订单
     *
     * @param string $orderId 订单 ID
     * @return array<string, mixed>
     * @throws PayException
     */
    public function captureOrder(string $orderId): array
    {
        return $this->post("api/orders/{$orderId}/capture", [], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
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
        return $this->post("api/orders/{$orderId}/cancel", [], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
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
        $this->validateRequired($params, ['order_id', 'refund_amount']);

        return $this->post("api/orders/{$params['order_id']}/refund", [
            'amount' => (int) ($params['refund_amount'] * 100),
            'description' => $params['description'] ?? '',
        ], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 查询退款（RefundCapableInterface）
     *
     * Revolut 退款会生成一个新的 refund 类型 order，查询即检索该退款订单：
     * GET /api/orders/{refundOrderId}。
     *
     * @param string $outRefundNo 退款订单 ID（退款创建时返回）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRefund(string $outRefundNo): array
    {
        return $this->get("api/orders/{$outRefundNo}", [], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /* ==================== 退款能力（RefundCapableInterface） ==================== */

    /**
     * 申请退款（RefundCapableInterface）
     *
     * 将退款能力接口标准参数（out_refund_no / refund_fee(分) / out_trade_no|transaction_id）
     * 映射到 Revolut 退款请求（order_id / amount / description），复用 {@see refund()}。
     * 金额按分传入，refund() 内部 ×100 转最小货币单位。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function applyRefund(array $params): array
    {
        $orderId = $params['transaction_id'] ?? ($params['out_trade_no'] ?? '');

        return $this->refund([
            'order_id' => $orderId,
            'refund_amount' => ((int) ($params['refund_fee'] ?? 0)) / 100,
            'description' => $params['refund_desc'] ?? '',
        ]);
    }

    /**
     * 取消退款（Revolut 不支持，统一报「无此方法」）
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function cancelRefund(string $outRefundNo): array
    {
        throw PayException::methodNotSupported('revolut', 'cancelRefund');
    }

    /**
     * 验证异步通知
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        // Revolut Webhook 使用签名验证
        if (!isset($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        $payload = json_encode($data) ?: '';
        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_key'));

        return hash_equals($expected, $signature);
    }

    /**
     * 验证 Webhook 原始请求签名（与运行时解耦版本）
     *
     * Revolut 以 `X-Signature` 请求头传递对「原始请求体」的 HMAC-SHA256 签名，
     * 故需对原始报文（而非重新编码的数组）做 HMAC 校验，比 {@see verifyNotify()} 的
     * 重编码方式更贴近真实规范。
     *
     * @param string $payload 原始请求体（JSON）
     * @param array<string, string> $headers 请求头（含 X-Signature）
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        if ($payload === '') {
            return false;
        }

        $signature = $this->webhookHeader($headers, 'X-Signature');
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->getConfig('api_key'));

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
            'gateway' => 'revolut',
            'event_id' => $data['event_id'] ?? $data['id'] ?? null,
            'event_type' => $data['event'] ?? $data['event_type'] ?? 'unknown',
            'data' => $data,
            'raw' => $payload,
        ];
    }

    /* ==================== 转账能力（TransferCapableInterface） ==================== */

    /**
     * 发起单笔转账（Revolut /pay 端点）
     *
     * 对齐 Revolut 真实「转账 / 出款」规范：POST /api/1.0/pay。
     * SDK 转账金额以最小货币单位（分）传入，Revolut /pay 的 amount 为主单位小数，
     * 故在此做 ÷100 换算（适用于 2 位小数币种，如 EUR/GBP/USD）。
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

        $type = $recipient['type'] ?? 'bank';
        $account = (string) $recipient['account'];
        $name = $recipient['name'] ?? '';

        if ($type === 'revolut') {
            $receiver = ['account_id' => $account];
        } elseif ($type === 'card') {
            $receiver = ['card_id' => $account];
        } elseif (isset($recipient['iban'])) {
            $receiver = ['iban' => (string) $recipient['iban'], 'holderName' => $name];
        } else {
            // 银行转账：以 counterparty_id 标识收款方
            $receiver = ['counterparty_id' => $account];
        }

        $sourceAccount = $params['account_id']
            ?? $this->getConfig('account_id', (string) $this->getConfig('merchant_id', ''));

        $requestData = [
            'request_id' => $params['out_biz_no'],
            'account_id' => $sourceAccount,
            'receiver' => $receiver,
            'amount' => (float) ((int) $params['amount']) / 100,
            'currency' => strtoupper((string) ($params['currency'] ?? 'EUR')),
            'reference' => $params['description'] ?? '',
        ];

        return $this->post('api/1.0/pay', $requestData, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 批量转账（Revolut 无原生批量转账，逐笔调用 singleTransfer 聚合）
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
                'out_biz_no' => $item['out_detail_no'] ?? uniqid('revolut_tf_', true),
                'amount' => $item['amount'],
                'currency' => $item['currency'] ?? 'EUR',
                'recipient' => $item['recipient'],
                'description' => $item['remark'] ?? '',
                'account_id' => $params['account_id'] ?? null,
            ]);
        }

        return [
            'out_biz_no' => $params['out_biz_no'],
            'transfers' => $results,
            'count' => count($results),
        ];
    }

    /**
     * 查询转账结果（按 request_id 过滤交易列表）
     *
     * @param string $outBizNo 商户转账单号（即 request_id）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryTransfer(string $outBizNo): array
    {
        return $this->get('api/1.0/transactions', [
            'request_id' => $outBizNo,
        ], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 查询转账电子回单
     *
     * Revolut 不提供电子回单能力，调用即报「无此方法」（与 Stripe 一致）。
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function transferReceipt(string $outBizNo): array
    {
        throw PayException::methodNotSupported('revolut', 'transferReceipt');
    }

    /* ==================== 余额查询能力（BalanceCapableInterface） ==================== */

    /**
     * 查询账户实时余额
     *
     * 对齐 Revolut 真实账户余额规范：GET /api/1.0/accounts 返回账户列表，
     * 每个账户含 `balance`（最小货币单位，整数）与 `currency`。多账户/多币种时取首个
     * `active` 账户作为可用余额，并保留完整账户列表于 `raw`，便于调用方按需聚合。
     *
     * @param array<string, mixed> $params 可选参数（Revolut 余额接口无额外业务参数）
     * @return array<string, mixed> 含 account_id / available_amount（分）/ pending_amount / currency / raw
     * @throws PayException
     */
    #[\Override]
    public function queryBalance(array $params = []): array
    {
        $response = $this->get('api/1.0/accounts', [], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);

        $accounts = $response['accounts'] ?? (is_array($response) ? $response : []);

        $primary = null;
        foreach ($accounts as $account) {
            if (($account['state'] ?? '') === 'active') {
                $primary = $account;
                break;
            }
        }
        $primary = $primary ?? $accounts[0] ?? [];

        return [
            'account_id' => $primary['id'] ?? null,
            'available_amount' => (int) ($primary['balance'] ?? 0),
            'pending_amount' => 0,
            'currency' => $primary['currency'] ?? 'EUR',
            'raw' => $response,
        ];
    }

    /**
     * 查询日终余额
     *
     * Revolut 未提供按日期的「日终余额」接口，`/api/1.0/accounts` 仅返回实时余额，故本方法不支持；
     * 如需历史资金快照请结合 `downloadBill`（交易列表）对账。
     *
     * @param string $date 对账日期，格式 YYYY-MM-DD
     * @param array<string, mixed> $params 可选参数
     * @throws PayException
     */
    #[\Override]
    public function queryDayEndBalance(string $date, array $params = []): array
    {
        throw PayException::methodNotSupported('revolut', 'queryDayEndBalance');
    }

    /* ==================== 对账能力（ReconciliationCapableInterface） ==================== */

    /**
     * 下载交易对账单（Revolut 交易列表）
     *
     * 对齐 Revolut 真实对账规范：GET /api/1.0/transactions（按日期范围拉取交易，
     * 作为交易级对账数据源）。解析为 records。
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填，格式 YYYYMMDD）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $date = $this->formatBillDate((string) $params['bill_date']);

        $response = $this->get('api/1.0/transactions', [
            'from' => $date . 'T00:00:00.000Z',
            'to' => $date . 'T23:59:59.999Z',
        ], [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);

        return [
            'bill_date' => $params['bill_date'],
            'bill_type' => 'transactions',
            'raw_data' => $response,
            'records' => $this->parseBill(json_encode($response) ?: ''),
        ];
    }

    /**
     * 下载资金账单
     *
     * Revolut 对账数据源即为交易列表，无独立的「资金账单」报表，调用报「无此方法」
     * （与 Stripe 一致）。交易级对账请使用 {@see downloadBill()}。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadFundFlow(array $params): array
    {
        throw PayException::methodNotSupported('revolut', 'downloadFundFlow');
    }

    /**
     * 解析对账单原始数据（Revolut JSON 交易列表）
     *
     * @param string $rawData 原始对账单 JSON
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function parseBill(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $data = json_decode($rawData, true);
        if (!is_array($data)) {
            return [];
        }

        $list = $data['data'] ?? $data['transactions'] ?? $data;
        if (!is_array($list)) {
            return [];
        }

        $records = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $records[] = [
                'id' => $item['id'] ?? '',
                'amount' => $item['amount'] ?? 0,
                'currency' => $item['currency'] ?? '',
                'type' => $item['type'] ?? '',
                'state' => $item['state'] ?? '',
                'created_at' => $item['created_at'] ?? '',
                'reference' => $item['reference'] ?? '',
                'request_id' => $item['request_id'] ?? '',
            ];
        }

        return $records;
    }

    /**
     * 将 YYYYMMDD 格式的对账日期转为 Revolut 交易查询所需的 YYYY-MM-DD
     *
     * @param string $billDate
     * @return string
     */
    private function formatBillDate(string $billDate): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Ymd', $billDate);
        return $dt !== false ? $dt->format('Y-m-d') : $billDate;
    }

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * 结算到外部账户（Revolut 出款 / Payout）
     *
     * 对齐 Revolut `/api/1.0/pay`：从平台账户（`account_id`/`merchant_id`）出款到收款人银行账户（iban）。
     * 金额单位为分，网关内部 `÷100` 转为主单位小数（与 `createOrder` 的 `×100` 方向相反）。
     *
     * @param array<string, mixed> $params 结算参数（out_biz_no / amount / account / real_name 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        // 复用单笔转账逻辑：type=bank（外部银行出款 → receiver{counterparty_id}）
        return $this->singleTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'EUR',
            'recipient' => [
                'type' => 'bank',
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? 'Auto settlement',
        ]);
    }

    /**
     * 结算到银行卡（Revolut 卡出款）
     *
     * 对齐 Revolut `/api/1.0/pay`：出款到收款人卡（`receiver.card_id`）。金额单位同出款（分 → ÷100）。
     *
     * @param array<string, mixed> $params 结算参数（out_biz_no / amount / bank_card_no / real_name 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToBankCard(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'bank_card_no']);

        return $this->singleTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'EUR',
            'recipient' => [
                'type' => 'card',
                'account' => $params['bank_card_no'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? 'Auto settlement',
        ]);
    }

    /**
     * 结算到平台内钱包余额（Revolut 内部账户出款）
     *
     * 对齐 Revolut `/api/1.0/pay`：出款到收款人 Revolut 内部账户（`receiver.account_id`）。
     *
     * @param array<string, mixed> $params 结算参数（out_biz_no / amount / account 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToWallet(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        return $this->singleTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'EUR',
            'recipient' => [
                'type' => 'revolut',
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? 'Auto settlement',
        ]);
    }

    /**
     * 查询结算结果（按 request_id 过滤交易列表）
     *
     * @param string $outBizNo 商户结算单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function querySettlement(string $outBizNo): array
    {
        return $this->queryTransfer($outBizNo);
    }

    /* ==================== 个人收款能力（PersonalReceiveCapableInterface） ==================== */

    /**
     * 生成个人收款链接（Revolut Merchant Order 的 checkout_url）
     *
     * Revolut 不返回二维码图片，返回的 `qr_code`（收款链接）可由调用方生成二维码。
     * 金额单位为最小货币单位（分），与 Revolut Orders 原生一致，不做换算。
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $outTradeNo = (string) ($params['out_trade_no'] ?? 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999));
        $currency = strtoupper((string) ($params['currency'] ?? $this->getConfig('currency', 'EUR')));

        $requestData = [
            'amount' => (int) $params['amount'],
            'currency' => $currency,
            'merchant_order_ext_ref' => $outTradeNo,
            'description' => (string) $params['description'],
            'capture_mode' => $params['capture_mode'] ?? 'AUTOMATIC',
        ];

        if (isset($params['return_url'])) {
            $requestData['merchant_order_data'] = ['ref' => $params['return_url']];
        }

        $response = $this->post('api/1.0/orders', $requestData, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);

        $checkoutUrl = $response['checkout_url'] ?? '';

        return [
            'out_trade_no' => $outTradeNo,
            'order_id' => $response['id'] ?? '',
            'qr_code' => $checkoutUrl,
            'payment_link' => $checkoutUrl,
            'amount' => (int) $params['amount'],
            'currency' => $currency,
            'description' => (string) $params['description'],
        ];
    }

    /**
     * 查询个人收款记录（Merchant Orders 列表）
     *
     * @param array<string, mixed> $params 查询参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRecords(array $params): array
    {
        $startTime = strtotime((string) ($params['start_time'] ?? '-30 days'));
        $endTime = strtotime((string) ($params['end_time'] ?? 'now'));

        if ($startTime === false || $endTime === false) {
            throw PayException::paramError('start_time / end_time 时间格式无法解析');
        }

        $query = [
            'from_created_date' => gmdate('Y-m-d\TH:i:s.v\Z', $startTime),
            'to_created_date' => gmdate('Y-m-d\TH:i:s.v\Z', $endTime),
            'limit' => (int) ($params['limit'] ?? 100),
        ];

        if (isset($params['email'])) {
            $query['email'] = $params['email'];
        }

        return $this->get('api/1.0/orders', $query, [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
        ]);
    }

    /**
     * 提现到银行账户（复用 Revolut 出款 /api/1.0/pay）
     *
     * @param array<string, mixed> $params 提现参数（out_biz_no / amount / account 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        return $this->singleTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? $this->getConfig('currency', 'EUR'),
            'recipient' => [
                'type' => $params['recipient_type'] ?? 'bank',
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
                'iban' => $params['iban'] ?? null,
            ],
            'description' => $params['description'] ?? 'Personal withdraw',
            'account_id' => $params['account_id'] ?? null,
        ]);
    }

    /**
     * 查询提现结果（按 request_id 过滤交易列表）
     *
     * @param string $outBizNo 商户提现单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryWithdraw(string $outBizNo): array
    {
        return $this->queryTransfer($outBizNo);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'revolut';
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
            throw PayException::gatewayError('Revolut 响应格式异常');
        }

        if (isset($data['message'])) {
            throw PayException::gatewayError(
                $data['message'],
                $data['code'] ?? '',
            );
        }

        return $data;
    }
}
