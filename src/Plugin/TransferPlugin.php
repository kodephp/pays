<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\PluginInterface;
use Kode\Pays\Core\PayException;

/**
 * 转账插件接口
 *
 * 定义企业付款/转账业务的标准方法，支持付款到零钱、付款到银行卡等场景。
 */
interface TransferPlugin extends PluginInterface
{
    /**
     * 发起单笔转账
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $params 转账参数
     *        - out_biz_no: 商户转账单号
     *        - amount: 转账金额（单位：分）
     *        - recipient: 接收方信息 {type, account, name}
     *        - description: 转账备注
     * @return array<string, mixed> 转账结果
     * @throws PayException
     */
    public function single(GatewayInterface $gateway, array $params): array;

    /**
     * 发起批量转账
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $params 批量转账参数
     *        - out_biz_no: 商户批量单号
     *        - total_amount: 总金额
     *        - total_num: 总笔数
     *        - transfer_detail_list: 明细列表
     * @return array<string, mixed>
     * @throws PayException
     */
    public function batch(GatewayInterface $gateway, array $params): array;

    /**
     * 查询转账结果
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(GatewayInterface $gateway, string $outBizNo): array;

    /**
     * 查询转账电子回单
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function receipt(GatewayInterface $gateway, string $outBizNo): array;
}
