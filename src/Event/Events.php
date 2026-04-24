<?php

declare(strict_types=1);

namespace Kode\Pays\Event;

/**
 * 支付生命周期事件常量定义
 *
 * 所有事件名称统一在此定义，避免硬编码字符串分散在各处。
 */
final class Events
{
    /**
     * 请求发送前事件
     *
     * payload: array{gateway: string, endpoint: string, params: array}
     */
    public const string REQUEST_SENDING = 'pay.request.sending';

    /**
     * 请求发送后事件
     *
     * payload: array{gateway: string, endpoint: string, response: string}
     */
    public const string REQUEST_SENT = 'pay.request.sent';

    /**
     * 响应解析后事件
     *
     * payload: array{gateway: string, endpoint: string, parsed: array}
     */
    public const string RESPONSE_PARSED = 'pay.response.parsed';

    /**
     * 支付成功事件
     *
     * payload: array{gateway: string, order_id: string, amount: int|float, raw: array}
     */
    public const string PAYMENT_SUCCESS = 'pay.payment.success';

    /**
     * 支付失败事件
     *
     * payload: array{gateway: string, order_id: string, error: string, raw: array}
     */
    public const string PAYMENT_FAILED = 'pay.payment.failed';

    /**
     * 异步通知接收事件
     *
     * payload: array{gateway: string, data: array}
     */
    public const string NOTIFY_RECEIVED = 'pay.notify.received';

    /**
     * 异步通知验证通过事件
     *
     * payload: array{gateway: string, data: array}
     */
    public const string NOTIFY_VERIFIED = 'pay.notify.verified';

    /**
     * 异常发生事件
     *
     * payload: array{gateway: string, exception: \Throwable}
     */
    public const string EXCEPTION_OCCURRED = 'pay.exception.occurred';

    /**
     * 退款成功事件
     *
     * payload: array{gateway: string, refund_id: string, order_id: string, amount: int|float}
     */
    public const string REFUND_SUCCESS = 'pay.refund.success';

    private function __construct()
    {
    }
}
