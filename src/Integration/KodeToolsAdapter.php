<?php

declare(strict_types=1);

namespace Kode\Pays\Integration;

use Kode\Pays\Core\PayException;

/**
 * kode/tools 工具包集成适配器
 *
 * 当项目中安装了 kode/tools 时，提供增强的二维码生成、
 * 字符串处理、数组工具、时间处理等能力。
 */
class KodeToolsAdapter
{
    /**
     * 生成支付二维码
     *
     * @param string $content 二维码内容（如支付 URL）
     * @param int $size 二维码尺寸（像素）
     * @param string $format 输出格式：png、svg
     * @return string 二维码图片二进制数据
     * @throws PayException
     */
    public static function qrCode(string $content, int $size = 300, string $format = 'png'): string
    {
        if (class_exists(\Kode\Tools\QrCode\QrCode::class)) {
            return self::generateWithKode($content, $size, $format);
        }

        // 降级到 endroid/qr-code
        if (class_exists(\Endroid\QrCode\QrCode::class)) {
            return self::generateWithEndroid($content, $size, $format);
        }

        throw PayException::configError(
            '生成二维码需要安装扩展包，请执行：composer require kode/tools 或 endroid/qr-code',
        );
    }

    /**
     * 生成带 Logo 的二维码
     *
     * @param string $content 二维码内容
     * @param string $logoPath Logo 图片路径
     * @param int $size 二维码尺寸
     * @return string 二维码图片二进制数据
     * @throws PayException
     */
    public static function qrCodeWithLogo(string $content, string $logoPath, int $size = 300): string
    {
        if (class_exists(\Kode\Tools\QrCode\QrCode::class)) {
            try {
                $qrCode = new \Kode\Tools\QrCode\QrCode($content);
                $qrCode->setSize($size);
                $qrCode->setLogo($logoPath);

                return $qrCode->render();
            } catch (\Throwable $e) {
                throw PayException::configError('生成带 Logo 二维码失败：' . $e->getMessage(), $e);
            }
        }

        throw PayException::configError(
            '生成带 Logo 二维码需要安装 kode/tools：composer require kode/tools',
        );
    }

    /**
     * 生成支付场景专用二维码
     *
     * 根据网关类型自动选择最佳参数
     *
     * @param string $gateway 网关标识（wechat、alipay 等）
     * @param string $content 二维码内容
     * @return string 二维码图片二进制数据
     * @throws PayException
     */
    public static function paymentQrCode(string $gateway, string $content): string
    {
        $size = match ($gateway) {
            'wechat' => 300,
            'alipay' => 400,
            'unionpay' => 350,
            default => 300,
        };

        return self::qrCode($content, $size, 'png');
    }

    /**
     * 使用 kode/tools 生成二维码
     *
     * @param string $content 二维码内容
     * @param int $size 尺寸
     * @param string $format 格式
     * @return string 图片数据
     * @throws PayException
     */
    protected static function generateWithKode(string $content, int $size, string $format): string
    {
        try {
            $qrCode = new \Kode\Tools\QrCode\QrCode($content);
            $qrCode->setSize($size);
            $qrCode->setFormat($format);

            return $qrCode->render();
        } catch (\Throwable $e) {
            throw PayException::configError('kode/tools 二维码生成失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 使用 endroid/qr-code 生成二维码
     *
     * @param string $content 二维码内容
     * @param int $size 尺寸
     * @param string $format 格式
     * @return string 图片数据
     * @throws PayException
     */
    protected static function generateWithEndroid(string $content, int $size, string $format): string
    {
        try {
            $qrCode = new \Endroid\QrCode\QrCode($content);
            $qrCode->setSize($size);

            $writer = match ($format) {
                'svg' => new \Endroid\QrCode\Writer\SvgWriter(),
                default => new \Endroid\QrCode\Writer\PngWriter(),
            };

            $result = $writer->write($qrCode);

            return $result->getString();
        } catch (\Throwable $e) {
            throw PayException::configError('endroid/qr-code 生成失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 使用 kode/tools 的 Str 工具
     *
     * @param string $method 方法名
     * @param array<mixed> $args 参数
     * @return mixed
     */
    public static function str(string $method, array $args): mixed
    {
        if (class_exists(\Kode\Tools\Str::class)) {
            return \Kode\Tools\Str::$method(...$args);
        }

        return null;
    }

    /**
     * 使用 kode/tools 的 Arr 工具
     *
     * @param string $method 方法名
     * @param array<mixed> $args 参数
     * @return mixed
     */
    public static function arr(string $method, array $args): mixed
    {
        if (class_exists(\Kode\Tools\Arr::class)) {
            return \Kode\Tools\Arr::$method(...$args);
        }

        return null;
    }
}
