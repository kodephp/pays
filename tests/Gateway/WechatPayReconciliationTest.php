<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付网关「对账」原生方法单元测试
 *
 * 验证 downloadBill / downloadFundFlow / parseBill 三个原生方法
 * 正确组装请求并调用基类 HTTP 通道（不依赖真实网络）。
 */
class WechatPayReconciliationTest extends TestCase
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
     * 对账单原始 CSV（含表头，微信解析以「总交易单数」行结束）
     */
    private function sampleCsv(): string
    {
        $header = implode(',', array_fill(0, 26, 'col'));

        $row = implode(',', [
            '2024-04-25 10:00:00', 'wx123', 'm1', 'sub1', 'dev1', 'TXN1', 'OUT1',
            'open1', 'NATIVE', 'SUCCESS', 'bank1', 'CNY', '100', '0', '', '', '0',
            '0', '', '', 'goods', '', '1', '', '0', '0',
        ]);

        return $header . "\n" . $row . "\n总交易单数,1\n";
    }

    /**
     * 将对账单原始文本包裹为微信可解析的 XML（data 字段承载 CSV）
     */
    private function csvXml(string $csv): string
    {
        return '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<data><![CDATA[' . $csv . ']]></data></xml>';
    }

    public function testDownloadBill(): void
    {
        $csv = $this->sampleCsv();
        $gateway = $this->createGateway(['pay/downloadbill' => $this->csvXml($csv)]);

        $result = $gateway->downloadBill(['bill_date' => '20240425', 'bill_type' => 'ALL']);

        $this->assertSame('20240425', $result['bill_date']);
        $this->assertSame('ALL', $result['bill_type']);
        $this->assertArrayHasKey('records', $result);
        $this->assertCount(1, $result['records']);
        $this->assertSame('OUT1', $result['records'][0]['out_trade_no']);
        $this->assertSame('SUCCESS', $result['records'][0]['trade_state']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/downloadbill', $last['url']);
        $this->assertSame('wx123', $last['data']['appid']);
        $this->assertSame('m1', $last['data']['mch_id']);
        $this->assertSame('20240425', $last['data']['bill_date']);
        $this->assertSame('ALL', $last['data']['bill_type']);
    }

    public function testDownloadBillMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $gateway->downloadBill(['bill_type' => 'ALL']);
    }

    public function testDownloadFundFlow(): void
    {
        $csv = $this->sampleCsv();
        $gateway = $this->createGateway(['pay/downloadfundflow' => $this->csvXml($csv)]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20240425', 'account_type' => 'Basic']);

        $this->assertSame('20240425', $result['bill_date']);
        $this->assertSame('Basic', $result['account_type']);
        $this->assertCount(1, $result['records']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('pay/downloadfundflow', $last['url']);
        $this->assertSame('Basic', $last['data']['account_type']);
    }

    public function testParseBill(): void
    {
        $gateway = $this->createGateway();

        $records = $gateway->parseBill($this->sampleCsv());

        $this->assertCount(1, $records);
        $this->assertSame('OUT1', $records[0]['out_trade_no']);
    }

    public function testParseBillEmpty(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame([], $gateway->parseBill(''));
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('wechat', WechatPayGateway::getName());
    }
}
