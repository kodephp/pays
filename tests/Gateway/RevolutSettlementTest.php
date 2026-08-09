<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Revolut 网关「自动结算」原生方法单元测试
 *
 * 结算复用 `/api/1.0/pay`（金额单位：分，网关内部 ÷100 转主单位小数）：
 * - settleToPayout：type=bank 出款到外部银行（receiver.counterparty_id）
 * - settleToBankCard：type=card 出款到卡（receiver.card_id）
 * - settleToWallet：type=revolut 出款到 Revolut 内部账户（receiver.account_id）
 * - querySettlement：按 request_id 查交易列表
 */
class RevolutSettlementTest extends TestCase
{
    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): RevolutGateway
    {
        $config = array_merge([
            'api_key' => 'revolut_key',
            'merchant_id' => 'RevMerchant',
            'account_id' => 'src_acc_001',
        ], $config);

        $gateway = new RevolutGateway($config, new MockHttpClient([]));

        // Mock 响应按 baseUrl 主机名子串匹配。生产主机为 merchant.revolut.com，
        // 沙箱为 sandbox-merchant.revolut.com（不含 "revolut.com" 子串）；
        // 二者均含 "merchant.revolut.com"，以此为键可兼容两种模式。
        if ($responses === []) {
            $responses = ['merchant.revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])];
        }

        return new RevolutGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(RevolutGateway $gateway): MockHttpClient
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
     * 结算到外部银行：验证 /api/1.0/pay 端点、counterparty_id 收款人、金额 ÷100
     */
    public function testSettleToPayoutPostsBankPay(): void
    {
        $gateway = $this->createGateway();

        $gateway->settleToPayout([
            'out_biz_no' => 'SETTLE_1',
            'amount' => 10000,
            'account' => 'GB29NWBK60161331926819',
            'real_name' => '张三',
            'currency' => 'eur',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/pay', $last['url']);
        $this->assertSame('SETTLE_1', $last['data']['request_id']);
        $this->assertSame('GB29NWBK60161331926819', $last['data']['receiver']['counterparty_id']);
        $this->assertSame(100.0, $last['data']['amount']);
        $this->assertSame('EUR', $last['data']['currency']);
        $this->assertSame('src_acc_001', $last['data']['account_id']);
    }

    /**
     * 结算到银行卡：验证 receiver.card_id
     */
    public function testSettleToBankCardPostsCardPay(): void
    {
        $gateway = $this->createGateway();

        $gateway->settleToBankCard([
            'out_biz_no' => 'SETTLE_C',
            'amount' => 5000,
            'bank_card_no' => '4111111111111111',
            'real_name' => '李四',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertSame('4111111111111111', $last['data']['receiver']['card_id']);
        $this->assertSame(50.0, $last['data']['amount']);
    }

    /**
     * 结算到平台内钱包（Revolut 内部账户）：验证 receiver.account_id
     */
    public function testSettleToWalletPostsInternalAccount(): void
    {
        $gateway = $this->createGateway();

        $gateway->settleToWallet([
            'out_biz_no' => 'SETTLE_W',
            'amount' => 3000,
            'account' => 'internal_acc_001',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertSame('internal_acc_001', $last['data']['receiver']['account_id']);
        $this->assertSame(30.0, $last['data']['amount']);
    }

    /**
     * 查询结算：按 request_id 走交易列表查询
     */
    public function testQuerySettlementFiltersByRequestId(): void
    {
        $gateway = $this->createGateway();

        $gateway->querySettlement('SETTLE_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/1.0/transactions', $last['url']);
        $this->assertSame('SETTLE_1', $last['data']['request_id'] ?? '');
    }

    /**
     * 校验缺失参数时抛错
     */
    public function testSettleToPayoutMissingAccount(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：account');

        $gateway->settleToPayout(['out_biz_no' => 'SETTLE_3', 'amount' => 100]);
    }

    /**
     * 结算到银行卡：缺 bank_card_no 抛错
     */
    public function testSettleToBankCardMissingCardNo(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：bank_card_no');

        $gateway->settleToBankCard(['out_biz_no' => 'SETTLE_4', 'amount' => 100]);
    }
}
