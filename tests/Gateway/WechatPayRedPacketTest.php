<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「红包」原生方法单元测试
 *
 * 验证 sendRedPacket / groupRedPacket / queryRedPacket 三个原生方法
 * 以「XML + MD5 签名」规范组装请求（投产前合规化），并携带商户 SSL 证书，
 * 不依赖真实网络。
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

    /**
     * 将微信 XML 响应/请求体解析为关联数组（与网关 xmlToArray 一致）
     */
    private function parseXml(string $xml): array
    {
        $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        $decoded = json_decode((string) json_encode($element), true);
        $result = is_array($decoded) ? $decoded : [];

        // 微信空元素经 SimpleXML + JSON 会退化为空数组，归一为空字符串以对齐 MD5 签名计算
        return array_map(static fn ($v) => is_array($v) && $v === [] ? '' : $v, $result);
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
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertStringContainsString('mmpaymkttransfers/sendredpack', $last['url']);
        $this->assertSame(['Content-Type' => 'text/xml'], $last['headers']);

        // 请求体为 XML，且经 MD5 签名
        $body = $last['data']['body'];
        $this->assertIsString($body);
        $this->assertStringContainsString('<mch_billno><![CDATA[REDPACK_1]]></mch_billno>', $body);
        $this->assertStringContainsString('<re_openid><![CDATA[oUpF8uMuAJO_M2pxb1Q9zNjWeS6o]]></re_openid>', $body);

        $parsed = $this->parseXml($body);
        $this->assertSame('1', (string) ($parsed['total_num'] ?? ''));
        $this->assertSame('m1', $parsed['mch_id']);
        $this->assertSame('wx123', $parsed['wxappid']);
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'), '响应体 MD5 签名应校验通过');
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

    public function testSendRedPacketPassesClientCert(): void
    {
        $gateway = $this->createGateway(
            ['mmpaymkttransfers/sendredpack' => $this->redpackXml()],
            ['cert_path' => '/tmp/apiclient_cert.pem', 'key_path' => '/tmp/apiclient_key.pem'],
        );

        $gateway->sendRedPacket([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $options = $last['data']['options'] ?? [];
        $this->assertSame('/tmp/apiclient_cert.pem', $options['cert'] ?? null);
        $this->assertSame('/tmp/apiclient_key.pem', $options['ssl_key'] ?? null);
    }

    public function testSendRedPacketWithoutCertConfigSendsNoCert(): void
    {
        $gateway = $this->createGateway(['mmpaymkttransfers/sendredpack' => $this->redpackXml()]);

        $gateway->sendRedPacket([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertArrayNotHasKey('cert', $last['data']['options'] ?? []);
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

        $body = $last['data']['body'];
        $parsed = $this->parseXml($body);
        $this->assertSame('3', (string) ($parsed['total_num'] ?? ''));
        $this->assertSame('ALL_RAND', $parsed['amt_type']);
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'));
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
        $gateway = $this->createGateway(
            ['mmpaymkttransfers/gethbinfo' => $this->redpackXml()],
            ['cert_path' => '/tmp/apiclient_cert.pem', 'key_path' => '/tmp/apiclient_key.pem'],
        );

        $result = $gateway->queryRedPacket('REDPACK_1');

        $this->assertSame('SUCCESS', $result['return_code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/gethbinfo', $last['url']);

        $body = $last['data']['body'];
        $parsed = $this->parseXml($body);
        $this->assertSame('REDPACK_1', $parsed['mch_billno']);
        $this->assertSame('MCHT', $parsed['bill_type']);
        $this->assertTrue(Signer::verifyMd5($parsed, 'testkey'));
        // 查询接口同样需携带证书
        $this->assertArrayHasKey('cert', $last['data']['options'] ?? []);
    }
}
