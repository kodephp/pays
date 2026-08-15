<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 二维码支付能力接口
 *
 * 将「生成扫码支付二维码」的平台组装与签名逻辑下沉到各网关原生方法，
 * 由 {@see \Kode\Pays\Facade\Pay} 的统一入口对外暴露。
 *
 * 设计约定：
 * - 网关方法仅承载「平台组装 + 签名 + 发请求」，不重复做业务参数校验（校验在插件/入口层）。
 * - 不支持的能力应抛 {@see PayException::methodNotSupported}，由 Pay::call 统一返回「无此方法」。
 *
 * 该接口与 {@see PersonalReceiveCapableInterface} 共享 createQrCode 方法签名，
 * 一个网关可同时实现二者（如个人收款码与扫码支付），二者签名完全一致、互不冲突。
 */
interface QrCapableInterface
{
    /**
     * 生成扫码支付二维码
     *
     * @param array<string, mixed> $params 二维码参数（如 out_trade_no / amount / product_id 等）
     * @return array<string, mixed> 含 code_url / qr_code 等扫码信息的响应
     * @throws PayException
     */
    public function createQrCode(array $params): array;
}
