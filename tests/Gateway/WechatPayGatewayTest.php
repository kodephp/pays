<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关单元测试
 */
class WechatPayGatewayTest extends TestCase
{
    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应
     * @param array<string, mixed> $config 网关配置
     */
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

    /**
     * 获取网关内部的 MockHttpClient（用于断言请求历史）
     */
    private function getMockClient(WechatPayGateway $gateway): MockHttpClient
    {
        $ref = new \ReflectionClass($gateway);

        while ($ref && !$ref->hasProperty('httpClient')) {
            $ref = $ref->getParentClass();
        }

        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);

        $client = $prop->getValue($gateway);
        $this->assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    /**
     * 测试创建订单：验证返回值与请求参数
     */
    public function testCreateOrder(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<code_url><![CDATA[weixin://wxpay/bizpayurl?pr=xxx]]></code_url></xml>';

        $gateway = $this->createGateway(['pay/unifiedorder' => $xml]);

        $result = $gateway->createOrder([
            'out_trade_no' => 'O1',
            'total_fee' => 100,
            'body' => 'test',
            'trade_type' => 'NATIVE',
        ]);

        $this->assertSame('SUCCESS', $result['return_code']);
        $this->assertSame('SUCCESS', $result['result_code']);
        $this->assertStringContainsString('weixin://wxpay/bizpayurl', $result['code_url']);

        // 验证 HTTP 请求历史
        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/unifiedorder', $last['url']);
        $this->assertSame('POST_RAW', $last['method']);

        // body 是 XML，应包含 appid/mch_id/sign
        $body = $last['data']['body'] ?? '';
        $this->assertStringContainsString('<appid>', $body);
        $this->assertStringContainsString('<mch_id>', $body);
        $this->assertStringContainsString('<sign>', $body);
        $this->assertStringContainsString('wx123', $body);
        $this->assertStringContainsString('m1', $body);
    }

    /**
     * 测试查询订单：验证请求 URL
     */
    public function testQueryOrder(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<out_trade_no><![CDATA[O1]]></out_trade_no>'
            . '<trade_state><![CDATA[SUCCESS]]></trade_state></xml>';

        $gateway = $this->createGateway(['pay/orderquery' => $xml]);

        $result = $gateway->queryOrder('O1');

        $this->assertSame('SUCCESS', $result['return_code']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/orderquery', $last['url']);
        $this->assertStringContainsString('O1', $last['data']['body']);
    }

    /**
     * 测试关闭订单：验证请求 URL
     */
    public function testCloseOrder(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code></xml>';

        $gateway = $this->createGateway(['pay/closeorder' => $xml]);

        $result = $gateway->closeOrder('O1');

        $this->assertSame('SUCCESS', $result['return_code']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/closeorder', $last['url']);
    }

    /**
     * 测试验证通知：构造带正确 sign 的通知数据，verifyNotify 返回 true
     */
    public function testVerifyNotifySuccess(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
            'out_trade_no' => 'O1',
            'total_fee' => 100,
            'transaction_id' => 'wx_tx_1',
        ];
        $data['sign'] = Signer::md5($data, 'testkey');

        $this->assertTrue($gateway->verifyNotify($data));
    }

    /**
     * 测试验证通知：无 sign 字段返回 false
     */
    public function testVerifyNotifyMissingSign(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'appid' => 'wx123',
            'mch_id' => 'm1',
            'out_trade_no' => 'O1',
        ];

        $this->assertFalse($gateway->verifyNotify($data));
    }

    /**
     * 测试退款参数校验：缺 out_refund_no 抛 PayException
     */
    public function testRefundValidation(): void
    {
        $gateway = $this->createGateway(['secapi/pay/refund' => '<xml></xml>']);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_refund_no');

        $gateway->refund([
            'out_trade_no' => 'O1',
            'total_fee' => 100,
            'refund_fee' => 50,
            // 缺 out_refund_no
        ]);
    }

    /**
     * 测试获取网关标识
     */
    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('wechat', WechatPayGateway::getName());
    }

    /**
     * 测试沙箱环境基础 URL：配置 sandbox=true，请求 URL 含 'sandboxnew'
     */
    public function testSandboxBaseUrl(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code></xml>';

        $gateway = $this->createGateway(
            ['pay/unifiedorder' => $xml],
            ['sandbox' => true],
        );

        $gateway->createOrder([
            'out_trade_no' => 'O1',
            'total_fee' => 100,
            'body' => 'test',
            'trade_type' => 'NATIVE',
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('sandboxnew', $last['url']);
    }
}
