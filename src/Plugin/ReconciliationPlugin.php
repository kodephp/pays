<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 对账插件
 *
 * 为支持对账的网关提供统一的对账管理能力。
 * 支持下载交易对账单、资金账单、解析对账数据。
 *
 * 支持网关：
 * - 微信支付（对账单下载、资金账单下载）
 * - 支付宝（对账单下载、账务明细查询）
 * - Stripe（Balance Transaction 导出）
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
 * ```
 */
class ReconciliationPlugin
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
     * 下载交易对账单
     *
     * @param array<string, mixed> $params 对账参数
     *        - bill_date: 对账日期（格式：YYYYMMDD）
     *        - bill_type: 账单类型（ALL/SUCCESS/REFUND/RECHARGE）
     * @return array<string, mixed> 对账单数据
     * @throws PayException
     */
    public function downloadBill(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->downloadWechatBill($params),
            'alipay' => $this->downloadAlipayBill($params),
            'stripe' => $this->downloadStripeBill($params),
            default => throw PayException::invalidArgument('当前网关不支持对账功能'),
        };
    }

    /**
     * 下载资金账单
     *
     * @param array<string, mixed> $params 资金账单参数
     *        - bill_date: 账单日期（格式：YYYYMMDD）
     *        - account_type: 资金账户类型（Basic/Operation/Fees）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function downloadFundFlow(array $params): array
    {
        $this->validateRequired($params, ['bill_date']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->downloadWechatFundFlow($params),
            'alipay' => $this->downloadAlipayFundFlow($params),
            default => throw PayException::invalidArgument('当前网关不支持资金账单下载'),
        };
    }

    /**
     * 解析对账单原始数据
     *
     * @param string $rawData 原始对账单数据（CSV/JSON/XML）
     * @return array<int, array<string, mixed>> 解析后的交易记录列表
     * @throws PayException
     */
    public function parseBill(string $rawData): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->parseWechatBill($rawData),
            'alipay' => $this->parseAlipayBill($rawData),
            'stripe' => $this->parseStripeBill($rawData),
            default => throw PayException::invalidArgument('当前网关不支持对账单解析'),
        };
    }

    /**
     * 对比系统订单与对账单差异
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
            'is_consistent' => empty($onlyInSystem) && empty($onlyInBill) && empty($amountMismatch) && empty($statusMismatch),
        ];
    }

    /* ==================== 微信支付对账实现 ==================== */

    /**
     * 下载微信对账单
     */
    protected function downloadWechatBill(array $params): array
    {
        $requestData = [
            'appid' => $this->getGatewayConfig('app_id'),
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'ALL',
            'tar_type' => $params['tar_type'] ?? '',
        ];

        $response = $this->gateway->post('pay/downloadbill', $requestData);

        return [
            'bill_date' => $params['bill_date'],
            'bill_type' => $params['bill_type'] ?? 'ALL',
            'raw_data' => $response,
            'records' => $this->parseWechatBill($response['data'] ?? ''),
        ];
    }

    /**
     * 下载微信资金账单
     */
    protected function downloadWechatFundFlow(array $params): array
    {
        $requestData = [
            'appid' => $this->getGatewayConfig('app_id'),
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'bill_date' => $params['bill_date'],
            'account_type' => $params['account_type'] ?? 'Basic',
            'tar_type' => $params['tar_type'] ?? '',
        ];

        $response = $this->gateway->post('pay/downloadfundflow', $requestData);

        return [
            'bill_date' => $params['bill_date'],
            'account_type' => $params['account_type'] ?? 'Basic',
            'raw_data' => $response,
        ];
    }

    /**
     * 解析微信对账单（CSV 格式）
     */
    protected function parseWechatBill(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $lines = explode("\n", $rawData);
        $records = [];
        $isHeader = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '总交易单数')) {
                break;
            }

            if ($isHeader) {
                $isHeader = false;
                continue;
            }

            $fields = str_getcsv($line, ',', '`');
            if (count($fields) < 10) {
                continue;
            }

            $records[] = [
                'transaction_time' => $fields[0] ?? '',
                'app_id' => $fields[1] ?? '',
                'mch_id' => $fields[2] ?? '',
                'sub_mch_id' => $fields[3] ?? '',
                'device_info' => $fields[4] ?? '',
                'transaction_id' => $fields[5] ?? '',
                'out_trade_no' => $fields[6] ?? '',
                'openid' => $fields[7] ?? '',
                'trade_type' => $fields[8] ?? '',
                'trade_state' => $fields[9] ?? '',
                'bank_type' => $fields[10] ?? '',
                'currency' => $fields[11] ?? '',
                'total_fee' => $fields[12] ?? '0',
                'red_packet_amount' => $fields[13] ?? '0',
                'refund_id' => $fields[14] ?? '',
                'out_refund_no' => $fields[15] ?? '',
                'refund_fee' => $fields[16] ?? '0',
                'refund_red_packet_amount' => $fields[17] ?? '0',
                'refund_type' => $fields[18] ?? '',
                'refund_status' => $fields[19] ?? '',
                'goods_name' => $fields[20] ?? '',
                'attach' => $fields[21] ?? '',
                'service_charge' => $fields[22] ?? '0',
                'rate' => $fields[23] ?? '',
                'order_amount' => $fields[24] ?? '0',
                'rate_amount' => $fields[25] ?? '0',
            ];
        }

        return $records;
    }

    /* ==================== 支付宝对账实现 ==================== */

    /**
     * 下载支付宝对账单
     */
    protected function downloadAlipayBill(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.data.dataservice.bill.downloadurl.query',
            'biz_content' => json_encode([
                'bill_type' => $params['bill_type'] ?? 'trade',
                'bill_date' => $params['bill_date'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 下载支付宝资金账单
     */
    protected function downloadAlipayFundFlow(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.data.bill.ereceipt.apply',
            'biz_content' => json_encode([
                'type' => 'FUND',
                'key' => $params['bill_date'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 解析支付宝对账单（CSV 格式）
     */
    protected function parseAlipayBill(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $lines = explode("\n", $rawData);
        $records = [];
        $isHeader = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '合计')) {
                break;
            }

            if ($isHeader) {
                $isHeader = false;
                continue;
            }

            $fields = str_getcsv($line);
            if (count($fields) < 10) {
                continue;
            }

            $records[] = [
                'alipay_trade_no' => $fields[0] ?? '',
                'merchant_order_no' => $fields[1] ?? '',
                'business_type' => $fields[2] ?? '',
                'subject' => $fields[3] ?? '',
                'create_time' => $fields[4] ?? '',
                'finish_time' => $fields[5] ?? '',
                'store_id' => $fields[6] ?? '',
                'store_name' => $fields[7] ?? '',
                'operator' => $fields[8] ?? '',
                'terminal_id' => $fields[9] ?? '',
                'seller_account' => $fields[10] ?? '',
                'order_amount' => $fields[11] ?? '0',
                'real_amount' => $fields[12] ?? '0',
                'red_packet_amount' => $fields[13] ?? '0',
                'integral_amount' => $fields[14] ?? '0',
                'alipay_discount' => $fields[15] ?? '0',
                'merchant_discount' => $fields[16] ?? '0',
                'service_charge' => $fields[17] ?? '0',
                'share_profit' => $fields[18] ?? '0',
                'refund_id' => $fields[19] ?? '',
                'refund_amount' => $fields[20] ?? '0',
                'remark' => $fields[21] ?? '',
                'status' => $fields[22] ?? '',
            ];
        }

        return $records;
    }

    /* ==================== Stripe 对账实现 ==================== */

    /**
     * 下载 Stripe Balance Transaction
     */
    protected function downloadStripeBill(array $params): array
    {
        $startTime = strtotime($params['bill_date'] . ' 00:00:00');
        $endTime = strtotime($params['bill_date'] . ' 23:59:59');

        return $this->gateway->get('v1/balance_transactions', [
            'created[gte]' => $startTime,
            'created[lte]' => $endTime,
            'limit' => $params['limit'] ?? 100,
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /**
     * 解析 Stripe Balance Transaction（JSON 格式）
     */
    protected function parseStripeBill(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $data = json_decode($rawData, true);

        if (!is_array($data) || !isset($data['data'])) {
            return [];
        }

        return array_map(function (array $item): array {
            return [
                'id' => $item['id'] ?? '',
                'amount' => $item['amount'] ?? 0,
                'currency' => $item['currency'] ?? '',
                'net' => $item['net'] ?? 0,
                'fee' => $item['fee'] ?? 0,
                'status' => $item['status'] ?? '',
                'type' => $item['type'] ?? '',
                'created' => $item['created'] ?? 0,
                'available_on' => $item['available_on'] ?? 0,
                'description' => $item['description'] ?? '',
                'source' => $item['source'] ?? '',
            ];
        }, $data['data']);
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
