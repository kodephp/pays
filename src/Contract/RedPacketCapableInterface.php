<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 现金红包（营销红包）能力接口
 *
 * 微信、支付宝等支付平台将「现金红包 / 裂变红包」作为各自网关的「特色方法」
 * 直接实现于网关类内部（复用基类配置、签名与 HTTP 通道），而非依赖统一插件层。
 * 实现本接口的网关即可被统一入口 {@see \Kode\Pays\Facade\Pay::call()} 与
 * {@see \Kode\Pays\Plugin\RedPacketPlugin} 类型安全地调用其红包方法，满足
 * 「统一入口可调用各平台特色方法」的设计目标。
 *
 * 方法命名与微信 / 支付宝的插件语义保持一致：
 * - sendRedPacket : 发放普通现金红包
 * - groupRedPacket: 发放裂变红包（微信群红包 / 支付宝群红包）
 * - queryRedPacket: 查询红包发放记录
 */
interface RedPacketCapableInterface
{
    /**
     * 发放普通现金红包
     *
     * @param array<string, mixed> $params 红包参数
     *        - mch_billno: 商户红包单号
     *        - send_name: 商户名称
     *        - re_openid: 接收用户 OpenID / 用户标识
     *        - total_amount: 红包总金额（微信 / 支付宝单位为分）
     *        - total_num: 红包发放总人数（普通红包为 1）
     *        - wishing: 红包祝福语
     *        - act_name: 活动名称
     *        - remark: 备注
     * @return array<string, mixed>
     * @throws PayException
     */
    public function sendRedPacket(array $params): array;

    /**
     * 发放裂变红包（群红包）
     *
     * @param array<string, mixed> $params 裂变红包参数（含 total_num，须 >= 3）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function groupRedPacket(array $params): array;

    /**
     * 查询红包发放记录
     *
     * @param string $mchBillNo 商户红包单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRedPacket(string $mchBillNo): array;
}
