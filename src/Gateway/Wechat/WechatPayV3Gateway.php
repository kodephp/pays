<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Encryptor;
use Kode\Pays\Support\StrUtil;
use Kode\Pays\Support\WechatBillParser;

/**
 * 微信支付 V3 网关
 *
 * 支持微信支付 APIv3 协议，使用 RSA 签名和 AES-GCM 加密。
 * 与 V2 版本相比，V3 提供更强的安全性和更简洁的接口。
 *
 * 签名遵循微信 APIv3 规范：签名串中的 URL 为去除域名后的绝对路径（含查询串），
 * 请求体则以 {@see postRaw()} 原样发送，确保「参与签名的字节」与「实际发送的字节」完全一致。
 */
class WechatPayV3Gateway extends AbstractGateway implements
    TransferCapableInterface,
    ReconciliationCapableInterface,
    RefundCapableInterface,
    ProfitSharingCapableInterface,
    SettlementCapableInterface,
    PersonalReceiveCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://api.mch.weixin.qq.com/v3/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api.mch.weixin.qq.com/v3/';

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

        return $this->signedPost('pay/transactions/' . ($params['trade_type'] ?? 'native'), $requestData);
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
        return $this->signedGet("pay/transactions/out-trade-no/{$orderId}", [
            'mchid' => $this->getConfig('mch_id'),
        ]);
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
        return $this->signedPost("pay/transactions/out-trade-no/{$orderId}/close", [
            'mchid' => $this->getConfig('mch_id'),
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

        return $this->signedPost('refund/domestic/refunds', $requestData);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRefund(string $refundId): array
    {
        return $this->signedGet("refund/domestic/refunds/{$refundId}");
    }

    /* ==================== 退款能力（RefundCapableInterface） ==================== */

    /**
     * 申请退款
     *
     * 与 {@see refund()} 的差异在于入参形态：本方法接收插件层归一化后的扁平参数
     * （金额以「分」为单位的 refund_fee / total_fee），并支持以 transaction_id 指定原单。
     *
     * @param array<string, mixed> $params 退款参数（out_refund_no、refund_fee 必填，
     *                                     out_trade_no 与 transaction_id 至少其一）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function applyRefund(array $params): array
    {
        $refundFee = (int) ($params['refund_fee'] ?? 0);

        $requestData = [
            'out_refund_no' => $params['out_refund_no'] ?? '',
            'reason' => $params['refund_desc'] ?? '',
            'amount' => [
                'refund' => $refundFee,
                'total' => (int) ($params['total_fee'] ?? $refundFee),
                'currency' => strtoupper((string) ($params['currency'] ?? 'CNY')),
            ],
        ];

        if (!empty($params['out_trade_no'])) {
            $requestData['out_trade_no'] = $params['out_trade_no'];
        } else {
            $requestData['transaction_id'] = $params['transaction_id'] ?? '';
        }

        if (!empty($params['notify_url'])) {
            $requestData['notify_url'] = $params['notify_url'];
        }

        return $this->signedPost('refund/domestic/refunds', $requestData);
    }

    /**
     * 取消退款（微信支付 APIv3 无该接口，统一报「无此方法」）
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function cancelRefund(string $outRefundNo): array
    {
        throw PayException::methodNotSupported('wechat_v3', 'cancelRefund');
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

    /* ==================== 转账能力（TransferCapableInterface） ==================== */

    /**
     * 单笔转账
     *
     * APIv3 商家转账统一以「批次」表达，单笔即为仅含一条明细的批次。
     *
     * @param array<string, mixed> $params 转账参数（out_biz_no、amount、recipient 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function singleTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['account']);

        return $this->batchTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'batch_name' => $params['batch_name'] ?? ($params['description'] ?? '商家转账'),
            'batch_remark' => $params['batch_remark'] ?? ($params['description'] ?? '商家转账'),
            'transfer_detail_list' => [
                [
                    'out_detail_no' => $params['out_detail_no'] ?? $params['out_biz_no'],
                    'amount' => $params['amount'],
                    'remark' => $params['description'] ?? '商家转账',
                    'recipient' => $recipient,
                ],
            ],
        ]);
    }

    /**
     * 批量转账
     *
     * @param array<string, mixed> $params 批量转账参数（out_biz_no、transfer_detail_list 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function batchTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        $list = $params['transfer_detail_list'];
        if (!is_array($list) || $list === []) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        $details = [];
        $totalAmount = 0;

        foreach ($list as $item) {
            if (!is_array($item)) {
                throw PayException::paramError('transfer_detail_list 每项必须是数组');
            }

            $this->validateRequired($item, ['out_detail_no', 'amount', 'recipient']);

            $recipient = $item['recipient'];
            $this->validateRequired($recipient, ['account']);

            $amount = (int) $item['amount'];
            $totalAmount += $amount;

            $detail = [
                'out_detail_no' => $item['out_detail_no'],
                'transfer_amount' => $amount,
                'transfer_remark' => $item['remark'] ?? '商家转账',
                'openid' => $recipient['account'],
            ];

            // 转账金额 >= 2000 元时微信要求校验真实姓名，姓名须以平台证书加密后传输
            if (isset($recipient['name']) && $recipient['name'] !== '') {
                $detail['user_name'] = $this->encryptSensitive((string) $recipient['name']);
            }

            $details[] = $detail;
        }

        return $this->signedPost('transfer/batches', [
            'appid' => $this->getConfig('app_id'),
            'out_batch_no' => $params['out_biz_no'],
            'batch_name' => $params['batch_name'] ?? '批量转账',
            'batch_remark' => $params['batch_remark'] ?? '批量转账',
            'total_amount' => $totalAmount,
            'total_num' => count($details),
            'transfer_detail_list' => $details,
        ]);
    }

    /**
     * 查询转账批次
     *
     * @param string $outBizNo 商户批次单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryTransfer(string $outBizNo): array
    {
        return $this->signedGet("transfer/batches/out-batch-no/{$outBizNo}", [
            'need_query_detail' => 'false',
        ]);
    }

    /**
     * 申请转账电子回单
     *
     * @param string $outBizNo 商户批次单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function transferReceipt(string $outBizNo): array
    {
        return $this->signedPost('transfer/bill-receipt', [
            'out_batch_no' => $outBizNo,
        ]);
    }

    /* ==================== 对账能力（ReconciliationCapableInterface） ==================== */

    /**
     * 下载交易对账单
     *
     * APIv3 账单为两步流程：先获取带 download_url 的元数据，再下载 CSV 文件。
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $query = [
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'ALL',
        ];

        if (!empty($params['tar_type'])) {
            $query['tar_type'] = $params['tar_type'];
        }

        $meta = $this->signedGet('bill/tradebill', $query);

        return [
            'bill_date' => $query['bill_date'],
            'bill_type' => $query['bill_type'],
            'download_url' => $meta['download_url'] ?? '',
            'hash_value' => $meta['hash_value'] ?? '',
            'raw_data' => $meta,
            'records' => $this->fetchBillRecords($meta, isset($query['tar_type'])),
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

        $query = [
            'bill_date' => $params['bill_date'],
            'account_type' => $params['account_type'] ?? 'BASIC',
        ];

        if (!empty($params['tar_type'])) {
            $query['tar_type'] = $params['tar_type'];
        }

        $meta = $this->signedGet('bill/fundflowbill', $query);

        return [
            'bill_date' => $query['bill_date'],
            'account_type' => $query['account_type'],
            'download_url' => $meta['download_url'] ?? '',
            'hash_value' => $meta['hash_value'] ?? '',
            'raw_data' => $meta,
            'records' => $this->fetchBillRecords($meta, isset($query['tar_type'])),
        ];
    }

    /**
     * 解析对账单原始数据（CSV 格式）
     *
     * @param string $rawData 原始对账单 CSV
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    #[\Override]
    public function parseBill(string $rawData): array
    {
        return WechatBillParser::parse($rawData);
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起分账
     *
     * APIv3 分账接收方姓名属敏感字段，需以平台证书加密后传输。
     *
     * @param array<string, mixed> $params 分账参数（transaction_id、out_order_no、receivers 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['transaction_id', 'out_order_no', 'receivers']);

        $receivers = $params['receivers'];
        if (!is_array($receivers) || $receivers === []) {
            throw PayException::paramError('receivers 必须是非空数组');
        }

        $mapped = [];

        foreach ($receivers as $receiver) {
            $item = $receiver instanceof Receiver ? $receiver->toWechatArray() : $receiver;

            if (!is_array($item)) {
                throw PayException::paramError('receivers 每项必须是数组或 Receiver 实例');
            }

            $entry = [
                'type' => $item['type'],
                'account' => $item['account'],
                'amount' => (int) $item['amount'],
                'description' => $item['description'] ?? '分账',
            ];

            if (isset($item['name']) && $item['name'] !== '') {
                $entry['name'] = $this->encryptSensitive((string) $item['name']);
            }

            $mapped[] = $entry;
        }

        return $this->signedPost('profitsharing/orders', [
            'appid' => $this->getConfig('app_id'),
            'transaction_id' => $params['transaction_id'],
            'out_order_no' => $params['out_order_no'],
            'receivers' => $mapped,
            'unfreeze_unsplit' => (bool) ($params['unfreeze_unsplit'] ?? false),
        ]);
    }

    /**
     * 查询分账结果
     *
     * 微信要求查询时同时携带原支付订单号；未提供时仅按商户分账单号查询，
     * 由调用方（或 {@see \Kode\Pays\Plugin\ProfitSharingPlugin}）自行保证参数完整。
     *
     * @param string $outOrderNo 商户分账订单号
     * @param string|null $transactionId 原支付订单号（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $query = $transactionId !== null && $transactionId !== ''
            ? ['transaction_id' => $transactionId]
            : [];

        return $this->signedGet("profitsharing/orders/{$outOrderNo}", $query);
    }

    /**
     * 分账回退
     *
     * @param array<string, mixed> $params 回退参数（out_order_no、out_return_no、return_amount 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function returnProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        return $this->signedPost('profitsharing/return-orders', [
            'out_order_no' => $params['out_order_no'],
            'out_return_no' => $params['out_return_no'],
            'return_mchid' => $params['return_account'] ?? $this->getConfig('mch_id'),
            'amount' => (int) $params['return_amount'],
            'description' => $params['description'] ?? '分账回退',
        ]);
    }

    /**
     * 查询分账回退结果
     *
     * @param string $outReturnNo 商户回退单号
     * @param string|null $outOrderNo 商户分账订单号（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryProfitSharingReturn(string $outReturnNo, ?string $outOrderNo = null): array
    {
        $query = $outOrderNo !== null && $outOrderNo !== ''
            ? ['out_order_no' => $outOrderNo]
            : [];

        return $this->signedGet("profitsharing/return-orders/{$outReturnNo}", $query);
    }

    /**
     * 解冻未分账的剩余资金
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选，缺省自动生成）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        return $this->signedPost('profitsharing/orders/unfreeze', [
            'transaction_id' => $transactionId,
            'out_order_no' => $outOrderNo ?? ('UNFREEZE_' . time()),
            'description' => '解冻剩余资金',
        ]);
    }

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * 结算到微信零钱（复用 APIv3 商家转账通道）
     *
     * @param array<string, mixed> $params 结算参数（out_biz_no、amount、account 必填）
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
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '自动结算',
        ]);
    }

    /**
     * 微信支付 APIv3 未提供「企业付款到银行卡」接口，调用即报「无此方法」
     *
     * 需结算到银行卡请改用 V2 网关（wechat）。
     *
     * @param array<string, mixed> $params 结算参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToBankCard(array $params): array
    {
        throw PayException::methodNotSupported('wechat_v3', 'settleToBankCard');
    }

    /**
     * 微信支付无外部账户 Payout 语义，调用即报「无此方法」
     *
     * @param array<string, mixed> $params 结算参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        throw PayException::methodNotSupported('wechat_v3', 'settleToPayout');
    }

    /**
     * 查询结算结果（复用转账批次查询）
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
     * 生成个人收款二维码（Native 下单）
     *
     * APIv3 下单强制要求回调地址，故 notify_url 为必填（V2 可省略）。
     *
     * @param array<string, mixed> $params 收款参数（amount、description、notify_url 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description', 'notify_url']);

        $outTradeNo = $params['out_trade_no'] ?? ('PERSONAL_' . date('YmdHis') . random_int(1000, 9999));

        $response = $this->createOrder([
            'trade_type' => 'native',
            'out_trade_no' => $outTradeNo,
            'description' => $params['description'],
            'amount' => (int) $params['amount'],
            'currency' => $params['currency'] ?? 'CNY',
            'notify_url' => $params['notify_url'],
            'attach' => $params['attach'] ?? null,
        ]);

        return [
            'out_trade_no' => $outTradeNo,
            'code_url' => $response['code_url'] ?? '',
            'amount' => (int) $params['amount'],
            'description' => $params['description'],
        ];
    }

    /**
     * 查询个人收款记录（复用交易对账单）
     *
     * @param array<string, mixed> $params 查询参数（start_time 可选，缺省取当天）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRecords(array $params): array
    {
        $startTime = strtotime((string) ($params['start_time'] ?? 'today'));

        return $this->downloadBill([
            'bill_date' => date('Y-m-d', $startTime === false ? time() : $startTime),
            'bill_type' => $params['bill_type'] ?? 'ALL',
        ]);
    }

    /**
     * 个人提现
     *
     * APIv3 未提供「企业付款到银行卡」接口，个人提现统一走商家转账到零钱；
     * 需提现到银行卡请改用 V2 网关（wechat）。
     *
     * @param array<string, mixed> $params 提现参数（out_biz_no、amount、account 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'account']);

        return $this->singleTransfer([
            'out_biz_no' => $params['out_biz_no'],
            'amount' => (int) $params['amount'],
            'recipient' => [
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '个人提现',
        ]);
    }

    /**
     * 查询提现结果（复用转账批次查询）
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
     * 依据账单元数据下载并解析 CSV
     *
     * 压缩格式（tar_type=GZIP）交由调用方自行解压后调用 {@see parseBill()}。
     *
     * @param array<string, mixed> $meta 账单元数据
     * @param bool $compressed 是否为压缩格式
     * @return array<int, array<string, mixed>>
     * @throws PayException
     */
    protected function fetchBillRecords(array $meta, bool $compressed): array
    {
        $downloadUrl = $meta['download_url'] ?? '';

        if ($compressed || !is_string($downloadUrl) || $downloadUrl === '') {
            return [];
        }

        return WechatBillParser::parse($this->downloadBillFile($downloadUrl));
    }

    /**
     * 下载对账单文件
     *
     * 账单文件为 CSV 文本而非 JSON，需绕过 {@see parseResponse()} 直取响应体。
     *
     * @param string $downloadUrl 账单下载地址（绝对 URL）
     * @throws PayException
     */
    protected function downloadBillFile(string $downloadUrl): string
    {
        $path = parse_url($downloadUrl, PHP_URL_PATH);
        $query = parse_url($downloadUrl, PHP_URL_QUERY);

        if (!is_string($path) || $path === '') {
            throw PayException::paramError('对账单下载地址无效');
        }

        $canonical = is_string($query) && $query !== '' ? $path . '?' . $query : $path;

        try {
            return $this->httpClient->get($downloadUrl, [], $this->buildV3Headers('GET', $canonical));
        } catch (\Throwable $e) {
            throw PayException::networkError('对账单下载失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 加密敏感信息（收款人真实姓名等）
     *
     * 微信 APIv3 要求敏感字段以平台证书公钥 RSA-OAEP 加密后传输。
     *
     * @param string $plain 明文
     * @throws PayException
     */
    protected function encryptSensitive(string $plain): string
    {
        $certificate = $this->resolvePlatformCertificate();

        if ($certificate === '') {
            throw PayException::configError('缺少微信支付平台证书（platform_certificate），无法加密收款人姓名');
        }

        return Encryptor::rsaEncrypt($plain, $certificate);
    }

    /**
     * 解析平台证书
     *
     * 优先使用运行时设置的证书，其次回落到配置项 platform_certificate。
     */
    protected function resolvePlatformCertificate(): string
    {
        if ($this->platformCertificate !== null && $this->platformCertificate !== '') {
            return $this->platformCertificate;
        }

        $configured = $this->getConfig('platform_certificate', '');

        return is_string($configured) ? $configured : '';
    }

    /**
     * 设置微信支付平台证书
     *
     * 用于响应验签与敏感字段加密；生产环境应由证书下载接口获取并缓存后注入。
     */
    public function setPlatformCertificate(string $certificate): void
    {
        $this->platformCertificate = $certificate;
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
        // V3 部分接口（如关单）成功时返回 204 No Content，空响应体即代表成功
        if (trim($response) === '') {
            return [];
        }

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
     * 发送已签名的 V3 POST 请求
     *
     * 请求体先行序列化，签名与发送使用同一份字节，避免序列化差异或中间件改写导致验签失败。
     *
     * @param string $endpoint API 端点（相对 v3 基础地址）
     * @param array<string, mixed> $data 请求数据
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function signedPost(string $endpoint, array $data): array
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = $this->buildV3Headers('POST', $this->canonicalPath($endpoint), $body);

        return $this->postRaw($endpoint, $body, $headers);
    }

    /**
     * 发送已签名的 V3 GET 请求
     *
     * @param string $endpoint API 端点（相对 v3 基础地址）
     * @param array<string, mixed> $query 查询参数（一并纳入签名串）
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function signedGet(string $endpoint, array $query = []): array
    {
        $headers = $this->buildV3Headers('GET', $this->canonicalPath($endpoint, $query));

        return $this->get($endpoint, $query, $headers);
    }

    /**
     * 构建参与签名的规范化 URL
     *
     * 微信 APIv3 要求签名串中的 URL 为「去除域名后的绝对路径」，存在查询参数时需附加 ?查询串。
     * 此处由基础地址与端点共同派生，基础地址调整时签名自动保持正确。
     *
     * @param string $endpoint API 端点
     * @param array<string, mixed> $query 查询参数
     */
    protected function canonicalPath(string $endpoint, array $query = []): string
    {
        $endpoint = ltrim($endpoint, '/');
        $parsed = parse_url($this->getBaseUrl() . $endpoint, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/' . $endpoint;

        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $path;
    }

    /**
     * 构建 V3 请求头
     *
     * @param string $method HTTP 方法
     * @param string $canonicalPath 参与签名的绝对路径（含查询串）
     * @param string $body 请求体（GET 请求传空字符串）
     * @return array<string, string>
     * @throws PayException
     */
    protected function buildV3Headers(string $method, string $canonicalPath, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = StrUtil::random(32);
        $serialNo = $this->getConfig('serial_no');

        $message = $method . "\n" . $canonicalPath . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = Encryptor::rsaSign($message, $this->getConfig('private_key'), 'sha256');

        $headers = [
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

        // 请求体含加密敏感字段时，微信要求声明所用平台证书序列号
        $platformSerial = $this->getConfig('platform_serial_no', '');
        if (is_string($platformSerial) && $platformSerial !== '') {
            $headers['Wechatpay-Serial'] = $platformSerial;
        }

        return $headers;
    }

    /**
     * 获取平台证书
     *
     * @param string $serial 证书序列号
     * @return string PEM 格式证书
     */
    protected function getPlatformCertificate(string $serial): string
    {
        // 生产环境应由证书下载接口按 serial 获取并缓存，此处回落到已注入/已配置的证书
        return $this->resolvePlatformCertificate();
    }
}
