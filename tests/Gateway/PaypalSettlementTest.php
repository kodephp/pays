<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Paypal\PaypalGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * PayPal 网关「自动结算」原生方法单元测试
 *
 * 验证 settleToPayout / querySettlement 正确组装 Payouts 批次请求（Bearer 鉴权、
 * 金额由分换算为两位小数）；settleToWallet / settleToBankCard 无对应语义，报「无此方法」。
 */
class PaypalSettlementTest extends TestCase
{
    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): PaypalGateway
    {
        $config = array_merge([
            'client_id' => 'cid_test',
            'client_secret' => 'csec_test',
        ], $config);

        return new PaypalGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(PaypalGateway $gateway): MockHttpClient
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
     * @return array{method: string, url: string, data: array<string, mixed>, headers: array<string, string>}
     */
    private function findRequest(MockHttpClient $client, string $urlFragment): array
    {
        foreach ($client->getHistory() as $request) {
            if (str_contains($request['url'], $urlFragment)) {
                return $request;
            }
        }

        $this->fail("未找到 URL 包含 {$urlFragment} 的请求");
    }

    /**
     * 结算到 PayPal 账户：验证 Payouts 端点、批次号、金额换算与鉴权头
     */
    public function testSettleToPayoutBuildsPayoutBatch(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/payments/payouts' => json_encode([
                'batch_header' => ['payout_batch_id' => 'PB_1', 'batch_status' => 'PENDING'],
            ]),
        ]);

        $result = $gateway->settleToPayout([
            'out_biz_no' => 'SETTLE_1',
            'amount' => 12345,
            'account' => 'payee@example.com',
            'description' => 'Auto settlement',
        ]);

        $this->assertSame('PB_1', $result['batch_header']['payout_batch_id']);

        $request = $this->findRequest($this->getMockClient($gateway), 'v1/payments/payouts');
        $this->assertSame('SETTLE_1', $request['data']['sender_batch_header']['sender_batch_id']);

        $item = $request['data']['items'][0];
        $this->assertSame('EMAIL', $item['recipient_type']);
        $this->assertSame('payee@example.com', $item['receiver']);
        $this->assertSame('123.45', $item['amount']['value']);
        $this->assertSame('USD', $item['amount']['currency']);
        $this->assertSame('SETTLE_1', $item['sender_item_id']);
        $this->assertSame('Bearer pp_token', $request['headers']['Authorization'] ?? '');
    }

    /**
     * 结算到 PayPal 账户：币种统一转大写
     */
    public function testSettleToPayoutNormalizesCurrency(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/payments/payouts' => json_encode(['batch_header' => ['payout_batch_id' => 'PB_2']]),
        ]);

        $gateway->settleToPayout([
            'out_biz_no' => 'SETTLE_2',
            'amount' => 100,
            'account' => 'payee@example.com',
            'currency' => 'eur',
        ]);

        $request = $this->findRequest($this->getMockClient($gateway), 'v1/payments/payouts');
        $this->assertSame('EUR', $request['data']['items'][0]['amount']['currency']);
        $this->assertSame('1.00', $request['data']['items'][0]['amount']['value']);
    }

    /**
     * 结算到 PayPal 账户：缺 account 抛 PayException
     */
    public function testSettleToPayoutMissingAccount(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：account');

        $gateway->settleToPayout(['out_biz_no' => 'SETTLE_3', 'amount' => 100]);
    }

    /**
     * 查询结算结果：按 payout_batch_id 查询
     */
    public function testQuerySettlementGetsPayoutBatch(): void
    {
        $gateway = $this->createGateway([
            'v1/oauth2/token' => json_encode(['access_token' => 'pp_token']),
            'v1/payments/payouts/PB_1' => json_encode([
                'batch_header' => ['payout_batch_id' => 'PB_1', 'batch_status' => 'SUCCESS'],
            ]),
        ]);

        $result = $gateway->querySettlement('PB_1');

        $this->assertSame('SUCCESS', $result['batch_header']['batch_status']);

        $request = $this->findRequest($this->getMockClient($gateway), 'v1/payments/payouts/PB_1');
        $this->assertSame('Bearer pp_token', $request['headers']['Authorization'] ?? '');
    }

    /**
     * PayPal 无平台内钱包结算语义，调用即报「无此方法」
     */
    public function testSettleToWalletNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->settleToWallet(['out_biz_no' => 'S', 'amount' => 1, 'account' => 'a']);
    }

    /**
     * PayPal 不支持直连银行卡结算，调用即报「无此方法」
     */
    public function testSettleToBankCardNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->settleToBankCard(['out_biz_no' => 'S', 'amount' => 1, 'bank_card_no' => '1', 'real_name' => 'a']);
    }
}
