<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 支付宝网关「个人收款」原生方法单元测试
 *
 * 验证 createQrCode / queryRecords / withdraw / queryWithdraw 四个原生方法
 * 复用 buildRequestParams 标准 RSA2 签名，金额按分（/100）。
 */
class AlipayPersonalReceiveTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): AlipayGateway
    {
        $privateKey = $this->generateRsaPrivateKey();

        $config = array_merge([
            'app_id' => '2021000000000000',
            'private_key' => $privateKey,
            'public_key' => $privateKey, // 单测仅校验本地签名，不校验回执
            'notify_url' => 'https://example.com/notify',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new AlipayGateway($config, $mock);
    }

    /**
     * 临时生成合法 RSA 私钥（对齐 SignerTest 做法，避免依赖外部文件）
     */
    private function generateRsaPrivateKey(): string
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
            'bits' => 2048,
        ]);

        if ($res === false) {
            $this->markTestSkipped('当前环境不支持 openssl 生成 RSA 私钥');
        }

        $exported = '';
        openssl_pkey_export($res, $exported);

        return $exported;
    }

    private function getMockClient(AlipayGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        return $prop->getValue($gateway);
    }

    private function okJson(string $method): string
    {
        return json_encode([
            "{$method}_response" => [
                'code' => '10000',
                'msg' => 'Success',
                'out_biz_no' => 'WD_20240425000001',
            ],
        ]);
    }

    private function decodeBizContent(MockHttpClient $client): array
    {
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertArrayHasKey('biz_content', $last['data']);
        $biz = json_decode($last['data']['biz_content'], true);
        $this->assertIsArray($biz);

        return $biz;
    }

    public function testCreateQrCode(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_trade_precreate')]);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
            'attach' => ['product_id' => '123'],
        ]);

        $this->assertStringStartsWith('PERSONAL_', $result['out_trade_no']);
        $this->assertSame(100, $result['amount']);
        $this->assertSame('商品付款', $result['description']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway.do', $last['url']);
        $this->assertSame('alipay.trade.precreate', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('商品付款', $biz['subject']);
        $this->assertSame('1.00', $biz['total_amount']);
    }

    public function testCreateQrCodeMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $gateway->createQrCode(['description' => '商品付款']);
    }

    public function testQueryRecords(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_trade_query')]);

        $gateway->queryRecords([
            'start_time' => '2024-04-01 00:00:00',
            'end_time' => '2024-04-25 23:59:59',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.trade.query', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('2024-04-01 00:00:00', $biz['start_time']);
        $this->assertSame('2024-04-25 23:59:59', $biz['end_time']);
    }

    public function testWithdraw(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_fund_trans_uni_transfer')]);

        $gateway->withdraw([
            'amount' => 5000,
            'bank_card_no' => '6222020000000000',
            'real_name' => '张三',
            'out_biz_no' => 'WD_20240425000001',
            'bank_code' => 'CMB',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.uni.transfer', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('WD_20240425000001', $biz['out_biz_no']);
        $this->assertSame('50.00', $biz['trans_amount']);
        $this->assertSame('6222020000000000', $biz['payee_info']['identity']);
        $this->assertSame('张三', $biz['payee_info']['name']);
        $this->assertSame('CMB', $biz['payee_info']['bank_code']);
    }

    public function testWithdrawMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $gateway->withdraw(['amount' => 5000, 'bank_card_no' => '6222', 'real_name' => '张三']);
    }

    public function testQueryWithdraw(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson('alipay_fund_trans_common_query')]);

        $gateway->queryWithdraw('WD_20240425000001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.common.query', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('WD_20240425000001', $biz['out_biz_no']);
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('alipay', AlipayGateway::getName());
    }
}
