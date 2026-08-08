<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

/**
 * 异步回调（Notify）统一安全校验层
 *
 * 在统一入口 {@see \Kode\Pays\Facade\Pay::verify()} 中作为前置校验使用，对各个支付
 * 平台回推的异步通知做通用安全过滤，避免将畸形或重放数据直接交给各网关的验签逻辑：
 *
 * - 必填字段校验：通知必须包含业务所需字段；
 * - 签名字段校验：存在签名机制时，通知必须携带签名字段；
 * - 时间戳防重放：通知携带的时间戳必须落在有效窗口内（默认 ±5 分钟容差）；
 * - nonce 防重放：同一 nonce 不允许被重复使用（由调用方提供已见 nonce 集合）。
 *
 * 本类为纯函数式、无外部依赖，便于单元测试与在不同运行环境中复用。
 */
class NotifyGuard
{
    /**
     * 对异步通知做统一安全校验
     *
     * 任意一项校验不通过即抛出 {@see PayException}。
     *
     * @param array<string, mixed> $data 通知数据
     * @param array<string, mixed> $options 校验选项：
     *        - require_sign_field: 签名字段名，空字符串表示不校验，默认 'sign'
     *        - require_fields: 必填字段列表，默认 []
     *        - timestamp: 通知时间戳（秒），为 null 时不校验时间窗口
     *        - nonce: 通知唯一随机串，为 null 时不校验 nonce
     *        - seen_nonces: 已出现过的 nonce 集合（由调用方维护），用于重放检测
     *        - max_age: 时间戳有效窗口（秒），默认 300
     *        - allow_replay: 是否允许重放（跳过时间与 nonce 校验），默认 false
     * @throws PayException
     */
    public static function guard(array $data, array $options = []): void
    {
        $requireSignField = $options['require_sign_field'] ?? 'sign';
        $requireFields = $options['require_fields'] ?? [];
        $timestamp = $options['timestamp'] ?? null;
        $nonce = $options['nonce'] ?? null;
        $seenNonces = $options['seen_nonces'] ?? [];
        $maxAge = $options['max_age'] ?? 300;
        $allowReplay = $options['allow_replay'] ?? false;

        if (!is_array($seenNonces)) {
            $seenNonces = [];
        }

        // 必填字段校验
        foreach ($requireFields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                throw PayException::paramError("异步通知缺少必填字段：{$field}");
            }
        }

        // 签名字段校验
        if ($requireSignField !== '' && !isset($data[$requireSignField])) {
            throw PayException::signError("异步通知缺少签名字段：{$requireSignField}");
        }

        // 时间戳防重放窗口
        if ($timestamp !== null && !$allowReplay) {
            if (!is_int($timestamp) && !is_numeric($timestamp)) {
                throw PayException::signError('异步通知时间戳格式非法');
            }

            $ts = (int) $timestamp;
            $now = time();

            if ($ts < ($now - $maxAge) || $ts > ($now + 10)) {
                throw PayException::signError('异步通知时间戳超出有效窗口，可能存在重放攻击');
            }
        }

        // nonce 防重放
        if ($nonce !== null && $nonce !== '' && !$allowReplay) {
            if (in_array($nonce, $seenNonces, true)) {
                throw PayException::signError('异步通知 nonce 已被使用，疑似重放攻击');
            }
        }
    }
}
