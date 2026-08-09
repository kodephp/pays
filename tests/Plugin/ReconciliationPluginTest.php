<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\ReconciliationPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 支持对账能力的假网关：记录原生方法调用，便于验证插件「校验 + 转发」行为
 */
class ReconciliationCapableFakeGateway extends FakeGateway implements ReconciliationCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $reconCalls = [];

    public static function getName(): string
    {
        return 'recongw';
    }

    public function downloadBill(array $params): array
    {
        $this->reconCalls[] = ['downloadBill', $params];

        return ['ok' => true, 'bill_date' => $params['bill_date']];
    }

    public function downloadFundFlow(array $params): array
    {
        $this->reconCalls[] = ['downloadFundFlow', $params];

        return ['ok' => true];
    }

    public function parseBill(string $rawData): array
    {
        $this->reconCalls[] = ['parseBill', $rawData];

        return [['row' => $rawData]];
    }
}

/**
 * 对账插件单元测试
 *
 * 验证插件只做「参数校验 + 类型安全转发」，不再承载平台组装逻辑；
 * diff 作为平台无关工具方法可直接使用。
 */
class ReconciliationPluginTest extends TestCase
{
    public function testDownloadBillForwardsToGateway(): void
    {
        $gateway = new ReconciliationCapableFakeGateway();
        $plugin = new ReconciliationPlugin($gateway);

        $result = $plugin->downloadBill(['bill_date' => '20240425', 'bill_type' => 'ALL']);

        $this->assertSame(['ok' => true, 'bill_date' => '20240425'], $result);
        $this->assertSame('downloadBill', $gateway->reconCalls[0][0]);
        $this->assertSame('20240425', $gateway->reconCalls[0][1]['bill_date']);
    }

    public function testDownloadFundFlowForwardsToGateway(): void
    {
        $gateway = new ReconciliationCapableFakeGateway();
        $plugin = new ReconciliationPlugin($gateway);

        $plugin->downloadFundFlow(['bill_date' => '20240425']);

        $this->assertSame('downloadFundFlow', $gateway->reconCalls[0][0]);
        $this->assertSame('20240425', $gateway->reconCalls[0][1]['bill_date']);
    }

    public function testParseBillForwardsToGateway(): void
    {
        $gateway = new ReconciliationCapableFakeGateway();
        $plugin = new ReconciliationPlugin($gateway);

        $result = $plugin->parseBill('RAW_CSV');

        $this->assertSame([['row' => 'RAW_CSV']], $result);
        $this->assertSame('parseBill', $gateway->reconCalls[0][0]);
        $this->assertSame('RAW_CSV', $gateway->reconCalls[0][1]);
    }

    public function testDownloadBillMissingRequiredThrows(): void
    {
        $gateway = new ReconciliationCapableFakeGateway();
        $plugin = new ReconciliationPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $plugin->downloadBill(['bill_type' => 'ALL']);
    }

    public function testNonCapableGatewayThrows(): void
    {
        $gateway = new FakeGateway(); // 仅实现基础接口，未实现对账能力接口
        $plugin = new ReconciliationPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/未实现对账能力接口/');

        $plugin->downloadBill(['bill_date' => '20240425']);
    }

    public function testDiffDetectsMismatch(): void
    {
        $gateway = new ReconciliationCapableFakeGateway();
        $plugin = new ReconciliationPlugin($gateway);

        $systemOrders = [
            ['out_trade_no' => 'A', 'total_fee' => 100, 'status' => 'SUCCESS'],
            ['out_trade_no' => 'B', 'total_fee' => 200, 'status' => 'SUCCESS'],
        ];
        $billRecords = [
            ['out_trade_no' => 'A', 'total_fee' => 100, 'trade_state' => 'SUCCESS'],
            ['out_trade_no' => 'C', 'total_fee' => 300, 'trade_state' => 'SUCCESS'],
        ];

        $report = $plugin->diff($systemOrders, $billRecords);

        $this->assertSame(2, $report['total_system']);
        $this->assertSame(2, $report['total_bill']);
        $this->assertCount(1, $report['only_in_system']); // B
        $this->assertCount(1, $report['only_in_bill']); // C
        $this->assertFalse($report['is_consistent']);
    }

    public function testDiffConsistent(): void
    {
        $gateway = new ReconciliationCapableFakeGateway();
        $plugin = new ReconciliationPlugin($gateway);

        $orders = [['out_trade_no' => 'A', 'total_fee' => 100, 'status' => 'SUCCESS']];
        $records = [['out_trade_no' => 'A', 'total_fee' => 100, 'trade_state' => 'SUCCESS']];

        $report = $plugin->diff($orders, $records);

        $this->assertTrue($report['is_consistent']);
    }
}
