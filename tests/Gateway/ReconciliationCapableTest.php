<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Adyen\AdyenGateway;
use Kode\Pays\Gateway\Afterpay\AfterpayGateway;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Gateway\Amazon\AmazonGateway;
use Kode\Pays\Gateway\Coinbase\CoinbaseGateway;
use Kode\Pays\Gateway\Jd\JdGateway;
use Kode\Pays\Gateway\Klarna\KlarnaGateway;
use Kode\Pays\Gateway\Meituan\MeituanGateway;
use Kode\Pays\Gateway\Qq\QqGateway;
use Kode\Pays\Gateway\Revolut\RevolutGateway;
use Kode\Pays\Gateway\Square\SquareGateway;
use Kode\Pays\Gateway\Stripe\StripeGateway;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayGateway;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * ReconciliationCapableInterface 集中功能测试（v2.22.0 新增，与 Webhook/QR/Refund/
 * Transfer/ProfitSharing/Balance/Settlement/Subscription/RedPacket 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 8 家真实实现 ReconciliationCapableInterface（stripe / revolut / adyen /
 *   jd / meituan / alipay / wechat_v2 / wechat_v3），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 downloadBill / downloadFundFlow，验证其确实向平台
 *   对账端点发起请求（含多步下载：Adyen 生成报表→下载 CSV、支付宝申请电子回单→
 *   轮询→下载、微信 V2/V3 下载账单文件）并返回解析后的记录列表；
 * - 无独立资金账单语义的网关（stripe / revolut）诚实抛 methodNotSupported
 *   （「无此方法」），不伪造资金账单逻辑；
 * - parseBill 直接以各网关原生格式（Stripe/Revolut JSON、其余 CSV）驱动，验证
 *   解析器确实产出结构化记录，而非占位 / 空响应。
 */
class ReconciliationCapableTest extends TestCase
{
    // ---- 8 家对账网关工厂（复用 TransferCapableTest 的同构配置） ----

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], []), new MockHttpClient($responses));
    }

    private function revolut(array $responses = []): RevolutGateway
    {
        $responses = $responses === []
            ? ['merchant.revolut.com' => json_encode(['id' => 'X', 'state' => 'completed'])]
            : $responses;

        return new RevolutGateway(array_merge([
            'api_key' => 'rev_api',
            'merchant_id' => 'rev_mid',
            'account_id' => 'rev_src',
        ], []), new MockHttpClient($responses));
    }

    private function adyen(array $responses = []): AdyenGateway
    {
        $responses = $responses === []
            ? ['adyen.com' => json_encode(['id' => 'X', 'status' => 'received'])]
            : $responses;

        return new AdyenGateway(array_merge([
            'api_key' => 'adyen_key',
            'merchant_account' => 'AdyenMerchant',
            'environment' => 'test',
        ], []), new MockHttpClient($responses));
    }

    private function jd(array $responses = []): JdGateway
    {
        return new JdGateway(array_merge([
            'merchant_no' => 'jd_mno',
            'des_key' => 'jd_des',
            'md5_key' => 'jd_md5',
        ], []), new MockHttpClient($responses));
    }

    private function meituan(array $responses = []): MeituanGateway
    {
        return new MeituanGateway(array_merge([
            'app_id' => 'mt_app',
            'app_secret' => 'mt_sec',
            'merchant_id' => 'mt_mid',
        ], []), new MockHttpClient($responses));
    }

    private function alipay(array $responses = []): AlipayGateway
    {
        $key = $this->rsaPrivateKey();

        return new AlipayGateway(array_merge([
            'app_id' => 'alipay_app',
            'private_key' => $key,
            'public_key' => $key,
            'notify_url' => 'https://example.com/notify',
        ], []), new MockHttpClient($responses));
    }

    private function wechat(array $responses = []): WechatPayGateway
    {
        return new WechatPayGateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'api_key' => 'wechat_api_key',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
        ], []), new MockHttpClient($responses));
    }

    private function wechatV3(array $responses = []): WechatPayV3Gateway
    {
        return new WechatPayV3Gateway(array_merge([
            'app_id' => 'wx_app',
            'mch_id' => 'mch_1',
            'serial_no' => 'serial_1',
            'private_key' => $this->rsaPrivateKey(),
            'api_key' => 'wechat_api_key',
        ], []), new MockHttpClient($responses));
    }

    /**
     * 临时生成合法 RSA 私钥（对齐 TransferCapableTest 做法）
     */
    private function rsaPrivateKey(): string
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

    /**
     * 通用 CSV 对账单样本（表头 3 列，数据行同列数，满足 parseCsvBill 严格列数校验）
     */
    private function csvSample(): string
    {
        return "a,b,c\n1,2,3";
    }

    /**
     * 微信风格对账单 CSV（表头任意、数据行 ≥10 列，满足 WechatBillParser 的 MIN_FIELDS）
     * 数据行按微信固定列序：transaction_time/app_id/mch_id/.../transaction_id(索引 5)
     */
    private function wechatCsvSample(): string
    {
        return "col1,col2,col3,col4,col5,col6,col7,col8,col9,col10,col11,col12\n"
            . "`2026-08-16 10:00:00`,wx,mch,,,txn1,out1,open1,trade,,cny";
    }

    // ---- 契约一致性断言 ----

    public function testAllEightReconciliationGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->adyen());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->jd());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->meituan());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $this->wechatV3());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $klarna = new KlarnaGateway(['username' => 'u', 'password' => 'p']);
        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, $klarna);

        $amazon = new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a',
            'secret_key' => 's', 'client_id' => 'c',
        ]);
        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, $amazon);

        $afterpay = new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']);
        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, $afterpay);

        $coinbase = new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']);
        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, $coinbase);

        $square = new SquareGateway(['application_id' => 'a', 'access_token' => 't']);
        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, $square);

        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, new QqGateway([
            'app_id' => 'qq_app', 'mch_id' => 'qq_mch', 'api_key' => 'k',
            'serial_no' => 's', 'private_key' => $this->rsaPrivateKey(),
        ]));
        $this->assertNotInstanceOf(ReconciliationCapableInterface::class, $this->unionpay());
    }

    private function unionpay(): UnionPayGateway
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'up-test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($cert, $certPem);

        $certFile = tempnam(sys_get_temp_dir(), 'up_');
        $verifyFile = tempnam(sys_get_temp_dir(), 'upv_');
        file_put_contents($certFile, $privatePem);
        file_put_contents($verifyFile, $certPem);
        register_shutdown_function(static function () use ($certFile, $verifyFile): void {
            @unlink($certFile);
            @unlink($verifyFile);
        });

        return new UnionPayGateway([
            'mer_id' => 'm1',
            'cert_path' => $certFile,
            'verify_cert_path' => $verifyFile,
            'cert_pwd' => '123456',
        ], new MockHttpClient());
    }

    // ---- downloadBill 真实功能验证（MockHttpClient 驱动） ----

    public function testStripeDownloadBillReturnsBalanceTransactions(): void
    {
        $gateway = $this->stripe(['v1/balance_transactions' => json_encode([
            'data' => [['id' => 'bt_1', 'amount' => 100, 'currency' => 'usd']],
        ])]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('bt_1', $result['data'][0]['id']);
    }

    public function testRevolutDownloadBillReturnsParsedRecords(): void
    {
        $gateway = $this->revolut(['api/1.0/transactions' => json_encode([
            'data' => [['id' => 't_1', 'amount' => 50, 'currency' => 'EUR']],
        ])]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('t_1', $result['records'][0]['id']);
    }

    public function testAdyenDownloadBillReportsThenDownloadsCsv(): void
    {
        $gateway = $this->adyen([
            'pal/servlet/Reports/v68/getReport' => json_encode(['url' => 'https://adyen.test/r.csv']),
            'r.csv' => $this->csvSample(),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('settlement_detail_report', $result['bill_type']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('1', $result['records'][0]['a']);
    }

    public function testJdDownloadBillReturnsParsedRecords(): void
    {
        $gateway = $this->jd(['api/bill/download' => json_encode(['resultCode' => '000000', 'billContent' => $this->csvSample()])]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('1', $result['records'][0]['a']);
    }

    public function testMeituanDownloadBillReturnsParsedRecords(): void
    {
        $gateway = $this->meituan(['api/bill/download' => json_encode(['status' => 'SUCCESS', 'bill_content' => $this->csvSample()])]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('1', $result['records'][0]['a']);
    }

    public function testAlipayDownloadBillReturnsDownloadUrlOrEmpty(): void
    {
        $gateway = $this->alipay(['gateway.do' => json_encode([
            'alipay_data_dataservice_bill_downloadurl_query_response' => [
                'code' => '10000',
                'bill_download_url' => '',
            ],
        ])]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertSame('', $result['bill_download_url']);
        $this->assertSame([], $result['records']);
    }

    public function testWechatV2DownloadBillReturnsParsedRecords(): void
    {
        $gateway = $this->wechat(['pay/downloadbill' => $this->wechatCsvSample()]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('txn1', $result['records'][0]['transaction_id']);
    }

    public function testWechatV3DownloadBillReturnsMetaWithoutDownload(): void
    {
        $gateway = $this->wechatV3(['bill/tradebill' => json_encode([
            'download_url' => '',
            'hash_value' => '',
        ])]);

        $result = $gateway->downloadBill(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertSame('', $result['download_url']);
        $this->assertSame([], $result['records']);
    }

    // ---- downloadFundFlow 真实功能验证（2 家诚实抛「无此方法」，6 家真实） ----

    public function testStripeDownloadFundFlowNotSupported(): void
    {
        $gateway = $this->stripe();

        try {
            $gateway->downloadFundFlow(['bill_date' => '20260816']);
            $this->fail('stripe 应当抛 methodNotSupported，但未抛异常');
        } catch (PayException $e) {
            $this->assertStringContainsString('无此方法', $e->getMessage());
        }
    }

    public function testRevolutDownloadFundFlowNotSupported(): void
    {
        $gateway = $this->revolut();

        try {
            $gateway->downloadFundFlow(['bill_date' => '20260816']);
            $this->fail('revolut 应当抛 methodNotSupported，但未抛异常');
        } catch (PayException $e) {
            $this->assertStringContainsString('无此方法', $e->getMessage());
        }
    }

    public function testAdyenDownloadFundFlowReportsThenDownloadsCsv(): void
    {
        $gateway = $this->adyen([
            'pal/servlet/Reports/v68/getReport' => json_encode(['url' => 'https://adyen.test/ff.csv']),
            'ff.csv' => $this->csvSample(),
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260816']);

        $this->assertSame('payment_accounting_report', $result['bill_type']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('1', $result['records'][0]['a']);
    }

    public function testJdDownloadFundFlowReturnsParsedRecords(): void
    {
        $gateway = $this->jd(['api/bill/fundflow' => json_encode(['resultCode' => '000000', 'billContent' => $this->csvSample()])]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('1', $result['records'][0]['a']);
    }

    public function testMeituanDownloadFundFlowReturnsParsedRecords(): void
    {
        $gateway = $this->meituan(['api/bill/fundflow' => json_encode(['status' => 'SUCCESS', 'bill_content' => $this->csvSample()])]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('1', $result['records'][0]['a']);
    }

    public function testAlipayDownloadFundFlowRunsEreceiptFlow(): void
    {
        $gateway = $this->alipay([
            'gateway.do' => json_encode([
                'alipay_data_bill_ereceipt_apply_response' => [
                    'code' => '10000',
                    'file_id' => 'F1',
                    'status' => 'SUCCESS',
                    'download_url' => 'https://alipay.test/dl/F1.zip',
                ],
            ]),
            'dl/F1.zip' => "file_id,status\nF1,SUCCESS",
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260814', 'type' => 'BALANCE']);

        $this->assertSame('F1', $result['file_id']);
        $this->assertSame('SUCCESS', $result['status']);
        $this->assertSame("file_id,status\nF1,SUCCESS", $result['file_content']);
    }

    public function testWechatV2DownloadFundFlowReturnsParsedRecords(): void
    {
        $gateway = $this->wechat(['pay/downloadfundflow' => $this->wechatCsvSample()]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('txn1', $result['records'][0]['transaction_id']);
    }

    public function testWechatV3DownloadFundFlowReturnsMetaWithoutDownload(): void
    {
        $gateway = $this->wechatV3(['bill/fundflowbill' => json_encode([
            'download_url' => '',
            'hash_value' => '',
        ])]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260816']);

        $this->assertSame('20260816', $result['bill_date']);
        $this->assertSame('', $result['download_url']);
        $this->assertSame([], $result['records']);
    }

    // ---- parseBill 直接驱动（各网关原生格式） ----

    public function testStripeParseBillReturnsRecords(): void
    {
        $gateway = $this->stripe();

        $records = $gateway->parseBill(json_encode(['data' => [['id' => 's_1', 'amount' => 10]]]));

        $this->assertCount(1, $records);
        $this->assertSame('s_1', $records[0]['id']);
    }

    public function testRevolutParseBillReturnsRecords(): void
    {
        $gateway = $this->revolut();

        $records = $gateway->parseBill(json_encode(['data' => [['id' => 'r_1', 'amount' => 20]]]));

        $this->assertCount(1, $records);
        $this->assertSame('r_1', $records[0]['id']);
    }

    public function testAdyenParseBillReturnsRecords(): void
    {
        $gateway = $this->adyen();

        $records = $gateway->parseBill($this->csvSample());

        $this->assertCount(1, $records);
        $this->assertSame('1', $records[0]['a']);
    }

    public function testJdParseBillReturnsRecords(): void
    {
        $gateway = $this->jd();

        $records = $gateway->parseBill($this->csvSample());

        $this->assertCount(1, $records);
        $this->assertSame('1', $records[0]['a']);
    }

    public function testMeituanParseBillReturnsRecords(): void
    {
        $gateway = $this->meituan();

        $records = $gateway->parseBill($this->csvSample());

        $this->assertCount(1, $records);
        $this->assertSame('1', $records[0]['a']);
    }

    public function testAlipayParseBillReturnsRecords(): void
    {
        $gateway = $this->alipay();

        $raw = "h1,h2,h3,h4,h5,h6,h7,h8,h9,h10,h11,h12\n"
            . "v1,v2,v3,v4,v5,v6,v7,v8,v9,v10,v11,v12";
        $records = $gateway->parseBill($raw);

        $this->assertCount(1, $records);
        $this->assertSame('v1', $records[0]['alipay_trade_no']);
    }

    public function testWechatV2ParseBillReturnsRecords(): void
    {
        $gateway = $this->wechat();

        $records = $gateway->parseBill($this->wechatCsvSample());

        $this->assertCount(1, $records);
        $this->assertSame('txn1', $records[0]['transaction_id']);
    }

    public function testWechatV3ParseBillReturnsRecords(): void
    {
        $gateway = $this->wechatV3();

        $records = $gateway->parseBill($this->wechatCsvSample());

        $this->assertCount(1, $records);
        $this->assertSame('txn1', $records[0]['transaction_id']);
    }
}
