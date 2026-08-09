<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「个人收款」原生方法单元测试
 *
 * 验证 createQrCode / queryRecords / withdraw / queryWithdraw 四个原生方法
 * 以「XML + MD5 签名」规范组装请求（投产前合规化），付款到银行卡携带商户 SSL 证书，
 * 不依赖真实网络。
 */
class WechatPayPersonalReceiveTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): WechatPayGateway
    {
        $config = array_merge([
            'app_id' => 'wx123',
            'mch_id' => 'm1',
            'api_key' => 'testkey',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new WechatPayGateway($config, $mock);
    }

    private function getMockClient(WechatPayGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        return $prop->getValue($gateway);
    }

    /**
     * 将微信 XML 请求体解析为关联数组（与网关 xmlToArray 一致）
     */
    private function parseXml(string $xml): array
    {
        $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        $decoded = json_decode((string) json_encode($element), true);
        $result = is_array($decoded) ? $decoded : [];

        // 微信空元素经 SimpleXML + JSON 会退化为空数组，归一为空字符串以对齐 MD5 签名计算
        return array_map(static fn ($v) => is_array($v) && $v === [] ? '' : $v, $result);
    }

    private function okXml(array $extra = []): string
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<mch_id><![CDATA[m1]]></mch_id>';

        foreach ($extra as $k => $v) {
            $xml .= "<{$k}><![CDATA[{$v}]]></{$k}>";
        }

        return $xml . '</xml>';
    }

    private function billCsv(): string
    {
        return "交易时间,公众账号ID,商户号,子商户号,设备号,微信订单号,商户订单号,用户标识,交易类型,交易状态,付款银行,货币种类,总金额,企业红包金额,微信退款单号,商户退款单号,退款金额,退款企业红包金额,退款类型,退款状态,商品名称,商户数据包,手续费,费率,订单金额,费率金额\n"
            . "`2024-04-01 10:00:00`,`wx123`,`m1`,`0`,`0`,`420000123`,`PERSONAL_x`,`openid`,`NATIVE`,`SUCCESS`,`BANK`,`CNY`,`100`,`0`,`0`,`0`,`0`,`0`,`0`,`0`,`商品`,`0`,`0`,`0`,`100`,`0`\n"
            . "总交易单数,1\n";
    }

    public function testCreateQrCode(): void
    {
        $gateway = $this->createGateway(['pay/unifiedorder' => $this->okXml([
            'code_url' => 'weixin://wxpay/bizpayurl?pr=abc',
            'prepay_id' => 'prepay_1',
        ])]);

        $result = $gateway->createQrCode([
            'amount' => 100,
            'description' => '商品付款',
            'attach' => ['product_id' => '123'],
        ]);

        $this->assertStringStartsWith('PERSONAL_', $result['out_trade_no']);
        $this->assertSame('weixin://wxpay/bizpayurl?pr=abc', $result['code_url']);
        $this->assertSame('prepay_1', $result['prepay_id']);
        $this->assertSame(100, $result['amount']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertStringContainsString('pay/unifiedorder', $last['url']);

        $body = $last['data']['body'];
        $parsed = $this->parseXml($body);
        $this->assertSame('商品付款', $parsed['body']);
        $this->assertSame('100', (string) ($parsed['total_fee'] ?? ''));
        $this->assertSame('NATIVE', $parsed['trade_type']);
        $this->assertSame('wx123', $parsed['appid']);
        $this->assertSame('m1', $parsed['mch_id']);
        $this->assertSame('PERSONAL_PAY', $parsed['product_id']);
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'));
        // unifiedorder 无需商户证书
        $this->assertArrayNotHasKey('cert', $last['data']['options'] ?? []);
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
        $gateway = $this->createGateway(['pay/downloadbill' => $this->billCsv()]);

        $result = $gateway->queryRecords([
            'start_time' => '2024-04-01 00:00:00',
            'end_time' => '2024-04-25 23:59:59',
        ]);

        $this->assertArrayHasKey('records', $result);
        $this->assertNotEmpty($result['records']);
        $this->assertSame('20240401', $result['bill_date']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/downloadbill', $last['url']);

        $body = $last['data']['body'];
        $parsed = $this->parseXml($body);
        $this->assertSame('ALL', $parsed['bill_type']);
        $this->assertSame('m1', $parsed['mch_id']);
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'));
    }

    public function testWithdraw(): void
    {
        $gateway = $this->createGateway(
            ['mmpaymkttransfers/pay_bank' => $this->okXml()],
            ['cert_path' => '/tmp/apiclient_cert.pem', 'key_path' => '/tmp/apiclient_key.pem'],
        );

        $gateway->withdraw([
            'amount' => 5000,
            'bank_card_no' => '6222020000000000',
            'real_name' => '张三',
            'out_biz_no' => 'WD_20240425000001',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/pay_bank', $last['url']);

        $body = $last['data']['body'];
        $parsed = $this->parseXml($body);
        $this->assertSame('WD_20240425000001', $parsed['partner_trade_no']);
        $this->assertSame('5000', (string) ($parsed['amount'] ?? ''));
        // 未配置 bank_public_key，退化为 base64 透传
        $this->assertSame('6222020000000000', base64_decode((string) $parsed['enc_bank_no']));
        $this->assertSame('张三', base64_decode((string) $parsed['enc_true_name']));
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'));
        // 付款到银行卡需携带商户证书
        $this->assertArrayHasKey('cert', $last['data']['options'] ?? []);
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
        $gateway = $this->createGateway(
            ['mmpaymkttransfers/query_bank' => $this->okXml()],
            ['cert_path' => '/tmp/apiclient_cert.pem', 'key_path' => '/tmp/apiclient_key.pem'],
        );

        $gateway->queryWithdraw('WD_20240425000001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/query_bank', $last['url']);

        $body = $last['data']['body'];
        $parsed = $this->parseXml($body);
        $this->assertSame('WD_20240425000001', $parsed['partner_trade_no']);
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'));
        $this->assertArrayHasKey('cert', $last['data']['options'] ?? []);
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('wechat', WechatPayGateway::getName());
    }
}
