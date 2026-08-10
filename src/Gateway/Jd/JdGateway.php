<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Jd;

use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Signer;

/**
 * 京东支付网关
 *
 * 支持京东钱包支付、京东白条支付等场景。
 * 覆盖京东 App、京东小程序、PC 网页等渠道。
 */
class JdGateway extends AbstractGateway implements
    TransferCapableInterface,
    ProfitSharingCapableInterface,
    RedPacketCapableInterface,
    ReconciliationCapableInterface,
    SettlementCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://uat-wg.jd.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://wg.jd.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['merchant_no', 'des_key', 'md5_key']);
    }

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('jd');
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
        $this->validateRequired($params, ['out_trade_no', 'total_amount', 'subject', 'notify_url']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $params['out_trade_no'],
            'totalAmount' => $params['total_amount'],
            'subject' => $params['subject'],
            'notifyUrl' => $params['notify_url'],
            'tradeTime' => date('YmdHis'),
            'tradeType' => $params['trade_type'] ?? 'APP',
        ];

        if (isset($params['return_url'])) {
            $requestData['returnUrl'] = $params['return_url'];
        }

        if (isset($params['body'])) {
            $requestData['body'] = $params['body'];
        }

        if (isset($params['expire_time'])) {
            $requestData['expireTime'] = $params['expire_time'];
        }

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/unifiedOrder', $requestData);
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
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $orderId,
            'tradeTime' => date('YmdHis'),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/queryOrder', $requestData);
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

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $params['out_trade_no'],
            'refundAmount' => $params['refund_amount'],
            'outRefundNo' => $params['out_refund_no'] ?? uniqid('refund_', true),
            'tradeTime' => date('YmdHis'),
        ];

        if (isset($params['refund_reason'])) {
            $requestData['refundReason'] = $params['refund_reason'];
        }

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/refund', $requestData);
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
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outRefundNo' => $refundId,
            'tradeTime' => date('YmdHis'),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/queryRefund', $requestData);
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
        unset($data['sign']);

        return hash_equals($this->sign($data), $sign);
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
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outTradeNo' => $orderId,
            'tradeTime' => date('YmdHis'),
        ];

        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/pay/closeOrder', $requestData);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'jd';
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
            throw PayException::gatewayError('京东响应格式异常');
        }

        if (($data['resultCode'] ?? '') !== '000000') {
            throw PayException::gatewayError(
                $data['resultMessage'] ?? '京东业务失败',
                $data['resultCode'] ?? '',
            );
        }

        return $data;
    }

    /**
     * 生成签名
     *
     * @param array<string, mixed> $params 待签名参数
     * @return string
     */
    protected function sign(array $params): string
    {
        ksort($params);

        $string = '';
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $string .= $key . '=' . $value . '&';
        }

        $string .= 'key=' . $this->getConfig('md5_key');

        return strtoupper(md5($string));
    }

    /* ==================== 转账能力（TransferCapableInterface） ==================== */

    /**
     * 单笔转账 / 企业付款
     *
     * @param array<string, mixed> $params 转账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function singleTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['type', 'account', 'name']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outBizNo' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'recipientType' => $recipient['type'],
            'recipientAccount' => $recipient['account'],
            'recipientName' => $recipient['name'],
            'description' => $params['description'] ?? '企业付款',
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/transfer/single', $requestData);
    }

    /**
     * 批量转账
     *
     * @param array<string, mixed> $params 批量转账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function batchTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        /** @var array<int, array<string, mixed>> $list */
        $list = $params['transfer_detail_list'];
        if (!is_array($list) || empty($list)) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        $details = array_map(static function (array $item): array {
            $recipient = $item['recipient'] ?? [];

            return [
                'outDetailNo' => $item['out_detail_no'] ?? '',
                'amount' => (int) ($item['amount'] ?? 0),
                'remark' => $item['remark'] ?? '',
                'recipientType' => $recipient['type'] ?? '',
                'recipientAccount' => $recipient['account'] ?? '',
                'recipientName' => $recipient['name'] ?? '',
            ];
        }, $list);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outBizNo' => $params['out_biz_no'],
            'totalAmount' => array_sum(array_column($list, 'amount')),
            'totalNum' => count($list),
            'transferDetailList' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/transfer/batch', $requestData);
    }

    /**
     * 查询转账结果
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryTransfer(string $outBizNo): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outBizNo' => $outBizNo,
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/transfer/query', $requestData);
    }

    /**
     * 查询转账电子回单
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function transferReceipt(string $outBizNo): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outBizNo' => $outBizNo,
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/transfer/receipt', $requestData);
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起分账
     *
     * @param array<string, mixed> $params 分账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createProfitSharing(array $params): array
    {
        /** @var array<int, Receiver|array<string, mixed>> $receivers */
        $receivers = $params['receivers'];
        $mapped = array_map(static function ($r): array {
            if ($r instanceof Receiver) {
                return $r->toJdArray();
            }

            return [
                'account' => (string) ($r['account'] ?? ''),
                'amount' => (int) ($r['amount'] ?? 0),
            ];
        }, $receivers);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'transactionId' => $params['transaction_id'],
            'outOrderNo' => $params['out_order_no'],
            'receivers' => json_encode($mapped, JSON_UNESCAPED_UNICODE),
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/profitsharing/create', $requestData);
    }

    /**
     * 查询分账结果
     *
     * @param string $outOrderNo 商户分账订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outOrderNo' => $outOrderNo,
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/profitsharing/query', $requestData);
    }

    /**
     * 分账回退
     *
     * @param array<string, mixed> $params 回退参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function returnProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outOrderNo' => $params['out_order_no'],
            'outReturnNo' => $params['out_return_no'],
            'returnAmount' => (int) $params['return_amount'],
            'description' => $params['description'] ?? '分账回退',
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/profitsharing/return', $requestData);
    }

    /**
     * 查询分账回退结果
     *
     * @param string $outReturnNo 商户回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outReturnNo' => $outReturnNo,
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/profitsharing/return/query', $requestData);
    }

    /**
     * 解冻未分账的剩余资金
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'transactionId' => $transactionId,
            'outOrderNo' => $outOrderNo ?? ('UNFREEZE_' . time()),
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/profitsharing/finish', $requestData);
    }

    /* ==================== 现金红包能力（RedPacketCapableInterface） ==================== */

    /**
     * 发放普通现金红包
     *
     * @param array<string, mixed> $params 红包参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function sendRedPacket(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'wishing', 'act_name', 'remark']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'mchBillNo' => $params['mch_billno'],
            'sendName' => $params['send_name'],
            'reOpenid' => $params['re_openid'],
            'totalAmount' => (int) $params['total_amount'],
            'totalNum' => (int) ($params['total_num'] ?? 1),
            'wishing' => $params['wishing'],
            'actName' => $params['act_name'],
            'remark' => $params['remark'],
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/redpacket/send', $requestData);
    }

    /**
     * 发放裂变红包（群红包）
     *
     * @param array<string, mixed> $params 裂变红包参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function groupRedPacket(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'total_num', 'wishing', 'act_name', 'remark']);

        if ((int) $params['total_num'] < 3) {
            throw PayException::paramError('裂变红包 total_num 必须 >= 3');
        }

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'mchBillNo' => $params['mch_billno'],
            'sendName' => $params['send_name'],
            'reOpenid' => $params['re_openid'],
            'totalAmount' => (int) $params['total_amount'],
            'totalNum' => (int) $params['total_num'],
            'wishing' => $params['wishing'],
            'actName' => $params['act_name'],
            'remark' => $params['remark'],
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/redpacket/group', $requestData);
    }

    /**
     * 查询红包发放记录
     *
     * @param string $mchBillNo 商户红包单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRedPacket(string $mchBillNo): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'mchBillNo' => $mchBillNo,
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/redpacket/query', $requestData);
    }

    /* ==================== 对账能力（ReconciliationCapableInterface） ==================== */

    /**
     * 下载交易对账单
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'billDate' => $params['bill_date'],
            'billType' => $params['bill_type'] ?? 'ALL',
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        $response = $this->post('api/bill/download', $requestData);
        $rawText = (string) ($response['billContent'] ?? '');

        return [
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'ALL',
            'raw_data' => $response,
            'records' => $this->parseBill($rawText),
        ];
    }

    /**
     * 下载资金账单
     *
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadFundFlow(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'billDate' => $params['bill_date'],
            'accountType' => $params['account_type'] ?? 'BASIC',
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        $response = $this->post('api/bill/fundflow', $requestData);
        $rawText = (string) ($response['billContent'] ?? '');

        return [
            'bill_date' => $params['bill_date'],
            'account_type' => $params['account_type'] ?? 'BASIC',
            'raw_data' => $response,
            'records' => $this->parseBill($rawText),
        ];
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
    protected function parseCsvBill(string $rawData): array
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

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * 结算到平台内钱包余额（复用单笔转账通道）
     *
     * @param array<string, mixed> $params 结算参数
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
                'type' => 'openid',
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '自动结算',
        ]);
    }

    /**
     * 结算到银行卡
     *
     * @param array<string, mixed> $params 结算参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToBankCard(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'bank_card_no', 'real_name']);

        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outBizNo' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'bankCardNo' => $params['bank_card_no'],
            'realName' => $params['real_name'],
            'bankCode' => $params['bank_code'] ?? '',
            'description' => $params['description'] ?? '自动结算到银行卡',
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/settle/bankcard', $requestData);
    }

    /**
     * 京东支付无外部账户 Payout 语义，调用即报「无此方法」
     *
     * @param array<string, mixed> $params 结算参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        throw PayException::methodNotSupported('jd', 'settleToPayout');
    }

    /**
     * 查询结算结果
     *
     * @param string $outBizNo 商户结算单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function querySettlement(string $outBizNo): array
    {
        $requestData = [
            'merchantNo' => $this->getConfig('merchant_no'),
            'outBizNo' => $outBizNo,
            'tradeTime' => date('YmdHis'),
        ];
        $requestData['sign'] = $this->sign($requestData);

        return $this->post('api/settle/query', $requestData);
    }
}
