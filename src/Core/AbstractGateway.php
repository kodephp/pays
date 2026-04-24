<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Event\EventDispatcher;
use Kode\Pays\Event\Events;
use Kode\Pays\Pipeline\Pipeline;
use Kode\Pays\Support\HttpClient;

/**
 * 支付网关抽象基类
 *
 * 所有具体支付网关应继承此类，复用通用 HTTP 请求、事件触发、管道中间件等能力。
 * 子类只需关注业务逻辑，无需处理底层通信和横切关注点。
 */
abstract class AbstractGateway implements GatewayInterface
{
    /**
     * HTTP 客户端
     */
    protected HttpClient $httpClient;

    /**
     * 网关配置数组
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * 是否沙箱模式
     */
    protected bool $sandbox = false;

    /**
     * 事件分发器（可选，用于触发支付生命周期事件）
     */
    protected ?EventDispatcher $dispatcher = null;

    /**
     * 管道中间件栈
     *
     * @var array<callable>
     */
    protected array $middleware = [];

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 网关配置
     * @param HttpClient|null $httpClient HTTP 客户端（可选，用于测试注入）
     */
    public function __construct(array $config, ?HttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->sandbox = $config['sandbox'] ?? false;
        $this->httpClient = $httpClient ?? new HttpClient();

        $this->initialize();
    }

    /**
     * 初始化钩子
     *
     * 子类可重写此方法进行额外初始化（如加载证书、设置基础 URL 等）
     */
    protected function initialize(): void
    {
    }

    /**
     * 设置事件分发器
     *
     * @param EventDispatcher $dispatcher
     * @return self
     */
    public function setDispatcher(EventDispatcher $dispatcher): self
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }

    /**
     * 注册中间件
     *
     * @param callable $middleware
     * @return self
     */
    public function pushMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * 获取配置项
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * 获取当前环境的基础 API URL
     *
     * @return string 基础 URL
     */
    abstract protected function getBaseUrl(): string;

    /**
     * 发送 POST 请求并解析响应
     *
     * 自动触发 REQUEST_SENDING 和 REQUEST_SENT 事件
     *
     * @param string $endpoint API 端点
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    protected function post(string $endpoint, array $data = [], array $headers = []): array
    {
        return $this->request('post', $endpoint, $data, $headers);
    }

    /**
     * 发送 POST 请求（原始 body）并解析响应
     *
     * @param string $endpoint API 端点
     * @param string $body 原始请求体
     * @param array<string, string> $headers 请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    protected function postRaw(string $endpoint, string $body, array $headers = []): array
    {
        $url = $this->getBaseUrl() . $endpoint;

        $this->emit(Events::REQUEST_SENDING, [
            'gateway' => static::getName(),
            'endpoint' => $endpoint,
            'method' => 'POST_RAW',
            'params' => $body,
        ]);

        try {
            $response = $this->httpClient->postRaw($url, $body, $headers);
        } catch (\Throwable $e) {
            throw PayException::networkError('请求发送失败：' . $e->getMessage(), $e);
        }

        $this->emit(Events::REQUEST_SENT, [
            'gateway' => static::getName(),
            'endpoint' => $endpoint,
            'response' => $response,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * 发送 GET 请求并解析响应
     *
     * @param string $endpoint API 端点
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    protected function get(string $endpoint, array $query = [], array $headers = []): array
    {
        return $this->request('get', $endpoint, $query, $headers);
    }

    /**
     * 发送 PUT 请求并解析响应
     *
     * @param string $endpoint API 端点
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    protected function put(string $endpoint, array $data = [], array $headers = []): array
    {
        return $this->request('put', $endpoint, $data, $headers);
    }

    /**
     * 发送 DELETE 请求并解析响应
     *
     * @param string $endpoint API 端点
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    protected function delete(string $endpoint, array $query = [], array $headers = []): array
    {
        return $this->request('delete', $endpoint, $query, $headers);
    }

    /**
     * 统一请求方法
     *
     * 支持管道中间件处理，自动触发事件
     *
     * @param string $method HTTP 方法
     * @param string $endpoint API 端点
     * @param array<string, mixed> $data 请求数据
     * @param array<string, string> $headers 请求头
     * @return array<string, mixed> 解析后的响应
     * @throws PayException
     */
    protected function request(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->getBaseUrl() . $endpoint;

        $payload = [
            'method' => strtoupper($method),
            'url' => $url,
            'endpoint' => $endpoint,
            'data' => $data,
            'headers' => $headers,
        ];

        // 如果有中间件，通过管道处理
        if (!empty($this->middleware)) {
            $pipeline = new Pipeline();
            $payload = $pipeline
                ->send($payload)
                ->through($this->middleware)
                ->then(function (array $payload) {
                    return $payload;
                });
        }

        $this->emit(Events::REQUEST_SENDING, [
            'gateway' => static::getName(),
            'endpoint' => $endpoint,
            'method' => $payload['method'],
            'params' => $payload['data'],
        ]);

        try {
            $response = match (strtolower($method)) {
                'get' => $this->httpClient->get($url, $payload['data'], $payload['headers']),
                'post' => $this->httpClient->post($url, $payload['data'], $payload['headers']),
                'put' => $this->httpClient->put($url, $payload['data'], $payload['headers']),
                'delete' => $this->httpClient->delete($url, $payload['data'], $payload['headers']),
                default => throw PayException::paramError("不支持的 HTTP 方法：{$method}"),
            };
        } catch (\Throwable $e) {
            throw PayException::networkError('请求发送失败：' . $e->getMessage(), $e);
        }

        $this->emit(Events::REQUEST_SENT, [
            'gateway' => static::getName(),
            'endpoint' => $endpoint,
            'response' => $response,
        ]);

        $result = $this->parseResponse($response);

        $this->emit(Events::RESPONSE_PARSED, [
            'gateway' => static::getName(),
            'endpoint' => $endpoint,
            'parsed' => $result,
        ]);

        return $result;
    }

    /**
     * 解析响应内容
     *
     * 子类应重写此方法，将网关原始响应转换为统一数组格式
     *
     * @param string $response 原始响应字符串
     * @return array<string, mixed> 解析后的数据
     * @throws PayException
     */
    abstract protected function parseResponse(string $response): array;

    /**
     * 验证必填参数
     *
     * @param array<string, mixed> $params 待校验参数
     * @param string[] $required 必填字段列表
     * @throws PayException
     */
    protected function validateRequired(array $params, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }

    /**
     * 获取参数校验器
     *
     * 提供链式校验能力，支持类型、范围、枚举等规则
     *
     * @param array<string, mixed> $params 待校验参数
     * @return \Kode\Pays\Support\Validator
     */
    protected function validator(array $params): \Kode\Pays\Support\Validator
    {
        return \Kode\Pays\Support\Validator::make($params);
    }

    /**
     * 包装原始响应为 PayResponse 对象
     *
     * @param array<string, mixed> $raw 原始响应数组
     * @return PayResponse
     */
    protected function wrapResponse(array $raw): PayResponse
    {
        return new PayResponse($raw);
    }

    /**
     * 触发事件
     *
     * @param string $eventName 事件名称
     * @param mixed $payload 事件载荷
     */
    protected function emit(string $eventName, mixed $payload = null): void
    {
        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch($eventName, $payload);
        }
    }
}
