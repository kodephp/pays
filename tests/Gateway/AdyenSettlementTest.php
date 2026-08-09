<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Adyen 网关「自动结算」原生方法单元测试
 *
 * 结算复用 Transfers API（`POST /pal/servlet/Transfer/v68/transfer`）：
 * - settleToPayout：category=bank 出款到银行（需 balance_account_id 作为出款来源）
 * - settleToBankCard：category=card 出款到银行卡
 * - querySettlement：按 reference 查询
 * - settleToWallet：Adyen 无平台内钱包语义，报「无此方法」
 */
class AdyenSettlementTest extends TestCase
{
    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): AdyenGateway
    {
        $config = array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'environment' => 'test',
            'balance_account_id' => 'BA00000000000000000000001',
        ], $config);

        return new AdyenGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(AdyenGateway $gateway): MockHttpClient
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
     * 结算到外部银行：验证 Transfers API 端点、bank 分类、balanceAccountId 来源、iban 收款人
     */
    public function testSettleToPayoutPostsBankTransfer(): void
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
        $this->assertStringContainsString('pal/servlet/Transfer/v68/transfer', $last['url']);
        $this->assertSame('bank', $last['data']['category']);
        $this->assertSame('BA00000000000000000000001', $last['data']['balanceAccount']);
        $this->assertSame('SETTLE_1', $last['data']['reference']);
        $this->assertSame(10000, $last['data']['amount']['value']);
        $this->assertSame('EUR', $last['data']['amount']['currency']);
        $this->assertSame('GB29NWBK60161331926819', $last['data']['counterparty']['bankAccount']['iban']);
        $this->assertSame('张三', $last['data']['counterparty']['bankAccount']['holderName']);
    }

    /**
     * 结算到银行卡：验证 category=card 与 cardAccount 收款人
     */
    public function testSettleToBankCardPostsCardTransfer(): void
    {
        $gateway = $this->createGateway();

        $gateway->settleToBankCard([
            'out_biz_no' => 'SETTLE_C',
            'amount' => 5000,
            'bank_card_no' => '4111111111111111',
            'real_name' => '李四',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertSame('card', $last['data']['category']);
        $this->assertSame('4111111111111111', $last['data']['counterparty']['cardAccount']['number']);
        $this->assertSame('李四', $last['data']['counterparty']['cardAccount']['holderName']);
        $this->assertSame('BA00000000000000000000001', $last['data']['balanceAccount']);
    }

    /**
     * 缺少余额账户配置时，结算出款应明确报配置错误
     */
    public function testSettleToPayoutRequiresBalanceAccount(): void
    {
        $gateway = $this->createGateway([], ['balance_account_id' => '']);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('balance_account_id');

        $gateway->settleToPayout([
            'out_biz_no' => 'SETTLE_X',
            'amount' => 100,
            'account' => 'GB29NWBK60161331926819',
        ]);
    }

    /**
     * 结算到平台内钱包：Adyen 无此语义，报「无此方法」
     */
    public function testSettleToWalletNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->settleToWallet(['out_biz_no' => 'S', 'amount' => 1, 'account' => 'a']);
    }

    /**
     * 查询结算：按 reference 走 Transfer 查询端点
     */
    public function testQuerySettlementFiltersByReference(): void
    {
        $gateway = $this->createGateway();

        $gateway->querySettlement('SETTLE_1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('pal/servlet/Transfer/v68/transfer', $last['url']);
        $this->assertSame('SETTLE_1', $last['data']['reference'] ?? '');
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
}
