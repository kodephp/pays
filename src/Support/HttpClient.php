<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kode\Pays\Contract\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP 客户端封装
 *
 * 基于 GuzzleHttp 提供统一的 GET/POST/PUT/DELETE 请求能力，支持超时、SSL、连接池、请求日志等配置。
 * 实现 HttpClientInterface，允许用户注入自定义客户端。
 */
class HttpClient implements HttpClientInterface
{
    /**
     * Guzzle 客户端实例
     */
    protected Client $client;

    /**
     * 默认请求超时时间（秒）
     */
    protected int $timeout = 30;

    /**
     * 默认连接超时时间（秒）
     */
    protected int $connectTimeout = 10;

    /**
     * 最大重试次数
     */
    protected int $maxRetries = 0;

    /**
     * 重试间隔（毫秒）
     */
    protected int $retryDelay = 1000;

    /**
     * 请求日志记录器
     */
    protected ?LoggerInterface $logger = null;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $options Guzzle 额外配置
     */
    public function __construct(array $options = [])
    {
        $defaults = [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => false,
            'verify' => true,
        ];

        $this->client = new Client(array_merge($defaults, $options));
    }

    /**
     * 发送 GET 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws GuzzleException
     */
    public function get(string $url, array $query = [], array $headers = []): string
    {
        return $this->requestWithRetry('GET', $url, [
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    /**
     * 发送 POST 请求（表单或 JSON）
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws GuzzleException
     */
    public function post(string $url, array $data = [], array $headers = []): string
    {
        $options = ['headers' => $headers];

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $options['json'] = $data;
        } else {
            $options['form_params'] = $data;
        }

        return $this->requestWithRetry('POST', $url, $options);
    }

    /**
     * 发送 POST 请求（原始 body）
     *
     * @param string $url 请求地址
     * @param string $body 原始请求体
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws GuzzleException
     */
    public function postRaw(string $url, string $body, array $headers = []): string
    {
        return $this->requestWithRetry('POST', $url, [
            'body' => $body,
            'headers' => $headers,
        ]);
    }

    /**
     * 发送 PUT 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws GuzzleException
     */
    public function put(string $url, array $data = [], array $headers = []): string
    {
        $options = ['headers' => $headers];

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $options['json'] = $data;
        } else {
            $options['form_params'] = $data;
        }

        return $this->requestWithRetry('PUT', $url, $options);
    }

    /**
     * 发送 DELETE 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws GuzzleException
     */
    public function delete(string $url, array $query = [], array $headers = []): string
    {
        return $this->requestWithRetry('DELETE', $url, [
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    /**
     * 带重试机制的统一请求方法
     *
     * @param string $method HTTP 方法
     * @param string $url 请求地址
     * @param array<string, mixed> $options Guzzle 请求选项
     * @return string 响应体
     * @throws GuzzleException
     */
    protected function requestWithRetry(string $method, string $url, array $options): string
    {
        $lastException = null;
        $attempts = $this->maxRetries + 1;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $startTime = microtime(true);
                $response = $this->client->request($method, $url, $options);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                $body = (string) $response->getBody();

                $this->logRequest($method, $url, $options, $response->getStatusCode(), $duration);

                return $body;
            } catch (GuzzleException $e) {
                $lastException = $e;

                $this->logError($method, $url, $e, $i + 1);

                if ($i < $this->maxRetries) {
                    usleep($this->retryDelay * 1000);
                }
            }
        }

        throw $lastException;
    }

    /**
     * 记录请求日志
     *
     * @param string $method HTTP 方法
     * @param string $url 请求地址
     * @param array<string, mixed> $options 请求选项
     * @param int $statusCode 响应状态码
     * @param float $duration 请求耗时（毫秒）
     */
    protected function logRequest(string $method, string $url, array $options, int $statusCode, float $duration): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'duration_ms' => $duration,
        ];

        if ($statusCode >= 400) {
            $this->logger->warning('HTTP 请求返回非成功状态码', $context);
        } else {
            $this->logger->debug('HTTP 请求完成', $context);
        }
    }

    /**
     * 记录错误日志
     *
     * @param string $method HTTP 方法
     * @param string $url 请求地址
     * @param GuzzleException $exception 异常
     * @param int $attempt 当前尝试次数
     */
    protected function logError(string $method, string $url, GuzzleException $exception, int $attempt): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->error('HTTP 请求失败', [
            'method' => $method,
            'url' => $url,
            'attempt' => $attempt,
            'max_retries' => $this->maxRetries,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * 设置超时时间
     *
     * @param int $seconds 秒数
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * 设置连接超时时间
     *
     * @param int $seconds 秒数
     * @return self
     */
    public function setConnectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;

        return $this;
    }

    /**
     * 设置重试策略
     *
     * @param int $maxRetries 最大重试次数
     * @param int $delayMs 重试间隔（毫秒）
     * @return self
     */
    public function setRetry(int $maxRetries, int $delayMs = 1000): self
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $delayMs;

        return $this;
    }

    /**
     * 设置请求日志记录器
     *
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * 获取 Guzzle 客户端实例
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
