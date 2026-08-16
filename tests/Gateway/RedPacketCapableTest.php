<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Douyin\DouyinPayGateway;
use Kode\Pays\Gateway\Jd\JdGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
use Kode\Pays\Gateway\Meituan\MeituanGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Tests\MockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * 红包能力（CAP_RED_PACKET）集中功能验证
 *
 * 与 WebhookCapableTest / QrCapableTest / RefundCapableTest / TransferCapableTest /
 * ProfitSharingCapableTest / BalanceCapableTest / SettlementCapableTest /
 * SubscriptionCapableTest 完全同构的能力视角集中测试：
 *  - 断言恰好 4 家真实实现者（alipay / wechat_v2 / jd / meituan）实现接口；
 *  - 断言明确无关的网关不实现接口（防虚报/误接回归）；
 *  - 用 MockHttpClient 真实驱动 3 个方法（sendRedPacket / groupRedPacket / queryRedPacket），
 *    验证确实向平台端点发请求并返回解析后的响应；
 *  - 裂变红包 total_num >= 3 的校验由各网关诚实抛出 paramError，予以断言。
 */
class RedPacketCapableTest extends TestCase
{
    // ---- 工厂：复用既有 *CapableTest 的成熟配置 ----

    private function alipay(array $responses = []): AlipayGateway
    {
        return new AlipayGateway([
            'app_id' => 'mock_app_id',
            'private_key' => $this->rsaPrivateKey(),
            'public_key' => 'mock_public_key',
            'notify_url' => 'https://example.com/notify',
        ], new MockHttpClient($responses));
    }

    private function wechat(array $responses = []): WechatPayGateway
    {
        return new WechatPayGateway([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'api_key_123',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
        ], new MockHttpClient($responses));
    }

    private function jd(array $responses = []): JdGateway
    {
        return new JdGateway([
            'merchant_no' => 'merchant_1',
            'des_key' => 'des_key_123',
            'md5_key' => 'md5_key_123',
        ], new MockHttpClient($responses));
    }

    private function meituan(array $responses = []): MeituanGateway
    {
        return new MeituanGateway([
            'app_id' => 'mt_app',
            'app_secret' => 'mt_secret',
            'merchant_id' => 'mt_mch',
        ], new MockHttpClient($responses));
    }

    // ---- 响应助手 ----

    private function rsaPrivateKey(): string
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

    /**
     * 微信 V2 红包响应 XML（不携带 sign，跳过响应签名校验）
     */
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

    // ---- 接口归属断言 ----

    public function testAllFourRedPacketGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(RedPacketCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(RedPacketCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(RedPacketCapableInterface::class, $this->jd());
        $this->assertInstanceOf(RedPacketCapableInterface::class, $this->meituan());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new KlarnaGateway(['username' => 'u', 'password' => 'p']));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a', 'secret_key' => 's', 'client_id' => 'c',
        ]));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']));
        // 微信 V3（APIv3）未登记 CAP_RED_PACKET，亦不实现该接口
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new WechatPayV3Gateway([
            'app_id' => 'wx_app', 'mch_id' => 'mch_1', 'serial_no' => 's',
            'private_key' => $this->rsaPrivateKey(), 'api_key' => 'k',
        ]));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new RevolutGateway([
            'api_key' => 'r', 'merchant_id' => 'm', 'account_id' => 'a',
        ]));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new DouyinPayGateway([
            'app_id' => 'a', 'merchant_id' => 'm', 'salt' => 's',
        ]));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new UnionPayGateway([
            'mer_id' => 'm1', 'cert_path' => '/tmp/c', 'verify_cert_path' => '/tmp/v', 'cert_pwd' => '123456',
        ]));
        $this->assertNotInstanceOf(RedPacketCapableInterface::class, new StripeGateway(['secret_key' => 'k']));
    }

    // ---- 支付宝：3 方法真实（gateway.do，method 在请求体） ----

    public function testAlipaySendRedPacketReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_coupon_order_app_pay_response' => ['code' => '10000', 'out_order_no' => 'RP_001'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->sendRedPacket([
            'mch_billno' => 'RP_001', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 100, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_001', $result['out_order_no']);
    }

    public function testAlipayGroupRedPacketReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_coupon_order_app_pay_response' => ['code' => '10000', 'out_order_no' => 'RP_G'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->groupRedPacket([
            'mch_billno' => 'RP_G', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 300, 'total_num' => 3, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_G', $result['out_order_no']);
    }

    public function testAlipayQueryRedPacketReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_coupon_order_query_response' => ['code' => '10000', 'out_order_no' => 'RP_Q'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->queryRedPacket('RP_Q');

        $this->assertSame('RP_Q', $result['out_order_no']);
    }

    // ---- 微信 V2：3 方法真实（XML，mmpaymkttransfers/*） ----

    public function testWechatV2SendRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/sendredpack' => $this->okXml(['mch_billno' => 'RP_001']),
        ]);

        $result = $gateway->sendRedPacket([
            'mch_billno' => 'RP_001', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 100, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_001', $result['mch_billno']);
    }

    public function testWechatV2GroupRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/sendgroupredpack' => $this->okXml(['mch_billno' => 'RP_G']),
        ]);

        $result = $gateway->groupRedPacket([
            'mch_billno' => 'RP_G', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 300, 'total_num' => 3, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_G', $result['mch_billno']);
    }

    public function testWechatV2QueryRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/gethbinfo' => $this->okXml(['mch_billno' => 'RP_Q']),
        ]);

        $result = $gateway->queryRedPacket('RP_Q');

        $this->assertSame('RP_Q', $result['mch_billno']);
    }

    // ---- 京东：3 方法真实（JSON，api/redpacket/*，resultCode=000000） ----

    public function testJdSendRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->jd([
            'api/redpacket/send' => json_encode(['resultCode' => '000000', 'mchBillNo' => 'RP_001']),
        ]);

        $result = $gateway->sendRedPacket([
            'mch_billno' => 'RP_001', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 100, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_001', $result['mchBillNo']);
    }

    public function testJdGroupRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->jd([
            'api/redpacket/group' => json_encode(['resultCode' => '000000', 'mchBillNo' => 'RP_G']),
        ]);

        $result = $gateway->groupRedPacket([
            'mch_billno' => 'RP_G', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 300, 'total_num' => 3, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_G', $result['mchBillNo']);
    }

    public function testJdQueryRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->jd([
            'api/redpacket/query' => json_encode(['resultCode' => '000000', 'mchBillNo' => 'RP_Q']),
        ]);

        $result = $gateway->queryRedPacket('RP_Q');

        $this->assertSame('RP_Q', $result['mchBillNo']);
    }

    // ---- 美团：3 方法真实（JSON，api/redpacket/*，status=SUCCESS） ----

    public function testMeituanSendRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->meituan([
            'api/redpacket/send' => json_encode(['status' => 'SUCCESS', 'mchBillNo' => 'RP_001']),
        ]);

        $result = $gateway->sendRedPacket([
            'mch_billno' => 'RP_001', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 100, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_001', $result['mchBillNo']);
    }

    public function testMeituanGroupRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->meituan([
            'api/redpacket/group' => json_encode(['status' => 'SUCCESS', 'mchBillNo' => 'RP_G']),
        ]);

        $result = $gateway->groupRedPacket([
            'mch_billno' => 'RP_G', 'send_name' => '商户', 're_openid' => 'openid_1',
            'total_amount' => 300, 'total_num' => 3, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
        ]);

        $this->assertSame('RP_G', $result['mchBillNo']);
    }

    public function testMeituanQueryRedPacketReturnsParsedResponse(): void
    {
        $gateway = $this->meituan([
            'api/redpacket/query' => json_encode(['status' => 'SUCCESS', 'mchBillNo' => 'RP_Q']),
        ]);

        $result = $gateway->queryRedPacket('RP_Q');

        $this->assertSame('RP_Q', $result['mchBillNo']);
    }

    // ---- 裂变红包 total_num >= 3 诚实校验 ----

    public function testGroupRedPacketRequiresTotalNumAtLeastThree(): void
    {
        $gateways = [
            'alipay' => $this->alipay(),
            'wechat_v2' => $this->wechat(),
            'jd' => $this->jd(),
            'meituan' => $this->meituan(),
        ];

        foreach ($gateways as $name => $gateway) {
            try {
                $gateway->groupRedPacket([
                    'mch_billno' => 'RP_X', 'send_name' => '商户', 're_openid' => 'openid_1',
                    'total_amount' => 300, 'total_num' => 2, 'wishing' => '祝福', 'act_name' => '活动', 'remark' => '备注',
                ]);
                $this->fail("{$name} 应在 total_num<3 时抛异常");
            } catch (PayException $e) {
                $this->assertStringContainsString('total_num', $e->getMessage());
            }
        }
    }
}
