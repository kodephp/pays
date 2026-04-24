<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use Kode\Pays\Core\PayException;

/**
 * 二维码生成器
 *
 * 为支付场景提供二维码生成能力，支持微信支付 Native、支付宝当面付等场景。
 * 优先使用 kode/tools 扩展包（如果已安装），否则提供基础实现或抛出异常提示安装。
 *
 * 使用示例：
 * ```php
 * use Kode\Pays\Support\QrCodeGenerator;
 *
 * // 生成微信支付二维码
 * $qrCode = QrCodeGenerator::generate('weixin://wxpay/bizpayurl?pr=xxx');
 * file_put_contents('wechat_pay.png', $qrCode);
 *
 * // 生成带 Logo 的二维码（需安装 kode/tools）
 * $qrCode = QrCodeGenerator::generateWithLogo($url, '/path/to/logo.png');
 * ```
 */
class QrCodeGenerator
{
    /**
     * 二维码默认尺寸（像素）
     */
    protected const int DEFAULT_SIZE = 300;

    /**
     * 生成二维码图片数据
     *
     * @param string $content 二维码内容（如支付 URL）
     * @param int $size 二维码尺寸（像素）
     * @param string $format 输出格式：png、svg
     * @return string 二维码图片二进制数据
     * @throws PayException
     */
    public static function generate(string $content, int $size = self::DEFAULT_SIZE, string $format = 'png'): string
    {
        // 如果安装了 kode/tools，优先使用其高级能力
        if (class_exists(\Kode\Tools\QrCode\QrCode::class)) {
            return self::generateWithKode($content, $size, $format);
        }

        // 如果安装了 endroid/qr-code，使用其能力
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
    public static function generateWithLogo(string $content, string $logoPath, int $size = self::DEFAULT_SIZE): string
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
    public static function forPayment(string $gateway, string $content): string
    {
        $size = match ($gateway) {
            'wechat' => 300,
            'alipay' => 400,
            'unionpay' => 350,
            default => self::DEFAULT_SIZE,
        };

        return self::generate($content, $size, 'png');
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
}
