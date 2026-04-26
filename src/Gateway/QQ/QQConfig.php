<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\QQ;

use Kode\Pays\Contract\ConfigInterface;

/**
 * QQ 支付配置 DTO
 *
 * @param string $appId QQ 支付商户应用 ID
 * @param string $mchId QQ 支付商户号
 * @param string $apiKey QQ 支付 API 密钥
 * @param string $notifyUrl 异步通知回调地址
 * @param bool $sandbox 是否沙箱模式
 */
readonly class QQConfig implements ConfigInterface
{
    public function __construct(
        public string $appId,
        public string $mchId,
        public string $apiKey,
        public string $notifyUrl = '',
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            appId: $config['app_id'] ?? '',
            mchId: $config['mch_id'] ?? '',
            apiKey: $config['api_key'] ?? '',
            notifyUrl: $config['notify_url'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'qq';
    }
}
