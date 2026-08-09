<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 对账能力接口
 *
 * 为支持对账的网关提供统一的对账管理能力：下载交易对账单、下载资金账单、解析对账单原始数据。
 *
 * 与分账/转账/红包/订阅/个人收款一致，平台组装逻辑下沉到各网关原生方法，
 * 由 {@see \Kode\Pays\Facade\Pay::call()} 统一派发；网关未实现的方法调用时抛「无此方法」。
 */
interface ReconciliationCapableInterface
{
    /**
     * 下载交易对账单
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<string, mixed> 账单数据（含原始响应与解析后的记录列表）
     */
    public function downloadBill(array $params): array;

    /**
     * 下载资金账单
     *
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<string, mixed>
     */
    public function downloadFundFlow(array $params): array;

    /**
     * 解析对账单原始数据
     *
     * @param string $rawData 原始对账单数据（CSV / JSON）
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     */
    public function parseBill(string $rawData): array;
}
