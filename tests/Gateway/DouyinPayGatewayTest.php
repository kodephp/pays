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
     * 发起分账：端点正确、请求携带签名与接收方（金额按分）
     */
    public function testCreateProfitSharingPostsToCorrectEndpointAndSigns(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['create_profit_sharing' => $ok]);

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
        $this->assertStringContainsString('api/apps/ecpay/v1/create_profit_sharing', $last['url']);
        $this->assertArrayHasKey('sign', $last['data']);
        $this->assertArrayHasKey('timestamp', $last['data']);
        $this->assertSame('tt123', $last['data']['app_id']);

        $receivers = json_decode((string) $last['data']['receivers'], true);
        $this->assertSame(100, $receivers[0]['amount']);
        $this->assertSame('MERCHANT_ID', $receivers[0]['type']);
    }

    /**
     * 查询分账：转发到正确端点并携带分账单号
     */
    public function testQueryProfitSharing(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['query_profit_sharing' => $ok]);

        $gateway->queryProfitSharing('SHARE_1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('query_profit_sharing', $last['url']);
        $this->assertSame('SHARE_1', $last['data']['out_order_no']);
    }

    /**
     * 分账回退：转发到正确端点并携带回退金额
     */
    public function testReturnProfitSharing(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['return_profit_sharing' => $ok]);

        $gateway->returnProfitSharing(['out_order_no' => 'SHARE_1', 'out_return_no' => 'R1', 'return_amount' => 50]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('return_profit_sharing', $last['url']);
        $this->assertSame(50, $last['data']['return_amount']);
    }

    /**
     * 解冻剩余资金：转发到正确端点并携带解冻单号
     */
    public function testUnfreezeProfitSharing(): void
    {
        $ok = json_encode(['err_no' => 0, 'err_tips' => 'ok']);
        $gateway = $this->createGateway(['finish_profit_sharing' => $ok]);

        $gateway->unfreezeProfitSharing('T100', 'FINISH_9');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('finish_profit_sharing', $last['url']);
        $this->assertSame('FINISH_9', $last['data']['out_order_no']);
    }

    /**
     * 分账参数校验：缺 out_order_no 抛异常
     */
    public function testCreateProfitSharingValidation(): void
    {
        $gateway = $this->createGateway(['create_profit_sharing' => json_encode(['err_no' => 0])]);

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
