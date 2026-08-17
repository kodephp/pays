<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 对账插件
 *
 * 为支持对账的网关提供统一的对账管理能力：下载交易对账单、下载资金账单、解析对账单原始数据。
 *
 * 平台组装逻辑已下沉到各网关原生方法（网关声明 {@see ReconciliationCapableInterface}），
 * 本插件仅负责「参数校验 + 类型安全转发」，不承载平台组装逻辑。
 *
 * 支持网关：
 * - 微信支付（交易对账单、资金账单）
 * - 支付宝（对账单下载地址、资金账单电子回单）
 * - Stripe（Balance Transaction 导出；资金账单能力暂未提供，调用会报「无此方法」）
 *
 * 使用示例：
 * ```php
 * $plugin = new ReconciliationPlugin($wechatGateway);
 *
 * // 下载交易对账单
 * $bill = $plugin->downloadBill([
 *     'bill_date' => '20240425',
 *     'bill_type' => 'ALL',
 * ]);
 *
 * // 下载资金账单
 * $fundFlow = $plugin->downloadFundFlow([
 *     'bill_date' => '20240425',
 *     'account_type' => 'Basic',
 * ]);
 *
 * // 解析对账单
 * $records = $plugin->parseBill($rawCsvData);
 *
 * // 统一入口等价写法
 * \Kode\Pays\Facade\Pay::reconciliationDownloadBill('wechat', $params);
 * ```
 */
class ReconciliationPlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力，并实现对账能力接口）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需继承 AbstractGateway）
     */
    public function __construct(GatewayInterface $gateway)
    {
        self::assertHttpCapable($gateway);

        $this->gateway = $gateway;
    }

    /**
     * 下载交易对账单
     *
     * @param array<string, mixed> $params 对账参数（bill_date 必填）
     * @return array<int|string, mixed>
     * @throws PayException
     */
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        return $this->forwardToCapableGateway('downloadBill', $params);
    }

    /**
     * 下载资金账单
     *
     * @param array<string, mixed> $params 资金账单参数（bill_date 必填）
     * @return array<int|string, mixed>
     * @throws PayException
     */
    public function downloadFundFlow(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        return $this->forwardToCapableGateway('downloadFundFlow', $params);
    }

    /**
     * 解析对账单原始数据
     *
     * @param string $rawData 原始对账单数据（CSV/JSON）
     * @return array<int|string, mixed> 解析后的交易记录列表
     * @throws PayException
     */
    public function parseBill(string $rawData): array
    {
        return $this->forwardToCapableGateway('parseBill', $rawData);
    }

    /**
     * 对比系统订单与对账单差异
     *
     * 平台无关工具方法，直接比对两组交易记录，无需网关能力。
     *
     * @param array<int, array<string, mixed>> $systemOrders 系统订单列表
     * @param array<int, array<string, mixed>> $billRecords 对账单记录列表
     * @return array<string, mixed> 差异报告
     */
    public function diff(array $systemOrders, array $billRecords): array
    {
        $systemMap = [];
        foreach ($systemOrders as $order) {
            $key = $order['out_trade_no'] ?? $order['order_id'] ?? '';
            if ($key !== '') {
                $systemMap[$key] = $order;
            }
        }

        $billMap = [];
        foreach ($billRecords as $record) {
            $key = $record['out_trade_no'] ?? $record['merchant_order_no'] ?? '';
            if ($key !== '') {
                $billMap[$key] = $record;
            }
        }

        $onlyInSystem = [];
        $onlyInBill = [];
        $amountMismatch = [];
        $statusMismatch = [];

        // 系统有但账单没有的
        foreach ($systemMap as $key => $order) {
            if (!isset($billMap[$key])) {
                $onlyInSystem[] = $order;
                continue;
            }

            $record = $billMap[$key];

            // 金额比对
            $sysAmount = (int) (($order['total_fee'] ?? $order['amount'] ?? 0) * 100);
            $billAmount = (int) (($record['total_fee'] ?? $record['order_amount'] ?? 0) * 100);
            if ($sysAmount !== $billAmount) {
                $amountMismatch[] = [
                    'order' => $order,
                    'bill' => $record,
                    'system_amount' => $sysAmount,
                    'bill_amount' => $billAmount,
                ];
            }

            // 状态比对
            $sysStatus = $order['status'] ?? $order['trade_state'] ?? '';
            $billStatus = $record['trade_state'] ?? $record['order_status'] ?? '';
            if ($sysStatus !== '' && $billStatus !== '' && $sysStatus !== $billStatus) {
                $statusMismatch[] = [
                    'order' => $order,
                    'bill' => $record,
                    'system_status' => $sysStatus,
                    'bill_status' => $billStatus,
                ];
            }
        }

        // 账单有但系统没有的
        foreach ($billMap as $key => $record) {
            if (!isset($systemMap[$key])) {
                $onlyInBill[] = $record;
            }
        }

        return [
            'total_system' => count($systemOrders),
            'total_bill' => count($billRecords),
            'only_in_system' => $onlyInSystem,
            'only_in_bill' => $onlyInBill,
            'amount_mismatch' => $amountMismatch,
            'status_mismatch' => $statusMismatch,
            'is_consistent' => empty($onlyInSystem)
                && empty($onlyInBill)
                && empty($amountMismatch)
                && empty($statusMismatch),
        ];
    }

    /**
     * 类型安全地转发到网关原生方法
     *
     * @param mixed ...$args
     * @return array<int|string, mixed>
     * @throws PayException
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof ReconciliationCapableInterface) {
            throw PayException::invalidArgument(sprintf(
                '网关 %s 未实现对账能力接口（ReconciliationCapableInterface）',
                $this->gateway::getName(),
            ));
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var ReconciliationCapableInterface $gateway */
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
