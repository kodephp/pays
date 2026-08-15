<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Douyin;

use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Signer;

/**
 * 抖音支付网关
 *
 * 支持 App、小程序等场景的支付接入；分账作为网关「特色方法」实现于本类内部
 * （复用基类配置、MD5 签名与 HTTP 通道），并通过 {@see ProfitSharingCapableInterface} 暴露，
 * 可被统一入口 {@see \Kode\Pays\Facade\Pay::call()} 直接调用。
 */
class DouyinPayGateway extends AbstractGateway implements ProfitSharingCapableInterface, WebhookCapableInterface
{
    /**
     * 测试环境基础 URL
     */
    protected const TEST_BASE_URL = 'https://developer-sandbox.toutiao.com/';

    /**
     * 生产环境基础 URL
     */
    protected const PROD_BASE_URL = 'https://developer.toutiao.com/';

    /**
     * 初始化
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'merchant_id', 'salt']);
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
        $this->validateRequired($params, ['out_order_no', 'total_amount', 'subject', 'body', 'valid_time']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_order_no' => $params['out_order_no'],
            'total_amount' => $params['total_amount'],
            'subject' => $params['subject'],
            'body' => $params['body'],
            'valid_time' => $params['valid_time'],
            'notify_url' => $params['notify_url'] ?? '',
            'disable_msg' => $params['disable_msg'] ?? 0,
            'msg_page' => $params['msg_page'] ?? '',
        ];

        if (isset($params['expand_order_info'])) {
            $requestData['expand_order_info'] = json_encode($params['expand_order_info']);
        }

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/create_order', $requestData, [
            'Content-Type' => 'application/json',
        ]);
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
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_order_no' => $orderId,
        ];

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/query_order', $requestData, [
            'Content-Type' => 'application/json',
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
        $this->validateRequired($params, ['out_refund_no', 'refund_amount', 'reason']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_refund_no' => $params['out_refund_no'],
            'refund_amount' => $params['refund_amount'],
            'reason' => $params['reason'],
        ];

        if (isset($params['out_order_no'])) {
            $requestData['out_order_no'] = $params['out_order_no'];
        }

        if (isset($params['cp_extra'])) {
            $requestData['cp_extra'] = $params['cp_extra'];
        }

        if (isset($params['notify_url'])) {
            $requestData['notify_url'] = $params['notify_url'];
        }

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/create_refund', $requestData, [
            'Content-Type' => 'application/json',
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
        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_refund_no' => $refundId,
        ];

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/query_refund', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 验证异步通知签名
     *
     * @param array<int|string, mixed> $data 通知数据
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
     * 验证 Webhook 原始请求签名（与运行时解耦版本）
     *
     * 复用 {@see verifyNotify()} 的 MD5 验签逻辑（加盐 salt），但接收原始报文，
     * 不再依赖全局 `$_SERVER` / `php://input`。
     *
     * @param string $payload 原始请求体（form-urlencoded 或 JSON）
     * @param array<string, string> $headers 请求头（抖音通知签名在报文体内，未使用）
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        if ($payload === '') {
            return false;
        }

        return $this->verifyNotify($this->parseNotifyPayload($payload));
    }

    /**
     * 解析 Webhook 原始请求体为统一事件结构
     *
     * @param string $payload 原始请求体（form-urlencoded 或 JSON）
     * @return array<string, mixed>
     */
    public function parseWebhook(string $payload): array
    {
        $data = $this->parseNotifyPayload($payload);

        return [
            'gateway' => 'douyin',
            'event_id' => $data['order_id'] ?? $data['out_order_no'] ?? null,
            'event_type' => $data['type'] ?? $data['msg_type'] ?? 'unknown',
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
        throw PayException::gatewayError('抖音支付暂不支持主动关闭订单');
    }

    /**
     * 发起抖音分账（结算及分账）
     *
     * 对应抖音 ecpay「发起结算及分账」接口（api/apps/ecpay/v1/settle）。
     * 一笔订单到达结算周期后，通过本接口将资金结算给各分账方（settle_params）。
     *
     * 通用接口参数 → 抖音 ecpay 真实字段映射：
     * - out_order_no   → out_settle_no  （开发者侧分账单号，幂等，仅数字/大小写字母/_-*）
     * - transaction_id → out_order_no   （原支付订单的商户单号 out_order_no）
     * - receivers      → settle_params  （JSON 数组：[{merchant_uid, amount(分)}]）
     * - settle_desc    → settle_desc    （结算描述，缺省 "分账结算"）
     * - finish         → finish         （'true' 时剩余未分账金额一并结算给商户）
     *
     * ⚠️ 签名沿用 ecpay 家族 MD5 方案；若商户分账服务要求 RSA 签名需切换算法。
     *
     * @param array<string, mixed> $params 分账参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'transaction_id', 'receivers']);

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_settle_no' => $params['out_order_no'],
            'out_order_no' => $params['transaction_id'],
            'settle_desc' => $params['settle_desc'] ?? '分账结算',
            'settle_params' => json_encode(
                $this->mapReceivers((array) $params['receivers'], 'douyin'),
                JSON_UNESCAPED_UNICODE,
            ),
        ];

        foreach (['cp_extra', 'notify_url', 'finish'] as $optional) {
            if (isset($params[$optional])) {
                $requestData[$optional] = $params[$optional];
            }
        }

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/settle', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 查询抖音分账结果（结算及分账结果查询）
     *
     * 对应抖音 ecpay「结算及分账结果查询」接口（api/apps/ecpay/v1/query_settle）。
     * 使用开发者侧分账单号 out_settle_no 查询。
     *
     * @param string $outOrderNo 商户分账订单号（out_settle_no）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryProfitSharing(string $outOrderNo, ?string $transactionId = null): array
    {
        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_settle_no' => $outOrderNo,
        ];

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/query_settle', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 抖音分账回退
     *
     * 抖音 ecpay 无独立「退分账」接口；分账回退由「退款」触发
     * （平台在退款时自动将已分账资金退回商户可提现账户）。
     * 故本方法映射为退款请求：out_return_no→out_refund_no、return_amount→refund_amount、
     * out_order_no→out_order_no，reason 默认「退分账」。
     *
     * @param array<string, mixed> $params 回退参数
     *        - out_order_no: 原支付商户订单号
     *        - out_return_no: 商户回退单号
     *        - return_amount: 回退金额（分）
     *        - reason: 退款原因（可选，默认「退分账」）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function returnProfitSharing(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        return $this->refund([
            'out_order_no' => $params['out_order_no'],
            'out_refund_no' => $params['out_return_no'],
            'refund_amount' => $params['return_amount'],
            'reason' => $params['reason'] ?? '退分账',
        ]);
    }

    /**
     * 查询抖音分账回退结果
     *
     * 抖音分账回退经由退款触发，故回退结果查询映射为退款查询
     * （api/apps/ecpay/v1/query_refund），以商户回退单号（out_refund_no）查询。
     *
     * @param string $outReturnNo 商户回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        return $this->queryRefund($outReturnNo);
    }

    /**
     * 解冻抖音未分账的剩余资金（完结分账）
     *
     * 抖音 ecpay 通过「发起结算及分账」接口的 finish=true 将剩余未分账金额
     * 一并结算给本商户，等价于完结/解冻。此处以原订单号 + 新分账单号发起一次
     * 不带外部分账方（settle_params 为空数组）的结算。
     *
     * @param string $transactionId 原支付订单号（out_order_no）
     * @param string|null $outOrderNo 商户解冻单号（可选，缺省自动生成）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $outSettleNo = $outOrderNo ?? ('UNFREEZE_' . $transactionId . '_' . time());

        $requestData = [
            'app_id' => $this->getConfig('app_id'),
            'merchant_id' => $this->getConfig('merchant_id'),
            'out_settle_no' => $outSettleNo,
            'out_order_no' => $transactionId,
            'settle_desc' => '解冻剩余资金',
            'settle_params' => json_encode([], JSON_UNESCAPED_UNICODE),
            'finish' => 'true',
        ];

        $requestData['sign'] = $this->sign($requestData);
        $requestData['timestamp'] = (string) time();

        return $this->post('api/apps/ecpay/v1/settle', $requestData, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'douyin';
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
            throw PayException::gatewayError('抖音支付响应格式异常');
        }

        if (!isset($data['err_no'])) {
            throw PayException::gatewayError('抖音支付响应缺少状态码');
        }

        if ($data['err_no'] !== 0) {
            throw PayException::gatewayError(
                $data['err_tips'] ?? '抖音支付业务失败',
                (string) $data['err_no'],
            );
        }

        return $data;
    }

    /**
     * 将接收方列表（Receiver DTO 或数组）映射为抖音分账参数
     *
     * 金额统一按最小货币单位（分）上报。
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
                'type' => $r['type'] ?? '',
                'account' => $r['account'] ?? '',
                'amount' => (int) ($r['amount'] ?? 0),
                'description' => $r['description'] ?? '分账',
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
     * 签名
     *
     * 抖音支付使用 MD5 签名，参数按 key 升序拼接后加盐
     *
     * @param array<string, mixed> $params 待签名参数
     * @return string 签名结果
     */
    protected function sign(array $params): string
    {
        $salt = $this->getConfig('salt');

        return md5(Signer::buildQueryString($params) . '&salt=' . $salt);
    }
}
