<?php

declare(strict_types=1);

namespace Kode\Pays\Event;

use Closure;

/**
 * 轻量级事件分发器
 *
 * 用于解耦支付生命周期中的各阶段通知，如请求发送前、响应接收后、异常发生时等。
 * 支持同步监听器和优先级排序。
 */
class EventDispatcher
{
    /**
     * 监听器注册表
     *
     * @var array<string, array<int, array<callable>>>
     */
    protected array $listeners = [];

    /**
     * 注册事件监听器
     *
     * @param string $eventName 事件名称
     * @param callable $listener 监听器
     * @param int $priority 优先级（数值越大越先执行）
     */
    public function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][$priority][] = $listener;
    }

    /**
     * 分发事件
     *
     * 按优先级降序调用所有监听器，如果某个监听器返回 false，则中断后续执行。
     *
     * @param string $eventName 事件名称
     * @param mixed $payload 事件载荷
     * @return mixed 最后一个监听器的返回值，或原始 payload
     */
    public function dispatch(string $eventName, mixed $payload = null): mixed
    {
        if (!isset($this->listeners[$eventName])) {
            return $payload;
        }

        $listeners = $this->listeners[$eventName];

        // 按优先级降序排序
        krsort($listeners);

        $result = $payload;

        foreach ($listeners as $group) {
            foreach ($group as $listener) {
                $result = $listener($result);

                if ($result === false) {
                    return false;
                }
            }
        }

        return $result;
    }

    /**
     * 判断某事件是否有监听器
     *
     * @param string $eventName 事件名称
     * @return bool
     */
    public function hasListeners(string $eventName): bool
    {
        return isset($this->listeners[$eventName]) && !empty($this->listeners[$eventName]);
    }

    /**
     * 移除某事件的所有监听器
     *
     * @param string $eventName 事件名称
     */
    public function forget(string $eventName): void
    {
        unset($this->listeners[$eventName]);
    }
}
