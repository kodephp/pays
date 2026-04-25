<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 红包插件
 *
 * 为支持红包/现金红包的网关提供统一的红包发放管理能力。
 * 支持普通红包、裂变红包、查询红包记录。
 *
 * 支持网关：
 * - 微信支付（现金红包、裂变红包）
 * - 支付宝（商家红包、现金红包）
 *
 * 使用示例：
 * ```php
 * $plugin = new RedPacketPlugin($wechatGateway);
 *
 * // 发放普通红包
 * $result = $plugin->send([
 *     'mch_billno' => 'REDPACK_' . date('YmdHis'),
 *     'send_name'  => '某某公司',
 *     're_openid'  => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
 *     'total_amount' => 100,
 *     'total_num'    => 1,
 *     'wishing'      => '恭喜发财',
 *     'act_name'     => '新年活动',
 *     'remark'       => '参与活动领取红包',
 * ]);
 *
 * // 发放裂变红包
 * $result = $plugin->group([
 *     'mch_billno' => 'GROUP_' . date('YmdHis'),
 *     'send_name'  => '某某公司',
 *     're_openid'  => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
 *     'total_amount' => 100,
 *     'total_num'    => 3,
 *     'wishing'      => '裂变红包',
 *     'act_name'     => '分享活动',
 *     'remark'       => '分享给好友领取',
 * ]);
 *
 * // 查询红包记录
 * $result = $plugin->query('REDPACK_20240425000001');
 * ```
 */
class RedPacketPlugin
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关
     */
    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 发放普通红包
     *
     * @param array<string, mixed> $params 红包参数
     *        - mch_billno: 商户红包单号
     *        - send_name: 商户名称
     *        - re_openid: 接收用户 OpenID
     *        - total_amount: 红包总金额（微信单位为分）
     *        - total_num: 红包发放总人数（普通红包为 1）
     *        - wishing: 红包祝福语
     *        - act_name: 活动名称
     *        - remark: 备注
     *        - scene_id: 场景 ID（可选，PRODUCT_1~PRODUCT_8）
     * @return array<string, mixed> 发放结果
     * @throws PayException
     */
    public function send(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'wishing', 'act_name', 'remark']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->sendWechatRedPacket($params),
            'alipay' => $this->sendAlipayRedPacket($params),
            default => throw PayException::invalidArgument('当前网关不支持红包功能'),
        };
    }

    /**
     * 发放裂变红包
     *
     * @param array<string, mixed> $params 裂变红包参数
     *        - mch_billno: 商户红包单号
     *        - send_name: 商户名称
     *        - re_openid: 接收用户 OpenID（种子用户）
     *        - total_amount: 红包总金额（微信单位为分）
     *        - total_num: 红包发放总人数（>=3）
     *        - wishing: 红包祝福语
     *        - act_name: 活动名称
     *        - remark: 备注
     * @return array<string, mixed>
     * @throws PayException
     */
    public function group(array $params): array
    {
        $this->validateRequired($params, ['mch_billno', 'send_name', 're_openid', 'total_amount', 'total_num', 'wishing', 'act_name', 'remark']);

        if ((int) $params['total_num'] < 3) {
            throw PayException::paramError('裂变红包 total_num 必须 >= 3');
        }

        return match ($this->gateway::getName()) {
            'wechat' => $this->sendWechatGroupRedPacket($params),
            'alipay' => $this->sendAlipayGroupRedPacket($params),
            default => throw PayException::invalidArgument('当前网关不支持裂变红包'),
        };
    }

    /**
     * 查询红包记录
     *
     * @param string $mchBillNo 商户红包单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $mchBillNo): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatRedPacket($mchBillNo),
            'alipay' => $this->queryAlipayRedPacket($mchBillNo),
            default => throw PayException::invalidArgument('当前网关不支持红包查询'),
        };
    }

    /* ==================== 微信红包实现 ==================== */

    /**
     * 微信发放普通红包
     */
    protected function sendWechatRedPacket(array $params): array
    {
        $requestData = [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $params['mch_billno'],
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'wxappid' => $this->getGatewayConfig('app_id'),
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

        return $this->gateway->post('mmpaymkttransfers/sendredpack', $requestData);
    }

    /**
     * 微信发放裂变红包
     */
    protected function sendWechatGroupRedPacket(array $params): array
    {
        $requestData = [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $params['mch_billno'],
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'wxappid' => $this->getGatewayConfig('app_id'),
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

        return $this->gateway->post('mmpaymkttransfers/sendgroupredpack', $requestData);
    }

    /**
     * 查询微信红包记录
     */
    protected function queryWechatRedPacket(string $mchBillNo): array
    {
        return $this->gateway->post('mmpaymkttransfers/gethbinfo', [
            'nonce_str' => $this->generateNonceStr(),
            'mch_billno' => $mchBillNo,
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'appid' => $this->getGatewayConfig('app_id'),
            'bill_type' => 'MCHT',
        ]);
    }

    /* ==================== 支付宝红包实现 ==================== */

    /**
     * 支付宝发放普通红包
     */
    protected function sendAlipayRedPacket(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.coupon.order.app.pay',
            'biz_content' => json_encode([
                'out_order_no' => $params['mch_billno'],
                'out_request_no' => $params['mch_billno'],
                'order_title' => $params['act_name'],
                'amount' => number_format($params['total_amount'] / 100, 2),
                'payer_user_id' => $this->getGatewayConfig('app_id'),
                'payee_user_id' => $params['re_openid'],
                'remark' => $params['remark'],
                'business_params' => json_encode(['sub_biz_scene' => 'CUSTOMIZED']),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 支付宝发放群红包（裂变红包）
     */
    protected function sendAlipayGroupRedPacket(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.coupon.order.app.pay',
            'biz_content' => json_encode([
                'out_order_no' => $params['mch_billno'],
                'out_request_no' => $params['mch_billno'],
                'order_title' => $params['act_name'],
                'amount' => number_format($params['total_amount'] / 100, 2),
                'payer_user_id' => $this->getGatewayConfig('app_id'),
                'payee_user_id' => $params['re_openid'],
                'remark' => $params['remark'],
                'business_params' => json_encode([
                    'sub_biz_scene' => 'GROUP_RED_PACKET',
                    'total_num' => (int) $params['total_num'],
                ]),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝红包记录
     */
    protected function queryAlipayRedPacket(string $mchBillNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.coupon.order.query',
            'biz_content' => json_encode([
                'out_order_no' => $mchBillNo,
                'out_request_no' => $mchBillNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== 通用工具方法 ==================== */

    /**
     * 验证必填参数
     *
     * @param array<string, mixed> $params
     * @param string[] $required
     * @throws PayException
     */
    protected function validateRequired(array $params, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }

    /**
     * 获取网关配置项
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function getGatewayConfig(string $key, mixed $default = null): mixed
    {
        $reflection = new \ReflectionClass($this->gateway);

        if ($reflection->hasProperty('config')) {
            $property = $reflection->getProperty('config');
            $property->setAccessible(true);
            $config = $property->getValue($this->gateway);

            return $config[$key] ?? $default;
        }

        return $default;
    }

    /**
     * 生成随机字符串
     */
    protected function generateNonceStr(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
