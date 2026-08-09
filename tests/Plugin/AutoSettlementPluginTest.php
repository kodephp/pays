<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\WalletManager;
use Kode\Pays\Plugin\AutoSettlementPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use PHPUnit\Framework\TestCase;

/**
 * 具备结算能力的假网关：记录转发调用，不发起真实 HTTP。
 */
class SettlementCapableFakeGateway extends FakeGateway implements SettlementCapableInterface
{
    /** @var array<int, array{method: string, args: array<int, mixed>}> */
    public array $settlementCalls = [];

    public function settleToWallet(array $params): array
    {
        $this->settlementCalls[] = ['method' => 'settleToWallet', 'args' => [$params]];

        return ['success' => true, 'channel' => 'wallet'];
    }

    public function settleToBankCard(array $params): array
    {
        $this->settlementCalls[] = ['method' => 'settleToBankCard', 'args' => [$params]];

        return ['success' => true, 'channel' => 'bank_card'];
    }

    public function settleToPayout(array $params): array
    {
        $this->settlementCalls[] = ['method' => 'settleToPayout', 'args' => [$params]];

        return ['success' => true, 'channel' => 'payout'];
    }

    public function querySettlement(string $outBizNo): array
    {
        $this->settlementCalls[] = ['method' => 'querySettlement', 'args' => [$outBizNo]];

        return ['success' => true, 'out_biz_no' => $outBizNo];
    }
}

/**
 * 结算失败的假网关：用于验证失败回调与 settled 归一化。
 */
class FailingSettlementFakeGateway extends SettlementCapableFakeGateway
{
    public function settleToWallet(array $params): array
    {
        return ['success' => false, 'reason' => '余额不足'];
    }
}

/**
 * 自动结算插件测试
 *
 * 插件已收敛为「编排 + 类型安全转发」，因此断言聚焦于：
 * 结算条件判定、目标类型 → 网关方法映射、入参归一化、结果归一化、
 * 回调触发，以及网关不具备结算能力时的报错。
 */
class AutoSettlementPluginTest extends TestCase
{
    private function makeWalletManager(string $type, array $overrides = []): WalletManager
    {
        $manager = new WalletManager();
        $manager->bind('user_001', $type, array_merge([
            'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            'real_name' => '张三',
            'auto_settlement' => true,
            'min_amount' => 100,
            'settlement_type' => 'realtime',
        ], $overrides));

        return $manager;
    }

    public function testSettleToWalletForwardsToGatewayNativeMethod(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, $this->makeWalletManager('wechat_wallet'));

        $result = $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 500,
            'out_biz_no' => 'SETTLE_001',
            'description' => '订单结算',
            'force' => true,
        ]);

        self::assertSame('settleToWallet', $gateway->settlementCalls[0]['method']);

        $payload = $gateway->settlementCalls[0]['args'][0];
        self::assertSame('SETTLE_001', $payload['out_biz_no']);
        self::assertSame(500, $payload['amount']);
        self::assertSame('oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', $payload['account']);
        self::assertSame('张三', $payload['real_name']);
        self::assertSame('订单结算', $payload['description']);

        self::assertTrue($result['settled']);
        self::assertSame('wechat_wallet', $result['target_type']);
        self::assertSame(500, $result['amount']);
        self::assertSame('user_001', $result['user_id']);
    }

    public function testSettleToBankCardMapsCardFields(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $walletManager = $this->makeWalletManager('bank_card', [
            'account' => '6222021234567890',
            'real_name' => '李四',
            'bank_code' => 'ICBC',
            'is_default' => true,
        ]);
        $plugin = new AutoSettlementPlugin($gateway, $walletManager);

        $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 10000,
            'out_biz_no' => 'SETTLE_002',
            'force' => true,
        ]);

        self::assertSame('settleToBankCard', $gateway->settlementCalls[0]['method']);

        $payload = $gateway->settlementCalls[0]['args'][0];
        self::assertSame('6222021234567890', $payload['bank_card_no']);
        self::assertSame('李四', $payload['real_name']);
        self::assertSame('ICBC', $payload['bank_code']);
        self::assertArrayNotHasKey('account', $payload);
    }

    public function testSettleToPayoutForStripeConnect(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $walletManager = $this->makeWalletManager('stripe_connect', [
            'account' => 'acct_123',
            'is_default' => true,
        ]);
        $plugin = new AutoSettlementPlugin($gateway, $walletManager);

        $plugin->settle('user_001', [
            'transaction_id' => 'pi_123',
            'amount' => 2000,
            'out_biz_no' => 'SETTLE_003',
            'force' => true,
        ]);

        self::assertSame('settleToPayout', $gateway->settlementCalls[0]['method']);
        self::assertSame('acct_123', $gateway->settlementCalls[0]['args'][0]['account']);
    }

    public function testSettleReturnsNotSettledWhenBelowMinAmount(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, $this->makeWalletManager('wechat_wallet', [
            'min_amount' => 1000,
        ]));

        $result = $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 100,
            'out_biz_no' => 'SETTLE_004',
        ]);

        self::assertFalse($result['settled']);
        self::assertSame('未满足自动结算条件（未绑定钱包或金额不足）', $result['reason']);
        self::assertSame([], $gateway->settlementCalls);
    }

    public function testSettleThrowsOnMissingRequiredParam(): void
    {
        $plugin = new AutoSettlementPlugin(
            new SettlementCapableFakeGateway(),
            $this->makeWalletManager('wechat_wallet'),
        );

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_biz_no');

        $plugin->settle('user_001', ['transaction_id' => '4200000000', 'amount' => 500]);
    }

    public function testSettleThrowsOnUnsupportedTargetType(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, $this->makeWalletManager('unionpay_card', [
            'account' => '6222021234567890',
            'is_default' => true,
        ]));

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('不支持的结算目标类型：unionpay_card');

        $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 500,
            'out_biz_no' => 'SETTLE_005',
            'force' => true,
        ]);
    }

    public function testSettleThrowsWhenGatewayLacksSettlementCapability(): void
    {
        $plugin = new AutoSettlementPlugin(new FakeGateway(), $this->makeWalletManager('wechat_wallet'));

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('未实现结算能力接口');

        $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 500,
            'out_biz_no' => 'SETTLE_006',
            'force' => true,
        ]);
    }

    public function testQueryForwardsToGateway(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, new WalletManager());

        $result = $plugin->query('SETTLE_007');

        self::assertSame('querySettlement', $gateway->settlementCalls[0]['method']);
        self::assertSame('SETTLE_007', $gateway->settlementCalls[0]['args'][0]);
        self::assertSame('SETTLE_007', $result['out_biz_no']);
    }

    public function testQueryRejectsEmptyOutBizNo(): void
    {
        $plugin = new AutoSettlementPlugin(new SettlementCapableFakeGateway(), new WalletManager());

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_biz_no');

        $plugin->query('');
    }

    public function testSuccessCallbackIsTriggered(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, $this->makeWalletManager('wechat_wallet'));

        $captured = null;
        $plugin->onSettlementSuccess(function (array $result) use (&$captured): void {
            $captured = $result;
        });

        $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 500,
            'out_biz_no' => 'SETTLE_008',
            'force' => true,
        ]);

        self::assertIsArray($captured);
        self::assertTrue($captured['settled']);
    }

    public function testFailureCallbackIsTriggered(): void
    {
        $gateway = new FailingSettlementFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, $this->makeWalletManager('wechat_wallet'));

        $captured = null;
        $plugin->onSettlementFailed(function (array $result) use (&$captured): void {
            $captured = $result;
        });

        $result = $plugin->settle('user_001', [
            'transaction_id' => '4200000000',
            'amount' => 500,
            'out_biz_no' => 'SETTLE_009',
            'force' => true,
        ]);

        self::assertFalse($result['settled']);
        self::assertIsArray($captured);
        self::assertSame('余额不足', $captured['reason']);
    }

    public function testSettleBatchCollectsPerItemResults(): void
    {
        $gateway = new SettlementCapableFakeGateway();
        $plugin = new AutoSettlementPlugin($gateway, $this->makeWalletManager('wechat_wallet'));

        $results = $plugin->settleBatch([
            [
                'user_id' => 'user_001',
                'transaction_id' => '4200000001',
                'amount' => 500,
                'out_biz_no' => 'SETTLE_B1',
                'force' => true,
            ],
            [
                'user_id' => 'user_001',
                'transaction_id' => '4200000002',
                'amount' => 600,
                // 缺 out_biz_no，应被捕获为失败项而非中断整批
            ],
        ]);

        self::assertCount(2, $results);
        self::assertTrue($results[0]['settled']);
        self::assertFalse($results[1]['settled']);
        self::assertStringContainsString('缺少必填参数：out_biz_no', $results[1]['reason']);
    }
}
