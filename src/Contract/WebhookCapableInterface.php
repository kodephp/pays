<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * Webhook 事件订阅能力接口
 *
 * 为支持异步 Webhook 事件推送的网关提供统一契约：验证原始 Webhook 请求签名、
 * 并将原始报文解析为统一事件结构。
 *
 * 与 {@see GatewayInterface::verifyNotify()} 的区别：
 * - {@see GatewayInterface::verifyNotify()} 接收「已解析的通知数组」，且多数网关实现依赖全局
 *   `$_SERVER` / `php://input`，与运行时耦合、不便测试；
 * - 本接口接收「原始请求体（string）+ 请求头（array）」，与运行时解耦，便于单元测试与中间层复用。
 *
 * 平台组装逻辑下沉到各网关原生方法，由 {@see \Kode\Pays\Facade\Pay::call()} 统一派发；
 * 网关未实现的方法调用时抛「无此方法」。
 */
interface WebhookCapableInterface
{
    /**
     * 验证 Webhook 原始请求签名
     *
     * @param string $payload 原始请求体（未解码字符串）
     * @param array<string, string> $headers 请求头（键名大小写不敏感）
     * @return bool 验签是否通过
     */
    public function verifyWebhook(string $payload, array $headers = []): bool;

    /**
     * 解析 Webhook 原始请求体为统一事件结构
     *
     * 返回结构含：gateway（网关标识）、event_id（事件 ID）、event_type（事件类型）、
     * data（解码后的完整报文）、raw（原始报文）。
     *
     * @param string $payload 原始请求体（JSON 字符串）
     * @return array<string, mixed> 统一事件结构
     * @throws PayException 报文非合法 JSON 时
     */
    public function parseWebhook(string $payload): array;
}
