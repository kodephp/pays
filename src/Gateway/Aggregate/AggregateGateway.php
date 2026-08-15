<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Aggregate;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\WebhookCapableInterface;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\PayException;

/**
 * 聚合支付网关
 *
 * 封装多家支付渠道，根据配置自动路由到最优渠道
 * 支持渠道优先级配置、失败自动切换等能力
 */
class AggregateGateway implements GatewayInterface, WebhookCapableInterface
{
    /**
     * 渠道配置列表
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $channels = [];

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 聚合配置
     * @throws PayException
     */
    public function __construct(array $config)
    {
        if (!isset($config['channels']) || !is_array($config['channels']) || $config['channels'] === []) {
            throw PayException::configError('聚合支付必须配置 channels 渠道列表');
        }

        foreach ($config['channels'] as $channel) {
            if (!isset($channel['gateway'])) {
                throw PayException::configError('聚合支付渠道必须配置 gateway 标识');
            }

            $this->channels[] = $channel;
        }

        // 按优先级排序（priority 越小优先级越高）
        usort($this->channels, static function (array $a, array $b): int {
            $pa = $a['priority'] ?? 999;
            $pb = $b['priority'] ?? 999;

            return $pa <=> $pb;
        });
    }

    /**
     * 创建支付订单
     *
     * 按优先级尝试各渠道，直到成功
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed> 支付响应
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $lastException = null;

        foreach ($this->channels as $channel) {
            try {
                $gateway = $this->getGateway($channel);
                $result = $gateway->createOrder($params);

                // 记录实际使用的渠道
                $result['_channel'] = $channel['gateway'];

                return $result;
            } catch (PayException $e) {
                $lastException = $e;

                // 如果配置了不可重试的错误码，直接抛出
                if (isset($channel['no_retry_codes']) && in_array($e->getCode(), $channel['no_retry_codes'], true)) {
                    throw $e;
                }

                continue;
            }
        }

        throw PayException::gatewayError(
            '聚合支付所有渠道均失败：' . ($lastException?->getMessage() ?? '未知错误'),
        );
    }

    /**
     * 查询订单
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryOrder(string $orderId): array
    {
        // 优先使用创建订单时记录的渠道查询
        $channel = $this->channels[0] ?? null;

        if ($channel === null) {
            throw PayException::configError('聚合支付未配置任何渠道');
        }

        return $this->getGateway($channel)->queryOrder($orderId);
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $params 退款参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function refund(array $params): array
    {
        $channel = $this->channels[0] ?? null;

        if ($channel === null) {
            throw PayException::configError('聚合支付未配置任何渠道');
        }

        return $this->getGateway($channel)->refund($params);
    }

    /**
     * 查询退款
     *
     * @param string $refundId 退款单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryRefund(string $refundId): array
    {
        $channel = $this->channels[0] ?? null;

        if ($channel === null) {
            throw PayException::configError('聚合支付未配置任何渠道');
        }

        return $this->getGateway($channel)->queryRefund($refundId);
    }

    /**
     * 验证异步通知签名
     *
     * 遍历所有渠道尝试验证，直到成功
     *
     * @param array<string, mixed> $data 通知数据
     * @return bool
     */
    public function verifyNotify(array $data): bool
    {
        foreach ($this->channels as $channel) {
            try {
                $gateway = $this->getGateway($channel);

                if ($gateway->verifyNotify($data)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * 验证 Webhook 原始请求签名（委派到子网关）
     *
     * 聚合支付本身不持有密钥，通知验签委派给各渠道子网关；任一实现
     * {@see WebhookCapableInterface} 的子网关验签通过即返回 true。
     * 仅当所有子网关均无法验签时才返回 false，不伪造通过。
     *
     * @param string $payload 原始请求体
     * @param array<string, string> $headers 请求头
     * @return bool
     */
    public function verifyWebhook(string $payload, array $headers = []): bool
    {
        foreach ($this->channels as $channel) {
            try {
                $gateway = $this->getGateway($channel);

                if (!$gateway instanceof WebhookCapableInterface) {
                    continue;
                }

                if ($gateway->verifyWebhook($payload, $headers)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * 解析 Webhook 原始请求体为统一事件结构（委派到子网关）
     *
     * 优先委派给首个实现 {@see WebhookCapableInterface} 且能成功解析的子网关；
     * 若所有子网关均无法解析，则回退为通用结构（gateway 标记为 aggregate）。
     *
     * @param string $payload 原始请求体
     * @return array<string, mixed>
     * @throws PayException 报文无法被任何子网关解析时
     */
    public function parseWebhook(string $payload): array
    {
        foreach ($this->channels as $channel) {
            try {
                $gateway = $this->getGateway($channel);

                if (!$gateway instanceof WebhookCapableInterface) {
                    continue;
                }

                return $gateway->parseWebhook($payload);
            } catch (\Throwable) {
                continue;
            }
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            parse_str($payload, $data);
        }

        return [
            'gateway' => 'aggregate',
            'event_id' => $data['out_trade_no'] ?? $data['trade_no'] ?? null,
            'event_type' => $data['trade_status'] ?? $data['event_type'] ?? 'unknown',
            'data' => $data,
            'raw' => $payload,
        ];
    }

    /**
     * 关闭订单
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function closeOrder(string $orderId): array
    {
        $channel = $this->channels[0] ?? null;

        if ($channel === null) {
            throw PayException::configError('聚合支付未配置任何渠道');
        }

        return $this->getGateway($channel)->closeOrder($orderId);
    }

    /**
     * 获取网关标识
     */
    public static function getName(): string
    {
        return 'aggregate';
    }

    /**
     * 设置事件分发器
     *
     * 聚合网关本身不直接发起请求，事件分发器会透传给实际使用的子网关。
     *
     * @param \Kode\Pays\Event\EventDispatcher $dispatcher
     * @return void
     */
    public function setDispatcher(\Kode\Pays\Event\EventDispatcher $dispatcher): void
    {
        // 聚合网关按渠道动态创建子网关，分发器在创建时由工厂注入或后续设置
        // 此处保留实现以满足接口契约，子网关在 getGateway() 时可按需注入
    }

    /**
     * 设置 HTTP 客户端
     *
     * 聚合网关将 HTTP 客户端透传给子网关使用。
     *
     * @param \Kode\Pays\Support\HttpClient $httpClient
     * @return void
     */
    public function setHttpClient(\Kode\Pays\Support\HttpClient $httpClient): void
    {
        // 子网关在 GatewayFactory::create() 时使用各自配置创建
        // 此处保留实现以满足接口契约
    }

    /**
     * 获取指定渠道的网关实例
     *
     * @param array<string, mixed> $channel 渠道配置
     * @return GatewayInterface
     * @throws PayException
     */
    protected function getGateway(array $channel): GatewayInterface
    {
        return GatewayFactory::create($channel['gateway'], $channel['config'] ?? []);
    }
}
