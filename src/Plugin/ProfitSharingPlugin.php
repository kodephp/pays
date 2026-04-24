<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\PluginInterface;
use Kode\Pays\Core\PayException;

/**
 * 分账插件接口
 *
 * 定义分账业务的标准方法，各网关可根据自身能力实现。
 * 分账用于将一笔订单金额按约定比例分给多个接收方。
 */
interface ProfitSharingPlugin extends PluginInterface
{
    /**
     * 创建分账订单
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $params 分账参数
     *        - transaction_id: 原支付订单号
     *        - out_order_no: 分账订单号
     *        - receivers: 接收方列表 [{type, account, amount, description}]
     * @return array<string, mixed> 分账结果
     * @throws PayException
     */
    public function create(GatewayInterface $gateway, array $params): array;

    /**
     * 查询分账结果
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param string $outOrderNo 分账订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(GatewayInterface $gateway, string $outOrderNo): array;

    /**
     * 分账回退
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param array<string, mixed> $params 回退参数
     *        - out_order_no: 分账订单号
     *        - out_return_no: 回退单号
     *        - return_amount: 回退金额
     * @return array<string, mixed>
     * @throws PayException
     */
    public function return(GatewayInterface $gateway, array $params): array;

    /**
     * 查询分账回退结果
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param string $outReturnNo 回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryReturn(GatewayInterface $gateway, string $outReturnNo): array;

    /**
     * 解冻剩余资金
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param string $transactionId 原支付订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function unfreeze(GatewayInterface $gateway, string $transactionId): array;
}
