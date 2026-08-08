<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\PersonalReceivePlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 支持个人收款能力的假网关：记录原生方法调用，便于验证插件「校验 + 转发」行为
 */
class PersonalReceiveCapableFakeGateway extends FakeGateway implements PersonalReceiveCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $receiveCalls = [];

    public static function getName(): string
    {
        return 'recvgw';
    }

    public function createQrCode(array $params): array
    {
        $this->receiveCalls[] = ['createQrCode', $params];

        return ['ok' => true, 'out_trade_no' => 'PERSONAL_1'];
    }

    public function queryRecords(array $params): array
    {
        $this->receiveCalls[] = ['queryRecords', $params];

        return ['ok' => true];
    }

    public function withdraw(array $params): array
    {
        $this->receiveCalls[] = ['withdraw', $params];

        return ['ok' => true];
    }

    public function queryWithdraw(string $outBizNo): array
    {
        $this->receiveCalls[] = ['queryWithdraw', $outBizNo];

        return ['ok' => true];
    }
}

/**
 * 个人收款插件单元测试
 *
 * 验证插件只做「参数校验 + 类型安全转发」，不再承载平台组装逻辑。
 */
class PersonalReceivePluginTest extends TestCase
{
    public function testCreateQrCodeForwardsToGateway(): void
    {
        $gateway = new PersonalReceiveCapableFakeGateway();
        $plugin = new PersonalReceivePlugin($gateway);

        $result = $plugin->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame(['ok' => true, 'out_trade_no' => 'PERSONAL_1'], $result);
        $this->assertSame('createQrCode', $gateway->receiveCalls[0][0]);
        $this->assertSame('商品付款', $gateway->receiveCalls[0][1]['description']);
    }

    public function testQueryRecordsForwardsToGateway(): void
    {
        $gateway = new PersonalReceiveCapableFakeGateway();
        $plugin = new PersonalReceivePlugin($gateway);

        $result = $plugin->queryRecords([
            'start_time' => '2024-04-01 00:00:00',
            'end_time' => '2024-04-25 23:59:59',
        ]);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame('queryRecords', $gateway->receiveCalls[0][0]);
        $this->assertSame('2024-04-01 00:00:00', $gateway->receiveCalls[0][1]['start_time']);
    }

    public function testWithdrawForwardsToGateway(): void
    {
        $gateway = new PersonalReceiveCapableFakeGateway();
        $plugin = new PersonalReceivePlugin($gateway);

        $plugin->withdraw([
            'amount' => 5000,
            'bank_card_no' => '6222',
            'real_name' => '张三',
            'out_biz_no' => 'WD_1',
        ]);

        $this->assertSame('withdraw', $gateway->receiveCalls[0][0]);
        $this->assertSame('WD_1', $gateway->receiveCalls[0][1]['out_biz_no']);
    }

    public function testQueryWithdrawForwardsToGateway(): void
    {
        $gateway = new PersonalReceiveCapableFakeGateway();
        $plugin = new PersonalReceivePlugin($gateway);

        $result = $plugin->queryWithdraw('WD_1');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame('queryWithdraw', $gateway->receiveCalls[0][0]);
        $this->assertSame('WD_1', $gateway->receiveCalls[0][1]);
    }

    public function testCreateQrCodeMissingRequiredThrows(): void
    {
        $gateway = new PersonalReceiveCapableFakeGateway();
        $plugin = new PersonalReceivePlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $plugin->createQrCode(['description' => '商品付款']);
    }

    public function testWithdrawMissingRequiredThrows(): void
    {
        $gateway = new PersonalReceiveCapableFakeGateway();
        $plugin = new PersonalReceivePlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $plugin->withdraw(['amount' => 5000, 'bank_card_no' => '6222', 'real_name' => '张三']);
    }

    public function testNonCapableGatewayThrows(): void
    {
        $gateway = new FakeGateway(); // 仅实现基础接口，未实现 PersonalReceiveCapableInterface
        $plugin = new PersonalReceivePlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/未实现个人收款能力接口/');

        $plugin->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
        ]);
    }
}
