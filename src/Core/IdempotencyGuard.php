<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Integration\KodeCacheAdapter;

/**
 * 订单幂等性保护器
 *
 * 防止同一笔订单被重复支付或重复处理，确保支付操作的原子性。
 * 支持基于 kode/cache 分布式锁和本地内存锁两种模式。
 *
 * 使用示例：
 * ```php
 * $guard = new IdempotencyGuard();
 *
 * if ($guard->acquire('ORDER_001')) {
 *     try {
 *         $result = $gateway->createOrder($params);
 *         $guard->markSuccess('ORDER_001', $result);
 *     } catch (\Throwable $e) {
 *         $guard->markFailed('ORDER_001', $e->getMessage());
 *         throw $e;
 *     } finally {
 *         $guard->release('ORDER_001');
 *     }
 * } else {
 *     // 订单正在处理中或已处理完成
 *     $status = $guard->getStatus('ORDER_001');
 * }
 * ```
 */
class IdempotencyGuard
{
    /**
     * 锁前缀
     */
    protected const string LOCK_PREFIX = 'kode_pays_idem:';

    /**
     * 锁默认过期时间（秒）
     */
    protected int $lockTtl;

    /**
     * 结果缓存时间（秒）
     */
    protected int $resultTtl;

    /**
     * 构造函数
     *
     * @param int $lockTtl 锁过期时间（秒），默认 60
     * @param int $resultTtl 结果缓存时间（秒），默认 86400
     */
    public function __construct(int $lockTtl = 60, int $resultTtl = 86400)
    {
        $this->lockTtl = $lockTtl;
        $this->resultTtl = $resultTtl;
    }

    /**
     * 获取订单锁
     *
     * 如果订单正在处理中或已成功处理，返回 false
     *
     * @param string $orderId 商户订单号
     * @return bool 是否获取成功
     */
    public function acquire(string $orderId): bool
    {
        $lockKey = self::LOCK_PREFIX . 'lock:' . $orderId;
        $statusKey = self::LOCK_PREFIX . 'status:' . $orderId;

        // 先检查是否已有处理结果
        if (KodeCacheAdapter::has($statusKey)) {
            return false;
        }

        // 尝试获取分布式锁
        return KodeCacheAdapter::lock($lockKey, $this->lockTtl);
    }

    /**
     * 释放订单锁
     *
     * @param string $orderId 商户订单号
     * @return bool
     */
    public function release(string $orderId): bool
    {
        $lockKey = self::LOCK_PREFIX . 'lock:' . $orderId;

        return KodeCacheAdapter::unlock($lockKey);
    }

    /**
     * 标记订单处理成功
     *
     * @param string $orderId 商户订单号
     * @param array<string, mixed> $result 处理结果
     * @return bool
     */
    public function markSuccess(string $orderId, array $result): bool
    {
        $statusKey = self::LOCK_PREFIX . 'status:' . $orderId;

        return KodeCacheAdapter::set($statusKey, [
            'status' => 'success',
            'result' => $result,
            'time' => date('Y-m-d H:i:s'),
        ], $this->resultTtl);
    }

    /**
     * 标记订单处理失败
     *
     * @param string $orderId 商户订单号
     * @param string $error 错误信息
     * @return bool
     */
    public function markFailed(string $orderId, string $error): bool
    {
        $statusKey = self::LOCK_PREFIX . 'status:' . $orderId;

        return KodeCacheAdapter::set($statusKey, [
            'status' => 'failed',
            'error' => $error,
            'time' => date('Y-m-d H:i:s'),
        ], $this->resultTtl);
    }

    /**
     * 获取订单处理状态
     *
     * @param string $orderId 商户订单号
     * @return array<string, mixed>|null
     */
    public function getStatus(string $orderId): ?array
    {
        $statusKey = self::LOCK_PREFIX . 'status:' . $orderId;

        return KodeCacheAdapter::get($statusKey);
    }

    /**
     * 判断订单是否已处理成功
     *
     * @param string $orderId 商户订单号
     * @return bool
     */
    public function isSuccess(string $orderId): bool
    {
        $status = $this->getStatus($orderId);

        return $status !== null && ($status['status'] ?? '') === 'success';
    }

    /**
     * 判断订单是否正在处理中
     *
     * @param string $orderId 商户订单号
     * @return bool
     */
    public function isProcessing(string $orderId): bool
    {
        $lockKey = self::LOCK_PREFIX . 'lock:' . $orderId;

        // 如果锁存在且没有结果，说明正在处理
        return !KodeCacheAdapter::has(self::LOCK_PREFIX . 'status:' . $orderId)
            && !$this->acquire($orderId);
    }

    /**
     * 清除订单状态（用于测试或手动重置）
     *
     * @param string $orderId 商户订单号
     * @return bool
     */
    public function clear(string $orderId): bool
    {
        $lockKey = self::LOCK_PREFIX . 'lock:' . $orderId;
        $statusKey = self::LOCK_PREFIX . 'status:' . $orderId;

        KodeCacheAdapter::unlock($lockKey);
        KodeCacheAdapter::delete($statusKey);

        return true;
    }
}
