<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kode\Pays\Contract\HttpClientInterface;

/**
 * HTTP 客户端封装
 *
 * 基于 GuzzleHttp 提供统一的 GET/POST/PUT/DELETE 请求能力，支持超时、SSL 等配置。
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
        $options = [];

        if (!empty($query)) {
            $options['query'] = $query;
        }

        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        $response = $this->client->get($url, $options);

        return (string) $response->getBody();
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
        $options = [];

        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        // 根据 Content-Type 决定发送格式
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $options['json'] = $data;
        } else {
            $options['form_params'] = $data;
        }

        $response = $this->client->post($url, $options);

        return (string) $response->getBody();
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
        $options = [
            'body' => $body,
        ];

        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        $response = $this->client->post($url, $options);

        return (string) $response->getBody();
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
        $options = [];

        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $options['json'] = $data;
        } else {
            $options['form_params'] = $data;
        }

        $response = $this->client->put($url, $options);

        return (string) $response->getBody();
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
        $options = [];

        if (!empty($query)) {
            $options['query'] = $query;
        }

        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        $response = $this->client->delete($url, $options);

        return (string) $response->getBody();
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
}
