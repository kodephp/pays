<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 红包插件
 *
 * 为支持红包 / 现金红包的网关提供统一的红包发放管理能力。
 * 支持普通红包、裂变红包、查询红包记录。
 *
 * 架构说明（对齐「统一入口」设计）：
 * 各平台的红包逻辑已下沉到各自的网关类内部，实现 {@see RedPacketCapableInterface}
 * （微信支付、支付宝均已实现）。本插件只做「参数校验 + 类型安全转发」，不重复承载
 * 平台组装逻辑，保证单一职责：
 * - 校验通过后，经 {@see forwardToCapableGateway()} 调用网关原生方法；
 * - 网关未实现 {@see RedPacketCapableInterface}（或不支持某方法）时，统一抛「无此方法」。
 *
 * 支持网关：微信支付、支付宝。
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
 *
 * // 统一入口亦可：Pay::redPacketSend('wechat', [...])
 * ```
 */
class RedPacketPlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需实现 RedPacketCapableInterface）
     */
    public function __construct(GatewayInterface $gateway)
    {
        self::assertHttpCapable($gateway);

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
     * @return array<string, mixed> 发放结果
     * @throws PayException
     */
    public function send(array $params): array
    {
        $this->validateRequired($params, [
            'mch_billno',
            'send_name',
            're_openid',
            'total_amount',
            'wishing',
            'act_name',
            'remark',
        ]);

        return $this->forwardToCapableGateway('sendRedPacket', $params);
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
        $this->validateRequired($params, [
            'mch_billno',
            'send_name',
            're_openid',
            'total_amount',
            'total_num',
            'wishing',
            'act_name',
            'remark',
        ]);

        return $this->forwardToCapableGateway('groupRedPacket', $params);
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
        return $this->forwardToCapableGateway('queryRedPacket', $mchBillNo);
    }

    /**
     * 类型安全转发到支持红包的网关原生方法
     *
     * 微信 / 支付宝的「红包」是其各自网关类内部实现的特色方法
     * （声明了 {@see RedPacketCapableInterface}）。插件在此只做校验与转发，不重复承载
     * 平台组装逻辑。网关不支持某方法时抛 {@see PayException::methodNotSupported}（无此方法）。
     *
     * @param string $method 网关原生红包方法名
     * @param mixed ...$args 透传参数
     * @return array<string, mixed>
     * @throws PayException 当网关未实现红包能力接口或不支持该方法时
     *
     * @phpstan-assert RedPacketCapableInterface $this->gateway
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof RedPacketCapableInterface) {
            throw PayException::invalidArgument(
                sprintf(
                    '网关 %s 未实现红包能力接口（RedPacketCapableInterface）',
                    $this->gateway::getName(),
                ),
            );
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var RedPacketCapableInterface $gateway */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }

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
            if (!isset($params[$field]) || $params[$field] === '') {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }
}
