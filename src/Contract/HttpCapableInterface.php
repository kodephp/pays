<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 具备 HTTP 通道能力的网关接口
 *
 * 插件（退款、转账、分账、对账等）需要复用网关已配置好的 HTTP 通道
 * （含签名、证书、基础 URL、中间件与事件）发起额外的接口调用。
 * 该接口把这项能力显式契约化，避免插件对具体实现类的隐式依赖。
 *
 * {@see \Kode\Pays\Core\AbstractGateway} 已默认实现本接口，
 * 因此所有继承自 AbstractGateway 的网关均自动具备该能力。
 *
 * 使用示例：
 * ```php
 * if ($gateway instanceof HttpCapableInterface) {
 *     $result = $gateway->post('/v3/refund/domestic/refunds', $params);
 * }
 * ```
 */
interface HttpCapableInterface
{
    /**
     * 发送 POST 请求并解析响应
     *
     * @param string $endpoint API 端点（相对于网关基础 URL）
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 额外请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    public function post(string $endpoint, array $data = [], array $headers = []): array;

    /**
     * 发送 POST 请求（原始 body）并解析响应
     *
     * @param string $endpoint API 端点（相对于网关基础 URL）
     * @param string $body 原始请求体
     * @param array<string, string> $headers 额外请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    public function postRaw(string $endpoint, string $body, array $headers = []): array;

    /**
     * 发送 GET 请求并解析响应
     *
     * @param string $endpoint API 端点（相对于网关基础 URL）
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 额外请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    public function get(string $endpoint, array $query = [], array $headers = []): array;

    /**
     * 发送 PUT 请求并解析响应
     *
     * @param string $endpoint API 端点（相对于网关基础 URL）
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 额外请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    public function put(string $endpoint, array $data = [], array $headers = []): array;

    /**
     * 发送 DELETE 请求并解析响应
     *
     * @param string $endpoint API 端点（相对于网关基础 URL）
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 额外请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    public function delete(string $endpoint, array $query = [], array $headers = []): array;
}
