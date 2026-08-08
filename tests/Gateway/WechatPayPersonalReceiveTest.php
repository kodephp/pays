<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「个人收款」原生方法单元测试
 *
 * 验证 createQrCode / queryRecords / withdraw / queryWithdraw 四个原生方法
 * 正确组装请求并调用基类 HTTP 通道（不依赖真实网络）。
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
        $this->assertStringContainsString('pay/unifiedorder', $last['url']);

        $body = $last['data'];
        $this->assertSame('商品付款', $body['body']);
        $this->assertSame(100, $body['total_fee']);
        $this->assertSame('NATIVE', $body['trade_type']);
        $this->assertSame('wx123', $body['appid']);
        $this->assertSame('m1', $body['mch_id']);
        $this->assertSame('PERSONAL_PAY', $body['product_id']);
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
        $gateway = $this->createGateway(['pay/downloadbill' => $this->okXml()]);

        $gateway->queryRecords([
            'start_time' => '2024-04-01 00:00:00',
            'end_time' => '2024-04-25 23:59:59',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/downloadbill', $last['url']);
        $this->assertSame('ALL', $last['data']['bill_type']);
        $this->assertSame('m1', $last['data']['mch_id']);
    }

    public function testWithdraw(): void
    {
        $gateway = $this->createGateway(['mmpaymkttransfers/pay_bank' => $this->okXml()]);

        $gateway->withdraw([
            'amount' => 5000,
            'bank_card_no' => '6222020000000000',
            'real_name' => '张三',
            'out_biz_no' => 'WD_20240425000001',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/pay_bank', $last['url']);

        $body = $last['data'];
        $this->assertSame('WD_20240425000001', $body['partner_trade_no']);
        $this->assertSame(5000, $body['amount']);
        // 未配置 bank_public_key，退化为 base64 透传
        $this->assertSame('6222020000000000', base64_decode($body['enc_bank_no']));
        $this->assertSame('张三', base64_decode($body['enc_true_name']));
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
        $gateway = $this->createGateway(['mmpaymkttransfers/query_bank' => $this->okXml()]);

        $gateway->queryWithdraw('WD_20240425000001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/query_bank', $last['url']);
        $this->assertSame('WD_20240425000001', $last['data']['partner_trade_no']);
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('wechat', WechatPayGateway::getName());
    }
}
