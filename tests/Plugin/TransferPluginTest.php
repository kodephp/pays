<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\FundConstraintValidator;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\TransferPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 支持转账能力的假网关：记录原生方法调用，便于验证插件「校验 + 转发」行为
 */
class TransferCapableFakeGateway extends FakeGateway implements TransferCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $transferCalls = [];

    public static function getName(): string
    {
        return 'transgw';
    }

    public function singleTransfer(array $params): array
    {
        $this->transferCalls[] = ['single', $params];

        return ['ok' => true, 'out_biz_no' => $params['out_biz_no'] ?? ''];
    }

    public function batchTransfer(array $params): array
    {
        $this->transferCalls[] = ['batch', $params];

        return ['ok' => true];
    }

    public function queryTransfer(string $outBizNo): array
    {
        $this->transferCalls[] = ['query', $outBizNo];

        return ['ok' => true];
    }

    public function transferReceipt(string $outBizNo): array
    {
        $this->transferCalls[] = ['receipt', $outBizNo];

        return ['ok' => true];
    }
}

/**
 * 转账插件单元测试
 */
class TransferPluginTest extends TestCase
{
    /**
     * single 经转发调用网关原生 singleTransfer
     */
    public function testSingleForwardsToGateway(): void
    {
        $gateway = new TransferCapableFakeGateway();
        $plugin = new TransferPlugin($gateway);

        $result = $plugin->single([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['type' => 'openid', 'account' => 'a', 'name' => 'n'],
        ]);

        $this->assertSame(['ok' => true, 'out_biz_no' => 'T1'], $result);
        $this->assertSame('single', $gateway->transferCalls[0][0]);
        $this->assertSame('T1', $gateway->transferCalls[0][1]['out_biz_no']);
    }

    /**
     * batch 经转发调用网关原生 batchTransfer
     */
    public function testBatchForwardsToGateway(): void
    {
        $gateway = new TransferCapableFakeGateway();
        $plugin = new TransferPlugin($gateway);

        $plugin->batch([
            'out_biz_no' => 'B1',
            'transfer_detail_list' => [['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['account' => 'a']]],
        ]);

        $this->assertSame('batch', $gateway->transferCalls[0][0]);
    }

    /**
     * query 经转发调用网关原生 queryTransfer
     */
    public function testQueryForwardsToGateway(): void
    {
        $gateway = new TransferCapableFakeGateway();
        $plugin = new TransferPlugin($gateway);

        $plugin->query('T1');

        $this->assertSame('query', $gateway->transferCalls[0][0]);
        $this->assertSame('T1', $gateway->transferCalls[0][1]);
    }

    /**
     * receipt 经转发调用网关原生 transferReceipt
     */
    public function testReceiptForwardsToGateway(): void
    {
        $gateway = new TransferCapableFakeGateway();
        $plugin = new TransferPlugin($gateway);

        $plugin->receipt('T1');

        $this->assertSame('receipt', $gateway->transferCalls[0][0]);
        $this->assertSame('T1', $gateway->transferCalls[0][1]);
    }

    /**
     * 缺 recipient 应在插件层抛 paramError（不走到网关）
     */
    public function testSingleMissingRecipientThrows(): void
    {
        $plugin = new TransferPlugin(new TransferCapableFakeGateway());

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $plugin->single(['out_biz_no' => 'T1', 'amount' => 100]);
    }

    /**
     * 网关未实现 TransferCapableInterface 应抛清晰异常
     */
    public function testNonCapableGatewayThrows(): void
    {
        $plugin = new TransferPlugin(new FakeGateway());

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('TransferCapableInterface');

        $plugin->single([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['account' => 'a'],
        ]);
    }

    /**
     * 资金约束校验拦截：min_amount 高于请求金额
     */
    public function testValidatorBlocksBelowMinAmount(): void
    {
        $validator = new FundConstraintValidator();
        $validator->setTransferConstraints(['min_amount' => 1000]);

        $plugin = new TransferPlugin(new TransferCapableFakeGateway(), $validator);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('金额不能低于');

        $plugin->single([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['account' => 'a'],
        ]);
    }

    /**
     * 资金约束校验通过时放行
     */
    public function testValidatorPasses(): void
    {
        $validator = new FundConstraintValidator();

        $gateway = new TransferCapableFakeGateway();
        $plugin = new TransferPlugin($gateway, $validator);

        $result = $plugin->single([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['account' => 'a'],
        ]);

        $this->assertSame(['ok' => true, 'out_biz_no' => 'T1'], $result);
    }
}
