<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Wechat;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Encryptor;
use Kode\Pays\Support\StrUtil;

/**
 * 微信 APIv3 请求签名复用特质。
 *
 * 供需要调用 V3 端点、但主协议为 V2 的网关（如 WechatPayGateway 的批量转账）
 * 复用，避免重复实现 V3 Authorization 头与服务商字段注入逻辑。
 *
 * 约定：传入的 $endpoint 为相对地址，需自行包含 v3 前缀（如 'v3/transfer/batches'），
 * 以匹配各网关 getBaseUrl() 拼接出的绝对路径，使规范化签名串与真实请求一致。
 *
 * @mixin AbstractGateway
 */
trait WechatV3SigningTrait
{
    /**
     * 发送已签名的 V3 POST 请求。
     *
     * 与 WechatPayV3Gateway::signedPost 一致：先注入服务商字段，再按微信 V3 规范
     * 序列化 body 并以其精确字节参与签名，最终走 postRaw 保证「签名串 == 发送字节」。
     *
     * @param string $endpoint 相对地址（需含 v3 前缀）
     * @param array<string, mixed> $data 请求数据
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function signedV3Post(string $endpoint, array $data): array
    {
        $data = $this->applyV3ServiceProviderFields($data);

        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = $this->buildV3Headers('POST', $this->canonicalPath($endpoint), $body);

        return $this->postRaw($endpoint, $body, $headers);
    }

    /**
     * 发送已签名的 V3 GET 请求。
     *
     * @param string $endpoint 相对地址（需含 v3 前缀）
     * @param array<string, mixed> $query 查询参数
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function signedV3Get(string $endpoint, array $query = []): array
    {
        $query = $this->applyV3ServiceProviderFields($query);

        $headers = $this->buildV3Headers('GET', $this->canonicalPath($endpoint, $query));

        return $this->get($endpoint, $query, $headers);
    }

    /**
     * 服务商模式字段注入（V3 命名：sp_appid / sp_mchid / sub_appid / sub_mchid）。
     *
     * 仅注入配置中实际存在的字段，且不覆盖调用方已显式传入的同名字段，
     * 因此普通商户请求（未配置这些字段）行为完全不变。
     *
     * @param array<string, mixed> $payload 请求体或查询参数
     * @return array<string, mixed>
     */
    private function applyV3ServiceProviderFields(array $payload): array
    {
        foreach (['sp_appid', 'sp_mchid', 'sub_appid', 'sub_mchid'] as $key) {
            $value = $this->getConfig($key);

            if (is_string($value) && $value !== '' && !array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * 构建 V3 请求头（Authorization 等）。
     *
     * @param string $method HTTP 方法
     * @param string $canonicalPath 参与签名的绝对路径（含查询串）
     * @param string $body 请求体（GET 请求传空字符串）
     * @return array<string, string>
     * @throws PayException
     */
    protected function buildV3Headers(string $method, string $canonicalPath, string $body = ''): array
    {
        $serialNo = $this->getConfig('serial_no');
        if (!is_string($serialNo) || $serialNo === '') {
            throw PayException::configError('缺少微信支付证书序列号 serial_no，无法发起 V3 请求');
        }

        $timestamp = (string) time();
        $nonce = StrUtil::random(32);
        $message = $method . "\n" . $canonicalPath . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = Encryptor::rsaSign($message, (string) $this->getConfig('private_key'), 'sha256');

        $headers = [
            'Authorization' => sprintf(
                'WECHATPAY2-SHA256-RSA2048 mchid="%s",serial_no="%s",timestamp="%s",nonce_str="%s",signature="%s"',
                (string) $this->getConfig('mch_id'),
                $serialNo,
                $timestamp,
                $nonce,
                $signature,
            ),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // 请求体含加密敏感字段时，微信要求声明所用平台证书序列号
        $platformSerial = $this->getConfig('platform_serial_no', '');
        if (is_string($platformSerial) && $platformSerial !== '') {
            $headers['Wechatpay-Serial'] = $platformSerial;
        }

        return $headers;
    }

    /**
     * 构建参与签名的规范化绝对路径（含查询串）。
     *
     * 微信 APIv3 要求签名串中的 URL 为去除域名后的绝对路径，存在查询参数时需附加 ?查询串。
     * 此处由基础地址与端点共同派生，基础地址调整时签名自动保持正确。
     *
     * @param string $endpoint 端点（可含 v3 前缀）
     * @param array<string, mixed> $query 查询参数
     */
    protected function canonicalPath(string $endpoint, array $query = []): string
    {
        $endpoint = ltrim($endpoint, '/');
        $parsed = parse_url($this->getBaseUrl() . $endpoint, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/' . $endpoint;

        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $path;
    }
}
