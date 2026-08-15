<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\UnionPay;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ProfitSharing\Receiver;

/**
 * 云闪付网关
 *
 * 支持 App、H5、小程序、二维码等支付场景；分账与个人收款作为网关「特色方法」实现于本类内部
 * （复用基类配置、RSA 签名与 HTTP 通道），并通过 {@see ProfitSharingCapableInterface}
 * 与 {@see PersonalReceiveCapableInterface} 暴露，
 * 可被统一入口 {@see \Kode\Pays\Facade\Pay::call()} 直接调用。
 */
class UnionPayGateway extends AbstractGateway implements
    ProfitSharingCapableInterface,
    PersonalReceiveCapableInterface,
    WebhookCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://gateway.test.95516.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://gateway.95516.com/';

    /**
     * 分账（商户分账）全渠道交易类型/子类/产品码
     *
     * 银联全渠道「商户分账」采用后台交易（backTransReq.do）报文内嵌 accSplitData 分账域，
     * 无独立 /profitSharing.do 端点。下方交易类型为「资金类/代收」族，具体产品码（bizType）
     * 与各收单机构/银联商户分账服务配置强相关，
     * ⚠️ 投产前须按本商户签约的「商户分账」产品参数联调确认。
     */
    private const PS_TXN_TYPE = '11';
    private const PS_TXN_SUB_TYPE = '00';
    private const PS_BIZ_TYPE = '000000';
    private const PS_RETURN_TXN_TYPE = '04';
    private const PS_RETURN_SUB_TYPE = '00';
    private const PS_FINISH_TXN_TYPE = '11';
    private const PS_FINISH_SUB_TYPE = '00';

    /**
     * 个人收款（二维码消费）与代付提现的交易类型/子类/产品码
     *
     * 二维码消费走后台交易 backTransReq.do（txnType=01、txnSubType=07、bizType=000000）；
     * 提现走代付产品（txnType=12、bizType=000401）。
     * ⚠️ 代付产品需单独签约开通，投产前须按本商户签约参数联调确认。
     */
    private const QR_TXN_TYPE = '01';
    private const QR_TXN_SUB_TYPE = '07';
    private const QR_BIZ_TYPE = '000000';
    private const WITHDRAW_TXN_TYPE = '12';
    private const WITHDRAW_SUB_TYPE = '00';
    private const WITHDRAW_BIZ_TYPE = '000401';

    /**
     * 缓存的证书私钥对象（懒加载，避免每次签名/验签重复读盘与解析 PEM）
     */
    private ?\OpenSSLAsymmetricKey $certKey = null;

    /**
     * 缓存的验签公钥对象（银联公钥证书，懒加载）
     *
     * 银联异步通知由「银联平台」签名，商户须使用银联下发的公钥证书验签，
     * 与商户自身签名证书（cert_path，含商户私钥）相互独立。
     */
    private ?\OpenSSLAsymmetricKey $verifyCertKey = null;

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['mer_id', 'cert_path', 'cert_pwd']);
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
        $this->validateRequired($params, ['orderId', 'txnAmt', 'currency']);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'txnType' => '01',
            'txnSubType' => '01',
            'bizType' => '000201',
            'signMethod' => '01',
            'channelType' => '08',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $params['orderId'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => $params['txnAmt'],
            'currencyCode' => $params['currency'],
            'frontUrl' => $params['frontUrl'] ?? '',
            'backUrl' => $params['backUrl'] ?? '',
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/frontTransReq.do', $requestData);
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
        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => '000000',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $orderId,
            'txnTime' => date('YmdHis'),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/queryTrans.do', $requestData);
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
        $this->validateRequired($params, ['orderId', 'origQryId', 'txnAmt']);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '04',
            'txnSubType' => '00',
            'bizType' => '000201',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $params['orderId'],
            'origQryId' => $params['origQryId'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => $params['txnAmt'],
            'backUrl' => $params['backUrl'] ?? '',
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/backTransReq.do', $requestData);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        return $this->queryOrder($refundId);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<int|string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        if (!isset($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        return $this->verify($data, $signature);
    }

    /**
     * 验证 Webhook 原始请求签名（与运行时解耦版本）
     *
     * 复用 {@see verifyNotify()} 的 SHA256 证书验签逻辑，但接收原始报文与请求头，
     * 不再依赖全局 `$_SERVER` / `php://input`。银联通知签名在报文体内，
     * 验签使用银联公钥证书（见 {@see loadVerifyCert()}）。
     *
     * @param string $payload 原始请求体（form-urlencoded）
     * @param array<string, string> $headers 请求头（银联通知签名在报文体内，未使用）
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        if ($payload === '') {
            return false;
        }

        parse_str($payload, $data);

        return $this->verifyNotify((array) $data);
    }

    /**
     * 解析 Webhook 原始请求体为统一事件结构
     *
     * @param string $payload 原始请求体（form-urlencoded）
     * @return array<string, mixed>
     */
    public function parseWebhook(string $payload): array
    {
        parse_str($payload, $data);
        $data = (array) $data;

        return [
            'gateway' => 'unionpay',
            'event_id' => $data['queryId'] ?? null,
            'event_type' => $data['respCode'] ?? 'unknown',
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
        throw PayException::gatewayError('云闪付暂不支持主动关闭订单');
    }

    /**
     * 发起银联分账
     *
     * 将一笔已支付订单的金额按接收方列表进行分账。接收方金额按最小货币单位（分，txnAmt）上报。
     *
     * @param array<string, mixed> $params 分账参数
     *        - transaction_id: 原支付交易查询流水号（origQryId）
     *        - out_order_no: 商户分账订单号（作为银联 orderId）
     *        - receivers: 接收方列表 [{type, account, amount(分), description}]
     *        - txnAmt: 分账总金额（可选，缺省取接收方合计）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'transaction_id', 'receivers']);

        $receivers = $this->mapReceivers((array) $params['receivers'], 'unionpay');
        $txnAmt = isset($params['txnAmt']) ? (int) $params['txnAmt'] : $this->sumReceiverAmount($receivers);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => self::PS_TXN_TYPE,
            'txnSubType' => self::PS_TXN_SUB_TYPE,
            'bizType' => self::PS_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $params['out_order_no'],
            'origQryId' => $params['transaction_id'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => $txnAmt,
            'accSplitData' => $this->buildAccSplitData($receivers),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/backTransReq.do', $requestData);
    }

    /**
     * 查询银联分账结果
     *
     * @param string $outOrderNo 商户分账订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => self::PS_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $outOrderNo,
            'txnTime' => date('YmdHis'),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/queryTrans.do', $requestData);
    }

    /**
     * 银联分账回退
     *
     * @param array<string, mixed> $params 回退参数
     *        - out_order_no: 商户分账订单号
     *        - out_return_no: 商户回退单号
     *        - return_amount: 回退金额（分）
     *        - transaction_id: 原交易查询流水号（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function returnProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        $receivers = isset($params['receivers'])
            ? $this->mapReceivers((array) $params['receivers'], 'unionpay')
            : [];

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => self::PS_RETURN_TXN_TYPE,
            'txnSubType' => self::PS_RETURN_SUB_TYPE,
            'bizType' => self::PS_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $params['out_return_no'],
            'origQryId' => $params['out_order_no'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => (int) $params['return_amount'],
            'accSplitData' => $this->buildAccSplitData($receivers),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/backTransReq.do', $requestData);
    }

    /**
     * 查询银联分账回退结果
     *
     * @param string $outReturnNo 商户回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => self::PS_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $outReturnNo,
            'txnTime' => date('YmdHis'),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/queryTrans.do', $requestData);
    }

    /**
     * 解冻银联未分账的剩余资金
     *
     * @param string $transactionId 原支付交易查询流水号
     * @param string|null $outOrderNo 商户解冻单号（可选，缺省自动生成）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => self::PS_FINISH_TXN_TYPE,
            'txnSubType' => self::PS_FINISH_SUB_TYPE,
            'bizType' => self::PS_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $outOrderNo ?? ('UNFREEZE_' . $transactionId . '_' . time()),
            'origQryId' => $transactionId,
            'txnTime' => date('YmdHis'),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/backTransReq.do', $requestData);
    }

    /* ==================== 个人收款能力（PersonalReceiveCapableInterface） ==================== */

    /**
     * 生成个人收款二维码（银联全渠道二维码消费）
     *
     * 金额单位为分（`txnAmt`），与银联原生一致，不做换算。
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        $orderId = (string) ($params['out_trade_no'] ?? 'PR' . date('YmdHis') . random_int(1000, 9999));

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => self::QR_TXN_TYPE,
            'txnSubType' => self::QR_TXN_SUB_TYPE,
            'bizType' => self::QR_BIZ_TYPE,
            'channelType' => '07',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $orderId,
            'txnTime' => date('YmdHis'),
            'txnAmt' => (string) (int) $params['amount'],
            'currencyCode' => $params['currency'] ?? '156',
            'orderDesc' => (string) $params['description'],
            'backUrl' => $params['notify_url'] ?? '',
        ];

        if (isset($params['expire_seconds'])) {
            $requestData['payTimeout'] = date('YmdHis', time() + (int) $params['expire_seconds']);
        }

        if (!empty($params['attach'])) {
            $requestData['reqReserved'] = is_string($params['attach'])
                ? $params['attach']
                : (string) json_encode($params['attach'], JSON_UNESCAPED_UNICODE);
        }

        $requestData['signature'] = $this->sign($requestData);

        $response = $this->post('gateway/api/backTransReq.do', $requestData);

        return [
            'out_trade_no' => $orderId,
            'qr_code' => $response['qrCode'] ?? '',
            'query_id' => $response['queryId'] ?? '',
            'amount' => (int) $params['amount'],
            'description' => (string) $params['description'],
        ];
    }

    /**
     * 查询个人收款记录
     *
     * 银联全渠道无「交易列表」开放接口，仅支持按商户订单号逐笔查询
     * （批量对账请使用对账文件下载）。因此本方法要求传入 `out_trade_no`。
     *
     * @param array<string, mixed> $params 查询参数，须含 `out_trade_no`
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryRecords(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no']);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => self::QR_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => (string) $params['out_trade_no'],
            'txnTime' => (string) ($params['txn_time'] ?? date('YmdHis')),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/queryTrans.do', $requestData);
    }

    /**
     * 提现到银行卡（银联代付）
     *
     * 金额单位为分。收款账号需按银联要求做敏感信息加密的，由接入方在
     * `account_encrypted` 传入密文；未传时按 `bank_card_no` 明文上报（仅测试环境）。
     *
     * @param array<string, mixed> $params 提现参数
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'bank_card_no', 'real_name']);

        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => self::WITHDRAW_TXN_TYPE,
            'txnSubType' => self::WITHDRAW_SUB_TYPE,
            'bizType' => self::WITHDRAW_BIZ_TYPE,
            'channelType' => '07',
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => (string) $params['out_biz_no'],
            'txnTime' => date('YmdHis'),
            'txnAmt' => (string) (int) $params['amount'],
            'currencyCode' => $params['currency'] ?? '156',
            'accType' => $params['acc_type'] ?? '01',
            'accNo' => (string) ($params['account_encrypted'] ?? $params['bank_card_no']),
            'customerInfo' => $this->buildWithdrawCustomerInfo($params),
            'backUrl' => $params['notify_url'] ?? '',
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/backTransReq.do', $requestData);
    }

    /**
     * 查询提现结果
     *
     * @param string $outBizNo 商户提现单号
     * @return array<string, mixed>
     * @throws PayException
     */
    #[\Override]
    public function queryWithdraw(string $outBizNo): array
    {
        $requestData = [
            'version' => '5.1.0',
            'encoding' => 'utf-8',
            'signMethod' => '01',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => self::WITHDRAW_BIZ_TYPE,
            'accessType' => '0',
            'merId' => $this->getConfig('mer_id'),
            'orderId' => $outBizNo,
            'txnTime' => date('YmdHis'),
        ];

        $requestData['signature'] = $this->sign($requestData);

        return $this->post('gateway/api/queryTrans.do', $requestData);
    }

    /**
     * 组装代付收款人信息域（banse64 编码的 key=value 串）
     *
     * @param array<string, mixed> $params
     * @throws PayException
     */
    protected function buildWithdrawCustomerInfo(array $params): string
    {
        $info = [
            'customerNm' => (string) $params['real_name'],
        ];

        if (isset($params['cert_type'], $params['cert_no'])) {
            $info['certifTp'] = (string) $params['cert_type'];
            $info['certifId'] = (string) $params['cert_no'];
        }

        if (isset($params['phone'])) {
            $info['phoneNo'] = (string) $params['phone'];
        }

        $pairs = [];
        foreach ($info as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return base64_encode('{' . implode('&', $pairs) . '}');
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'unionpay';
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
            throw PayException::gatewayError('云闪付响应格式异常');
        }

        if (!isset($data['respCode'])) {
            throw PayException::gatewayError('云闪付响应缺少状态码');
        }

        if ($data['respCode'] !== '00') {
            throw PayException::gatewayError(
                $data['respMsg'] ?? '云闪付业务失败',
                $data['respCode'],
            );
        }

        return $data;
    }

    /**
     * 签名
     *
     * @param array<string, mixed> $params 待签名参数
     * @return string 签名结果
     * @throws PayException
     */
    protected function sign(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $string = implode('&', $pairs);

        $privateKey = $this->loadCertKey();

        if (openssl_sign($string, $signature, $privateKey, OPENSSL_ALGO_SHA256) === false) {
            throw PayException::configError('云闪付签名失败，请检查证书配置');
        }

        return base64_encode($signature);
    }

    /**
     * 加载并缓存证书私钥对象
     *
     * 证书（.pfx / PEM）仅在首次签名或验签时读取并解析一次，后续复用，
     * 避免每次请求都读盘与解析带来的性能损耗。
     *
     * @return \OpenSSLAsymmetricKey
     * @throws PayException
     */
    protected function loadCertKey(): \OpenSSLAsymmetricKey
    {
        if ($this->certKey !== null) {
            return $this->certKey;
        }

        $cert = file_get_contents($this->getConfig('cert_path'));
        if ($cert === false) {
            throw PayException::configError('无法读取云闪付证书文件');
        }

        $key = openssl_pkey_get_private($cert, (string) ($this->getConfig('cert_pwd') ?? ''));
        if ($key === false) {
            throw PayException::configError('云闪付证书加载失败，请检查 cert_path 与 cert_pwd');
        }

        return $this->certKey = $key;
    }

    /**
     * 验签
     *
     * @param array<int|string, mixed> $params 待验证参数
     * @param string $signature 签名值
     * @return bool
     * @throws PayException
     */
    protected function verify(array $params, string $signature): bool
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $string = implode('&', $pairs);

        $publicKey = $this->loadVerifyCert();

        return openssl_verify($string, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 加载并缓存验签公钥（银联公钥证书）对象
     *
     * 银联异步通知由「银联平台」私钥签名，商户须以银联下发的公钥证书（verify_cert_path）
     * 验签，与商户自身签名证书（cert_path，含商户私钥）相互独立。
     * 若未单独配置 verify_cert_path，则回退到 cert_path（兼容同一证书自签自验场景）。
     *
     * @return \OpenSSLAsymmetricKey
     * @throws PayException
     */
    protected function loadVerifyCert(): \OpenSSLAsymmetricKey
    {
        if ($this->verifyCertKey !== null) {
            return $this->verifyCertKey;
        }

        $certPath = $this->getConfig('verify_cert_path') ?? $this->getConfig('cert_path');
        $cert = file_get_contents((string) $certPath);
        if ($cert === false) {
            throw PayException::configError('无法读取云闪付验签证书文件');
        }

        $key = openssl_pkey_get_public($cert);
        if ($key === false) {
            throw PayException::configError('云闪付验签证书加载失败，请检查 verify_cert_path（需为银联公钥证书）或 cert_path');
        }

        return $this->verifyCertKey = $key;
    }

    /**
     * 将接收方列表（Receiver DTO 或数组）映射为银联分账参数
     *
     * 金额统一按最小货币单位（分，txnAmt）上报。
     *
     * @param array<int, Receiver|array<string, mixed>> $receivers
     * @param 'douyin'|'unionpay' $platform
     * @return array<int, array<string, mixed>>
     */
    protected function mapReceivers(array $receivers, string $platform): array
    {
        return array_map(static function ($r) use ($platform): array {
            if ($r instanceof Receiver) {
                return $platform === 'unionpay' ? $r->toUnionPayArray() : $r->toDouyinArray();
            }

            return [
                'merchant_uid' => $r['merchant_uid'] ?? $r['account'] ?? '',
                'amount' => (int) ($r['amount'] ?? 0),
            ];
        }, $receivers);
    }

    /**
     * 计算接收方金额合计（最小货币单位）
     *
     * @param array<int, array<string, mixed>> $receivers
     * @return int
     */
    protected function sumReceiverAmount(array $receivers): int
    {
        $sum = 0;
        foreach ($receivers as $r) {
            $sum += (int) ($r['amount'] ?? 0);
        }

        return $sum;
    }

    /**
     * 构建银联全渠道 accSplitData 分账域
     *
     * 分账接收方经 accSplitData 分账域承载，格式为「笔数^接收方1^接收方2…」，
     * 每个接收方以「商户号|分账金额(分)」表示（字段分隔符 |，接收方分隔符 ^）：
     *   accSplitData = "{cnt}^{merchant_uid1}|{amount1}^{merchant_uid2}|{amount2}"
     *
     * ⚠️ 该子格式为银联商户分账服务配置相关项，投产前须按签约产品参数联调确认。
     *
     * @param array<int, array<string, mixed>> $receivers
     * @return string
     */
    protected function buildAccSplitData(array $receivers): string
    {
        $items = [];
        foreach ($receivers as $r) {
            $items[] = ($r['merchant_uid'] ?? $r['account'] ?? '') . '|' . ($r['amount'] ?? 0);
        }

        return count($receivers) . '^' . implode('^', $items);
    }
}
