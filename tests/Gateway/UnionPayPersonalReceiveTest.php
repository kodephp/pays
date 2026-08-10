<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 云闪付网关「个人收款」原生方法单元测试
 *
 * 覆盖二维码消费收款（backTransReq.do）、按单号查询收款记录与提现结果
 * （queryTrans.do）、代付提现（backTransReq.do）。
 * 全部请求须携带 RSA 签名，测试在 setUp 中临时生成私钥。
 */
class UnionPayPersonalReceiveTest extends TestCase
{
    private string $certFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
            'bits' => 2048,
        ]);
        openssl_pkey_export($key, $privateKey);

        $this->certFile = tempnam(sys_get_temp_dir(), 'up_pr_cert_');
        file_put_contents($this->certFile, (string) $privateKey);
    }

    protected function tearDown(): void
    {
        if ($this->certFile !== '' && file_exists($this->certFile)) {
            unlink($this->certFile);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): UnionPayGateway
    {
        $config = array_merge([
            'mer_id' => 'm1',
            'cert_path' => $this->certFile,
            'cert_pwd' => '123456',
        ], $config);

        return new UnionPayGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(UnionPayGateway $gateway): MockHttpClient
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

    public function testCreateQrCodeHitsBackTransWithSignature(): void
    {
        $gateway = $this->createGateway([
            'backTransReq.do' => json_encode([
                'respCode' => '00',
                'respMsg' => 'success',
                'qrCode' => 'https://qr.95516.com/00010000/abc',
                'queryId' => 'Q123',
            ]),
        ]);

        $result = $gateway->createQrCode([
            'amount' => 5000,
            'description' => '个人收款',
            'out_trade_no' => 'PR2026081001',
            'notify_url' => 'https://example.com/notify',
            'expire_seconds' => 600,
            'attach' => ['scene' => 'personal'],
        ]);

        $this->assertSame('PR2026081001', $result['out_trade_no']);
        $this->assertSame('https://qr.95516.com/00010000/abc', $result['qr_code']);
        $this->assertSame('Q123', $result['query_id']);
        $this->assertSame(5000, $result['amount']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway/api/backTransReq.do', $last['url']);
        $this->assertSame('01', $last['data']['txnType']);
        $this->assertSame('07', $last['data']['txnSubType']);
        $this->assertSame('5000', $last['data']['txnAmt']);
        $this->assertSame('156', $last['data']['currencyCode']);
        $this->assertSame('PR2026081001', $last['data']['orderId']);
        $this->assertSame('https://example.com/notify', $last['data']['backUrl']);
        $this->assertNotEmpty($last['data']['payTimeout']);
        $this->assertStringContainsString('personal', $last['data']['reqReserved']);
        $this->assertNotEmpty($last['data']['signature']);
    }

    public function testCreateQrCodeMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：description');

        $gateway->createQrCode(['amount' => 100]);
    }

    public function testQueryRecordsRequiresOutTradeNo(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_trade_no');

        $gateway->queryRecords(['start_time' => '2026-08-01']);
    }

    public function testQueryRecordsHitsQueryTrans(): void
    {
        $gateway = $this->createGateway([
            'queryTrans.do' => json_encode(['respCode' => '00', 'origRespCode' => '00']),
        ]);

        $gateway->queryRecords(['out_trade_no' => 'PR2026081001', 'txn_time' => '20260810120000']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway/api/queryTrans.do', $last['url']);
        $this->assertSame('00', $last['data']['txnType']);
        $this->assertSame('000000', $last['data']['bizType']);
        $this->assertSame('PR2026081001', $last['data']['orderId']);
        $this->assertSame('20260810120000', $last['data']['txnTime']);
        $this->assertNotEmpty($last['data']['signature']);
    }

    public function testWithdrawBuildsPayoutRequest(): void
    {
        $gateway = $this->createGateway([
            'backTransReq.do' => json_encode(['respCode' => '00', 'respMsg' => 'success']),
        ]);

        $gateway->withdraw([
            'out_biz_no' => 'WD2026081001',
            'amount' => 20000,
            'bank_card_no' => '6222020000000000',
            'real_name' => '张三',
            'cert_type' => '01',
            'cert_no' => '11010119900101001X',
            'phone' => '13800138000',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway/api/backTransReq.do', $last['url']);
        $this->assertSame('12', $last['data']['txnType']);
        $this->assertSame('000401', $last['data']['bizType']);
        $this->assertSame('20000', $last['data']['txnAmt']);
        $this->assertSame('6222020000000000', $last['data']['accNo']);
        $this->assertSame('01', $last['data']['accType']);
        $this->assertNotEmpty($last['data']['signature']);

        $customerInfo = base64_decode($last['data']['customerInfo']);
        $this->assertStringContainsString('customerNm=张三', $customerInfo);
        $this->assertStringContainsString('certifId=11010119900101001X', $customerInfo);
        $this->assertStringContainsString('phoneNo=13800138000', $customerInfo);
    }

    public function testWithdrawPrefersEncryptedAccount(): void
    {
        $gateway = $this->createGateway([
            'backTransReq.do' => json_encode(['respCode' => '00']),
        ]);

        $gateway->withdraw([
            'out_biz_no' => 'WD_2',
            'amount' => 100,
            'bank_card_no' => '6222020000000000',
            'account_encrypted' => 'ENC_ACC_BASE64',
            'real_name' => '李四',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('ENC_ACC_BASE64', $last['data']['accNo']);
    }

    public function testWithdrawMissingRequired(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：real_name');

        $gateway->withdraw([
            'out_biz_no' => 'WD_1',
            'amount' => 100,
            'bank_card_no' => '6222',
        ]);
    }

    public function testQueryWithdrawUsesPayoutBizType(): void
    {
        $gateway = $this->createGateway([
            'queryTrans.do' => json_encode(['respCode' => '00', 'origRespCode' => '00']),
        ]);

        $gateway->queryWithdraw('WD2026081001');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway/api/queryTrans.do', $last['url']);
        $this->assertSame('000401', $last['data']['bizType']);
        $this->assertSame('WD2026081001', $last['data']['orderId']);
        $this->assertNotEmpty($last['data']['signature']);
    }

    public function testImplementsPersonalReceiveContract(): void
    {
        $this->assertTrue(
            is_subclass_of(UnionPayGateway::class, PersonalReceiveCapableInterface::class),
        );
    }
}
