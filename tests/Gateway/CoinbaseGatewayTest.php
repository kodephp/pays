<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Coinbase 网关「加密货币能力」原生方法单元测试
 *
 * 验证 implements CryptoCapableInterface 后各原生方法正确组装请求并调用基类 HTTP 通道
 * （不依赖真实网络）。
 */
class CoinbaseGatewayTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): CoinbaseGateway
    {
        $config = array_merge([
            'api_key' => 'test_api_key',
            'webhook_secret' => 'test_webhook_secret',
        ], $config);

        return new CoinbaseGateway($config, new MockHttpClient($responses));
    }

    public function testCreateOrderBuildsChargeRequest(): void
    {
        $responses = [
            'v2/charges' => json_encode([
                'data' => [
                    'id' => 'chg_abc',
                    'code' => 'code_xyz',
                    'hosted_url' => 'https://commerce.coinbase.com/charges/chg_abc',
                    'timeline' => [['status' => 'NEW']],
                    'pricing' => [],
                    'addresses' => [],
                ],
            ]),
        ];

        $gateway = $this->createGateway($responses);
        $result = $gateway->createOrder([
            'out_trade_no' => 'O1',
            'total_amount' => 10000,
            'currency' => 'USD',
        ]);

        $this->assertSame('O1', $result['out_trade_no']);
        $this->assertSame('chg_abc', $result['charge_id']);
        $this->assertSame('https://commerce.coinbase.com/charges/chg_abc', $result['hosted_url']);

        $last = $this->lastRequest($gateway);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('v2/charges', $last['url']);
        $this->assertSame('test_api_key', $last['headers']['X-CC-Api-Key'] ?? '');
    }

    public function testCreateOrderRequiresOutTradeNo(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->createOrder(['total_amount' => 10000]);
    }

    public function testCreateCryptoOrderRejectsUnsupportedCurrency(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->createCryptoOrder([
            'out_trade_no' => 'O2',
            'crypto_amount' => '1.0',
            'crypto_currency' => 'NOTREAL',
        ]);
    }

    public function testQueryOrderParsesCharge(): void
    {
        $responses = [
            'v2/charges' => json_encode([
                'data' => [
                    'id' => 'chg_q',
                    'code' => 'c_q',
                    'timeline' => [['status' => 'COMPLETED']],
                    'pricing' => [],
                    'payments' => [],
                    'addresses' => [],
                ],
            ]),
        ];

        $gateway = $this->createGateway($responses);
        $result = $gateway->queryOrder('chg_q');

        $this->assertSame('chg_q', $result['charge_id']);
        $this->assertSame('COMPLETED', $result['status']);
    }

    public function testRefundPostsToRefundEndpoint(): void
    {
        $responses = [
            'refund' => json_encode([
                'data' => ['id' => 'ref_1', 'status' => 'pending'],
            ]),
        ];

        $gateway = $this->createGateway($responses);
        $result = $gateway->refund(['charge_id' => 'chg_r', 'refund_fee' => 500]);

        $this->assertSame('ref_1', $result['data']['id'] ?? '');

        $last = $this->lastRequest($gateway);
        $this->assertStringContainsString('v2/charges/chg_r/refund', $last['url']);
    }

    public function testGetConfirmationsParsesPayments(): void
    {
        $responses = [
            'v2/charges' => json_encode([
                'data' => [
                    'payments' => [
                        [
                            'transaction_id' => 'tx1',
                            'status' => 'CONFIRMED',
                            'confirmations' => 6,
                            'confirmations_required' => 6,
                            'value' => ['crypto' => ['currency' => 'BTC']],
                        ],
                    ],
                ],
            ]),
        ];

        $gateway = $this->createGateway($responses);
        $result = $gateway->getConfirmations('chg_c');

        $this->assertArrayHasKey('BTC', $result);
        $this->assertSame('tx1', $result['BTC']['transaction_id']);
        $this->assertSame(6, $result['BTC']['confirmations']);
    }

    public function testGetExchangeRateReadsRates(): void
    {
        $responses = [
            'v2/exchange-rates' => json_encode([
                'data' => [
                    'currency' => 'USD',
                    'rates' => ['btc' => '60000'],
                    'timestamp' => 1700000000,
                ],
            ]),
        ];

        $gateway = $this->createGateway($responses);
        $result = $gateway->getExchangeRate('BTC', 'USD');

        $this->assertSame('BTC', $result['crypto_currency']);
        $this->assertSame('60000', $result['rate']);
    }

    public function testVerifyNotifyRejectsMissingSignature(): void
    {
        $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] = '';

        $this->assertFalse($this->createGateway()->verifyNotify([]));

        unset($_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE']);
    }

    public function testGatewayNameIsCoinbase(): void
    {
        $this->assertSame('coinbase', CoinbaseGateway::getName());
    }

    /**
     * 取网关最近一次请求的记录（经反射读取 MockHttpClient 历史）
     *
     * @return array{method: string, url: string, data: array<string, mixed>, headers: array<string, string>}
     */
    private function lastRequest(CoinbaseGateway $gateway): array
    {
        $ref = new \ReflectionClass($gateway);
        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        /** @var MockHttpClient $client */
        $client = $prop->getValue($gateway);

        return $client->getLastRequest() ?? ['method' => '', 'url' => '', 'data' => [], 'headers' => []];
    }
}
