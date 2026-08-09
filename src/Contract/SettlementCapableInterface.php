<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 自动结算能力接口
 *
 * 支付成功后把资金结算到用户绑定的目标账户（钱包余额 / 银行卡 / 平台商户账户等）
 * 是各网关的「特色方法」，直接实现于网关类内部（复用基类配置、签名与 HTTP 通道），
 * 而非依赖插件层硬编码分支。实现本接口的网关即可被
 * {@see \Kode\Pays\Plugin\AutoSettlementPlugin} 与统一入口
 * {@see \Kode\Pays\Facade\Pay::call()} 类型安全地调用其结算方法。
 *
 * 方法按「结算目标语义」划分，每个网关根据自身平台特性多态实现，
 * 不支持的语义统一抛 {@see PayException::methodNotSupported()}（无此方法）：
 * - settleToWallet    : 结算到平台内钱包余额（微信零钱 / 支付宝余额）
 * - settleToBankCard  : 结算到银行卡（微信企业付款到银行卡 / 支付宝银行卡转账）
 * - settleToPayout    : 结算到外部账户（Stripe Connect 转账 / PayPal Payout）
 * - querySettlement   : 查询结算结果
 */
interface SettlementCapableInterface
{
    /**
     * 结算到平台内钱包余额
     *
     * @param array<string, mixed> $params 结算参数（已归一化）
     *        - out_biz_no: 商户结算单号
     *        - amount: 结算金额（分）
     *        - account: 目标账户标识（openid / 支付宝账号等）
     *        - real_name: 收款人真实姓名（可选）
     *        - description: 结算说明（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function settleToWallet(array $params): array;

    /**
     * 结算到银行卡
     *
     * @param array<string, mixed> $params 结算参数（已归一化）
     *        - out_biz_no: 商户结算单号
     *        - amount: 结算金额（分）
     *        - bank_card_no: 银行卡号
     *        - real_name: 持卡人真实姓名
     *        - bank_code: 银行编码（可选）
     *        - description: 结算说明（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function settleToBankCard(array $params): array;

    /**
     * 结算到外部账户（Payout / Connect 转账）
     *
     * @param array<string, mixed> $params 结算参数（已归一化）
     *        - out_biz_no: 商户结算单号
     *        - amount: 结算金额（最小货币单位）
     *        - account: 目标账户标识（Connect 账户 / 邮箱等）
     *        - description: 结算说明（可选）
     *        - currency: 币种（可选，默认平台币种）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function settleToPayout(array $params): array;

    /**
     * 查询结算结果
     *
     * @param string $outBizNo 商户结算单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function querySettlement(string $outBizNo): array;
}
