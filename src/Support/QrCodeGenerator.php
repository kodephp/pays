<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;
use Kode\Pays\Core\PayException;

/**
 * 二维码生成器
 *
 * 为支付场景提供二维码生成能力，支持微信支付 Native、支付宝当面付等场景。
 * 默认使用 endroid/qr-code（已内置），如果安装了 kode/tools 则优先使用其高级能力。
 *
 * 使用示例：
 * ```php
 * use Kode\Pays\Support\QrCodeGenerator;
 *
 * // 生成微信支付二维码
 * $qrCode = QrCodeGenerator::generate('weixin://wxpay/bizpayurl?pr=xxx');
 * file_put_contents('wechat_pay.png', $qrCode);
 *
 * // 生成带 Logo 的二维码
 * $qrCode = QrCodeGenerator::generateWithLogo($url, '/path/to/logo.png');
 * ```
 */
class QrCodeGenerator
{
    /**
     * 二维码默认尺寸（像素）
     */
    protected const DEFAULT_SIZE = 300;

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

        // 使用内置的 endroid/qr-code
        return self::generateWithEndroid($content, $size, $format);
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
            return self::generateWithKodeLogo($content, $logoPath, $size);
        }

        // 使用 endroid/qr-code 内置的 Logo 支持
        return self::generateWithEndroidLogo($content, $logoPath, $size);
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
     * 使用 kode/tools 生成带 Logo 的二维码
     *
     * @param string $content 二维码内容
     * @param string $logoPath Logo 路径
     * @param int $size 尺寸
     * @return string 图片数据
     * @throws PayException
     */
    protected static function generateWithKodeLogo(string $content, string $logoPath, int $size): string
    {
        try {
            $qrCode = new \Kode\Tools\QrCode\QrCode($content);
            $qrCode->setSize($size);
            $qrCode->setLogo($logoPath);

            return $qrCode->render();
        } catch (\Throwable $e) {
            throw PayException::configError('kode/tools 带 Logo 二维码生成失败：' . $e->getMessage(), $e);
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
            $writer = self::createEndroidWriter($format);

            $qrCode = new QrCode(
                data: $content,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Low,
                size: $size,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255),
            );

            $result = $writer->write($qrCode);

            return $result->getString();
        } catch (\Throwable $e) {
            throw PayException::configError('endroid/qr-code 生成失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 使用 endroid/qr-code 生成带 Logo 的二维码
     *
     * @param string $content 二维码内容
     * @param string $logoPath Logo 路径
     * @param int $size 尺寸
     * @return string 图片数据
     * @throws PayException
     */
    protected static function generateWithEndroidLogo(string $content, string $logoPath, int $size): string
    {
        try {
            $writer = new PngWriter();

            $qrCode = new QrCode(
                data: $content,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Low,
                size: $size,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255),
            );

            // endroid/qr-code v5 通过 Logo 类添加 Logo
            $logo = new Logo($logoPath);

            $result = $writer->write($qrCode, $logo);

            return $result->getString();
        } catch (\Throwable $e) {
            throw PayException::configError('endroid/qr-code 带 Logo 二维码生成失败：' . $e->getMessage(), $e);
        }
    }

    /**
     * 创建 endroid Writer 实例
     *
     * @param string $format 格式
     * @return WriterInterface
     */
    protected static function createEndroidWriter(string $format): WriterInterface
    {
        return match ($format) {
            'svg' => new SvgWriter(),
            default => new PngWriter(),
        };
    }
}
