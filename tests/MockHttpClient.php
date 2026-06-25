<?php

declare(strict_types=1);

namespace Kode\Pays\Tests;

use Kode\Pays\Contract\HttpClientInterface;
use Kode\Pays\Support\HttpClient;

/**
 * Mock HTTP 客户端
 *
 * 用于单元测试，根据预设 URL 返回对应响应，不发起真实网络请求。
 *
 * 注意：继承自 HttpClient 以满足 AbstractGateway 中 `protected HttpClient $httpClient`
 * 这一类型化属性的注入要求（PHP 8.x 反射无法绕过类型化属性的类型校验）。
 * 构造函数刻意不调用父类构造，避免实例化真实 Guzzle 客户端。
 */
class MockHttpClient extends HttpClient implements HttpClientInterface
{
    /**
     * 预设响应映射
     *
     * @var array<string, string>
     */
    protected array $responses = [];

    /**
     * 请求历史记录
     *
     * @var array<int, array{method: string, url: string, data: array<string, mixed>, headers: array<string, string>}>
     */
    protected array $history = [];

    /**
     * 构造函数
     *
     * 刻意不调用 parent::__construct()，避免创建真实 Guzzle 客户端。
     *
     * @param array<string, string> $responses 预设响应映射 [url_pattern => response_body]
     */
    public function __construct(array $responses = [])
    {
        // 不调用父类构造函数，避免实例化 Guzzle 客户端
        $this->responses = $responses;
    }

    /**
     * 发送 GET 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     */
    public function get(string $url, array $query = [], array $headers = []): string
    {
        $this->record('GET', $url, $query, $headers);

        return $this->getResponse($url);
    }

    /**
     * 发送 POST 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     */
    public function post(string $url, array $data = [], array $headers = []): string
    {
        $this->record('POST', $url, $data, $headers);

        return $this->getResponse($url);
    }

    /**
     * 发送 POST 请求（原始 body）
     *
     * @param string $url 请求地址
     * @param string $body 原始请求体
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     */
    public function postRaw(string $url, string $body, array $headers = []): string
    {
        $this->record('POST_RAW', $url, ['body' => $body], $headers);

        return $this->getResponse($url);
    }

    /**
     * 发送 PUT 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     */
    public function put(string $url, array $data = [], array $headers = []): string
    {
        $this->record('PUT', $url, $data, $headers);

        return $this->getResponse($url);
    }

    /**
     * 发送 DELETE 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     */
    public function delete(string $url, array $query = [], array $headers = []): string
    {
        $this->record('DELETE', $url, $query, $headers);

        return $this->getResponse($url);
    }

    /**
     * 添加预设响应
     *
     * @param string $urlPattern URL 模式（支持子串匹配）
     * @param string $response 响应体
     * @return self
     */
    public function addResponse(string $urlPattern, string $response): self
    {
        $this->responses[$urlPattern] = $response;

        return $this;
    }

    /**
     * 获取请求历史
     *
     * @return array<int, array{method: string, url: string, data: array<string, mixed>, headers: array<string, string>}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * 获取最后一次请求
     *
     * @return array{method: string, url: string, data: array<string, mixed>, headers: array<string, string>}|null
     */
    public function getLastRequest(): ?array
    {
        return $this->history[count($this->history) - 1] ?? null;
    }

    /**
     * 获取匹配 URL 的预设响应
     *
     * @param string $url 请求 URL
     * @return string
     */
    protected function getResponse(string $url): string
    {
        foreach ($this->responses as $pattern => $response) {
            if (str_contains($url, $pattern)) {
                return $response;
            }
        }

        return json_encode(['code' => 'SUCCESS', 'message' => 'mock response']);
    }

    /**
     * 记录请求历史
     *
     * @param string $method HTTP 方法
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     */
    protected function record(string $method, string $url, array $data, array $headers): void
    {
        $this->history[] = [
            'method' => $method,
            'url' => $url,
            'data' => $data,
            'headers' => $headers,
        ];
    }
}
