<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 余额查询能力接口
 *
 * 为支持资金余额查询的网关提供统一能力：查询账户实时余额、查询日终余额。
 * 这是生产对账链路（账实核对、可用资金监控）的关键一环，与
 * {@see ReconciliationCapableInterface}（对账单下载）互补。
 *
 * 平台组装逻辑下沉到各网关原生方法，由 {@see \Kode\Pays\Facade\Pay::call()} 统一派发；
 * 网关未实现的方法调用时抛「无此方法」。
 */
interface BalanceCapableInterface
{
    /**
     * 查询账户实时余额。
     *
     * @param array<string, mixed> $params 可选参数：
     *        - account_type：账户类型，BASIC（基本账户）/ OPERATION（运营账户）/ FEES（手续费账户），默认 BASIC
     *        - sub_mchid：服务商模式下指定子商户号（由网关自动注入，无需手动传）
     * @return array<string, mixed> 余额信息，含 available_amount（可用余额，分）、
     *                              pending_amount（待结算金额，分）、currency 等
     * @throws PayException
     */
    public function queryBalance(array $params = []): array;

    /**
     * 查询日终余额（服务商模式下按子商户结算）。
     *
     * @param string $date 对账日期，格式 YYYY-MM-DD
     * @param array<string, mixed> $params 可选参数：
     *        - account_type：账户类型，默认 BASIC
     *        - sub_mchid：服务商模式下指定子商户号（由网关自动注入，无需手动传）
     * @return array<string, mixed> 日终余额信息，含 available_amount、pending_amount、
     *                              day_end_balance（当日余额，分）等
     * @throws PayException
     */
    public function queryDayEndBalance(string $date, array $params = []): array;
}
