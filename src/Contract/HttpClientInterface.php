<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * HTTP 客户端接口
 *
 * 定义 SDK 所需的 HTTP 能力，允许用户注入自定义实现（如带重试、熔断的客户端）。
 */
interface HttpClientInterface
{
    /**
     * 发送 GET 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws \Throwable
     */
    public function get(string $url, array $query = [], array $headers = []): string;

    /**
     * 发送 POST 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws \Throwable
     */
    public function post(string $url, array $data = [], array $headers = []): string;

    /**
     * 发送 POST 请求（原始 body）
     *
     * @param string $url 请求地址
     * @param string $body 原始请求体
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws \Throwable
     */
    public function postRaw(string $url, string $body, array $headers = []): string;

    /**
     * 发送 PUT 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws \Throwable
     */
    public function put(string $url, array $data = [], array $headers = []): string;

    /**
     * 发送 DELETE 请求
     *
     * @param string $url 请求地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return string 响应体
     * @throws \Throwable
     */
    public function delete(string $url, array $query = [], array $headers = []): string;
}
