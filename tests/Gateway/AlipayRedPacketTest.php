<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 支付宝网关「红包」原生方法单元测试
 *
 * 验证 sendRedPacket / groupRedPacket / queryRedPacket 三个原生方法
 * 复用 buildRequestParams 标准 RSA2 签名，金额按分（/100）。
 */
class AlipayRedPacketTest extends TestCase
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

    private function okJson(): string
    {
        return json_encode([
            'alipay_fund_coupon_order_app_pay_response' => [
                'code' => '10000',
                'msg' => 'Success',
                'out_order_no' => 'REDPACK_1',
                'status' => 'SUCCESS',
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

    public function testSendRedPacket(): void
    {
        $gateway = $this->createGateway(['gateway.do' => $this->okJson()]);

        $result = $gateway->sendRedPacket([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => '2088xxxx',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);

        $this->assertSame('10000', $result['code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway.do', $last['url']);
        $this->assertSame('alipay.fund.coupon.order.app.pay', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('REDPACK_1', $biz['out_order_no']);
        $this->assertSame('2088xxxx', $biz['payee_user_id']);
        $this->assertSame('1.00', $biz['amount']);
        $this->assertSame('新年活动', $biz['order_title']);
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
        $gateway = $this->createGateway(['gateway.do' => $this->okJson()]);

        $result = $gateway->groupRedPacket([
            'mch_billno' => 'GROUP_1',
            'send_name' => '某某公司',
            're_openid' => '2088xxxx',
            'total_amount' => 300,
            'total_num' => 3,
            'wishing' => '裂变红包',
            'act_name' => '分享活动',
            'remark' => '分享给好友领取',
        ]);

        $this->assertSame('10000', $result['code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.coupon.order.app.pay', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('3.00', $biz['amount']);

        $businessParams = json_decode($biz['business_params'], true);
        $this->assertSame('GROUP_RED_PACKET', $businessParams['sub_biz_scene']);
        $this->assertSame(3, $businessParams['total_num']);
    }

    public function testGroupRedPacketRequiresAtLeastThree(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/total_num 必须 >= 3/');

        $gateway->groupRedPacket([
            'mch_billno' => 'GROUP_1',
            'send_name' => '某某公司',
            're_openid' => '2088xxxx',
            'total_amount' => 300,
            'total_num' => 2,
            'wishing' => '裂变红包',
            'act_name' => '分享活动',
            'remark' => '分享给好友领取',
        ]);
    }

    public function testQueryRedPacket(): void
    {
        $queryJson = json_encode([
            'alipay_fund_coupon_order_query_response' => [
                'code' => '10000',
                'msg' => 'Success',
                'out_order_no' => 'REDPACK_1',
                'status' => 'SUCCESS',
            ],
        ]);

        $gateway = $this->createGateway(['gateway.do' => $queryJson]);

        $result = $gateway->queryRedPacket('REDPACK_1');

        $this->assertSame('10000', $result['code']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.coupon.order.query', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('REDPACK_1', $biz['out_order_no']);
    }
}
