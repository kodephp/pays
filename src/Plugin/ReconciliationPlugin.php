<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\PluginInterface;
use Kode\Pays\Core\PayException;

/**
 * 对账插件接口
 *
 * 定义交易对账业务的标准方法，支持下载对账单、资金账单等。
 */
interface ReconciliationPlugin extends PluginInterface
{
    /**
     * 下载交易对账单
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $params 对账参数
     *        - bill_date: 对账日期（格式：YYYYMMDD）
     *        - bill_type: 账单类型（ALL/SUCCESS/REFUND）
     * @return array<string, mixed> 对账单数据
     * @throws PayException
     */
    public function downloadBill(GatewayInterface $gateway, array $params): array;

    /**
     * 下载资金账单
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $params 资金账单参数
     *        - bill_date: 账单日期（格式：YYYYMMDD）
     *        - account_type: 资金账户类型（Basic/Operation/Fees）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function downloadFundFlow(GatewayInterface $gateway, array $params): array;

    /**
     * 解析对账单数据
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param string $rawData 原始对账单数据
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    public function parseBill(GatewayInterface $gateway, string $rawData): array;
}
