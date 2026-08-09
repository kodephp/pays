<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;
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

    /**
     * 测试单笔转账：验证端点与请求字段（金额按分）
     */
    public function testSingleTransfer(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<partner_trade_no><![CDATA[T1]]></partner_trade_no></xml>';

        $gateway = $this->createGateway(['mmpaymkttransfers/promotion/transfers' => $xml]);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['type' => 'openid', 'account' => 'openid123', 'name' => '张三'],
            'description' => '佣金',
            'client_ip' => '10.0.0.1',
        ]);

        $this->assertSame('SUCCESS', $result['return_code']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('mmpaymkttransfers/promotion/transfers', $last['url']);
        $this->assertSame('openid123', $last['data']['openid'] ?? '');
        $this->assertSame('T1', $last['data']['partner_trade_no'] ?? '');
        $this->assertSame(100, $last['data']['amount'] ?? 0);
        $this->assertSame('wx123', $last['data']['mch_appid'] ?? '');
        $this->assertSame('m1', $last['data']['mchid'] ?? '');
    }

    /**
     * 测试单笔转账必填校验：缺 recipient 抛 PayException
     */
    public function testSingleTransferMissingRecipient(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $gateway->singleTransfer(['out_biz_no' => 'T1', 'amount' => 100]);
    }

    /**
     * 测试批量转账：验证明细组装
     */
    public function testBatchTransfer(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code></xml>';

        $gateway = $this->createGateway(['v3/transfer/batches' => $xml]);

        $gateway->batchTransfer([
            'out_biz_no' => 'B1',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['account' => 'o1', 'name' => '张三'], 'remark' => '佣金'],
                ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['account' => 'o2', 'name' => '李四'], 'remark' => '奖励'],
            ],
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v3/transfer/batches', $last['url']);
        $this->assertSame('B1', $last['data']['out_batch_no'] ?? '');
        $this->assertSame(300, $last['data']['total_amount'] ?? 0);
        $this->assertCount(2, $last['data']['transfer_detail_list'] ?? []);
    }

    /**
     * 测试查询转账结果：GET 端点含商户单号
     */
    public function testQueryTransfer(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code></xml>';

        $gateway = $this->createGateway(['v3/transfer/batches/out-batch-no/T1' => $xml]);

        $gateway->queryTransfer('T1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertStringContainsString('v3/transfer/batches/out-batch-no/T1', $last['url']);
    }

    /**
     * 测试查询转账电子回单：GET 端点
     */
    public function testTransferReceipt(): void
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code></xml>';

        $gateway = $this->createGateway(
            ['v3/transfer/batches/out-batch-no/T1/details/out-detail-no/T1/electronic-receipt' => $xml],
        );

        $gateway->transferReceipt('T1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('electronic-receipt', $last['url']);
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起分账：端点正确、Receiver DTO 金额按分上报
     */
    public function testCreateProfitSharingPostsToCorrectEndpoint(): void
    {
        $gateway = $this->createGateway(['profitsharing' => '<xml><return_code><![CDATA[SUCCESS]]></return_code><result_code><![CDATA[SUCCESS]]></result_code></xml>']);

        $gateway->createProfitSharing([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('secapi/pay/profitsharing', $last['url']);

        $receivers = json_decode($last['data']['receivers'], true);
        $this->assertSame(100, $receivers[0]['amount']);
        $this->assertSame('MERCHANT_ID', $receivers[0]['type']);
    }

    /**
     * 分账配置查询：端点正确、参数透传
     */
    public function testQueryProfitSharingConfigPostsToCorrectEndpoint(): void
    {
        $gateway = $this->createGateway(['profitsharing' => '<xml><return_code><![CDATA[SUCCESS]]></return_code><result_code><![CDATA[SUCCESS]]></result_code></xml>']);

        $gateway->queryProfitSharingConfig('SHARE_1', 'T100');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/profitsharingconfigquery', $last['url']);
        $this->assertSame('SHARE_1', $last['data']['out_order_no']);
        $this->assertSame('T100', $last['data']['transaction_id']);
    }

    /**
     * 添加分账接收方：端点正确、参数 JSON 化
     */
    public function testAddProfitSharingReceiverPostsToCorrectEndpoint(): void
    {
        $gateway = $this->createGateway(['profitsharing' => '<xml><return_code><![CDATA[SUCCESS]]></return_code><result_code><![CDATA[SUCCESS]]></result_code></xml>']);

        $gateway->addProfitSharingReceiver(['type' => 'MERCHANT_ID', 'account' => '123', 'name' => '供应商']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/profitsharingaddreceiver', $last['url']);
        $receiver = json_decode($last['data']['receiver'], true);
        $this->assertSame('123', $receiver['account']);
    }

    /**
     * 解冻剩余资金：端点正确、out_order_no 透传
     */
    public function testUnfreezeProfitSharingPostsToCorrectEndpoint(): void
    {
        $gateway = $this->createGateway(['profitsharing' => '<xml><return_code><![CDATA[SUCCESS]]></return_code><result_code><![CDATA[SUCCESS]]></result_code></xml>']);

        $gateway->unfreezeProfitSharing('T100', 'FINISH_9');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('secapi/pay/profitsharingfinish', $last['url']);
        $this->assertSame('FINISH_9', $last['data']['out_order_no']);
    }
}
