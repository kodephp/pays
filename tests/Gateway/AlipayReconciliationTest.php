<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 支付宝网关「对账」原生方法单元测试
 *
 * 验证 downloadBill / downloadFundFlow / parseBill 三个原生方法
 * 复用 buildRequestParams 标准 RSA2 签名。
 */
class AlipayReconciliationTest extends TestCase
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

    private function okJson(string $method, array $extra = []): string
    {
        $innerKey = str_replace('.', '_', $method) . '_response';
        $body = array_merge(['code' => '10000', 'msg' => 'Success'], $extra);

        return json_encode([$innerKey => $body]);
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

    public function testDownloadBill(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay.data.dataservice.bill.downloadurl.query', [
                'bill_download_url' => 'https://example.com/bill.zip',
            ]),
            'bill.zip' => $this->sampleBillCsv(),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20240425', 'bill_type' => 'trade']);

        $this->assertSame('20240425', $result['bill_date']);
        $this->assertSame('trade', $result['bill_type']);
        $this->assertSame('https://example.com/bill.zip', $result['bill_download_url']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('MER1', $result['records'][0]['merchant_order_no']);

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertCount(2, $history);
        $apply = $history[0];
        $this->assertStringContainsString('gateway.do', $apply['url']);
        $this->assertSame('alipay.data.dataservice.bill.downloadurl.query', $apply['data']['method']);

        $biz = json_decode((string) $apply['data']['biz_content'], true);
        $this->assertSame('trade', $biz['bill_type']);
        $this->assertSame('20240425', $biz['bill_date']);
    }

    /**
     * ZIP 压缩包对账单：取首个明细 CSV 解析
     */
    public function testDownloadBillExtractsZip(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay.data.dataservice.bill.downloadurl.query', [
                'bill_download_url' => 'https://example.com/bill.zip',
            ]),
            'bill.zip' => $this->buildZipWithCsv($this->sampleBillCsv()),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20240425']);

        $this->assertCount(1, $result['records']);
        $this->assertSame('MER1', $result['records'][0]['merchant_order_no']);
    }

    /**
     * 响应无下载地址时返回空记录，不发起下载
     */
    public function testDownloadBillWithoutUrlReturnsEmptyRecords(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay.data.dataservice.bill.downloadurl.query', []),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20240425']);

        $this->assertSame('', $result['bill_download_url']);
        $this->assertSame([], $result['records']);
        $this->assertCount(1, $this->getMockClient($gateway)->getHistory());
    }

    /**
     * 构造一份与 parseBill 兼容的支付宝对账单 CSV 样本
     */
    private function sampleBillCsv(): string
    {
        $header = implode(',', array_fill(0, 23, 'col'));
        $row = implode(',', [
            'ALI1', 'MER1', 'PAY', 'subject', '2024-04-25 10:00:00', '2024-04-25 10:05:00',
            'store1', 'store name', 'op', 'term1', 'seller1', '10.00', '10.00', '0', '0',
            '0', '0', '1', '0', 'ref1', '0', 'remark', 'SUCCESS',
        ]);

        return $header . "\n" . $row . "\n合计,1\n";
    }

    /**
     * 在内存中构造含单个 CSV 的 ZIP 包字节
     */
    private function buildZipWithCsv(string $csv): string
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('当前环境缺少 ZipArchive 扩展');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'alipay_zip_');
        if ($tmp === false) {
            $this->markTestSkipped('无法创建临时文件');
        }

        $zip = new \ZipArchive();
        @unlink($tmp);
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('业务明细.csv', $csv);
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }


    public function testDownloadBillMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $gateway->downloadBill(['bill_type' => 'trade']);
    }

    public function testDownloadFundFlow(): void
    {
        $gateway = $this->createGateway([
            'gateway.do' => $this->okJson('alipay.data.bill.ereceipt.apply', [
                'bill_file_url' => 'https://example.com/fund.zip',
            ]),
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20240425']);

        $this->assertSame('20240425', $result['bill_date']);
        $this->assertSame('https://example.com/fund.zip', $result['bill_file_url']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.data.bill.ereceipt.apply', $last['data']['method']);

        $biz = $this->decodeBizContent($this->getMockClient($gateway));
        $this->assertSame('FUND', $biz['type']);
        $this->assertSame('20240425', $biz['key']);
    }

    public function testParseBill(): void
    {
        $gateway = $this->createGateway();

        $header = implode(',', array_fill(0, 23, 'col'));
        $row = implode(',', [
            'ALI1', 'MER1', 'PAY', 'subject', '2024-04-25 10:00:00', '2024-04-25 10:05:00',
            'store1', 'store name', 'op', 'term1', 'seller1', '10.00', '10.00', '0', '0',
            '0', '0', '1', '0', 'ref1', '0', 'remark', 'SUCCESS',
        ]);
        $csv = $header . "\n" . $row . "\n合计,1\n";

        $records = $gateway->parseBill($csv);

        $this->assertCount(1, $records);
        $this->assertSame('MER1', $records[0]['merchant_order_no']);
        $this->assertSame('SUCCESS', $records[0]['status']);
    }

    public function testParseBillEmpty(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame([], $gateway->parseBill(''));
    }

    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('alipay', AlipayGateway::getName());
    }
}
