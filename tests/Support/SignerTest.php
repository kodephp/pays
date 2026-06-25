<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Support;

use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\TestCase;

/**
 * 签名工具单元测试
 *
 * 验证 MD5、HMAC-SHA256、RSA2 签名与验签、查询字符串构建等行为。
 */
class SignerTest extends TestCase
{
    /**
     * 测试 MD5 签名与验签往返
     */
    public function testMd5SignAndVerify(): void
    {
        $params = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
            'out_trade_no' => 'O1',
            'total_fee' => 100,
        ];
        $key = 'testkey';

        $sign = Signer::md5($params, $key);

        // 签名应为 32 位大写十六进制
        $this->assertSame(32, strlen($sign));
        $this->assertSame(strtoupper($sign), $sign);

        // 验签应通过
        $paramsWithSign = $params;
        $paramsWithSign['sign'] = $sign;
        $this->assertTrue(Signer::verifyMd5($paramsWithSign, $key));
    }

    /**
     * 测试 MD5 验签：错误签名返回 false
     */
    public function testVerifyMd5FailsWithWrongSign(): void
    {
        $params = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
            'sign' => 'WRONGSIGN1234567890ABCDEF1234567',
        ];
        $key = 'testkey';

        $this->assertFalse(Signer::verifyMd5($params, $key));
    }

    /**
     * 测试 MD5 验签：无 sign 字段返回 false
     */
    public function testVerifyMd5MissingSignField(): void
    {
        $params = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
        ];
        $key = 'testkey';

        $this->assertFalse(Signer::verifyMd5($params, $key));
    }

    /**
     * 测试 buildQueryString：按 key 升序、排除空值和 sign 字段
     */
    public function testBuildQueryString(): void
    {
        $params = [
            'mch_id' => 'm1',
            'appid' => 'wx123',
            'empty_str' => '',
            'null_val' => null,
            'sign' => 'should_be_excluded',
            'total_fee' => 100,
        ];

        $query = Signer::buildQueryString($params);

        // 按 key 升序：appid, mch_id, total_fee
        // 排除空值（empty_str, null_val）和 sign 字段
        $this->assertSame('appid=wx123&mch_id=m1&total_fee=100', $query);
    }

    /**
     * 测试 buildQueryString：保留空值模式
     */
    public function testBuildQueryStringIncludeEmpty(): void
    {
        $params = [
            'b' => '',
            'a' => 'value',
        ];

        $query = Signer::buildQueryString($params, false);

        // 不排除空值，但 sign 仍然排除
        $this->assertSame('a=value&b=', $query);
    }

    /**
     * 测试 HMAC-SHA256 签名：长度和格式
     */
    public function testHmacSha256(): void
    {
        $params = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
        ];
        $key = 'testkey';

        $sign = Signer::hmacSha256($params, $key);

        // HMAC-SHA256 输出为 64 位十六进制，转大写
        $this->assertSame(64, strlen($sign));
        $this->assertSame(strtoupper($sign), $sign);
        $this->assertTrue(ctype_xdigit($sign), '签名应为十六进制字符');
    }

    /**
     * 测试 RSA2 签名与 verifyRsa 验签往返
     *
     * 使用 openssl_pkey_new 生成密钥对，如果环境不支持则跳过。
     */
    public function testRsa2SignAndVerify(): void
    {
        // 生成 RSA 密钥对（@ 抑制 OpenSSL 在受限环境下 "Unable to write random state" 的警告）
        $keyResource = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            $this->markTestSkipped('当前环境不支持 openssl_pkey_new 生成密钥对');
        }

        // 导出私钥
        $privateKeyPem = '';
        @openssl_pkey_export($keyResource, $privateKeyPem);

        // 导出公钥
        $keyDetails = openssl_pkey_get_details($keyResource);
        if ($keyDetails === false || !isset($keyDetails['key'])) {
            $this->markTestSkipped('无法导出公钥');
        }
        $publicKeyPem = $keyDetails['key'];

        $params = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
            'out_trade_no' => 'O1',
            'total_fee' => 100,
        ];

        // RSA2 签名
        $sign = Signer::rsa2($params, $privateKeyPem);

        // 签名应为 Base64 编码
        $this->assertNotEmpty($sign);
        $decoded = base64_decode($sign, true);
        $this->assertNotFalse($decoded, '签名应为有效的 Base64');
        $this->assertNotEmpty($decoded);

        // 验签应通过
        $this->assertTrue(Signer::verifyRsa($params, $publicKeyPem, $sign, false, 'SHA256'));
    }

    /**
     * 测试 verifyRsa：错误签名返回 false
     */
    public function testVerifyRsaFailsWithWrongSign(): void
    {
        $keyResource = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            $this->markTestSkipped('当前环境不支持 openssl_pkey_new 生成密钥对');
        }

        $keyDetails = openssl_pkey_get_details($keyResource);
        if ($keyDetails === false || !isset($keyDetails['key'])) {
            $this->markTestSkipped('无法导出公钥');
        }
        $publicKeyPem = $keyDetails['key'];

        $params = ['appid' => 'wx123'];

        // 错误的签名（无效 Base64 内容）
        $this->assertFalse(Signer::verifyRsa($params, $publicKeyPem, 'invalid-sign', false, 'SHA256'));
    }
}
