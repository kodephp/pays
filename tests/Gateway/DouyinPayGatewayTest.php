<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Douyin\DouyinPayGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 抖音支付网关单元测试（含分账特色方法）
 *
 * 分账已对齐抖音 ecpay 真实规范：
 * - 发起分账 → api/apps/ecpay/v1/settle（out_settle_no / out_order_no / settle_desc / settle_params）
 * - 查询分账 → api/apps/ecpay/v1/query_settle（out_settle_no）
 * - 退分账   → 经退款触发（api/apps/ecpay/v1/create_refund）
 * - 解冻     → settle(finish=true)
 */
class DouyinPayGatewayTest extends TestCase
{
    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应
     * @param array<string, mixed> $config 网关配置
     */
    private function createGateway(array $responses = [], array $config = []): DouyinPayGateway
    {
        $config = array_merge([
            'app_id' => 'tt123',
            'merchant_id' => 'm1',
            'salt' => 'testsalt',
        ], $config);

        return new DouyinPayGateway($config, new MockHttpClient($responses));
    }

    /**
     * 获取网关内部的 MockHttpClient（用于断言请求历史）
     */
    private function getMockClient(DouyinPayGateway $gateway): MockHttpClient
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
     * 发起分账：端点正确、请求携带签名与真实字段（out_settle_no / out_order_no / settle_params）
     */
    public function testCreateProfitSharingPostsToSettleEndpointAndSigns(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['settle' => $ok]);

        $result = $gateway->createProfitSharing([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $this->assertSame(0, $result['err_no']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('api/apps/ecpay/v1/settle', $last['url']);
        $this->assertArrayHasKey('sign', $last['data']);
        $this->assertArrayHasKey('timestamp', $last['data']);
        $this->assertSame('tt123', $last['data']['app_id']);
        // 通用参数 → 抖音真实字段映射
        $this->assertSame('SHARE_1', $last['data']['out_settle_no']);
        $this->assertSame('T100', $last['data']['out_order_no']);
        $this->assertSame('分账结算', $last['data']['settle_desc']);

        $receivers = json_decode((string) $last['data']['settle_params'], true);
        $this->assertSame(100, $receivers[0]['amount']);
        $this->assertSame('123', $receivers[0]['merchant_uid']);
    }

    /**
     * 查询分账：转发到 query_settle 端点并携带分账单号（out_settle_no）
     */
    public function testQueryProfitSharing(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['query_settle' => $ok]);

        $gateway->queryProfitSharing('SHARE_1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('query_settle', $last['url']);
        $this->assertSame('SHARE_1', $last['data']['out_settle_no']);
    }

    /**
     * 分账回退：抖音无独立退分账接口，映射为退款请求（create_refund）
     */
    public function testReturnProfitSharingMapsToRefund(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['create_refund' => $ok]);

        $gateway->returnProfitSharing([
            'out_order_no' => 'SHARE_1',
            'out_return_no' => 'R1',
            'return_amount' => 50,
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('create_refund', $last['url']);
        $this->assertSame('R1', $last['data']['out_refund_no']);
        $this->assertSame(50, $last['data']['refund_amount']);
        $this->assertSame('SHARE_1', $last['data']['out_order_no']);
        $this->assertSame('退分账', $last['data']['reason']);
    }

    /**
     * 解冻剩余资金：转发到 settle 端点，finish=true 且 settle_params 为空数组
     */
    public function testUnfreezeProfitSharing(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['settle' => $ok]);

        $gateway->unfreezeProfitSharing('T100', 'FINISH_9');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('api/apps/ecpay/v1/settle', $last['url']);
        $this->assertSame('T100', $last['data']['out_order_no']);
        $this->assertSame('FINISH_9', $last['data']['out_settle_no']);
        $this->assertSame('true', $last['data']['finish']);
        $this->assertSame('[]', $last['data']['settle_params']);
    }

    /**
     * 分账参数校验：缺 out_order_no 抛异常
     */
    public function testCreateProfitSharingValidation(): void
    {
        $gateway = $this->createGateway(['settle' => json_encode(['err_no' => 0])]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_order_no');

        $gateway->createProfitSharing(['transaction_id' => 'T', 'receivers' => []]);
    }

    /**
     * 网关标识
     */
    public function testGetName(): void
    {
        $this->assertSame('douyin', DouyinPayGateway::getName());
    }
}
