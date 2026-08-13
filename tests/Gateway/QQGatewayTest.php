<?php

declare(strict_types=1);

namespace Kode\Pays\Tests;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\QQ\QQGateway;
use PHPUnit\Framework\TestCase;

/**
 * QQ 支付网关测试。
 *
 * 重点覆盖：V3 鉴权头（WECHATPAY2-SHA256-RSA2048）是否在所有请求上正确注入、
 * 服务商字段透传、异步通知 MD5 验签，以及缺 V3 密钥的早失败。
 */
class QQGatewayTest extends TestCase
{
    private static ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$privateKey !== null) {
            return;
        }

        $keyResource = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            $this->markTestSkipped('当前环境不支持 openssl_pkey_new 生成密钥对');
        }

        $privateKeyPem = '';
        @openssl_pkey_export($keyResource, $privateKeyPem);
        self::$privateKey = $privateKeyPem;
    }

    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): QQGateway
    {
        $config = array_merge([
            'app_id' => 'qq_app_123',
            'mch_id' => 'qq_mch_456',
            'api_key' => 'qq_test_key',
            'serial_no' => 'QQ_SERIAL_123',
            'private_key' => self::$privateKey,
        ], $config);

        return new QQGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(QQGateway $gateway): MockHttpClient
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
     * 构造缺少 V3 密钥（serial_no）时应早失败，避免生产环境发出无鉴权请求。
     */
    public function testConstructWithoutV3KeysThrows(): void
    {
        $this->expectException(PayException::class);

        new QQGateway([
            'app_id' => 'qq_app_123',
            'mch_id' => 'qq_mch_456',
            'api_key' => 'qq_test_key',
        ], new MockHttpClient());
    }

    /**
     * createOrder 必须带 WECHATPAY2-SHA256-RSA2048 鉴权头，且 body 字段正确。
     */
    public function testCreateOrderCarriesV3AuthHeader(): void
    {
        $gateway = $this->createGateway();
        $gateway->createOrder([
            'out_trade_no' => 'ORDER_001',
            'total_amount' => 10000,
            'subject' => '商品购买',
            'trade_type' => 'JSAPI',
            'openid' => 'qq_openid_abc',
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertStringContainsString('v3/pay/transaction/jsapi', $last['url']);

        $this->assertArrayHasKey('Authorization', $last['headers']);
        $this->assertMatchesRegularExpression(
            '/^WECHATPAY2-SHA256-RSA2048 mchid="qq_mch_456"/',
            $last['headers']['Authorization'],
        );

        $body = json_decode($last['data']['body'] ?? '', true);
        $this->assertIsArray($body);
        $this->assertSame('ORDER_001', $body['out_trade_no'] ?? null);
        $this->assertSame('qq_app_123', $body['appid'] ?? null);
        $this->assertSame('qq_mch_456', $body['mchid'] ?? null);
        $this->assertSame('qq_openid_abc', $body['openid'] ?? null);
    }

    /**
     * JSAPI 支付缺少 openid 应直接报错。
     */
    public function testCreateOrderJsapiRequiresOpenid(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->createOrder([
            'out_trade_no' => 'ORDER_002',
            'total_amount' => 100,
            'trade_type' => 'JSAPI',
        ]);
    }

    /**
     * queryOrder 按 orderId 长度走 out-trade-no / transaction-id 两种端点，均带鉴权头。
     */
    public function testQueryOrderByOutTradeNo(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryOrder('SHORT_NO');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('out-trade-no/SHORT_NO', $last['url']);
        $this->assertArrayHasKey('Authorization', $last['headers']);
    }

    public function testQueryOrderByTransactionId(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryOrder(str_repeat('X', 40));

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('transaction/id/', $last['url']);
        $this->assertArrayHasKey('Authorization', $last['headers']);
    }

    /**
     * refund 走 V3 鉴权通道，body 字段正确。
     */
    public function testRefundCarriesV3AuthHeader(): void
    {
        $gateway = $this->createGateway();
        $gateway->refund([
            'out_trade_no' => 'ORDER_003',
            'out_refund_no' => 'REF_003',
            'refund_fee' => 100,
            'total_fee' => 200,
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertStringContainsString('v3/refund/domestic/refunds', $last['url']);
        $this->assertArrayHasKey('Authorization', $last['headers']);

        $body = json_decode($last['data']['body'] ?? '', true);
        $this->assertSame('REF_003', $body['out_refund_no'] ?? null);
        $this->assertSame(100, $body['refund_fee'] ?? null);
    }

    public function testQueryRefundCarriesV3AuthHeader(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryRefund('REF_004');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('refunds/REF_004', $last['url']);
        $this->assertArrayHasKey('Authorization', $last['headers']);
    }

    public function testCloseOrderCarriesV3AuthHeader(): void
    {
        $gateway = $this->createGateway();
        $gateway->closeOrder('ORDER_005');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertStringContainsString('out-trade-no/ORDER_005/close', $last['url']);
        $this->assertArrayHasKey('Authorization', $last['headers']);
    }

    /**
     * 服务商模式：配置 sub_mchid / sub_appid 时自动注入到 V3 请求。
     */
    public function testServiceProviderFieldsInjected(): void
    {
        $gateway = $this->createGateway([], [
            'sub_mchid' => 'SUB_MCH',
            'sub_appid' => 'SUB_APP',
        ]);

        $gateway->createOrder([
            'out_trade_no' => 'ORDER_SP',
            'total_amount' => 100,
            'trade_type' => 'NATIVE',
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $body = json_decode($last['data']['body'] ?? '', true);
        $this->assertSame('SUB_MCH', $body['sub_mchid'] ?? null);
        $this->assertSame('SUB_APP', $body['sub_appid'] ?? null);
    }

    /**
     * verifyNotify 使用 MD5(api_key) 验签，且与 hash_equals 时序安全。
     */
    public function testVerifyNotifyValid(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'appid' => 'qq_app_123',
            'mchid' => 'qq_mch_456',
            'out_trade_no' => 'ORDER_001',
            'total_fee' => '10000',
        ];
        ksort($data);
        $string = http_build_query($data, '', '&', PHP_QUERY_RFC3986) . '&key=qq_test_key';
        $data['sign'] = strtoupper(md5($string));

        $this->assertTrue($gateway->verifyNotify($data));
    }

    public function testVerifyNotifyTamperedFails(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'appid' => 'qq_app_123',
            'out_trade_no' => 'ORDER_001',
            'total_fee' => '10000',
            'sign' => 'DEADBEEF',
        ];

        $this->assertFalse($gateway->verifyNotify($data));
    }

    public function testVerifyNotifyMissingSignFails(): void
    {
        $gateway = $this->createGateway();

        $this->assertFalse($gateway->verifyNotify(['out_trade_no' => 'X']));
    }
}
