<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「红包」原生方法单元测试
 *
 * 验证 sendRedPacket / groupRedPacket / queryRedPacket 三个原生方法
 * 正确组装请求并调用基类 HTTP 通道（不依赖真实网络）。
 */
class WechatPayRedPacketTest extends TestCase
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

    private function redpackXml(): string
    {
        return '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<mch_billno><![CDATA[REDPACK_1]]></mch_billno></xml>';
    }

    public function testSendRedPacket(): void
    {
        $gateway = $this->createGateway(['mmpaymkttransfers/sendredpack' => $this->redpackXml()]);

        $result = $gateway->sendRedPacket([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);

        $this->assertSame('SUCCESS', $result['return_code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/sendredpack', $last['url']);

        // 注：当前 post 直接透传请求数组（投产前需接入 Signer::md5 与 arrayToXml），此处断言请求字段
        $body = $last['data'];
        $this->assertSame('REDPACK_1', $body['mch_billno']);
        $this->assertSame('oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', $body['re_openid']);
        $this->assertSame(100, $body['total_amount']);
        $this->assertSame(1, $body['total_num']);
        $this->assertSame('m1', $body['mch_id']);
        $this->assertSame('wx123', $body['wxappid']);
    }

    public function testSendRedPacketMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $gateway->sendRedPacket([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
        ]);
    }

    public function testGroupRedPacket(): void
    {
        $gateway = $this->createGateway(['mmpaymkttransfers/sendgroupredpack' => $this->redpackXml()]);

        $result = $gateway->groupRedPacket([
            'mch_billno' => 'GROUP_1',
            'send_name' => '某某公司',
            're_openid' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            'total_amount' => 300,
            'total_num' => 3,
            'wishing' => '裂变红包',
            'act_name' => '分享活动',
            'remark' => '分享给好友领取',
        ]);

        $this->assertSame('SUCCESS', $result['return_code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/sendgroupredpack', $last['url']);

        $body = $last['data'];
        $this->assertSame(3, $body['total_num']);
        $this->assertSame('ALL_RAND', $body['amt_type']);
    }

    public function testGroupRedPacketRequiresAtLeastThree(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/total_num 必须 >= 3/');

        $gateway->groupRedPacket([
            'mch_billno' => 'GROUP_1',
            'send_name' => '某某公司',
            're_openid' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            'total_amount' => 300,
            'total_num' => 2,
            'wishing' => '裂变红包',
            'act_name' => '分享活动',
            'remark' => '分享给好友领取',
        ]);
    }

    public function testQueryRedPacket(): void
    {
        $gateway = $this->createGateway(['mmpaymkttransfers/gethbinfo' => $this->redpackXml()]);

        $result = $gateway->queryRedPacket('REDPACK_1');

        $this->assertSame('SUCCESS', $result['return_code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/gethbinfo', $last['url']);

        $body = $last['data'];
        $this->assertSame('REDPACK_1', $body['mch_billno']);
        $this->assertSame('MCHT', $body['bill_type']);
    }
}
