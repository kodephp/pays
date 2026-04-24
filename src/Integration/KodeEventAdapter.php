<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Event\EventDispatcher;

/**
 * kode/event 集成适配器
 *
 * 当项目中安装了 kode/event 时，自动将其作为底层事件引擎，
 * 替换或增强内置的 EventDispatcher，提供更强大的事件总线能力。
 * 未安装时完全回退到内置实现，零依赖侵入。
 *
 * 使用示例：
 * ```php
 * // 方式1：直接获取适配后的事件分发器
 * $dispatcher = KodeEventAdapter::createDispatcher();
 *
 * // 方式2：将 kode/event 注入到 Pay 门面
 * KodeEventAdapter::injectIntoFacade();
 * ```
 */
class KodeEventAdapter
{
    /**
     * 是否已注入门面
     */
    private static bool $injected = false;

    /**
     * 创建兼容 kode/event 的事件分发器
     *
     * 如果安装了 kode/event，返回包装后的增强分发器；
     * 否则返回原生 EventDispatcher。
     *
     * @return EventDispatcher|\Kode\Event\EventBus
     */
    public static function createDispatcher(): EventDispatcher|\Kode\Event\EventBus
    {
        if (class_exists(\Kode\Event\EventBus::class)) {
            return new \Kode\Event\EventBus();
        }

        return new EventDispatcher();
    }

    /**
     * 将 kode/event 注入到 Pay 门面
     *
     * 替换门面内部使用的事件分发器为 kode/event 实现。
     */
    public static function injectIntoFacade(): void
    {
        if (self::$injected) {
            return;
        }

        if (!class_exists(\Kode\Event\EventBus::class)) {
            return;
        }

        $dispatcher = self::createDispatcher();

        if (class_exists(\Kode\Pays\Facade\Pay::class)) {
            \Kode\Pays\Facade\Pay::setDispatcher($dispatcher);
        }

        self::$injected = true;
    }

    /**
     * 触发事件（兼容层）
     *
     * @param string $eventName 事件名称
     * @param mixed $payload 事件载荷
     * @return mixed
     */
    public static function emit(string $eventName, mixed $payload = null): mixed
    {
        if (class_exists(\Kode\Event\EventBus::class)) {
            return \Kode\Event\EventBus::emit($eventName, $payload);
        }

        return $payload;
    }

    /**
     * 监听事件（兼容层）
     *
     * @param string $eventName 事件名称
     * @param callable $listener 监听器
     * @param int $priority 优先级
     */
    public static function on(string $eventName, callable $listener, int $priority = 0): void
    {
        if (class_exists(\Kode\Event\EventBus::class)) {
            \Kode\Event\EventBus::on($eventName, $listener, $priority);
        }
    }

    /**
     * 判断 kode/event 是否可用
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Kode\Event\EventBus::class);
    }
}
