<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Alipay;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
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
class AlipayGateway extends AbstractGateway implements TransferCapableInterface, RedPacketCapableInterface, PersonalReceiveCapableInterface, ReconciliationCapableInterface, RefundCapableInterface, ProfitSharingCapableInterface
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
     * @return array<string, mixed>
     */
    public function transferReceipt(string $outBizNo): array
    {
        $bizContent = ['out_biz_no' => $outBizNo];

        $requestParams = $this->buildRequestParams('alipay.fund.trans.invoice.query', $bizContent);

        return $this->post('', $requestParams);
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
     * 下载支付宝交易对账单（获取对账单下载地址）
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed> 含对账单下载地址与原始响应
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

        return [
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'trade',
            'bill_download_url' => $response['bill_download_url'] ?? '',
            'raw_data' => $response,
        ];
    }

    /**
     * 下载支付宝资金账单（电子回单申请）
     *
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<string, mixed> 含账单文件地址与原始响应
     * @throws PayException
     */
    public function downloadFundFlow(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $bizContent = [
            'type' => 'FUND',
            'key' => $params['bill_date'],
        ];

        $requestParams = $this->buildRequestParams('alipay.data.bill.ereceipt.apply', $bizContent);
        $response = $this->post('', $requestParams);

        return [
            'bill_date' => $params['bill_date'],
            'bill_file_url' => $response['bill_file_url'] ?? '',
            'raw_data' => $response,
        ];
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
        $data = json_decode($response, true);

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

        return $this->post('', [
            'method' => 'alipay.trade.order.settle',
            'biz_content' => json_encode([
                'out_request_no' => $params['out_order_no'],
                'trade_no' => $params['transaction_id'],
                'royalty_parameters' => $royaltyParameters,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝分账结果
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharing(string $outOrderNo): array
    {
        return $this->post('', [
            'method' => 'alipay.trade.order.settle.query',
            'biz_content' => json_encode([
                'out_request_no' => $outOrderNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
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
        return $this->post('', [
            'method' => 'alipay.trade.refund',
            'biz_content' => json_encode([
                'out_request_no' => $params['out_return_no'],
                'trade_no' => $params['transaction_id'] ?? '',
                'refund_amount' => (float) $params['return_amount'],
                'refund_reason' => $params['description'] ?? '分账回退',
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝分账回退结果
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        return $this->post('', [
            'method' => 'alipay.trade.fastpay.refund.query',
            'biz_content' => json_encode([
                'out_request_no' => $outReturnNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
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
}
