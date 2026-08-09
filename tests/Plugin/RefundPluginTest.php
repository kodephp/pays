<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\RefundPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 支持退款能力的假网关：记录原生方法调用，便于验证插件「校验 + 转发」行为
 */
class RefundCapableFakeGateway extends FakeGateway implements RefundCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $refundCalls = [];

    public static function getName(): string
    {
        return 'refundgw';
    }

    public function applyRefund(array $params): array
    {
        $this->refundCalls[] = ['applyRefund', $params];

        return ['ok' => true, 'out_refund_no' => $params['out_refund_no']];
    }

    public function queryRefund(string $outRefundNo): array
    {
        $this->refundCalls[] = ['queryRefund', $outRefundNo];

        return ['ok' => true, 'out_refund_no' => $outRefundNo];
    }

    public function cancelRefund(string $outRefundNo): array
    {
        $this->refundCalls[] = ['cancelRefund', $outRefundNo];

        return ['ok' => true, 'out_refund_no' => $outRefundNo];
    }
}

/**
 * 退款插件单元测试
 *
 * 验证插件只做「参数校验 + 类型安全转发」，不再承载平台组装逻辑。
 */
class RefundPluginTest extends TestCase
{
    public function testApplyForwardsToGateway(): void
    {
        $gateway = new RefundCapableFakeGateway();
        $plugin = new RefundPlugin($gateway);

        $result = $plugin->apply([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 50,
        ]);

        $this->assertSame(['ok' => true, 'out_refund_no' => 'REFUND_001'], $result);
        $this->assertSame('applyRefund', $gateway->refundCalls[0][0]);
        $this->assertSame('REFUND_001', $gateway->refundCalls[0][1]['out_refund_no']);
    }

    public function testApplyRequiresTradeOrTransaction(): void
    {
        $gateway = new RefundCapableFakeGateway();
        $plugin = new RefundPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('out_trade_no 和 transaction_id 必须至少提供一个');

        $plugin->apply([
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 50,
        ]);
    }

    public function testApplyMissingRequired(): void
    {
        $gateway = new RefundCapableFakeGateway();
        $plugin = new RefundPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $plugin->apply([
            'out_trade_no' => 'ORDER_001',
            'refund_fee' => 50,
        ]);
    }

    public function testQueryForwardsToGateway(): void
    {
        $gateway = new RefundCapableFakeGateway();
        $plugin = new RefundPlugin($gateway);

        $plugin->query('REFUND_001');

        $this->assertSame('queryRefund', $gateway->refundCalls[0][0]);
        $this->assertSame('REFUND_001', $gateway->refundCalls[0][1]);
    }

    public function testCancelForwardsToGateway(): void
    {
        $gateway = new RefundCapableFakeGateway();
        $plugin = new RefundPlugin($gateway);

        $plugin->cancel('REFUND_001');

        $this->assertSame('cancelRefund', $gateway->refundCalls[0][0]);
        $this->assertSame('REFUND_001', $gateway->refundCalls[0][1]);
    }

    public function testNonCapableGatewayThrows(): void
    {
        $gateway = new FakeGateway(); // 未实现退款能力接口
        $plugin = new RefundPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/未实现退款能力接口/');

        $plugin->apply([
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 50,
        ]);
    }
}
