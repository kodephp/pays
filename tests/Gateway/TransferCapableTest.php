<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\TransferCapableInterface;
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
 * TransferCapableInterface 集中功能测试（v2.16.0 新增，与 WebhookCapableTest /
 * QrCapableTest / RefundCapableTest 同定位）
 *
 * 从「能力视角」一次性锁定：
 * - 恰好 8 家真实实现 TransferCapableInterface（stripe / revolut / adyen / jd /
 *   meituan / alipay / wechat_v2 / wechat_v3），其余网关不应被误判为实现者；
 * - 用 MockHttpClient 真实驱动 singleTransfer / batchTransfer / queryTransfer，
 *   验证其确实向平台端点发起请求并返回解析后的响应，而非占位 / 空响应；
 * - transferReceipt 仅 5 家（jd / meituan / alipay / wechat_v2 / wechat_v3）提供
 *   真实回单能力，stripe / revolut / adyen 诚实抛 methodNotSupported（「无此方法」），
 *   不伪造回单逻辑。
 */
class TransferCapableTest extends TestCase
{
    // ---- 8 家转账网关工厂 ----

    private function stripe(array $responses = []): StripeGateway
    {
        return new StripeGateway(array_merge([
            'secret_key' => 'sk_test_123',
        ], $responses === [] ? [] : []), new MockHttpClient($responses));
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
     * 临时生成合法 RSA 私钥（对齐 AlipayRefundTest / WechatPayV3CapabilityTest 做法）
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
     * 微信 V2 单笔转账成功响应 XML（对齐 RefundCapableTest::okXml，确保 libxml 解析稳定）
     */
    private function okXml(array $extra = []): string
    {
        $xml = '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<result_code><![CDATA[SUCCESS]]></result_code>'
            . '<mch_id><![CDATA[m1]]></mch_id>';

        foreach ($extra as $k => $v) {
            $xml .= "<{$k}><![CDATA[{$v}]]></{$k}>";
        }

        return $xml . '</xml>';
    }

    /**
     * 统一的收款方结构（满足各网关 singleTransfer 的最小必填校验）
     */
    private function recipient(): array
    {
        return ['type' => 'openid', 'account' => 'ACC123', 'name' => '张三'];
    }

    /**
     * 批量转账明细（2 条，用于校验聚合返回的 count）
     *
     * 仅含 account（openid），不含 name——微信 V3 批量转账仅在金额 ≥ 2000 元时才需
     * 以平台证书加密姓名，本测试金额远小于该阈值，避免触发 platform_certificate 依赖。
     */
    private function details(int $n = 2): array
    {
        $items = [];
        for ($i = 1; $i <= $n; $i++) {
            $items[] = [
                'out_detail_no' => 'D' . $i,
                'amount' => 100,
                'currency' => 'USD',
                'remark' => 'r' . $i,
                'recipient' => ['account' => 'ACC' . $i],
            ];
        }

        return $items;
    }

    // ---- 契约一致性断言 ----

    public function testAllEightTransferGatewaysImplementInterface(): void
    {
        $this->assertInstanceOf(TransferCapableInterface::class, $this->stripe());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->revolut());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->adyen());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->jd());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->meituan());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->alipay());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->wechat());
        $this->assertInstanceOf(TransferCapableInterface::class, $this->wechatV3());
    }

    public function testUnrelatedGatewaysDoNotImplementInterface(): void
    {
        $klarna = new KlarnaGateway(['username' => 'u', 'password' => 'p']);
        $this->assertNotInstanceOf(TransferCapableInterface::class, $klarna);

        $amazon = new AmazonGateway([
            'merchant_id' => 'm', 'access_key' => 'a',
            'secret_key' => 's', 'client_id' => 'c',
        ]);
        $this->assertNotInstanceOf(TransferCapableInterface::class, $amazon);

        $afterpay = new AfterpayGateway(['merchant_id' => 'm', 'secret_key' => 's']);
        $this->assertNotInstanceOf(TransferCapableInterface::class, $afterpay);

        $coinbase = new CoinbaseGateway(['api_key' => 'k', 'webhook_secret' => 'w']);
        $this->assertNotInstanceOf(TransferCapableInterface::class, $coinbase);

        $square = new SquareGateway(['application_id' => 'a', 'access_token' => 't']);
        $this->assertNotInstanceOf(TransferCapableInterface::class, $square);

        $this->assertNotInstanceOf(TransferCapableInterface::class, new QqGateway([
            'app_id' => 'qq_app', 'mch_id' => 'qq_mch', 'api_key' => 'k',
            'serial_no' => 's', 'private_key' => $this->rsaPrivateKey(),
        ]));
        $this->assertNotInstanceOf(TransferCapableInterface::class, $this->unionpay());
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

    // ---- singleTransfer 真实功能验证（MockHttpClient 驱动） ----

    public function testStripeSingleTransferReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/payouts' => json_encode(['id' => 'po_1', 'status' => 'paid'])]);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('po_1', $result['id']);
    }

    public function testRevolutSingleTransferReturnsParsedResponse(): void
    {
        $gateway = $this->revolut();

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('X', $result['id']);
    }

    public function testAdyenSingleTransferReturnsParsedResponse(): void
    {
        $gateway = $this->adyen();

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('X', $result['id']);
    }

    public function testJdSingleTransferReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JT1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('JT1', $result['outBizNo']);
    }

    public function testMeituanSingleTransferReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MT1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('MT1', $result['out_biz_no']);
    }

    public function testAlipaySingleTransferReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_trans_uni_transfer_response' => ['code' => '10000', 'out_biz_no' => 'A_T1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('A_T1', $result['out_biz_no']);
    }

    public function testWechatV2SingleTransferReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'mmpaymkttransfers/promotion/transfers' => $this->okXml(['payment_no' => 'P1']),
        ]);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => $this->recipient(),
        ]);

        $this->assertSame('P1', $result['payment_no']);
    }

    public function testWechatV3SingleTransferReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        // 不含 name：金额 < 2000 元时微信不要求加密姓名，避免触发 platform_certificate 依赖
        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T_001',
            'amount' => 5000,
            'recipient' => ['account' => 'ACC123'],
        ]);

        $this->assertSame('B1', $result['out_batch_no']);
    }

    // ---- batchTransfer 真实功能验证（MockHttpClient 驱动） ----

    public function testStripeBatchTransferReturnsCount(): void
    {
        $gateway = $this->stripe(['v1/payouts' => json_encode(['id' => 'po_1', 'status' => 'paid'])]);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame(2, $result['count']);
    }

    public function testRevolutBatchTransferReturnsCount(): void
    {
        $gateway = $this->revolut();

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame(2, $result['count']);
    }

    public function testAdyenBatchTransferReturnsCount(): void
    {
        $gateway = $this->adyen();

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame(2, $result['count']);
    }

    public function testJdBatchTransferReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JT_B1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame('JT_B1', $result['outBizNo']);
    }

    public function testMeituanBatchTransferReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MT_B1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame('MT_B1', $result['out_biz_no']);
    }

    public function testAlipayBatchTransferReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_trans_batch_create_response' => ['code' => '10000', 'out_biz_no' => 'A_B1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame('A_B1', $result['out_biz_no']);
    }

    public function testWechatV2BatchTransferReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'v3/transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame('B1', $result['out_batch_no']);
    }

    public function testWechatV3BatchTransferReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/bill-receipt' => json_encode(['out_batch_no' => 'B1']),
            'transfer/batches/out-batch-no' => json_encode(['out_batch_no' => 'B1']),
            'transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        $result = $gateway->batchTransfer([
            'out_biz_no' => 'T_B1',
            'transfer_detail_list' => $this->details(2),
        ]);

        $this->assertSame('B1', $result['out_batch_no']);
    }

    // ---- queryTransfer 真实功能验证（MockHttpClient 驱动） ----

    public function testStripeQueryTransferReturnsParsedResponse(): void
    {
        $gateway = $this->stripe(['v1/payouts' => json_encode(['id' => 'po_q1', 'status' => 'paid'])]);

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('po_q1', $result['id']);
    }

    public function testRevolutQueryTransferReturnsParsedResponse(): void
    {
        $gateway = $this->revolut();

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('X', $result['id']);
    }

    public function testAdyenQueryTransferReturnsParsedResponse(): void
    {
        $gateway = $this->adyen();

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('X', $result['id']);
    }

    public function testJdQueryTransferReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JT_Q1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('JT_Q1', $result['outBizNo']);
    }

    public function testMeituanQueryTransferReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MT_Q1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('MT_Q1', $result['out_biz_no']);
    }

    public function testAlipayQueryTransferReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_trans_common_query_response' => ['code' => '10000', 'out_biz_no' => 'A_Q1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('A_Q1', $result['out_biz_no']);
    }

    public function testWechatV2QueryTransferReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'v3/transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('B1', $result['out_batch_no']);
    }

    public function testWechatV3QueryTransferReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/bill-receipt' => json_encode(['out_batch_no' => 'B1']),
            'transfer/batches/out-batch-no' => json_encode(['out_batch_no' => 'B1']),
            'transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        $result = $gateway->queryTransfer('T_001');

        $this->assertSame('B1', $result['out_batch_no']);
    }

    // ---- transferReceipt 真实功能验证（仅 5 家真实，3 家诚实抛「无此方法」） ----

    public function testJdTransferReceiptReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['resultCode' => '000000', 'outBizNo' => 'JT_R1'])];
        $gateway = $this->jd($mock);

        $result = $gateway->transferReceipt('T_001');

        $this->assertSame('JT_R1', $result['outBizNo']);
    }

    public function testMeituanTransferReceiptReturnsParsedResponse(): void
    {
        $mock = ['api/transfer/' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'MT_R1'])];
        $gateway = $this->meituan($mock);

        $result = $gateway->transferReceipt('T_001');

        $this->assertSame('MT_R1', $result['out_biz_no']);
    }

    public function testAlipayTransferReceiptReturnsParsedResponse(): void
    {
        $mock = ['gateway.do' => json_encode([
            'alipay_fund_trans_invoice_query_response' => ['code' => '10000', 'out_biz_no' => 'A_R1'],
        ])];
        $gateway = $this->alipay($mock);

        $result = $gateway->transferReceipt('T_001');

        $this->assertSame('A_R1', $result['out_biz_no']);
        $this->assertNull($result['file_content']);
    }

    public function testWechatV2TransferReceiptReturnsParsedResponse(): void
    {
        $gateway = $this->wechat([
            'v3/transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        $result = $gateway->transferReceipt('T_001');

        $this->assertSame('B1', $result['out_batch_no']);
    }

    public function testWechatV3TransferReceiptReturnsParsedResponse(): void
    {
        $gateway = $this->wechatV3([
            'transfer/bill-receipt' => json_encode(['out_batch_no' => 'B1']),
            'transfer/batches/out-batch-no' => json_encode(['out_batch_no' => 'B1']),
            'transfer/batches' => json_encode(['out_batch_no' => 'B1', 'batch_id' => 'BID']),
        ]);

        $result = $gateway->transferReceipt('T_001');

        $this->assertSame('B1', $result['out_batch_no']);
        $this->assertNull($result['file_content']);
    }

    /**
     * stripe / revolut / adyen 不提供电子回单能力，调用即报「无此方法」（诚实不伪造）
     */
    public function testTransferReceiptNotSupportedForThreeGateways(): void
    {
        $gateways = [
            'stripe' => $this->stripe(),
            'revolut' => $this->revolut(),
            'adyen' => $this->adyen(),
        ];

        foreach ($gateways as $name => $gateway) {
            try {
                $gateway->transferReceipt('T_001');
                $this->fail("{$name} 应当抛 methodNotSupported，但未抛异常");
            } catch (PayException $e) {
                $this->assertStringContainsString('无此方法', $e->getMessage(), "{$name} 的回单异常信息不符");
            }
        }
    }
}
