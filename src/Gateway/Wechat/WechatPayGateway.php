<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Contract\QrCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Signer;
use Kode\Pays\Support\WechatBillParser;


/**
 * 微信支付网关
 *
 * 支持 JSAPI、Native、H5、App、小程序等支付场景
 */
class WechatPayGateway extends AbstractGateway implements
    TransferCapableInterface,
    RedPacketCapableInterface,
    PersonalReceiveCapableInterface,
    ReconciliationCapableInterface,
    RefundCapableInterface,
    ProfitSharingCapableInterface,
    SettlementCapableInterface,
    SubscriptionCapableInterface,
    WebhookCapableInterface,
    QrCapableInterface
{
    use WechatV3SigningTrait;

    /**
     * 沙箱环境基础 URL
     */
    protected const SANDBOX_BASE_URL = 'https://api.mch.weixin.qq.com/sandboxnew/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://api.mch.weixin.qq.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'mch_id', 'api_key']);
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
        $this->validateRequired($params, ['out_trade_no', 'total_fee', 'body', 'trade_type']);

        $tradeType = strtoupper((string) ($params['trade_type'] ?? ''));

        // JSAPI 支付必须提供支付用户的 openid（公众号 / 关联小程序场景，通常由 kode/miniapp 等授权后获得）
        if ($tradeType === 'JSAPI' && empty($params['openid'])) {
            throw PayException::paramError('JSAPI 支付必须提供 openid（来自公众号/小程序 OAuth 授权，如 kode/miniapp）');
        }

        // 服务商模式字段（sub_appid / sub_mch_id）由配置驱动，
        // 经 signedV2Post 自动并入请求（见 applyServiceProviderFields）。
        // appid 解析优先级：请求显式 app_id/appid > 配置 jsapi_app_id（仅 JSAPI 场景）> 配置 app_id。
        // 同一商户可绑定多个 appid（公众号 / 小程序 / App），JSAPI 必须使用与 openid 来源一致的 appid，
        // 故 jsapi_app_id 作为 JSAPI 场景的默认绑定 appid（仍可被请求级 app_id 覆盖）。
        $appId = $params['app_id'] ?? $params['appid'] ?? null;
        unset($params['app_id'], $params['appid']);
        if ($appId === null) {
            $appId = $tradeType === 'JSAPI'
                ? ($this->getConfig('jsapi_app_id') ?: $this->getConfig('app_id'))
                : $this->getConfig('app_id');
        }
        $params['appid'] = $appId;
        $params['mch_id'] = $this->getConfig('mch_id');
        $params['nonce_str'] = $this->generateNonceStr();

        return $this->signedV2Post('pay/unifiedorder', $params);
    }

    /**
     * 生成 JSAPI 调起支付参数（二次签名）
     *
     * 统一下单拿到 prepay_id 后，前端需以 `WeixinJSBridge.invoke('getBrandWCPayRequest', ...)`
     * 调起支付。本方法按微信规范用 api_key 做 MD5 二次签名，返回前端所需全部字段。
     *
     * @param string $prepayId 统一下单返回的 prepay_id
     * @return array<string, string> 含 appId / timeStamp / nonceStr / package / signType / paySign
     * @throws PayException
     */
    public function buildJsApiConfig(string $prepayId, ?string $appId = null): array
    {
        $effectiveAppId = $appId ?? $this->getEffectiveAppId();

        $params = [
            'appId' => $effectiveAppId,
            'timeStamp' => (string) time(),
            'nonceStr' => $this->generateNonceStr(),
            'package' => 'prepay_id=' . $prepayId,
            'signType' => 'MD5',
        ];

        $params['paySign'] = $this->signJsApi($params);

        return $params;
    }

    /**
     * JSAPI 二次签名（MD5）
     *
     * 签名串为「按 key 升序拼接的 query string」追加 `&key=api_key` 后做 MD5 并转大写。
     *
     * @param array<string, string> $params
     */
    private function signJsApi(array $params): string
    {
        ksort($params);

        $string = urldecode(http_build_query($params)) . '&key=' . $this->getConfig('api_key');

        return strtoupper(md5($string));
    }

    /**
     * 查询订单
     *
     * @param string $orderId 商户订单号或微信订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        $params = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
        ];

        // 优先使用微信订单号查询，否则使用商户订单号
        if (str_starts_with($orderId, 'wx')) {
            $params['transaction_id'] = $orderId;
        } else {
            $params['out_trade_no'] = $orderId;
        }

        return $this->signedV2Post('pay/orderquery', $params);
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
        $this->validateRequired($params, ['out_refund_no', 'total_fee', 'refund_fee']);

        $params['appid'] = $this->getConfig('app_id');
        $params['mch_id'] = $this->getConfig('mch_id');
        $params['nonce_str'] = $this->generateNonceStr();

        return $this->signedV2Post('secapi/pay/refund', $params, true);
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

        return Signer::verifyMd5($data, $this->getConfig('api_key'));
    }

    /**
     * 验证 Webhook 原始请求签名（与运行时解耦版本）
     *
     * 复用 {@see verifyNotify()} 的 MD5 验签逻辑，但接收原始 XML 报文，
     * 不再依赖全局 `$_SERVER` / `php://input`。
     *
     * @param string $payload 原始 XML 请求体
     * @param array<string, string> $headers 请求头（微信 V2 通知签名在报文体内，未使用）
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        if ($payload === '') {
            return false;
        }

        return $this->verifyNotify($this->xmlToArray($payload));
    }

    /**
     * 解析 Webhook 原始 XML 请求体为统一事件结构
     *
     * @param string $payload 原始 XML 请求体
     * @return array<string, mixed>
     * @throws PayException
     */
    public function parseWebhook(string $payload): array
    {
        $data = $this->xmlToArray($payload);

        return [
            'gateway' => 'wechat',
            'event_id' => $data['transaction_id'] ?? $data['out_trade_no'] ?? null,
            'event_type' => ($data['result_code'] ?? 'SUCCESS') === 'SUCCESS' ? 'pay_success' : 'pay_fail',
            'data' => $data,
            'raw' => $payload,
        ];
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
        $params = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'out_trade_no' => $orderId,
            'nonce_str' => $this->generateNonceStr(),
        ];

        return $this->signedV2Post('pay/closeorder', $params);
    }

    /**
     * 单笔转账到零钱（企业付款）
     *
     * 组装企业付款到零钱请求并复用基类 HTTP 通道。金额单位为分。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function singleTransfer(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        $recipient = $params['recipient'];
        $this->validateRequired($recipient, ['type', 'account', 'name']);

        $requestData = [
            'mch_appid' => $this->getConfig('app_id'),
            'mchid' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'partner_trade_no' => $params['out_biz_no'],
            'openid' => $recipient['account'],
            'check_name' => 'FORCE_CHECK',
            're_user_name' => $recipient['name'],
            'amount' => (int) $params['amount'],
            'desc' => $params['description'] ?? '企业付款',
            'spbill_create_ip' => $params['client_ip'] ?? '127.0.0.1',
        ];

        return $this->signedV2Post('mmpaymkttransfers/promotion/transfers', $requestData, true);
    }

    /**
     * 批量转账到零钱
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

        $transferList = array_map(static function (array $item): array {
            $recipient = $item['recipient'];
            return [
                'out_detail_no' => $item['out_detail_no'],
                'transfer_amount' => (int) $item['amount'],
                'transfer_remark' => $item['remark'] ?? '',
                'openid' => $recipient['account'],
                'user_name' => $recipient['name'] ?? '',
            ];
        }, $list);

        // 批量转账到零钱为微信 V3 接口，须以 V3 Authorization 证书头发起（含服务商字段注入）
        return $this->signedV3Post('v3/transfer/batches', [
            'appid' => $this->getEffectiveAppId(),
            'out_batch_no' => $params['out_biz_no'],
            'batch_name' => $params['batch_name'] ?? '批量转账',
            'batch_remark' => $params['batch_remark'] ?? '',
            'total_amount' => array_sum(array_column($list, 'amount')),
            'total_num' => count($list),
            'transfer_detail_list' => $transferList,
        ]);
    }

    /**
     * 查询转账结果
     *
     * @return array<string, mixed>
     */
    public function queryTransfer(string $outBizNo): array
    {
        return $this->signedV3Get("v3/transfer/batches/out-batch-no/{$outBizNo}");
    }

    /**
     * 查询转账电子回单
     *
     * @return array<string, mixed>
     */
    public function transferReceipt(string $outBizNo): array
    {
        return $this->signedV3Get(
            "v3/transfer/batches/out-batch-no/{$outBizNo}"
            . "/details/out-detail-no/{$outBizNo}/electronic-receipt",
        );
    }

    /**
     * 发放普通现金红包
     *
     * 组装现金红包请求并复用基类 HTTP 通道。金额单位为分。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function sendRedPacket(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'wishing', 'act_name', 'remark']);

        $requestData = [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $params['mch_billno'],
            'mch_id' => $this->getConfig('mch_id'),
            'wxappid' => $this->getConfig('app_id'),
            'send_name' => $params['send_name'],
            're_openid' => $params['re_openid'],
            'total_amount' => (int) $params['total_amount'],
            'total_num' => (int) ($params['total_num'] ?? 1),
            'wishing' => $params['wishing'],
            'client_ip' => $params['client_ip'] ?? '127.0.0.1',
            'act_name' => $params['act_name'],
            'remark' => $params['remark'],
        ];

        if (!empty($params['scene_id'])) {
            $requestData['scene_id'] = $params['scene_id'];
        }

        return $this->signedV2Post('mmpaymkttransfers/sendredpack', $requestData, true);
    }

    /**
     * 发放裂变红包（群红包）
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

        $requestData = [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $params['mch_billno'],
            'mch_id' => $this->getConfig('mch_id'),
            'wxappid' => $this->getConfig('app_id'),
            'send_name' => $params['send_name'],
            're_openid' => $params['re_openid'],
            'total_amount' => (int) $params['total_amount'],
            'total_num' => (int) $params['total_num'],
            'amt_type' => 'ALL_RAND',
            'wishing' => $params['wishing'],
            'act_name' => $params['act_name'],
            'remark' => $params['remark'],
        ];

        if (!empty($params['scene_id'])) {
            $requestData['scene_id'] = $params['scene_id'];
        }

        return $this->signedV2Post('mmpaymkttransfers/sendgroupredpack', $requestData, true);
    }

    /**
     * 查询红包发放记录
     *
     * @return array<string, mixed>
     */
    public function queryRedPacket(string $mchBillNo): array
    {
        return $this->signedV2Post('mmpaymkttransfers/gethbinfo', [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $mchBillNo,
            'mch_id' => $this->getConfig('mch_id'),
            'appid' => $this->getConfig('app_id'),
            'bill_type' => 'MCHT',
        ], true);
    }

    /**
     * 生成个人收款二维码（NATIVE 扫码支付）
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $outTradeNo = 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999);

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'body' => $params['description'],
            'out_trade_no' => $outTradeNo,
            'total_fee' => (int) $params['amount'],
            'spbill_create_ip' => $params['client_ip'] ?? '127.0.0.1',
            'notify_url' => $params['notify_url'] ?? '',
            'trade_type' => 'NATIVE',
            'product_id' => $params['product_id'] ?? 'PERSONAL_PAY',
            'attach' => !empty($params['attach']) ? json_encode($params['attach'], JSON_UNESCAPED_UNICODE) : '',
        ];

        if (!empty($params['expire_seconds'])) {
            $requestData['time_expire'] = date('YmdHis', time() + (int) $params['expire_seconds']);
        }

        $response = $this->signedV2Post('pay/unifiedorder', $requestData);

        return [
            'out_trade_no' => $outTradeNo,
            'code_url' => $response['code_url'] ?? '',
            'prepay_id' => $response['prepay_id'] ?? '',
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
        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'bill_date' => date('Ymd', strtotime($params['start_time'] ?? 'today')),
            'bill_type' => 'ALL',
        ];

        $raw = $this->signedV2Raw('pay/downloadbill', $requestData);

        return [
            'bill_date' => $requestData['bill_date'],
            'bill_type' => 'ALL',
            'raw_data' => $raw,
            'records' => $this->parseWechatBill($this->extractBillRawText(['data' => $raw])),
        ];
    }

    /**
     * 提现到银行卡（企业付款到银行卡）
     *
     * @param array<string, mixed> $params 提现参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['amount', 'bank_card_no', 'real_name', 'out_biz_no']);

        return $this->signedV2Post('mmpaymkttransfers/pay_bank', [
            'mch_id' => $this->getConfig('mch_id'),
            'partner_trade_no' => $params['out_biz_no'],
            'nonce_str' => $this->generateNonceStr(),
            'enc_bank_no' => $this->encryptBankCard($params['bank_card_no']),
            'enc_true_name' => $this->encryptBankCard($params['real_name']),
            'bank_code' => $params['bank_code'] ?? '',
            'amount' => (int) $params['amount'],
            'desc' => $params['description'] ?? '个人提现',
        ], true);
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
        return $this->signedV2Post('mmpaymkttransfers/query_bank', [
            'mch_id' => $this->getConfig('mch_id'),
            'partner_trade_no' => $outBizNo,
            'nonce_str' => $this->generateNonceStr(),
        ], true);
    }

    /**
     * 下载微信交易对账单
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed> 含原始响应与解析后的记录列表
     * @throws PayException
     */
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'ALL',
            'tar_type' => $params['tar_type'] ?? '',
        ];

        $response = $this->signedV2Raw('pay/downloadbill', $requestData);
        $rawText = $this->extractBillRawText(['data' => $response]);

        return [
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'ALL',
            'raw_data' => $response,
            'records' => $this->parseWechatBill($rawText),
        ];
    }

    /**
     * 下载微信资金账单
     *
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function downloadFundFlow(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'bill_date' => $params['bill_date'],
            'account_type' => $params['account_type'] ?? 'Basic',
            'tar_type' => $params['tar_type'] ?? '',
        ];

        $response = $this->signedV2Raw('pay/downloadfundflow', $requestData);
        $rawText = $this->extractBillRawText(['data' => $response]);

        return [
            'bill_date' => $params['bill_date'],
            'account_type' => $params['account_type'] ?? 'Basic',
            'raw_data' => $response,
            'records' => $this->parseWechatBill($rawText),
        ];
    }

    /**
     * 解析对账单原始数据（微信 CSV 格式）
     *
     * @param string $rawData 原始对账单 CSV
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    public function parseBill(string $rawData): array
    {
        return $this->parseWechatBill($rawData);
    }

    /**
     * 从统一响应中提取对账单原始文本
     *
     * 对账单接口返回的是 CSV 文本而非标准 XML，统一入口将原始文本置于 data 字段。
     *
     * @param array<string, mixed> $response 统一响应数组
     */
    protected function extractBillRawText(array $response): string
    {
        return WechatBillParser::extractRawText($response);
    }

    /**
     * 解析微信对账单（CSV 格式）
     *
     * @param string $rawData 原始对账单数据
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    protected function parseWechatBill(string $rawData): array
    {
        return WechatBillParser::parse($rawData);
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
        $this->validateRequired($params, ['out_refund_no', 'refund_fee']);

        if (empty($params['out_trade_no']) && empty($params['transaction_id'])) {
            throw PayException::paramError('out_trade_no 与 transaction_id 至少提供其一');
        }

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_refund_no' => $params['out_refund_no'],
            'refund_fee' => (int) $params['refund_fee'],
            'refund_desc' => $params['refund_desc'] ?? '',
        ];

        if (!empty($params['out_trade_no'])) {
            $requestData['out_trade_no'] = $params['out_trade_no'];
        } else {
            $requestData['transaction_id'] = $params['transaction_id'];
        }

        if (isset($params['total_fee'])) {
            $requestData['total_fee'] = (int) $params['total_fee'];
        }

        if (!empty($params['notify_url'])) {
            $requestData['notify_url'] = $params['notify_url'];
        }

        return $this->signedV2Post('secapi/pay/refund', $requestData, true);
    }

    /**
     * 查询退款结果
     *
     * @return array<string, mixed>
     * @throws PayException
     */

    public function queryRefund(string $outRefundNo): array
    {
        return $this->signedV2Post('pay/refundquery', [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_refund_no' => $outRefundNo,
        ]);
    }

    /**
     * 取消退款（微信支付不支持，统一报「无此方法」）
     *
     * @throws PayException
     */

    public function cancelRefund(string $outRefundNo): array
    {
        throw PayException::methodNotSupported('wechat', 'cancelRefund');
    }

    /**
     * 加密银行卡/姓名信息（微信支付要求 RSA 加密）
     *
     * @param string $data 待加密数据
     * @return string Base64 编码的密文
     */
    protected function encryptBankCard(string $data): string
    {
        $publicKey = $this->getConfig('bank_public_key');

        if (empty($publicKey)) {
            return base64_encode($data);
        }

        openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        return base64_encode($encrypted);
    }

    /**
     * 以微信 V2「XML + MD5 签名」规范发起 POST 并解析 XML 响应
     *
     * 现金红包、企业付款、付款到银行卡等 V2 现金类接口要求请求体为 XML、
     * 字段经 MD5 签名，且部分接口需携带商户 SSL 证书。本方法统一封装，
     * 确保「参与签名的字节」与「实际发送的字节」一致。
     *
     * @param array<string, mixed> $data 已组装的请求字段（不含 sign）
     * @param bool $withCert 是否携带商户 SSL 证书（红包 / 企业付款 / 付款到银行卡需 true）
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function signedV2Post(string $endpoint, array $data, bool $withCert = false): array
    {
        $data = $this->applyServiceProviderFields($data);

        $data['sign'] = Signer::md5($data, (string) $this->getConfig('api_key'));

        $options = [];
        if ($withCert) {
            $cert = $this->getConfig('cert_path');
            $key = $this->getConfig('key_path');
            if (is_string($cert) && $cert !== '' && is_string($key) && $key !== '') {
                $options['cert'] = $cert;
                $options['ssl_key'] = $key;
            }
        }

        return $this->postRaw($endpoint, $this->arrayToXml($data), ['Content-Type' => 'text/xml'], $options);
    }

    /**
     * 以微信 V2「XML + MD5 签名」规范发起 POST 并返回原始响应体
     *
     * 用于对账单等返回 CSV（非 XML）的接口：签名与发送一致，但绕过 XML 解析，
     * 交由调用方自行解析。
     *
     * @param array<string, mixed> $data 已组装的请求字段（不含 sign）
     * @return string 原始响应体
     * @throws PayException
     */
    protected function signedV2Raw(string $endpoint, array $data): string
    {
        $data = $this->applyServiceProviderFields($data);

        $data['sign'] = Signer::md5($data, (string) $this->getConfig('api_key'));

        return $this->httpClient->postRaw(
            $this->getBaseUrl() . $endpoint,
            $this->arrayToXml($data),
            ['Content-Type' => 'text/xml'],
        );
    }

    /**
     * 服务商模式字段注入（V2）
     *
     * 当网关配置包含 `sub_mch_id` / `sub_appid` 时并入请求，
     * 使一个开放平台主体可关联多个公众号 / 小程序 / 子商户。
     * 仅注入配置中实际存在的字段，且不覆盖调用方已显式传入的同名字段，
     * 因此普通商户请求（未配置这些字段）行为完全不变。
     *
     * @param array<string, mixed> $data 已组装的请求字段
     * @return array<string, mixed>
     */
    private function applyServiceProviderFields(array $data): array
    {
        // 服务商模式下，顶层 appid / mchid 即服务商主体。当配置 sp_appid / sp_mchid 时
        // 优先采用并覆盖统一下单等入口已写入的 app_id / mch_id，使 V2 与 V3 的服务商
        // 配置契约保持一致（sp_* 为服务商，sub_* 为子商户）。
        foreach (['sp_appid' => 'appid', 'sp_mchid' => 'mch_id'] as $cfgKey => $field) {
            $value = $this->getConfig($cfgKey);

            if (is_string($value) && $value !== '') {
                $data[$field] = $value;
            }
        }

        foreach (['sub_mch_id', 'sub_appid'] as $key) {
            $value = $this->getConfig($key);

            if (is_string($value) && $value !== '' && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * 取 JSAPI / 小程序调起支付所用的有效 appId
     *
     * 服务商模式下，前端调起支付应使用子商户的 sub_appid（交易归属的公众号 / 小程序），
     * 而非服务商顶层的 app_id；未配置 sub_appid 时回落到 app_id。
     */
    private function getEffectiveAppId(): string
    {
        $subAppId = $this->getConfig('sub_appid');
        if (is_string($subAppId) && $subAppId !== '') {
            return $subAppId;
        }

        // JSAPI 场景优先使用配置中声明的绑定 appid（与 openid 同源）
        $jsapiAppId = $this->getConfig('jsapi_app_id');
        if (is_string($jsapiAppId) && $jsapiAppId !== '') {
            return $jsapiAppId;
        }

        return (string) $this->getConfig('app_id');
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'wechat';
    }

    /**
     * 解析响应
     *
     * @param string $response XML 响应字符串
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function parseResponse(string $response): array
    {
        $trimmed = trim($response);

        // V3 接口（如转账到零钱批次、V3 查询/回单）返回 JSON，而非 V2 的 XML。
        // 以首字符区分响应格式，避免把 JSON 误当 XML 解析而抛「响应格式异常」。
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $data = $this->decodeJson($trimmed);

            if (!is_array($data)) {
                throw PayException::gatewayError('微信支付 V3 响应格式异常');
            }

            if (isset($data['code'])) {
                throw PayException::gatewayError(
                    $data['message'] ?? '微信支付 V3 业务失败',
                    (string) ($data['code'] ?? ''),
                );
            }

            return $data;
        }

        $data = $this->xmlToArray($response);

        if (!isset($data['return_code'])) {
            throw PayException::gatewayError('微信支付响应格式异常');
        }

        if ($data['return_code'] !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['return_msg'] ?? '微信支付通信失败',
                $data['return_code'],
            );
        }

        if (isset($data['result_code']) && $data['result_code'] !== 'SUCCESS') {
            throw PayException::gatewayError(
                $data['err_code_des'] ?? '微信支付业务失败',
                $data['err_code'] ?? '',
            );
        }

        // 验证响应签名
        if (isset($data['sign']) && !Signer::verifyMd5($data, $this->getConfig('api_key'))) {
            throw PayException::signError('微信支付响应签名验证失败');
        }

        return $data;
    }

    /**
     * 数组转 XML
     *
     * @param array<string, mixed> $data
     * @return string
     */
    protected function arrayToXml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            $xml .= is_numeric($val) ? "<{$key}>{$val}</{$key}>" : "<{$key}><![CDATA[{$val}]]></{$key}>";
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * XML 转数组
     *
     * @param string $xml
     * @return array<string, mixed>
     */
    protected function xmlToArray(string $xml): array
    {
        $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);

        if ($element === false) {
            return [];
        }

        $json = json_encode($element);

        if ($json === false) {
            return [];
        }

        $decoded = $this->decodeJson($json);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 生成随机字符串
     *
     * @param int $length 长度
     * @return string
     */
    protected function generateNonceStr(int $length = 32): string
    {
        return bin2hex(random_bytes(max(1, intdiv($length, 2))));
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起微信分账
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['transaction_id', 'out_order_no', 'receivers']);

        /** @var array<int, Receiver|array<string, mixed>> $receivers */
        $receivers = $params['receivers'];
        $mapped = array_map(static function ($r): array {
            return $r instanceof Receiver
                ? $r->toWechatArray()
                : [
                    'type' => $r['type'],
                    'account' => $r['account'],
                    'amount' => (int) $r['amount'],
                    'description' => $r['description'] ?? '分账',
                ];
        }, $receivers);

        return $this->signedV2Post('secapi/pay/profitsharing', [
            'transaction_id' => $params['transaction_id'],
            'out_order_no' => $params['out_order_no'],
            'receivers' => json_encode($mapped, JSON_UNESCAPED_UNICODE),
        ], true);
    }

    /**
     * 查询微信分账结果
     *
     * 微信要求 transaction_id 与 out_order_no 同时传入，故补可选第二参数。
     *
     * @param string $outOrderNo 商户分账订单号
     * @param string|null $transactionId 原支付订单号（微信必填，缺省则不在报文中携带）
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $data = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_order_no' => $outOrderNo,
        ];

        if ($transactionId !== null && $transactionId !== '') {
            $data['transaction_id'] = $transactionId;
        }

        return $this->signedV2Post('pay/profitsharingquery', $data);
    }

    /**
     * 微信分账回退
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function returnProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        return $this->signedV2Post('secapi/pay/profitsharingreturn', [
            'out_order_no' => $params['out_order_no'],
            'out_return_no' => $params['out_return_no'],
            'return_account_type' => $params['return_account_type'] ?? 'MERCHANT_ID',
            'return_account' => $params['return_account'] ?? '',
            'return_amount' => (int) $params['return_amount'],
            'description' => $params['description'] ?? '分账回退',
        ], true);
    }

    /**
     * 查询微信分账回退结果
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        return $this->signedV2Post('pay/profitsharingreturnquery', [
            'out_return_no' => $outReturnNo,
        ]);
    }

    /**
     * 解冻微信未分账的剩余资金
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选）
     * @return array<string, mixed>
     */
    #[\Override]
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        return $this->signedV2Post('secapi/pay/profitsharingfinish', [
            'transaction_id' => $transactionId,
            'out_order_no' => $outOrderNo ?? ('UNFREEZE_' . time()),
            'description' => '解冻剩余资金',
        ], true);
    }

    /**
     * 微信分账配置查询（最大分账比例与分账关系）
     *
     * @param string $outOrderNo 商户分账订单号
     * @param string|null $transactionId 原支付订单号
     * @return array<string, mixed>
     */
    public function queryProfitSharingConfig(string $outOrderNo, ?string $transactionId = null): array
    {
        $data = ['out_order_no' => $outOrderNo];
        if ($transactionId !== null) {
            $data['transaction_id'] = $transactionId;
        }

        return $this->signedV2Post('pay/profitsharingconfigquery', $data);
    }

    /**
     * 添加微信分账接收方
     *
     * @param array<string, mixed> $receiver
     * @return array<string, mixed>
     */
    public function addProfitSharingReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['type', 'account', 'name']);

        return $this->signedV2Post('pay/profitsharingaddreceiver', [
            'receiver' => json_encode([
                'type' => $receiver['type'],
                'account' => $receiver['account'],
                'name' => $receiver['name'],
                'relation_type' => $receiver['relation_type'] ?? 'SERVICE_PROVIDER',
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 删除微信分账接收方
     *
     * @param array<string, mixed> $receiver
     * @return array<string, mixed>
     */
    public function removeProfitSharingReceiver(array $receiver): array
    {
        $this->validateRequired($receiver, ['type', 'account']);

        return $this->signedV2Post('pay/profitsharingremovereceiver', [
            'receiver' => json_encode([
                'type' => $receiver['type'],
                'account' => $receiver['account'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== 自动结算能力（SettlementCapableInterface） ==================== */

    /**
     * 结算到微信零钱（复用企业付款到零钱通道）
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
                'type' => 'openid',
                'account' => $params['account'],
                'name' => $params['real_name'] ?? '',
            ],
            'description' => $params['description'] ?? '自动结算',
            'client_ip' => $params['client_ip'] ?? '127.0.0.1',
        ]);
    }

    /**
     * 结算到银行卡（复用企业付款到银行卡通道，卡号与姓名走 RSA 加密）
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
            'description' => $params['description'] ?? '自动结算到银行卡',
        ]);
    }

    /**
     * 微信支付无外部账户 Payout 语义，调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function settleToPayout(array $params): array
    {
        throw PayException::methodNotSupported('wechat', 'settleToPayout');
    }

    /**
     * 查询结算结果（复用转账批次查询）
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function querySettlement(string $outBizNo): array
    {
        return $this->queryTransfer($outBizNo);
    }

    /* ==================== 订阅能力（SubscriptionCapableInterface，委托代扣 papay） ==================== */

    /**
     * 微信「委托代扣」模板（plan_id）只能在商户平台后台配置，无开放接口，
     * 调用即报「无此方法」
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createPlan(array $params): array
    {
        throw PayException::methodNotSupported('wechat', 'createPlan');
    }

    /**
     * 创建订阅（papay/entrustweb 公众号纯签约）
     *
     * 微信委托代扣需用户在微信内完成签约授权，故本方法返回可跳转的签约链接，
     * 而非同步的订阅实体；签约结果由 notify_url 异步回调，或用
     * {@see getSubscription()} 以委托代扣协议号查询。
     *
     * 签名参与字节与实际发送的查询串一致（MD5 + api_key）。
     *
     * @param array<string, mixed> $params 订阅参数
     *        - customer_id: 商户侧签约协议号（映射 contract_code，用户维度唯一）
     *        - plan_id: 商户平台配置的模板 ID
     *        - contract_display_account: 用户账户展示名（可选，默认取 customer_id）
     *        - notify_url: 签约结果回调地址
     *        - request_serial: 请求序列号（可选，默认取当前时间戳）
     *        - return_web / return_app_id: 签约完成跳转参数（可选）
     * @return array<string, mixed> 含 method / url 的跳转描述
     * @throws PayException
     */
    #[\Override]
    public function createSubscription(array $params): array
    {
        $this->validateRequired($params, ['customer_id', 'plan_id', 'notify_url']);

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'plan_id' => $params['plan_id'],
            'contract_code' => $params['customer_id'],
            'request_serial' => (string) ($params['request_serial'] ?? time()),
            'contract_display_account' => $params['contract_display_account'] ?? $params['customer_id'],
            'notify_url' => $params['notify_url'],
            'version' => '1.0',
            'timestamp' => (string) time(),
        ];

        if (isset($params['return_web'])) {
            $requestData['return_web'] = $params['return_web'];
        }

        if (isset($params['return_app_id'])) {
            $requestData['return_appid'] = $params['return_app_id'];
        }

        $requestData['sign'] = Signer::md5($requestData, (string) $this->getConfig('api_key'));

        return [
            'method' => 'GET',
            'url' => $this->getBaseUrl() . 'papay/entrustweb?' . http_build_query($requestData),
            'contract_code' => $params['customer_id'],
            'plan_id' => $params['plan_id'],
        ];
    }

    /**
     * 取消订阅（papay/deletecontract 申请解约）
     *
     * @param string $subscriptionId 委托代扣协议号（contract_id）；
     *        以 `plan:{plan_id}:{contract_code}` 形式传入时按模板 + 商户协议号解约
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function cancelSubscription(string $subscriptionId): array
    {
        $requestData = array_merge([
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'version' => '1.0',
        ], $this->buildContractIdentity($subscriptionId), [
            'contract_termination_remark' => '用户申请解约',
        ]);

        return $this->signedV2Post('papay/deletecontract', $requestData);
    }

    /**
     * 微信委托代扣无「暂停」端点，调用即报「无此方法」
     *
     * 委托代扣由商户按需发起 {@see payWithContract()} 扣款，
     * 停止扣款只需不再发起请求，或直接解约。
     *
     * @param string $subscriptionId 协议号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function pauseSubscription(string $subscriptionId): array
    {
        throw PayException::methodNotSupported('wechat', 'pauseSubscription');
    }

    /**
     * 微信委托代扣无「恢复」端点，调用即报「无此方法」
     *
     * @param string $subscriptionId 协议号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function resumeSubscription(string $subscriptionId): array
    {
        throw PayException::methodNotSupported('wechat', 'resumeSubscription');
    }

    /**
     * 查询订阅详情（papay/querycontract 查询签约关系）
     *
     * @param string $subscriptionId 委托代扣协议号（contract_id）；
     *        以 `plan:{plan_id}:{contract_code}` 形式传入时按模板 + 商户协议号查询
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function getSubscription(string $subscriptionId): array
    {
        $requestData = array_merge([
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'version' => '1.0',
        ], $this->buildContractIdentity($subscriptionId));

        return $this->signedV2Post('papay/querycontract', $requestData);
    }

    /**
     * 委托代扣申请扣款（pay/pappayapply）
     *
     * 签约成功后由商户按周期主动发起扣款，微信侧异步返回扣款结果，
     * 可用 {@see queryContractOrder()} 查询最终状态。
     *
     * @param array<string, mixed> $params 扣款参数
     *        - out_trade_no: 商户订单号
     *        - total_fee: 扣款金额（分）
     *        - body: 商品描述
     *        - contract_id: 委托代扣协议号
     *        - notify_url: 扣款结果回调地址
     * @return array<string, mixed>
     * @throws PayException
     */
    public function payWithContract(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_fee', 'body', 'contract_id', 'notify_url']);

        $requestData = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'body' => $params['body'],
            'out_trade_no' => $params['out_trade_no'],
            'total_fee' => (int) $params['total_fee'],
            'spbill_create_ip' => $params['client_ip'] ?? '127.0.0.1',
            'notify_url' => $params['notify_url'],
            'trade_type' => 'PAP',
            'contract_id' => $params['contract_id'],
        ];

        return $this->signedV2Post('pay/pappayapply', $requestData);
    }

    /**
     * 查询委托代扣订单（pay/paporderquery）
     *
     * @param string $outTradeNo 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryContractOrder(string $outTradeNo): array
    {
        return $this->signedV2Post('pay/paporderquery', [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_trade_no' => $outTradeNo,
        ]);
    }

    /**
     * 解析签约关系标识
     *
     * 默认按微信委托代扣协议号（contract_id）；传入 `plan:{plan_id}:{contract_code}`
     * 时按「模板 ID + 商户协议号」定位，对应微信文档的二选一入参。
     *
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function buildContractIdentity(string $subscriptionId): array
    {
        if (!str_starts_with($subscriptionId, 'plan:')) {
            return ['contract_id' => $subscriptionId];
        }

        $segments = explode(':', $subscriptionId, 3);
        if (count($segments) !== 3 || $segments[1] === '' || $segments[2] === '') {
            throw PayException::paramError('委托代扣标识格式应为 plan:{plan_id}:{contract_code}');
        }

        return [
            'plan_id' => $segments[1],
            'contract_code' => $segments[2],
        ];
    }
}
