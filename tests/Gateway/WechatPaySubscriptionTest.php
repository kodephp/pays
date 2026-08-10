<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「委托代扣（papay）订阅」原生方法单元测试
 *
 * 重点验证：签约链接与解约 / 查约 / 代扣请求均带 MD5 签名，
 * 且「参与签名的字节」与「实际发送的字节」一致。
 */
class WechatPaySubscriptionTest extends TestCase
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

        $client = $prop->getValue($gateway);
        $this->assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    private function okXml(array $extra = []): string
    {
        $fields = array_merge([
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
        ], $extra);

        $xml = '<xml>';
        foreach ($fields as $key => $value) {
            $xml .= "<{$key}>{$value}</{$key}>";
        }

        return $xml . '</xml>';
    }

    /**
     * 解析签名后的 XML 请求体为数组
     */
    private function parseXmlBody(array $last): array
    {
        $body = $last['data']['body'] ?? '';
        $element = simplexml_load_string((string) $body, \SimpleXMLElement::class, LIBXML_NOCDATA);

        return json_decode(json_encode($element), true);
    }

    public function testCreatePlanNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/createPlan/');

        $gateway->createPlan(['name' => '月度会员', 'amount' => 100, 'currency' => 'CNY', 'interval' => 'month']);
    }

    public function testCreateSubscriptionReturnsSignedEntrustUrl(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->createSubscription([
            'customer_id' => 'CONTRACT_001',
            'plan_id' => '100001',
            'notify_url' => 'https://example.com/sign-notify',
        ]);

        $this->assertSame('GET', $result['method']);
        $this->assertStringContainsString('papay/entrustweb', $result['url']);

        $query = [];
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        $this->assertSame('wx123', $query['appid']);
        $this->assertSame('m1', $query['mch_id']);
        $this->assertSame('100001', $query['plan_id']);
        $this->assertSame('CONTRACT_001', $query['contract_code']);
        $this->assertSame('CONTRACT_001', $query['contract_display_account']);
        $this->assertSame('1.0', $query['version']);

        // 签名须覆盖实际发送的查询串
        $sign = $query['sign'];
        unset($query['sign']);
        $this->assertSame($sign, Signer::md5($query, 'testkey'), '签约链接签名应与发送参数一致');
    }

    public function testCreateSubscriptionRequiresNotifyUrl(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：notify_url');

        $gateway->createSubscription(['customer_id' => 'CONTRACT_001', 'plan_id' => '100001']);
    }

    public function testCancelSubscriptionSignsDeleteContract(): void
    {
        $gateway = $this->createGateway(['papay/deletecontract' => $this->okXml()]);

        $gateway->cancelSubscription('2000000000000001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertStringContainsString('papay/deletecontract', $last['url']);

        $body = $this->parseXmlBody($last);
        $this->assertSame('2000000000000001', $body['contract_id']);
        $this->assertSame('1.0', $body['version']);
        $this->assertNotEmpty($body['sign'], '解约请求应带 MD5 签名');
        $this->assertSame('text/xml', $last['headers']['Content-Type'] ?? '');
    }

    public function testCancelSubscriptionSupportsPlanAndContractCode(): void
    {
        $gateway = $this->createGateway(['papay/deletecontract' => $this->okXml()]);

        $gateway->cancelSubscription('plan:100001:CONTRACT_001');

        $body = $this->parseXmlBody($this->getMockClient($gateway)->getLastRequest());
        $this->assertSame('100001', $body['plan_id']);
        $this->assertSame('CONTRACT_001', $body['contract_code']);
        $this->assertArrayNotHasKey('contract_id', $body);
    }

    public function testCancelSubscriptionRejectsMalformedPlanIdentity(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('委托代扣标识格式应为 plan:{plan_id}:{contract_code}');

        $gateway->cancelSubscription('plan:100001');
    }

    public function testGetSubscriptionQueriesContract(): void
    {
        $gateway = $this->createGateway([
            'papay/querycontract' => $this->okXml(['contract_state' => '0']),
        ]);

        $result = $gateway->getSubscription('2000000000000001');

        $this->assertSame('0', $result['contract_state']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('papay/querycontract', $last['url']);

        $body = $this->parseXmlBody($last);
        $this->assertSame('2000000000000001', $body['contract_id']);
        $this->assertNotEmpty($body['sign']);
    }

    public function testPauseAndResumeNotSupported(): void
    {
        $gateway = $this->createGateway();

        try {
            $gateway->pauseSubscription('2000000000000001');
            $this->fail('暂停订阅应抛出「无此方法」');
        } catch (PayException $e) {
            $this->assertStringContainsString('pauseSubscription', $e->getMessage());
        }

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/resumeSubscription/');
        $gateway->resumeSubscription('2000000000000001');
    }

    public function testPayWithContractSignsPapPayApply(): void
    {
        $gateway = $this->createGateway([
            'pay/pappayapply' => $this->okXml(['transaction_id' => '42000000001']),
        ]);

        $result = $gateway->payWithContract([
            'out_trade_no' => 'SUB_202608_001',
            'total_fee' => 1990,
            'body' => '月度会员续费',
            'contract_id' => '2000000000000001',
            'notify_url' => 'https://example.com/pay-notify',
        ]);

        $this->assertSame('42000000001', $result['transaction_id']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/pappayapply', $last['url']);

        $body = $this->parseXmlBody($last);
        $this->assertSame('PAP', $body['trade_type']);
        // XML 往返后数值为字符串
        $this->assertSame('1990', $body['total_fee']);
        $this->assertSame('2000000000000001', $body['contract_id']);
        $this->assertNotEmpty($body['sign'], '代扣请求应带 MD5 签名');
    }

    public function testQueryContractOrderSignsRequest(): void
    {
        $gateway = $this->createGateway([
            'pay/paporderquery' => $this->okXml(['trade_state' => 'SUCCESS']),
        ]);

        $result = $gateway->queryContractOrder('SUB_202608_001');

        $this->assertSame('SUCCESS', $result['trade_state']);

        $body = $this->parseXmlBody($this->getMockClient($gateway)->getLastRequest());
        $this->assertSame('SUB_202608_001', $body['out_trade_no']);
        $this->assertNotEmpty($body['sign']);
    }
}
