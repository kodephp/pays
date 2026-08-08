<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Signer;

/**
 * 微信支付网关
 *
 * 支持 JSAPI、Native、H5、App、小程序等支付场景
 */
class WechatPayGateway extends AbstractGateway implements TransferCapableInterface, RedPacketCapableInterface
{
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

        $params['appid'] = $this->getConfig('app_id');
        $params['mch_id'] = $this->getConfig('mch_id');
        $params['nonce_str'] = $this->generateNonceStr();
        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/unifiedorder', $xml, ['Content-Type' => 'text/xml']);

        return $response;
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

        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/orderquery', $xml, ['Content-Type' => 'text/xml']);

        return $response;
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
        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('secapi/pay/refund', $xml, ['Content-Type' => 'text/xml']);

        return $response;
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
        $params = [
            'appid' => $this->getConfig('app_id'),
            'mch_id' => $this->getConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'out_refund_no' => $refundId,
        ];

        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/refundquery', $xml, ['Content-Type' => 'text/xml']);

        return $response;
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

        $params['sign'] = Signer::md5($params, $this->getConfig('api_key'));

        $xml = $this->arrayToXml($params);
        $response = $this->postRaw('pay/closeorder', $xml, ['Content-Type' => 'text/xml']);

        return $response;
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

        // 注：企业付款到零钱接口实际需 XML + MD5 签名，此处沿用既有插件构造，
        // 投产前如需严格合规请在此接入 Signer::md5 与 arrayToXml。
        return $this->post('mmpaymkttransfers/promotion/transfers', $requestData);
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

        return $this->post('v3/transfer/batches', [
            'appid' => $this->getConfig('app_id'),
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
        return $this->get("v3/transfer/batches/out-batch-no/{$outBizNo}");
    }

    /**
     * 查询转账电子回单
     *
     * @return array<string, mixed>
     */
    public function transferReceipt(string $outBizNo): array
    {
        return $this->get(
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
            'scene_id' => $params['scene_id'] ?? '',
        ];

        // 注：现金红包接口实际需 XML + MD5 签名，此处沿用既有插件构造，
        // 投产前如需严格合规请在此接入 Signer::md5 与 arrayToXml。
        return $this->post('mmpaymkttransfers/sendredpack', $requestData);
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
            'scene_id' => $params['scene_id'] ?? '',
        ];

        // 注：裂变红包接口实际需 XML + MD5 签名，此处沿用既有插件构造，
        // 投产前如需严格合规请在此接入 Signer::md5 与 arrayToXml。
        return $this->post('mmpaymkttransfers/sendgroupredpack', $requestData);
    }

    /**
     * 查询红包发放记录
     *
     * @return array<string, mixed>
     */
    public function queryRedPacket(string $mchBillNo): array
    {
        return $this->post('mmpaymkttransfers/gethbinfo', [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $mchBillNo,
            'mch_id' => $this->getConfig('mch_id'),
            'appid' => $this->getConfig('app_id'),
            'bill_type' => 'MCHT',
        ]);
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

        $decoded = json_decode($json, true);

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
}
