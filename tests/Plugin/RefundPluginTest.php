<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Plugin\RefundPlugin;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 退款插件单元测试
 *
 * 验证 RefundPlugin 对微信支付退款的支持：
 * - apply() 调用 secapi/pay/refund 端点
 * - query() 调用 pay/refundquery 端点
 * - 参数校验逻辑
 * - 不支持的网关抛异常
 *
 * 说明：RefundPlugin 通过 gateway->post() 调用 AbstractGateway::post()，
 * 后者内部只调用一次 parseResponse，不会触发 WechatPayGateway 双重 parseResponse 的 bug。
 */
class RefundPluginTest extends TestCase
{
    /**
     * 创建带 MockHttpClient 的微信网关
     */
    private function createWechatGateway(MockHttpClient $mock): WechatPayGateway
    {
        return new WechatPayGateway(
            ['app_id' => 'wx123', 'mch_id' => 'm1', 'api_key' => 'testkey'],
            $mock,
        );
    }

    /**
     * 微信退款成功响应 XML
     */
    private function refundSuccessXml(): string
    {
        return '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<refund_id><![CDATA[wx_r_001]]></refund_id>'
            . '<out_refund_no><![CDATA[R1]]></out_refund_no></xml>';
    }

    /**
     * 测试申请微信退款：验证请求 URL 含 'secapi/pay/refund'
     */
    public function testApplyWechatRefund(): void
    {
        $mock = new MockHttpClient([
            'secapi/pay/refund' => $this->refundSuccessXml(),
        ]);
        $gateway = $this->createWechatGateway($mock);
        $plugin = new RefundPlugin($gateway);

        $result = $plugin->apply([
            'out_trade_no' => 'O1',
            'out_refund_no' => 'R1',
            'total_fee' => 100,
            'refund_fee' => 50,
            'refund_desc' => '商品质量问题',
        ]);

        // 验证返回结果包含退款单号
        $this->assertSame('SUCCESS', $result['return_code']);
        $this->assertSame('R1', $result['out_refund_no']);

        // 验证请求 URL 含 'secapi/pay/refund'
        $last = $mock->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('secapi/pay/refund', $last['url']);
        $this->assertSame('POST', $last['method']);

        // 验证请求参数包含关键字段
        $data = $last['data'];
        $this->assertSame('wx123', $data['appid']);
        $this->assertSame('m1', $data['mch_id']);
        $this->assertSame('R1', $data['out_refund_no']);
        $this->assertSame(50, $data['refund_fee']);
        $this->assertSame('O1', $data['out_trade_no']);
    }

    /**
     * 测试查询微信退款：验证请求 URL 含 'pay/refundquery'
     */
    public function testQueryWechatRefund(): void
    {
        $queryXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<refund_count>1</refund_count>'
            . '<out_refund_no_0><![CDATA[R1]]></out_refund_no_0></xml>';

        $mock = new MockHttpClient([
            'pay/refundquery' => $queryXml,
        ]);
        $gateway = $this->createWechatGateway($mock);
        $plugin = new RefundPlugin($gateway);

        $result = $plugin->query('R1');

        $this->assertSame('SUCCESS', $result['return_code']);

        $last = $mock->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/refundquery', $last['url']);
        $this->assertSame('POST', $last['method']);

        // 验证请求参数
        $data = $last['data'];
        $this->assertSame('R1', $data['out_refund_no']);
        $this->assertSame('wx123', $data['appid']);
        $this->assertSame('m1', $data['mch_id']);
    }

    /**
     * 测试申请退款缺订单号：缺 out_trade_no 和 transaction_id 抛 PayException
     */
    public function testApplyMissingOrderThrowsException(): void
    {
        $mock = new MockHttpClient([]);
        $gateway = $this->createWechatGateway($mock);
        $plugin = new RefundPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('out_trade_no 和 transaction_id 必须至少提供一个');

        $plugin->apply([
            'out_refund_no' => 'R1',
            'refund_fee' => 50,
            // 缺 out_trade_no 和 transaction_id
        ]);
    }

    /**
     * 测试申请退款缺退款单号：缺 out_refund_no 抛 PayException
     */
    public function testApplyMissingRefundNoThrowsException(): void
    {
        $mock = new MockHttpClient([]);
        $gateway = $this->createWechatGateway($mock);
        $plugin = new RefundPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_refund_no');

        $plugin->apply([
            'out_trade_no' => 'O1',
            'refund_fee' => 50,
            // 缺 out_refund_no
        ]);
    }

    /**
     * 测试申请退款缺退款金额：缺 refund_fee 抛 PayException
     */
    public function testApplyMissingRefundFeeThrowsException(): void
    {
        $mock = new MockHttpClient([]);
        $gateway = $this->createWechatGateway($mock);
        $plugin = new RefundPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：refund_fee');

        $plugin->apply([
            'out_trade_no' => 'O1',
            'out_refund_no' => 'R1',
            // 缺 refund_fee
        ]);
    }

    /**
     * 测试不支持的网关：用 unionpay 构造 plugin，apply 抛异常
     */
    public function testUnsupportedGatewayThrowsException(): void
    {
        $mock = new MockHttpClient([]);
        $unionPay = new UnionPayGateway(
            ['mer_id' => 'm1', 'cert_path' => '/fake/cert', 'cert_pwd' => 'pwd'],
            $mock,
        );
        $plugin = new RefundPlugin($unionPay);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('当前网关不支持退款功能');

        $plugin->apply([
            'out_trade_no' => 'O1',
            'out_refund_no' => 'R1',
            'refund_fee' => 50,
        ]);
    }

    /**
     * 测试不支持的网关查询退款：用 unionpay 构造 plugin，query 抛异常
     */
    public function testUnsupportedGatewayQueryThrowsException(): void
    {
        $mock = new MockHttpClient([]);
        $unionPay = new UnionPayGateway(
            ['mer_id' => 'm1', 'cert_path' => '/fake/cert', 'cert_pwd' => 'pwd'],
            $mock,
        );
        $plugin = new RefundPlugin($unionPay);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('当前网关不支持退款查询');

        $plugin->query('R1');
    }
}
