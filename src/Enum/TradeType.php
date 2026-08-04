<?php

declare(strict_types=1);

namespace Kode\Pays\Enum;

/**
 * 交易类型（支付场景）枚举
 *
 * 统一聚合支付各渠道的下单场景标识：APP、公众号/小程序 JSAPI、扫码 NATIVE、
 * 付款码 MICROPAY、H5/MWEB、WAP 等。通过 {@see TradeType::fromRaw()} 将各网关
 * 不同字段值归一化。
 *
 * 使用示例：
 * ```php
 * $type = TradeType::fromRaw($params['trade_type'] ?? 'JSAPI');
 * ```
 */
enum TradeType: string
{
    /** APP 支付 */
    case APP = 'APP';

    /** 公众号 / 小程序支付（JSAPI） */
    case JSAPI = 'JSAPI';

    /** 扫码支付（Native） */
    case NATIVE = 'NATIVE';

    /** 付款码支付（被扫 / 收银员扫用户） */
    case MICROPAY = 'MICROPAY';

    /** H5 支付（手机浏览器） */
    case H5 = 'H5';

    /** 移动网页支付（MWEB） */
    case MWEB = 'MWEB';

    /** 刷卡 / 条码支付 */
    case BARCODE = 'BARCODE';

    /** wap 支付 */
    case WAP = 'WAP';

    /** 快捷 wap 支付 */
    case QUICK_WAP = 'QUICK_WAP';

    /** PC 网页支付 */
    case WEB = 'WEB';

    /** 小程序支付 */
    case MINI = 'MINI';

    /** 扫码（展示二维码由用户扫） */
    case SCAN = 'SCAN';

    /** 刷卡支付（用户被扫） */
    case CARD = 'CARD';

    /**
     * 原始交易类型别名映射表（键为大写后的原始值）
     *
     * @var array<string, self>
     */
    private const ALIASES = [
        'JSAPI' => self::JSAPI,
        'OFFICIAL' => self::JSAPI,
        'MINIPROGRAM' => self::MINI,
        'MP' => self::JSAPI,
        'NATIVE' => self::NATIVE,
        'QRCODE' => self::NATIVE,
        'SCAN' => self::SCAN,
        'MICROPAY' => self::MICROPAY,
        'CARD' => self::CARD,
        'BARCODE' => self::BARCODE,
        'WAVECODE' => self::BARCODE,
        'H5' => self::H5,
        'MWEB' => self::MWEB,
        'WAP' => self::WAP,
        'QUICKWAP' => self::QUICK_WAP,
        'WEB' => self::WEB,
        'PC' => self::WEB,
        'APP' => self::APP,
    ];

    /**
     * 从各网关原始交易类型字符串归一化
     *
     * @param string|null $raw 原始交易类型
     * @return self|null 无法识别时返回 null
     */
    public static function fromRaw(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtoupper(trim($raw));

        return self::tryFrom($normalized)
            ?? self::ALIASES[$normalized]
            ?? null;
    }
}
