<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Support\HttpClient;

/**
 * 内存假网关：实现 GatewayInterface + HttpCapableInterface，
 * createOrder 记录调用并返回微信风格 code_url，不发起真实 HTTP。
 */
class FakeGateway implements GatewayInterface, HttpCapableInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function __construct(array $config = [], ?HttpClient $httpClient = null)
    {
    }

    public function createOrder(array $params): array
    {
        $this->calls[] = $params;

        return ['code_url' => 'weixin://wxpay/bizpayurl?pr=' . ($params['out_trade_no'] ?? '')];
    }

    public function queryOrder(string $orderId): array
    {
        return [];
    }

    public function refund(array $params): array
    {
        return [];
    }

    public function queryRefund(string $refundId): array
    {
        return [];
    }

    public function verifyNotify(array $data): bool
    {
        return true;
    }

    public function closeOrder(string $orderId): array
    {
        return [];
    }

    public static function getName(): string
    {
        return 'fakechan';
    }

    public function setDispatcher(EventDispatcher $dispatcher): void
    {
    }

    public function setHttpClient(HttpClient $httpClient): void
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
