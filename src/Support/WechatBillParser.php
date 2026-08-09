<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

/**
 * 微信对账单解析器
 *
 * 微信交易对账单以 CSV 文本下发，字段值统一以反引号（`）前缀转义以防止 Excel 科学计数法。
 * V2 与 V3 网关的账单列结构一致，故由本类统一解析，避免各网关重复实现。
 */
final class WechatBillParser
{
    /**
     * 对账单列名（按微信下发顺序）
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'transaction_time',
        'app_id',
        'mch_id',
        'sub_mch_id',
        'device_info',
        'transaction_id',
        'out_trade_no',
        'openid',
        'trade_type',
        'trade_state',
        'bank_type',
        'currency',
        'total_fee',
        'red_packet_amount',
        'refund_id',
        'out_refund_no',
        'refund_fee',
        'refund_red_packet_amount',
        'refund_type',
        'refund_status',
        'goods_name',
        'attach',
        'service_charge',
        'rate',
        'order_amount',
        'rate_amount',
    ];

    /**
     * 金额类列（缺省值为 '0' 而非空串）
     *
     * @var array<int, string>
     */
    private const AMOUNT_COLUMNS = [
        'total_fee',
        'red_packet_amount',
        'refund_fee',
        'refund_red_packet_amount',
        'service_charge',
        'order_amount',
        'rate_amount',
    ];

    /**
     * 有效数据行的最少列数
     */
    private const MIN_FIELDS = 10;

    /**
     * 解析微信对账单 CSV
     *
     * 首行为表头，遇空行或以「总交易单数」开头的汇总行即终止。
     *
     * @param string $rawData 原始对账单文本
     * @return array<int, array<string, mixed>> 交易记录列表
     */
    public static function parse(string $rawData): array
    {
        if ($rawData === '') {
            return [];
        }

        $records = [];
        $isHeader = true;

        foreach (explode("\n", $rawData) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '总交易单数')) {
                break;
            }

            if ($isHeader) {
                $isHeader = false;
                continue;
            }

            $fields = str_getcsv($line, ',', '`');
            if (count($fields) < self::MIN_FIELDS) {
                continue;
            }

            $records[] = self::mapFields($fields);
        }

        return $records;
    }

    /**
     * 从统一响应中提取对账单原始文本
     *
     * 对账单接口返回 CSV 文本而非结构化报文，统一入口将原始文本置于 data 字段。
     *
     * @param array<string, mixed> $response 统一响应数组
     */
    public static function extractRawText(array $response): string
    {
        $raw = $response['data'] ?? $response;

        return is_string($raw) ? $raw : '';
    }

    /**
     * 将 CSV 字段数组映射为具名记录
     *
     * @param array<int, string|null> $fields
     * @return array<string, mixed>
     */
    private static function mapFields(array $fields): array
    {
        $record = [];

        foreach (self::COLUMNS as $index => $column) {
            $default = in_array($column, self::AMOUNT_COLUMNS, true) ? '0' : '';
            $record[$column] = $fields[$index] ?? $default;
        }

        return $record;
    }
}
