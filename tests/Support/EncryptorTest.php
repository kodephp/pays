<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Support;

use Kode\Pays\Core\PayException;
use Kode\Pays\Support\Encryptor;
use Kode\Pays\Tests\TestCase;

/**
 * Encryptor 单元测试
 *
 * 覆盖 AES-256-GCM / ECB / CBC 与原始（非 base64）变体的往返正确性，
 * 以及密钥长度校验等非正常分支。
 */
class EncryptorTest extends TestCase
{
    private function key32(): string
    {
        return str_repeat('a', 32);
    }

    public function testAesEcbRoundTrip(): void
    {
        $key = $this->key32();
        $plain = 'hello-wechat-资金账单';

        $cipher = Encryptor::aesEcbEncrypt($plain, $key);
        $this->assertSame($plain, Encryptor::aesEcbDecrypt($cipher, $key));
    }

    /**
     * 原始变体（不做 base64）与 OpenSSL 直调一致，用于「先加密后 GZIP」复合场景
     */
    public function testAesEcbRawRoundTrip(): void
    {
        $key = $this->key32();
        $plain = gzencode('csv-content-123');

        $cipher = Encryptor::aesEcbEncryptRaw($plain, $key);

        // 与 openssl 直调等价
        $expected = openssl_encrypt($plain, 'aes-256-ecb', $key, OPENSSL_RAW_DATA);
        $this->assertSame($expected, $cipher);

        $this->assertSame($plain, Encryptor::aesEcbDecryptRaw($cipher, $key));
    }

    public function testAesEcbRawCompatibleWithBase64Variant(): void
    {
        $key = $this->key32();
        $plain = 'payload';

        $raw = Encryptor::aesEcbEncryptRaw($plain, $key);
        // base64 变体解密原始变体的 base64 编码结果，应得到同一明文
        $this->assertSame($plain, Encryptor::aesEcbDecrypt(base64_encode($raw), $key));
    }

    public function testAesEcbRejectsWrongKeyLength(): void
    {
        $this->expectException(PayException::class);
        Encryptor::aesEcbEncryptRaw('x', 'short');
    }

    public function testAesEcbRawRejectsWrongKeyLength(): void
    {
        $this->expectException(PayException::class);
        Encryptor::aesEcbDecryptRaw('x', 'short');
    }

    public function testAesGcmRoundTrip(): void
    {
        $key = $this->key32();
        $plain = ['order' => 'O1', 'amount' => 1];

        $enc = Encryptor::aesGcmEncrypt((string) json_encode($plain), $key, 'transaction');

        $this->assertSame(
            (string) json_encode($plain),
            Encryptor::aesGcmDecrypt($enc['ciphertext'], $key, $enc['nonce'], $enc['tag'], 'transaction'),
        );
    }

    public function testAesCbcRoundTrip(): void
    {
        $key = $this->key32();
        $iv = str_repeat('i', 16);
        $plain = 'cbc-payload';

        $cipher = Encryptor::aesCbcEncrypt($plain, $key, $iv);
        $this->assertSame($plain, Encryptor::aesCbcDecrypt($cipher, $key, $iv));
    }
}
