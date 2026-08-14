<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Stripe 网关单元测试（含原生转账 / Payout 能力）
 */
class StripeGatewayTest extends TestCase
{
    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应
     * @param array<string, mixed> $config 网关配置
     */
    private function createGateway(array $responses = [], array $config = []): StripeGateway
    {
        $config = array_merge(['secret_key' => 'sk_test_123'], $config);

        $mock = new MockHttpClient($responses);

        return new StripeGateway($config, $mock);
    }

    /**
     * 获取网关内部的 MockHttpClient
     */
    private function getMockClient(StripeGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        $client = $prop->getValue($gateway);
        $this->assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    /**
     * 测试单笔 Payout：验证端点、destination、金额与 Bearer 头
     */
    public function testSingleTransfer(): void
    {
        $resp = json_encode(['id' => 'po_1', 'amount' => 100, 'currency' => 'usd']);

        $gateway = $this->createGateway(['v1/payouts' => $resp]);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'currency' => 'usd',
            'recipient' => ['type' => 'connect_account', 'account' => 'acct_1'],
            'description' => '佣金',
        ]);

        $this->assertSame('po_1', $result['id']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payouts', $last['url']);
        $this->assertSame('acct_1', $last['data']['destination'] ?? '');
        $this->assertSame(100, $last['data']['amount'] ?? 0);
        $this->assertSame('T1', $last['data']['metadata']['out_biz_no'] ?? '');
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
    }

    /**
     * 余额查询：取首个可用/待结算条目
     */
    public function testQueryBalance(): void
    {
        $resp = json_encode([
            'object' => 'balance',
            'available' => [['amount' => 12345, 'currency' => 'cny']],
            'pending' => [['amount' => 678, 'currency' => 'cny']],
            'livemode' => false,
        ]);

        $gateway = $this->createGateway(['v1/balance' => $resp]);

        $result = $gateway->queryBalance();

        $this->assertSame(12345, $result['available_amount']);
        $this->assertSame(678, $result['pending_amount']);
        $this->assertSame('cny', $result['currency']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/balance', $last['url']);
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
    }

    /**
     * 日终余额：Stripe 无按日期接口，抛「无此方法」
     */
    public function testQueryDayEndBalanceNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/无此方法|not supported|queryDayEndBalance/i');

        $gateway->queryDayEndBalance('2024-04-25');
    }

    /**
     * 测试单笔转账必填校验：缺 recipient 抛 PayException
     */
    public function testSingleTransferMissingRecipient(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $gateway->singleTransfer(['out_biz_no' => 'T1', 'amount' => 100]);
    }

    /**
     * 测试批量 Payout：逐笔调用并聚合
     */
    public function testBatchTransferLoopsSingle(): void
    {
        $resp = json_encode(['id' => 'po_1']);

        $gateway = $this->createGateway(['v1/payouts' => $resp]);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'B1',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['account' => 'acct_1'], 'remark' => 'a'],
                ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['account' => 'acct_2'], 'remark' => 'b'],
            ],
        ]);

        $this->assertSame(2, $result['count']);

        $client = $this->getMockClient($gateway);
        $this->assertCount(2, $client->getHistory());
    }

    /**
     * 测试查询 Payout：验证 metadata 过滤参数
     */
    public function testQueryTransfer(): void
    {
        $resp = json_encode(['id' => 'po_1']);

        $gateway = $this->createGateway(['v1/payouts' => $resp]);

        $gateway->queryTransfer('T1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/payouts', $last['url']);
        $this->assertSame('T1', $last['data']['metadata[out_biz_no]'] ?? '');
    }

    /**
     * 测试查询电子回单：Stripe 不支持，应抛「无此方法」
     */
    public function testTransferReceiptNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->transferReceipt('T1');
    }

    /**
     * 测试获取网关标识
     */
    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('stripe', StripeGateway::getName());
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起分账：逐接收方发起 Transfer，携带 Bearer 头与 source_transaction
     */
    public function testCreateProfitSharingPostsTransfersWithAuthHeader(): void
    {
        $gateway = $this->createGateway(['v1/transfers' => json_encode(['id' => 'tr_1'])]);

        $gateway->createProfitSharing([
            'transaction_id' => 'pi_1',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', 'acct_1', null, Money::fromMinor(300, 'USD'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/transfers', $last['url']);
        $this->assertSame(300, $last['data']['amount']);
        $this->assertSame('usd', $last['data']['currency']);
        $this->assertSame('pi_1', $last['data']['source_transaction']);
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization']);
    }

    /**
     * 分账回退：创建 Reversal 端点正确
     */
    public function testReturnProfitSharingPostsReversal(): void
    {
        $gateway = $this->createGateway(['v1/transfers/tr_1/reversals' => json_encode(['id' => 'rev_1'])]);

        $gateway->returnProfitSharing(['transfer_id' => 'tr_1', 'return_amount' => 100]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/transfers/tr_1/reversals', $last['url']);
        $this->assertSame(100, $last['data']['amount']);
    }

    /**
     * 解冻剩余资金：Stripe 无冻结，返回成功占位
     */
    public function testUnfreezeProfitSharingReturnsSuccessPlaceholder(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->unfreezeProfitSharing('pi_1');

        $this->assertSame('SUCCESS', $result['status']);
        $this->assertSame('pi_1', $result['payment_intent']);
    }

    /* ==================== 自动结算能力 ==================== */

    /**
     * 结算到 Connect 账户：验证 v1/transfers 端点、destination、金额与鉴权头
     */
    public function testSettleToPayoutPostsToTransfers(): void
    {
        $resp = json_encode(['id' => 'tr_1', 'amount' => 2000]);

        $gateway = $this->createGateway(['v1/transfers' => $resp]);

        $result = $gateway->settleToPayout([
            'out_biz_no' => 'SETTLE_1',
            'amount' => 2000,
            'account' => 'acct_1',
            'description' => 'Auto settlement',
        ]);

        $this->assertSame('tr_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/transfers', $last['url']);
        $this->assertSame('acct_1', $last['data']['destination'] ?? '');
        $this->assertSame(2000, $last['data']['amount'] ?? 0);
        $this->assertSame('usd', $last['data']['currency'] ?? '');
        $this->assertSame('SETTLE_1', $last['data']['metadata']['out_biz_no'] ?? '');
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
    }

    /**
     * 结算到 Connect 账户：币种可覆盖且统一转小写
     */
    public function testSettleToPayoutNormalizesCurrency(): void
    {
        $gateway = $this->createGateway(['v1/transfers' => json_encode(['id' => 'tr_2'])]);

        $gateway->settleToPayout([
            'out_biz_no' => 'SETTLE_2',
            'amount' => 100,
            'account' => 'acct_2',
            'currency' => 'EUR',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('eur', $last['data']['currency'] ?? '');
    }

    /**
     * 结算到 Connect 账户：缺 account 抛 PayException
     */
    public function testSettleToPayoutMissingAccount(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：account');

        $gateway->settleToPayout(['out_biz_no' => 'SETTLE_3', 'amount' => 100]);
    }

    /**
     * 查询结算结果：按 Transfer ID 查询
     */
    public function testQuerySettlementGetsTransferById(): void
    {
        $gateway = $this->createGateway(['v1/transfers/tr_1' => json_encode(['id' => 'tr_1'])]);

        $result = $gateway->querySettlement('tr_1');

        $this->assertSame('tr_1', $result['id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v1/transfers/tr_1', $last['url']);
        $this->assertSame('Bearer sk_test_123', $last['headers']['Authorization'] ?? '');
    }

    /**
     * Stripe 无平台内钱包结算语义，调用即报「无此方法」
     */
    public function testSettleToWalletNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->settleToWallet(['out_biz_no' => 'S', 'amount' => 1, 'account' => 'a']);
    }

    /**
     * Stripe 不支持直连银行卡结算，调用即报「无此方法」
     */
    public function testSettleToBankCardNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->settleToBankCard(['out_biz_no' => 'S', 'amount' => 1, 'bank_card_no' => '1', 'real_name' => 'a']);
    }
}
