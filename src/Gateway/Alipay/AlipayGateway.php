<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Alipay;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Signer;

/**
 * 支付宝网关
 *
 * 支持电脑网站、手机网站、App、小程序、当面付等支付场景
 */
class AlipayGateway extends AbstractGateway implements
    TransferCapableInterface,
    RedPacketCapableInterface,
    PersonalReceiveCapableInterface,
    ReconciliationCapableInterface,
    RefundCapableInterface,
    ProfitSharingCapableInterface,
    SettlementCapableInterface,
    SubscriptionCapableInterface,
    BalanceCapableInterface
{
    /**
     * 沙箱环境基础 URL
     */
    protected const SANDBOX_BASE_URL = 'https://openapi.alipaydev.com/gateway.do';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://openapi.alipay.com/gateway.do';

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
     * 单笔转账到支付宝账户
     *
     * 复用网关 {@see buildRequestParams()} 进行标准签名，金额单位为分。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function singleTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['type', 'account']);

        $bizContent = [
            'out_biz_no' => $params['out_biz_no'],
            'trans_amount' => number_format($params['amount'] / 100, 2),
            'product_code' => 'TRANS_ACCOUNT_NO_PWD',
            'biz_scene' => 'DIRECT_TRANSFER',
            'order_title' => $params['description'] ?? '转账',
            'payee_info' => [
                'identity_type' => $recipient['type'],
                'identity' => $recipient['account'],
                'name' => $recipient['name'] ?? '',
            ],
            'remark' => $params['description'] ?? '',
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.uni.transfer', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 批量转账到支付宝账户
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function batchTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        $list = $params['transfer_detail_list'];
        if (!is_array($list) || empty($list)) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        $detailList = array_map(static function (array $item): array {
            $recipient = $item['recipient'];
            return [
                'out_biz_no' => $item['out_detail_no'],
                'trans_amount' => number_format($item['amount'] / 100, 2),
                'product_code' => 'TRANS_ACCOUNT_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'order_title' => $item['remark'] ?? '转账',
                'payee_info' => [
                    'identity_type' => $recipient['type'] ?? 'ALIPAY_USER_ID',
                    'identity' => $recipient['account'],
                    'name' => $recipient['name'] ?? '',
                ],
            ];
        }, $list);

        $bizContent = [
            'out_biz_no' => $params['out_biz_no'],
            'product_code' => 'TRANS_ACCOUNT_NO_PWD',
            'biz_scene' => 'DIRECT_TRANSFER',
            'total_trans_amount' => number_format(array_sum(array_column($list, 'amount')) / 100, 2),
            'total_count' => count($list),
            'order_detail' => $detailList,
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.batch.create', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 查询转账结果
     *
     * @return array<string, mixed>
     */
    public function queryTransfer(string $outBizNo): array
    {
        $bizContent = [
            'product_code' => 'TRANS_ACCOUNT_NO_PWD',
            'biz_scene' => 'DIRECT_TRANSFER',
            'out_biz_no' => $outBizNo,
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.common.query', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 查询转账电子回单
     *
     * 调用 `alipay.fund.trans.invoice.query` 查询回单状态；若响应含可下载文件地址
     * （如 `invoice_url` / `file_url` / `download_url`），则下载并解压返回 `file_content`，
     * 否则仅返回查询元数据（file_content=null，调用方应稍后轮询）。
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed> 含回单查询元数据与（就绪时的）file_content
     * @throws PayException
     */
    public function transferReceipt(string $outBizNo): array
    {
        $bizContent = ['out_biz_no' => $outBizNo];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.invoice.query', $bizContent);
        $response = $this->post('', $requestParams);

        $result = $response + ['file_content' => null];

        foreach (['invoice_url', 'file_url', 'download_url'] as $urlKey) {
            if (isset($response[$urlKey]) && is_string($response[$urlKey]) && $response[$urlKey] !== '') {
                $raw = $this->downloadBillFile($response[$urlKey]);
                $result['file_content'] = $this->extractAlipayEreceiptFile($raw);
                break;
            }
        }

        return $result;
    }

    /**
     * 发放普通现金红包
     *
     * 复用网关 {@see buildRequestParams()} 进行标准签名，金额单位为分。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function sendRedPacket(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'wishing', 'act_name', 'remark']);

        $bizContent = [
            'out_order_no' => $params['mch_billno'],
            'out_request_no' => $params['mch_billno'],
            'order_title' => $params['act_name'],
            'amount' => number_format($params['total_amount'] / 100, 2),
            'payer_user_id' => $this->getConfig('app_id'),
            'payee_user_id' => $params['re_openid'],
            'remark' => $params['remark'],
            'business_params' => json_encode(['sub_biz_scene' => 'CUSTOMIZED'], JSON_UNESCAPED_UNICODE),
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.coupon.order.app.pay', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 发放群红包（裂变红包）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function groupRedPacket(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'total_num', 'wishing', 'act_name', 'remark']);

        if ((int) $params['total_num'] < 3) {
            throw PayException::paramError('裂变红包 total_num 必须 >= 3');
        }

        $bizContent = [
            'out_order_no' => $params['mch_billno'],
            'out_request_no' => $params['mch_billno'],
            'order_title' => $params['act_name'],
            'amount' => number_format($params['total_amount'] / 100, 2),
            'payer_user_id' => $this->getConfig('app_id'),
            'payee_user_id' => $params['re_openid'],
            'remark' => $params['remark'],
            'business_params' => json_encode([
                'sub_biz_scene' => 'GROUP_RED_PACKET',
                'total_num' => (int) $params['total_num'],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.coupon.order.app.pay', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 查询红包发放记录
     *
     * @return array<string, mixed>
     */
    public function queryRedPacket(string $mchBillNo): array
    {
        $bizContent = [
            'out_order_no' => $mchBillNo,
            'out_request_no' => $mchBillNo,
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.coupon.order.query', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 生成个人收款码（当面付扫码）
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $outTradeNo = 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999);

        $bizContent = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => number_format($params['amount'] / 100, 2),
            'subject' => $params['description'],
            'body' => !empty($params['attach']) ? json_encode($params['attach'], JSON_UNESCAPED_UNICODE) : '',
            'timeout_express' => isset($params['expire_seconds']) ? ($params['expire_seconds'] . 's') : '30m',
        ];

        $requestParams = $this->buildRequestParams('alipay.trade.precreate', $bizContent);

        $response = $this->post('', $requestParams);

        return [
            'out_trade_no' => $outTradeNo,
            'qr_code' => $response['qr_code'] ?? '',
            'amount' => $params['amount'],
            'description' => $params['description'],
        ];
    }

    /**
     * 查询个人收款记录
     *
     * @param array<string, mixed> $params 查询参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRecords(array $params): array
    {
        $bizContent = [
            'start_time' => $params['start_time'] ?? '',
            'end_time' => $params['end_time'] ?? '',
        ];

        $requestParams = $this->buildRequestParams('alipay.trade.query', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 提现到银行卡（转账到银行卡）
     *
     * @param array<string, mixed> $params 提现参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['amount', 'bank_card_no', 'real_name', 'out_biz_no']);

        $bizContent = [
            'out_biz_no' => $params['out_biz_no'],
            'trans_amount' => number_format($params['amount'] / 100, 2),
            'product_code' => 'TRANS_BANKCARD_NO_PWD',
            'biz_scene' => 'DIRECT_TRANSFER',
            'order_title' => '个人提现',
            'payee_info' => [
                'identity_type' => 'BANKCARD_ACCOUNT',
                'identity' => $params['bank_card_no'],
                'name' => $params['real_name'],
                'bank_code' => $params['bank_code'] ?? '',
            ],
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.uni.transfer', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 查询提现结果
     *
     * @param string $outBizNo 商户提现单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryWithdraw(string $outBizNo): array
    {
        $bizContent = [
            'product_code' => 'TRANS_BANKCARD_NO_PWD',
            'biz_scene' => 'DIRECT_TRANSFER',
            'out_biz_no' => $outBizNo,
        ];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.common.query', $bizContent);

        return $this->post('', $requestParams);
    }

    /* ==================== 退款能力（RefundCapableInterface） ==================== */

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */

    public function applyRefund(array $params): array
    {
        $bizContent = [
            'out_request_no' => $params['out_refund_no'],
            'refund_amount' => number_format($params['refund_fee'] / 100, 2),
        ];

        if (!empty($params['out_trade_no'])) {
            $bizContent['out_trade_no'] = $params['out_trade_no'];
        } else {
            $bizContent['trade_no'] = $params['transaction_id'];
        }

        if (!empty($params['refund_desc'])) {
            $bizContent['refund_reason'] = $params['refund_desc'];
        }

        $requestParams = $this->buildRequestParams('alipay.trade.refund', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 查询退款结果
     *
     * @return array<string, mixed>
     * @throws PayException
     */

    public function queryRefund(string $outRefundNo): array
    {
        $requestParams = $this->buildRequestParams('alipay.trade.fastpay.refund.query', [
            'out_request_no' => $outRefundNo,
        ]);

        return $this->post('', $requestParams);
    }

    /**
     * 取消退款（支付宝不支持，统一报「无此方法」）
     *
     * @throws PayException
     */

    public function cancelRefund(string $outRefundNo): array
    {
        throw PayException::methodNotSupported('alipay', 'cancelRefund');
    }

    /**
     * 下载支付宝交易对账单（下载并解析）
     *
     * 流程：先申请对账单下载地址（alipay.data.dataservice.bill.downloadurl.query），
     * 再下载文件并按需解压（ZIP 包取首个明细 CSV）后解析为交易记录列表。
     * 下载文件无需签名，直接 GET；返回结构含 bill_download_url / file_content / records。
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed> 含下载地址、原始响应、记录列表
     * @throws PayException
     */
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $bizContent = [
            'bill_type' => $params['bill_type'] ?? 'trade',
            'bill_date' => $params['bill_date'],
        ];

        $requestParams = $this->buildRequestParams('alipay.data.dataservice.bill.downloadurl.query', $bizContent);
        $response = $this->post('', $requestParams);

        $downloadUrl = $response['bill_download_url'] ?? '';

        $result = [
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'trade',
            'bill_download_url' => $downloadUrl,
            'raw_data' => $response,
            'records' => [],
        ];

        if (!is_string($downloadUrl) || $downloadUrl === '') {
            return $result;
        }

        $raw = $this->downloadBillFile($downloadUrl);
        $csv = $this->extractAlipayBillCsv($raw);
        $result['file_content'] = $csv;
        $result['records'] = $this->parseBill($csv);

        return $result;
    }

    /**
     * 下载对账单文件（无签名，直接 GET）
     *
     * @throws PayException
     */
    protected function downloadBillFile(string $downloadUrl): string
    {
        try {
            return $this->httpClient->get($downloadUrl);
        } catch (\Throwable $e) {
            throw PayException::networkError('支付宝对账单下载失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 从下载内容提取 CSV：ZIP 压缩包取首个明细 CSV，否则原样返回
     *
     * 支付宝对账单文件可能是单个 CSV，也可能是含多个 CSV 的 ZIP；ZIP 场景下
     * 取第一个 .csv 条目（业务明细）进行解析。
     *
     * @throws PayException
     */
    protected function extractAlipayBillCsv(string $raw): string
    {
        if (substr($raw, 0, 2) === "PK") {
            if (!class_exists('ZipArchive')) {
                throw PayException::configError('支付宝对账单为 ZIP 压缩包，需启用 PHP ZipArchive 扩展才能解析');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'alipay_bill_');
            if ($tmp === false) {
                throw PayException::gatewayError('无法创建临时文件用于解析对账单 ZIP');
            }

            try {
                file_put_contents($tmp, $raw);
                $zip = new \ZipArchive();
                $opened = $zip->open($tmp);
                if ($opened !== true) {
                    throw PayException::gatewayError('支付宝对账单 ZIP 打开失败');
                }

                try {
                    $csv = '';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if ($name !== false && strtolower(substr($name, -4)) === '.csv') {
                            $csv = (string) $zip->getFromIndex($i);
                            break;
                        }
                    }
                } finally {
                    $zip->close();
                }

                if ($csv === '') {
                    throw PayException::gatewayError('支付宝对账单 ZIP 内未找到 CSV 文件');
                }

                return $csv;
            } finally {
                @unlink($tmp);
            }
        }

        return $raw;
    }

    /**
     * 下载支付宝资金账单电子回单
     *
     * 支付宝电子回单为异步生成，标准两步流程：
     *   1) `alipay.data.bill.ereceipt.apply` 申请，拿到 `file_id`；
     *   2) `alipay.data.bill.ereceipt.query` 轮询，`status=SUCCESS` 后返回 `download_url`（ZIP，内含 PDF）。
     *
     * 本方法在一次调用内完成「申请 + 首次查询」；若首次查询尚未生成（`status≠SUCCESS`），
     * 返回元数据（含 `file_id`、`status`，`file_content=null`），调用方可持 `file_id`
     * 再次调用本方法轮询，直到 `status=SUCCESS` 并完成下载解压。
     *
     * @param array<string, mixed> $params 参数：
     *   - type: 申请类型（默认 `BALANCE`，即余额收支证明/资金账单，按账务日期申请；
     *     与全 SDK `downloadFundFlow` 的 `bill_date` 约定对齐）。可选 `FUND_DETAIL`（单笔资金业务回单，key 传转账 `pay_fund_order_id`）等
     *   - key: 申请参数值（依 type 而定；默认 `BALANCE` 传账务日期如 `20260814`，`FUND_DETAIL` 传转账 `pay_fund_order_id`）
     *   - bill_date: `key` 的别名（向后兼容，默认 BALANCE 场景下即账务日期）
     *   - file_id: 已持有的 file_id（轮询场景，传入后跳过申请步骤）
     * @return array<string, mixed> 含 file_id / status / download_url / file_content（PDF 二进制）/ raw_data
     * @throws PayException
     */
    public function downloadFundFlow(array $params): array
    {
        if (isset($params['file_id']) && $params['file_id'] !== '') {
            return $this->queryAlipayEreceipt((string) $params['file_id']);
        }

        $type = $params['type'] ?? 'BALANCE';
        $key = $params['key'] ?? $params['bill_date'] ?? '';
        if ($key === '') {
            throw PayException::paramError('支付宝资金账单电子回单需提供 key（或 bill_date）');
        }

        $applyParams = $this->buildRequestParams('alipay.data.bill.ereceipt.apply', [
            'type' => $type,
            'key' => (string) $key,
        ]);
        $apply = $this->post('', $applyParams);

        $fileId = $apply['file_id'] ?? '';
        if ($fileId === '') {
            throw PayException::gatewayError('支付宝电子回单申请未返回 file_id');
        }

        return $this->queryAlipayEreceipt($fileId);
    }

    /**
     * 查询电子回单状态，就绪（status=SUCCESS）时下载并解压
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function queryAlipayEreceipt(string $fileId): array
    {
        $queryParams = $this->buildRequestParams('alipay.data.bill.ereceipt.query', [
            'file_id' => $fileId,
        ]);
        $query = $this->post('', $queryParams);

        $status = $query['status'] ?? '';
        $downloadUrl = $query['download_url'] ?? '';

        $result = [
            'file_id' => $fileId,
            'status' => $status,
            'download_url' => $downloadUrl,
            'file_content' => null,
            'raw_data' => $query,
        ];

        if ($status === 'SUCCESS' && $downloadUrl !== '') {
            $raw = $this->downloadBillFile($downloadUrl);
            $result['file_content'] = $this->extractAlipayEreceiptFile($raw);
        }

        return $result;
    }

    /**
     * 从电子回单下载内容提取文件
     *
     * 支付宝电子回单 `download_url` 返回 ZIP（含带电子签章的 PDF），需解压后取首个 PDF/CSV 条目；
     * 若下载内容本身为文件（非 ZIP）则原样返回。
     *
     * @throws PayException
     */
    protected function extractAlipayEreceiptFile(string $raw): string
    {
        if (substr($raw, 0, 2) === "PK") {
            if (!class_exists('ZipArchive')) {
                throw PayException::configError('支付宝电子回单为 ZIP 压缩包，需启用 PHP ZipArchive 扩展');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'alipay_ereceipt_');
            if ($tmp === false) {
                throw PayException::gatewayError('无法创建临时文件用于解压电子回单');
            }

            try {
                file_put_contents($tmp, $raw);
                $zip = new \ZipArchive();
                if ($zip->open($tmp) !== true) {
                    throw PayException::gatewayError('支付宝电子回单 ZIP 打开失败');
                }

                try {
                    $content = '';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if ($name !== false && $this->isReceiptEntry((string) $name)) {
                            $content = (string) $zip->getFromIndex($i);
                            break;
                        }
                    }

                    if ($content === '' && $zip->numFiles > 0) {
                        $content = (string) $zip->getFromIndex(0);
                    }
                } finally {
                    $zip->close();
                }

                if ($content === '') {
                    throw PayException::gatewayError('支付宝电子回单 ZIP 内未找到可提取文件');
                }

                return $content;
            } finally {
                @unlink($tmp);
            }
        }

        return $raw;
    }

    /**
     * 判断 ZIP 条目是否为电子回单文件（PDF / CSV）
     */
    private function isReceiptEntry(string $name): bool
    {
        return in_array(strtolower(substr($name, -4)), ['.pdf', '.csv'], true);
    }

    /**
     * 解析对账单原始数据（支付宝 CSV 格式）
     *
     * @param string $rawData 原始对账单 CSV
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    public function parseBill(string $rawData): array
    {
        return $this->parseAlipayBill($rawData);
    }

    /**
     * 解析支付宝对账单（CSV 格式）
     *
     * @param string $rawData 原始对账单数据
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    protected function parseAlipayBill(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $lines = explode("\n", $rawData);
        $records = [];
        $isHeader = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '合计')) {
                break;
            }

            if ($isHeader) {
                $isHeader = false;
                continue;
            }

            $fields = str_getcsv($line);
            if (count($fields) < 10) {
                continue;
            }

            $records[] = [
                'alipay_trade_no' => $fields[0] ?? '',
                'merchant_order_no' => $fields[1] ?? '',
                'business_type' => $fields[2] ?? '',
                'subject' => $fields[3] ?? '',
                'create_time' => $fields[4] ?? '',
                'finish_time' => $fields[5] ?? '',
                'store_id' => $fields[6] ?? '',
                'store_name' => $fields[7] ?? '',
                'operator' => $fields[8] ?? '',
                'terminal_id' => $fields[9] ?? '',
                'seller_account' => $fields[10] ?? '',
                'order_amount' => $fields[11] ?? '0',
                'real_amount' => $fields[12] ?? '0',
                'red_packet_amount' => $fields[13] ?? '0',
                'integral_amount' => $fields[14] ?? '0',
                'alipay_discount' => $fields[15] ?? '0',
                'merchant_discount' => $fields[16] ?? '0',
                'service_charge' => $fields[17] ?? '0',
                'share_profit' => $fields[18] ?? '0',
                'refund_id' => $fields[19] ?? '',
                'refund_amount' => $fields[20] ?? '0',
                'remark' => $fields[21] ?? '',
                'status' => $fields[22] ?? '',
            ];
        }

        return $records;
    }

    /**
     * 查询账户实时余额
     *
     * 调用 `alipay.fund.account.query` 查询商户自身支付宝账户资产。
     * 支付宝返回金额单位为「元」，本方法统一换算为「分」返回，与 {@see BalanceCapableInterface} 约定一致。
     *
     * @param array<string, mixed> $params 可选参数：
     *        - account_type：账户类型，默认 ACCTRANS_ACCOUNT（余额户）；可传 CASH_SITE 等
     *        - account_scene：账户场景，如 CASH（余额户场景）
     *        - alipay_user_id：蚂蚁会员 ID（应等于调用方 PID）；省略时由 account_type 决定查询自身账户
     * @return array<string, mixed> 含 available_amount（可用余额，分）/ freeze_amount（冻结，分）/
     *                              total_amount（总余额，分）/ currency / raw
     * @throws PayException
     */
    public function queryBalance(array $params = []): array
    {
        $bizContent = ['account_type' => $params['account_type'] ?? 'ACCTRANS_ACCOUNT'];

        if (isset($params['account_scene']) && $params['account_scene'] !== '') {
            $bizContent['account_scene'] = $params['account_scene'];
        }
        if (isset($params['alipay_user_id']) && $params['alipay_user_id'] !== '') {
            $bizContent['alipay_user_id'] = $params['alipay_user_id'];
        }

        $requestParams = $this->buildRequestParams('alipay.fund.account.query', $bizContent);
        $response = $this->post('', $requestParams);

        return [
            'account_type' => $bizContent['account_type'],
            'available_amount' => $this->yuanToFen((string) ($response['available_amount'] ?? '0')),
            'freeze_amount' => $this->yuanToFen((string) ($response['freeze_amount'] ?? '0')),
            'total_amount' => $this->yuanToFen((string) ($response['total_amount'] ?? '0')),
            'currency' => 'CNY',
            'raw' => $response,
        ];
    }

    /**
     * 查询日终余额
     *
     * 支付宝未提供按日期查询「日终余额」的接口，`alipay.fund.account.query` 仅返回实时资产，
     * 故本方法不支持；如需历史资金快照请结合 `downloadFundFlow`（电子回单）对账。
     *
     * @param string $date 对账日期，格式 YYYY-MM-DD
     * @param array<string, mixed> $params 可选参数
     * @throws PayException
     */
    public function queryDayEndBalance(string $date, array $params = []): array
    {
        throw PayException::methodNotSupported('alipay', 'queryDayEndBalance');
    }

    /**
     * 元转分
     *
     * 支付宝金额字段以「元」为单位（字符串，保留两位），统一换算为「分」（整数）。
     */
    private function yuanToFen(string $yuan): int
    {
        if ($yuan === '') {
            return 0;
        }

        return (int) round(((float) $yuan) * 100);
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
        $data = $this->decodeJson($response);

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

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起支付宝分账
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createProfitSharing(array $params): array
    {
        /** @var array<int, Receiver|array<string, mixed>> $receivers */
        $receivers = $params['receivers'];
        $royaltyParameters = array_map(static function ($r): array {
            if ($r instanceof Receiver) {
                return $r->toAlipayArray();
            }

            return [
                'trans_out_type' => $r['trans_out_type'] ?? 'userId',
                'trans_out' => $r['trans_out'] ?? '',
                'trans_in_type' => $r['trans_in_type'] ?? 'userId',
                'trans_in' => $r['trans_in'],
                'amount' => (float) $r['amount'],
                'desc' => $r['desc'] ?? $r['description'] ?? '分账',
            ];
        }, $receivers);

        $requestParams = $this->buildRequestParams('alipay.trade.order.settle', [
            'out_request_no' => $params['out_order_no'],
            'trade_no' => $params['transaction_id'],
            'royalty_parameters' => $royaltyParameters,
        ]);

        return $this->post('', $requestParams);
    }

    /**
     * 查询支付宝分账结果
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $requestParams = $this->buildRequestParams('alipay.trade.order.settle.query', [
            'out_request_no' => $outOrderNo,
        ]);

        return $this->post('', $requestParams);
    }

    /**
     * 支付宝分账回退
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function returnProfitSharing(array $params): array
    {
        $requestParams = $this->buildRequestParams('alipay.trade.refund', [
            'out_request_no' => $params['out_return_no'],
            'trade_no' => $params['transaction_id'] ?? '',
            'refund_amount' => (float) $params['return_amount'],
            'refund_reason' => $params['description'] ?? '分账回退',
        ]);

        return $this->post('', $requestParams);
    }

    /**
     * 查询支付宝分账回退结果
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $requestParams = $this->buildRequestParams('alipay.trade.fastpay.refund.query', [
            'out_request_no' => $outReturnNo,
        ]);

        return $this->post('', $requestParams);
    }

    /**
     * 解冻支付宝未分账的剩余资金
     *
     * 支付宝分账完成后自动解冻，无需额外操作。
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选，忽略）
     * @return array<string, mixed>
     */
    #[\Override]
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        return [
            'trade_no' => $transactionId,
            'status' => 'SUCCESS',
            'message' => '支付宝分账完成后自动解冻剩余资金',
        ];
    }

    /**
     * 添加支付宝分账接收方
     *
     * @param array<string, mixed> $receiver
     * @return array<string, mixed>
     */
    public function addProfitSharingReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['account', 'name']);

        return $this->post('', [
            'method' => 'alipay.trade.royalty.relation.bind',
            'biz_content' => json_encode([
                'receiver_list' => [
                    [
                        'type' => $receiver['type'] ?? 'userId',
                        'account' => $receiver['account'],
                        'name' => $receiver['name'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 删除支付宝分账接收方
     *
     * @param array<string, mixed> $receiver
     * @return array<string, mixed>
     */
    public function removeProfitSharingReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['account']);

        return $this->post('', [
            'method' => 'alipay.trade.royalty.relation.unbind',
            'biz_content' => json_encode([
                'receiver_list' => [
                    [
                        'type' => $receiver['type'] ?? 'userId',
                        'account' => $receiver['account'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * 结算到支付宝余额（复用单笔转账通道）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToWallet(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        return $this->singleTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'recipient' => [
                'type' => $params['identity_type'] ?? 'ALIPAY_USER_ID',
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '自动结算',
        ]);
    }

    /**
     * 结算到银行卡（复用无密转账到银行卡通道）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToBankCard(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'bank_card_no', 'real_name']);

        return $this->withdraw([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'bank_card_no' => $params['bank_card_no'],
            'real_name' => $params['real_name'],
            'bank_code' => $params['bank_code'] ?? '',
        ]);
    }

    /**
     * 支付宝无外部账户 Payout 语义，调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        throw PayException::methodNotSupported('alipay', 'settleToPayout');
    }

    /**
     * 查询结算结果（复用转账查询）
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function querySettlement(string $outBizNo): array
    {
        return $this->queryTransfer($outBizNo);
    }

    /* ==================== 订阅能力（SubscriptionCapableInterface） ==================== */

    /**
     * 创建订阅计划（本地周期规则）
     *
     * 支付宝周期扣款没有服务端「计划」实体，周期规则（period_rule_params）在
     * 签约时随 alipay.user.agreement.page.sign 一并提交。因此本方法只做规则
     * 组装与校验，返回可直接透传给 {@see createSubscription()} 的计划描述，
     * 不产生网络请求。
     *
     * @param array<string, mixed> $params 计划参数
     *        - name: 计划名称（用于 subject / 协议展示）
     *        - amount: 单次扣款上限（元，支付宝周期扣款以元为单位）
     *        - currency: 货币（仅支持 CNY）
     *        - interval: 周期 day/month（支付宝仅支持 DAY / MONTH）
     *        - interval_count: 周期数量（可选，默认 1）
     *        - execute_time: 首次扣款日（可选，默认次日）
     *        - total_amount: 总金额上限（可选）
     *        - total_payments: 总扣款次数（可选）
     * @return array<string, mixed> 计划描述（含 plan_id / period_rule_params）
     * @throws PayException
     */
    #[\Override]
    public function createPlan(array $params): array
    {
        $this->validateRequired($params, ['name', 'amount', 'currency', 'interval']);

        $currency = strtoupper((string) $params['currency']);
        if ($currency !== 'CNY') {
            throw PayException::paramError('支付宝周期扣款仅支持 CNY');
        }

        $periodRuleParams = $this->buildPeriodRuleParams($params);

        return [
            'plan_id' => 'alipay_plan_' . md5((string) $params['name'] . serialize($periodRuleParams)),
            'name' => $params['name'],
            'currency' => $currency,
            'period_rule_params' => $periodRuleParams,
        ];
    }

    /**
     * 创建订阅（alipay.user.agreement.page.sign 页面签约）
     *
     * 支付宝周期扣款需用户在收银台完成签约授权，故本方法返回可跳转的签约链接，
     * 而非同步的订阅实体；签约结果由 notify_url 异步回调，或用
     * {@see getSubscription()} 以协议号查询。
     *
     * @param array<string, mixed> $params 订阅参数
     *        - customer_id: 商户侧协议号（映射 external_agreement_no，用户维度唯一）
     *        - plan_id: 计划标识（仅作商户侧标记，周期规则以 period_rule_params 为准）
     *        - period_rule_params: 周期规则（可选，缺省时由本方法按 amount/interval 组装）
     *        - amount / interval / interval_count / execute_time: 未传 period_rule_params 时必需
     *        - sign_scene: 签约场景（可选，默认 INDUSTRY|DEFAULT_SCENE）
     *        - product_code: 产品码（可选，默认 CYCLE_PAY_AUTH_P）
     *        - notify_url / return_url: 回调地址（可选）
     * @return array<string, mixed> 含 method / url 的跳转描述
     * @throws PayException
     */
    #[\Override]
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['customer_id', 'plan_id']);

        /** @var array<string, mixed> $periodRuleParams */
        $periodRuleParams = is_array($params['period_rule_params'] ?? null)
            ? $params['period_rule_params']
            : $this->buildPeriodRuleParams($params);

        $bizContent = [
            'personal_product_code' => $params['product_code'] ?? 'CYCLE_PAY_AUTH_P',
            'sign_scene' => $params['sign_scene'] ?? 'INDUSTRY|DEFAULT_SCENE',
            'external_agreement_no' => $params['customer_id'],
            'access_params' => ['channel' => $params['channel'] ?? 'ALIPAYAPP'],
            'period_rule_params' => $periodRuleParams,
        ];

        if (isset($params['notify_url'])) {
            $bizContent['sign_notify_url'] = $params['notify_url'];
        }

        $requestParams = $this->buildRequestParams('alipay.user.agreement.page.sign', $bizContent);

        if (isset($params['return_url'])) {
            $requestParams['return_url'] = $params['return_url'];
            $requestParams['sign'] = Signer::rsa2($requestParams, $this->getConfig('private_key'));
        }

        return [
            'method' => 'GET',
            'url' => $this->getBaseUrl() . '?' . http_build_query($requestParams),
            'external_agreement_no' => $params['customer_id'],
            'plan_id' => $params['plan_id'],
        ];
    }

    /**
     * 取消订阅（alipay.user.agreement.unsign 解约）
     *
     * @param string $subscriptionId 支付宝协议号（agreement_no）；
     *        以 `ext:` 前缀传入时按商户侧协议号（external_agreement_no）解约
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function cancelSubscription(string $subscriptionId): array
    {
        $requestParams = $this->buildRequestParams(
            'alipay.user.agreement.unsign',
            $this->buildAgreementIdentity($subscriptionId),
        );

        return $this->post('', $requestParams);
    }

    /**
     * 支付宝周期扣款无「暂停」端点，调用即报「无此方法」
     *
     * 如需延后扣款，请改用 {@see modifyExecutionPlan()}（周期扣款执行计划修改）。
     *
     * @param string $subscriptionId 协议号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function pauseSubscription(string $subscriptionId): array
    {
        throw PayException::methodNotSupported('alipay', 'pauseSubscription');
    }

    /**
     * 支付宝周期扣款无「恢复」端点，调用即报「无此方法」
     *
     * @param string $subscriptionId 协议号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function resumeSubscription(string $subscriptionId): array
    {
        throw PayException::methodNotSupported('alipay', 'resumeSubscription');
    }

    /**
     * 查询订阅详情（alipay.user.agreement.query）
     *
     * @param string $subscriptionId 支付宝协议号（agreement_no）；
     *        以 `ext:` 前缀传入时按商户侧协议号查询
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function getSubscription(string $subscriptionId): array
    {
        $requestParams = $this->buildRequestParams(
            'alipay.user.agreement.query',
            $this->buildAgreementIdentity($subscriptionId),
        );

        return $this->post('', $requestParams);
    }

    /**
     * 协议代扣（alipay.trade.pay，扣款场景 agreement_id）
     *
     * 周期扣款签约成功后由商户按周期主动发起扣款；支付宝要求扣款前
     * 至少提前一天调用 alipay.user.agreement.executionplan.modify 或依据
     * 签约时的执行计划发送通知，具体以商户签约的行业方案为准。
     *
     * @param array<string, mixed> $params 扣款参数
     *        - out_trade_no: 商户订单号
     *        - total_amount: 扣款金额（元）
     *        - subject: 订单标题
     *        - agreement_no: 支付宝协议号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function payWithAgreement(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'subject', 'agreement_no']);

        $bizContent = [
            'out_trade_no' => $params['out_trade_no'],
            'total_amount' => $params['total_amount'],
            'subject' => $params['subject'],
            'product_code' => $params['product_code'] ?? 'CYCLE_PAY_AUTH',
            'agreement_params' => ['agreement_no' => $params['agreement_no']],
        ];

        if (isset($params['notify_url'])) {
            $bizContent['notify_url'] = $params['notify_url'];
        }

        $requestParams = $this->buildRequestParams('alipay.trade.pay', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 修改周期扣款执行计划（alipay.user.agreement.executionplan.modify）
     *
     * 用于延后下一次扣款日期，是支付宝对「暂停订阅」最接近的替代能力。
     *
     * @param array<string, mixed> $params
     *        - agreement_no: 支付宝协议号
     *        - deduct_time: 下次扣款时间（yyyy-MM-dd）
     *        - memo: 修改原因（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function modifyExecutionPlan(array $params): array
    {
        $this->validateRequired($params, ['agreement_no', 'deduct_time']);

        $bizContent = [
            'agreement_no' => $params['agreement_no'],
            'deduct_time' => $params['deduct_time'],
        ];

        if (isset($params['memo'])) {
            $bizContent['memo'] = $params['memo'];
        }

        $requestParams = $this->buildRequestParams('alipay.user.agreement.executionplan.modify', $bizContent);

        return $this->post('', $requestParams);
    }

    /**
     * 组装支付宝周期扣款规则（period_rule_params）
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function buildPeriodRuleParams(array $params): array
    {
        $this->validateRequired($params, ['amount', 'interval']);

        $periodType = strtoupper((string) $params['interval']);
        if (!in_array($periodType, ['DAY', 'MONTH'], true)) {
            throw PayException::paramError('支付宝周期扣款的 interval 仅支持 day / month');
        }

        $rule = [
            'period_type' => $periodType,
            'period' => (int) ($params['interval_count'] ?? 1),
            'execute_time' => $params['execute_time'] ?? date('Y-m-d', strtotime('+1 day')),
            'single_amount' => (float) $params['amount'],
        ];

        if (isset($params['total_amount'])) {
            $rule['total_amount'] = (float) $params['total_amount'];
        }

        if (isset($params['total_payments'])) {
            $rule['total_payments'] = (int) $params['total_payments'];
        }

        return $rule;
    }

    /**
     * 解析协议标识：默认按支付宝协议号，`ext:` 前缀表示商户侧协议号
     *
     * @return array<string, string>
     */
    protected function buildAgreementIdentity(string $subscriptionId): array
    {
        if (str_starts_with($subscriptionId, 'ext:')) {
            return [
                'personal_product_code' => 'CYCLE_PAY_AUTH_P',
                'sign_scene' => 'INDUSTRY|DEFAULT_SCENE',
                'external_agreement_no' => substr($subscriptionId, 4),
            ];
        }

        return ['agreement_no' => $subscriptionId];
    }
}
