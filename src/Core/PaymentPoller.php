<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\GatewayInterface;

/**
 * 支付结果轮询器
 *
 * 自动轮询支付订单状态，支持自定义轮询间隔、最大次数、超时时间。
 * 适用于需要异步确认支付结果的场景（如扫码支付、H5 支付）。
 *
 * 使用示例：
 * ```php
 * $poller = new PaymentPoller($gateway);
 *
 * $result = $poller->poll('ORDER_001', function ($status, $data) {
 *     if ($status === 'SUCCESS') {
 *         // 支付成功，更新订单状态
 *         return true; // 停止轮询
 *     }
 *     return false; // 继续轮询
 * });
 * ```
 */
class PaymentPoller
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 轮询间隔（秒）
     */
    protected int $interval;

    /**
     * 最大轮询次数
     */
    protected int $maxAttempts;

    /**
     * 总超时时间（秒）
     */
    protected int $timeout;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关
     * @param int $interval 轮询间隔（秒），默认 3
     * @param int $maxAttempts 最大轮询次数，默认 20
     * @param int $timeout 总超时时间（秒），默认 60
     */
    public function __construct(
        GatewayInterface $gateway,
        int $interval = 3,
        int $maxAttempts = 20,
        int $timeout = 60,
    ) {
        $this->gateway = $gateway;
        $this->interval = $interval;
        $this->maxAttempts = $maxAttempts;
        $this->timeout = $timeout;
    }

    /**
     * 轮询订单支付状态
     *
     * @param string $orderId 商户订单号
     * @param callable|null $callback 状态回调，返回 true 停止轮询
     * @return array<string, mixed> 最终查询结果
     * @throws PayException
     */
    public function poll(string $orderId, ?callable $callback = null): array
    {
        $startTime = time();
        $lastResult = [];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            // 检查总超时
            if (time() - $startTime >= $this->timeout) {
                throw PayException::networkError('支付结果轮询超时');
            }

            try {
                $result = $this->gateway->queryOrder($orderId);
                $lastResult = $result;

                $status = $this->extractStatus($result);

                // 如果提供了回调，由回调决定是否停止
                if ($callback !== null) {
                    if ($callback($status, $result)) {
                        return $result;
                    }
                } else {
                    // 默认逻辑：支付成功或已关闭则停止
                    if (in_array($status, ['SUCCESS', 'CLOSED', 'REVOKED'], true)) {
                        return $result;
                    }
                }
            } catch (PayException $e) {
                // 订单不存在可能意味着还未创建成功，继续轮询
                if ($e->getCode() !== PayException::ERROR_ORDER_NOT_FOUND) {
                    throw $e;
                }
            }

            // 最后一次不等待
            if ($attempt < $this->maxAttempts) {
                sleep($this->interval);
            }
        }

        return $lastResult;
    }

    /**
     * 异步轮询（非阻塞，通过回调通知结果）
     *
     * @param string $orderId 商户订单号
     * @param callable $onSuccess 支付成功回调
     * @param callable|null $onFailure 支付失败/超时回调
     * @param callable|null $onProgress 每次轮询进度回调
     */
    public function pollAsync(
        string $orderId,
        callable $onSuccess,
        ?callable $onFailure = null,
        ?callable $onProgress = null,
    ): void {
        $startTime = time();

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            if (time() - $startTime >= $this->timeout) {
                if ($onFailure !== null) {
                    $onFailure('TIMEOUT', ['message' => '轮询超时']);
                }

                return;
            }

            try {
                $result = $this->gateway->queryOrder($orderId);
                $status = $this->extractStatus($result);

                if ($onProgress !== null) {
                    $onProgress($attempt, $status, $result);
                }

                if ($status === 'SUCCESS') {
                    $onSuccess($result);

                    return;
                }

                if (in_array($status, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                    if ($onFailure !== null) {
                        $onFailure($status, $result);
                    }

                    return;
                }
            } catch (PayException $e) {
                if ($onProgress !== null) {
                    $onProgress($attempt, 'ERROR', ['error' => $e->getMessage()]);
                }
            }

            if ($attempt < $this->maxAttempts) {
                sleep($this->interval);
            }
        }

        if ($onFailure !== null) {
            $onFailure('MAX_ATTEMPTS', ['message' => '达到最大轮询次数']);
        }
    }

    /**
     * 从查询结果中提取支付状态
     *
     * @param array<string, mixed> $result 查询结果
     * @return string
     */
    protected function extractStatus(array $result): string
    {
        return $result['trade_state']
            ?? $result['trade_status']
            ?? $result['status']
            ?? 'UNKNOWN';
    }

    /**
     * 设置轮询间隔
     *
     * @param int $seconds 秒数
     * @return self
     */
    public function setInterval(int $seconds): self
    {
        $this->interval = $seconds;

        return $this;
    }

    /**
     * 设置最大轮询次数
     *
     * @param int $count 次数
     * @return self
     */
    public function setMaxAttempts(int $count): self
    {
        $this->maxAttempts = $count;

        return $this;
    }

    /**
     * 设置总超时时间
     *
     * @param int $seconds 秒数
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }
}
