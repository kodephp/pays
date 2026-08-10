<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\Pays\Support\HttpClient;
use Kode\Pays\Tests\TestCase;
use RuntimeException;

/**
 * HTTP 客户端单元测试
 *
 * 覆盖连接池保留、请求级超时合并、指数退避重试与幂等白名单、响应体大小上限。
 */
class HttpClientTest extends TestCase
{
    /**
     * setTimeout/setConnectTimeout 不应重建 Client，保留 curl 连接池
     */
    public function testSetTimeoutDoesNotRebuildClient(): void
    {
        $http = new HttpClient();
        $client = $http->getClient();

        $http->setTimeout(5);
        $http->setConnectTimeout(3);

        // 同一实例说明连接池未被丢弃
        $this->assertSame($client, $http->getClient());
    }

    /**
     * 超时通过请求级选项覆盖生效（不重建 Client）
     */
    public function testPerRequestTimeoutMerged(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([new Response(200, [], 'ok')]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $http = new HttpClient(['handler' => $stack]);
        $http->setTimeout(7);
        $http->setConnectTimeout(2);

        $this->assertSame('ok', $http->get('https://api.test/x'));

        $this->assertCount(1, $container);
        $this->assertSame(7, $container[0]['options']['timeout']);
        $this->assertSame(2, $container[0]['options']['connect_timeout']);
    }

    /**
     * 连接异常（ConnectException）按指数退避重试一次后恢复
     */
    public function testRetryOnConnectException(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new ConnectException('conn down', new Request('GET', 'https://api.test/x')),
            new Response(200, [], 'recovered'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $http = new HttpClient(['handler' => $stack]);
        $http->setRetry(1, 10);

        $this->assertSame('recovered', $http->get('https://api.test/x'));
        $this->assertCount(2, $container, 'ConnectException 后应重试一次');
    }

    /**
     * 非连接异常的 POST 不重试（避免重复下单/退款）
     */
    public function testPostNotRetriedOnNonConnectException(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new RequestException('boom', new Request('POST', 'https://api.test/x')),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $http = new HttpClient(['handler' => $stack]);
        $http->setRetry(2, 10);

        $this->expectException(RequestException::class);
        try {
            $http->post('https://api.test/x', ['a' => '1']);
        } finally {
            $this->assertCount(1, $container, 'POST 非连接异常不应重试');
        }
    }

    /**
     * 幂等安全方法（GET）即使非连接异常也重试
     */
    public function testGetRetriedOnNonConnectException(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new RequestException('boom', new Request('GET', 'https://api.test/x')),
            new Response(200, [], 'ok'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $http = new HttpClient(['handler' => $stack]);
        $http->setRetry(1, 5);

        $this->assertSame('ok', $http->get('https://api.test/x'));
        $this->assertCount(2, $container, 'GET 非连接异常应重试');
    }

    /**
     * 响应体超过上限时抛 RuntimeException
     */
    public function testMaxResponseBytesEnforced(): void
    {
        $big = str_repeat('a', 100);
        $mock = new MockHandler([new Response(200, [], $big)]);
        $http = new HttpClient(['handler' => HandlerStack::create($mock)]);
        $http->setMaxResponseBytes(10);

        $this->expectException(RuntimeException::class);
        $http->get('https://api.test/x');
    }

    /**
     * 默认不限制响应体大小
     */
    public function testMaxResponseBytesUnlimitedByDefault(): void
    {
        $big = str_repeat('a', 100);
        $mock = new MockHandler([new Response(200, [], $big)]);
        $http = new HttpClient(['handler' => HandlerStack::create($mock)]);

        $this->assertSame(100, strlen($http->get('https://api.test/x')));
        $this->assertSame(0, $http->getMaxResponseBytes());
    }
}
