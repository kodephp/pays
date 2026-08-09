<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\CryptoCapableInterface;
use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\CryptoPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 加密货币插件收敛后测试
 *
 * 验证 CryptoPlugin 已退化为「参数可信转发」：
 * - 对实现了 CryptoCapableInterface 的网关，调用直接转发到原生方法
 * - 对未实现的网关，统一抛出「未实现加密货币能力接口」异常（替代原先的硬编码 match）
 * - getOnChainStatus 正确映射到网关 getConfirmations
 */
class CryptoPluginTest extends TestCase
{
    public function testCreateOrderForwardsToCapableGateway(): void
    {
        $gateway = new CryptoCapableFakeGateway();
        $plugin = new CryptoPlugin($gateway);

        $result = $plugin->createOrder(['out_trade_no' => 'O1', 'total_amount' => 100]);

        $this->assertSame('createOrder', $gateway->calls[0][0]);
        $this->assertSame(['out_trade_no' => 'O1', 'total_amount' => 100], $gateway->calls[0][1]);
        $this->assertSame(['createOrder-result'], $result);
    }

    public function testGetOnChainStatusMapsToGetConfirmations(): void
    {
        $gateway = new CryptoCapableFakeGateway();
        $plugin = new CryptoPlugin($gateway);

        $plugin->getOnChainStatus('chg_1');

        $this->assertSame('getConfirmations', $gateway->calls[0][0]);
        $this->assertSame('chg_1', $gateway->calls[0][1]);
    }

    public function testIsConfirmedAggregatesConfirmations(): void
    {
        $gateway = new CryptoCapableFakeGateway();
        $plugin = new CryptoPlugin($gateway);

        $result = $plugin->isConfirmed('chg_1', 6);

        $this->assertTrue($result['confirmed']);
        $this->assertArrayHasKey('BTC', $result['details']);
        $this->assertTrue($result['details']['BTC']['confirmed']);
    }

    public function testNonCryptoGatewayThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('未实现加密货币能力接口');

        $plugin = new CryptoPlugin(new PlainGateway());
        $plugin->createOrder(['out_trade_no' => 'O1']);
    }
}

/**
 * 支持加密货币能力的假网关
 */
class CryptoCapableFakeGateway implements CryptoCapableInterface, GatewayInterface, HttpCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    public static function getName(): string
    {
        return 'fakecrypto';
    }

    public function createOrder(array $params): array
    {
        $this->calls[] = ['createOrder', $params];

        return ['createOrder-result'];
    }

    public function createCryptoOrder(array $params): array
    {
        $this->calls[] = ['createCryptoOrder', $params];

        return ['ok' => true];
    }

    public function getPaymentAddresses(string $orderId): array
    {
        $this->calls[] = ['getPaymentAddresses', $orderId];

        return ['ok' => true];
    }

    public function getConfirmations(string $orderId): array
    {
        $this->calls[] = ['getConfirmations', $orderId];

        return [
            'BTC' => [
                'transaction_id' => 'tx1',
                'confirmations' => 6,
                'required' => 6,
            ],
        ];
    }

    public function getExchangeRate(string $cryptoCurrency, string $fiatCurrency = 'USD'): array
    {
        $this->calls[] = ['getExchangeRate', $cryptoCurrency, $fiatCurrency];

        return ['rate' => '1'];
    }

    public function queryOrder(string $orderId): array
    {
        $this->calls[] = ['queryOrder', $orderId];

        return ['ok' => true];
    }

    public function refund(array $params): array
    {
        $this->calls[] = ['refund', $params];

        return ['ok' => true];
    }

    public function verifyNotify(array $data): bool
    {
        $this->calls[] = ['verifyNotify', $data];

        return true;
    }

    public function queryRefund(string $refundId): array
    {
        $this->calls[] = ['queryRefund', $refundId];

        return ['ok' => true];
    }

    public function closeOrder(string $orderId): array
    {
        $this->calls[] = ['closeOrder', $orderId];

        return ['ok' => true];
    }

    public function setDispatcher(\Kode\Pays\Event\EventDispatcher $dispatcher): void
    {
    }

    public function setHttpClient(\Kode\Pays\Support\HttpClient $httpClient): void
    {
    }

    public function post(string $endpoint, array $data = [], array $headers = []): array
    {
        return [];
    }

    public function postRaw(string $endpoint, string $body, array $headers = []): array
    {
        return [];
    }

    public function get(string $endpoint, array $query = [], array $headers = []): array
    {
        return [];
    }

    public function put(string $endpoint, array $data = [], array $headers = []): array
    {
        return [];
    }

    public function delete(string $endpoint, array $query = [], array $headers = []): array
    {
        return [];
    }
}

/**
 * 普通网关（未实现加密货币能力）
 */
class PlainGateway extends FakeGateway
{
    public static function getName(): string
    {
        return 'plain';
    }
}
