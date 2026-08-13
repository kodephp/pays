<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付 V3 网关单元测试
 *
 * 重点覆盖 APIv3 签名规范（签名串 URL 须为含 /v3/ 的绝对路径、请求体签发一致）、
 * 转账与对账原生方法的请求组装，以及 204 空响应处理。
 */
class WechatPayV3GatewayTest extends TestCase
{
    /**
     * 测试用 RSA 私钥（PEM）
     */
    private static ?string $privateKey = null;

    /**
     * 测试用 RSA 公钥（PEM，充当平台证书）
     */
    private static ?string $publicKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$privateKey !== null) {
            return;
        }

        $keyResource = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            $this->markTestSkipped('当前环境不支持 openssl_pkey_new 生成密钥对');
        }

        $privateKeyPem = '';
        @openssl_pkey_export($keyResource, $privateKeyPem);

        $details = openssl_pkey_get_details($keyResource);
        if ($details === false || !isset($details['key'])) {
            $this->markTestSkipped('无法导出公钥');
        }

        self::$privateKey = $privateKeyPem;
        self::$publicKey = $details['key'];
    }

    /**
     * @param array<string, string> $responses
     * @param array<string, mixed> $config
     */
    private function createGateway(array $responses = [], array $config = []): WechatPayV3Gateway
    {
        $config = array_merge([
            'app_id' => 'wx123',
            'mch_id' => '1900000109',
            'serial_no' => 'SERIAL123',
            'private_key' => self::$privateKey,
            'api_key' => 'testkey',
        ], $config);

        return new WechatPayV3Gateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(WechatPayV3Gateway $gateway): MockHttpClient
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
     * 从 Authorization 头中解出签名串各字段
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function parseAuthorization(array $headers): array
    {
        $auth = $headers['Authorization'] ?? '';
        preg_match_all('/(\w+)="([^"]*)"/', $auth, $matches, PREG_SET_ORDER);

        $parsed = [];
        foreach ($matches as $match) {
            $parsed[$match[1]] = $match[2];
        }

        return $parsed;
    }

    /**
     * 用公钥校验签名串是否按预期内容签发
     *
     * @param array<string, string> $headers
     */
    private function assertSignedOver(array $headers, string $method, string $path, string $body): void
    {
        $fields = $this->parseAuthorization($headers);

        $this->assertArrayHasKey('signature', $fields);
        $this->assertArrayHasKey('timestamp', $fields);
        $this->assertArrayHasKey('nonce_str', $fields);

        $message = $method . "\n" . $path . "\n" . $fields['timestamp'] . "\n" . $fields['nonce_str'] . "\n" . $body . "\n";

        $this->assertTrue(
            openssl_verify($message, base64_decode($fields['signature']), (string) self::$publicKey, OPENSSL_ALGO_SHA256) === 1,
            "签名串与预期不符，期望对 [{$method} {$path}] 签发",
        );
    }

    /**
     * 下单时签名串 URL 必须是含 /v3/ 前缀的绝对路径
     */
    public function testCreateOrderSignsCanonicalAbsolutePath(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/native' => json_encode(['code_url' => 'weixin://wxpay/x']),
        ]);

        $gateway->createOrder([
            'out_trade_no' => 'ORDER1',
            'description' => '测试商品',
            'amount' => 100,
            'notify_url' => 'https://example.com/notify',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();

        $this->assertNotNull($last);
        $this->assertSame('POST_RAW', $last['method']);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/pay/transactions/native', $last['url']);

        $body = $last['data']['body'];
        $this->assertSignedOver($last['headers'], 'POST', '/v3/pay/transactions/native', $body);
    }

    /**
     * 参与签名的请求体与实际发送的字节完全一致
     */
    public function testSignedBodyMatchesSentBytes(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/native' => json_encode(['code_url' => 'weixin://wxpay/x']),
        ]);

        $gateway->createOrder([
            'out_trade_no' => 'ORDER1',
            'description' => '中文商品名',
            'amount' => 100,
            'notify_url' => 'https://example.com/notify',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);

        $body = $last['data']['body'];
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertSame('中文商品名', $decoded['description']);
        $this->assertSame(100, $decoded['amount']['total']);
        $this->assertSignedOver($last['headers'], 'POST', '/v3/pay/transactions/native', $body);
    }

    /**
     * JSAPI / 小程序支付缺少 openid 时抛出参数错误
     */
    public function testJsapiRequiresOpenid(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/jsapi' => json_encode(['prepay_id' => 'wx123']),
        ]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/openid/');

        $gateway->createOrder([
            'out_trade_no' => 'ORDER1',
            'description' => '测试商品',
            'amount' => 100,
            'notify_url' => 'https://example.com/notify',
            'trade_type' => 'jsapi',
        ]);
    }

    /**
     * 服务商模式字段（sp_ / sub_ 前缀）应透传至下单请求体
     */
    public function testServiceProviderFieldsForwarded(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/jsapi' => json_encode(['prepay_id' => 'wx123']),
        ]);

        $gateway->createOrder([
            'out_trade_no' => 'ORDER1',
            'description' => '测试商品',
            'amount' => 100,
            'notify_url' => 'https://example.com/notify',
            'trade_type' => 'jsapi',
            'openid' => 'oABC',
            'sub_mchid' => '1900000000',
            'sub_appid' => 'wxSubAppid',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $decoded = json_decode($last['data']['body'], true);

        $this->assertSame('1900000000', $decoded['sub_mchid']);
        $this->assertSame('wxSubAppid', $decoded['sub_appid']);
        $this->assertSame('oABC', $decoded['payer']['openid']);
    }

    /**
     * GET 请求的查询参数须一并纳入签名串
     */
    public function testQueryOrderIncludesQueryStringInSignature(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/out-trade-no' => json_encode(['trade_state' => 'SUCCESS']),
        ]);

        $gateway->queryOrder('ORDER1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);

        $this->assertSignedOver(
            $last['headers'],
            'GET',
            '/v3/pay/transactions/out-trade-no/ORDER1?mchid=1900000109',
            '',
        );
    }

    /**
     * 关单接口返回 204 空响应时视为成功
     */
    public function testCloseOrderAcceptsEmptyResponse(): void
    {
        $gateway = $this->createGateway(['close' => '']);

        $this->assertSame([], $gateway->closeOrder('ORDER1'));
    }

    /**
     * 单笔转账以「仅含一条明细的批次」表达
     */
    public function testSingleTransferBuildsSingleDetailBatch(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches' => json_encode(['batch_id' => 'B1', 'out_batch_no' => 'T1']),
        ]);

        $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 5000,
            'description' => '佣金结算',
            'recipient' => ['type' => 'openid', 'account' => 'openid_1'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/transfer/batches', $last['url']);

        $body = json_decode($last['data']['body'], true);

        $this->assertSame('T1', $body['out_batch_no']);
        $this->assertSame(5000, $body['total_amount']);
        $this->assertSame(1, $body['total_num']);
        $this->assertCount(1, $body['transfer_detail_list']);
        $this->assertSame('openid_1', $body['transfer_detail_list'][0]['openid']);
        $this->assertSame(5000, $body['transfer_detail_list'][0]['transfer_amount']);
        $this->assertArrayNotHasKey('user_name', $body['transfer_detail_list'][0]);

        $this->assertSignedOver($last['headers'], 'POST', '/v3/transfer/batches', $last['data']['body']);
    }

    /**
     * 批量转账自动汇总总金额与笔数
     */
    public function testBatchTransferAggregatesTotals(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches' => json_encode(['batch_id' => 'B1']),
        ]);

        $gateway->batchTransfer([
            'out_biz_no' => 'BATCH1',
            'batch_name' => '月度结算',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['account' => 'o1']],
                ['out_detail_no' => 'D2', 'amount' => 250, 'recipient' => ['account' => 'o2']],
            ],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);

        $body = json_decode($last['data']['body'], true);

        $this->assertSame(350, $body['total_amount']);
        $this->assertSame(2, $body['total_num']);
        $this->assertSame('月度结算', $body['batch_name']);
    }

    /**
     * 收款人姓名须以平台证书加密后传输
     */
    public function testTransferEncryptsRecipientName(): void
    {
        $gateway = $this->createGateway(
            ['transfer/batches' => json_encode(['batch_id' => 'B1'])],
            ['platform_certificate' => self::$publicKey, 'platform_serial_no' => 'PLAT1'],
        );

        $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 300000,
            'recipient' => ['account' => 'o1', 'name' => '张三'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);

        $body = json_decode($last['data']['body'], true);
        $encrypted = $body['transfer_detail_list'][0]['user_name'];

        $this->assertNotSame('张三', $encrypted);
        $this->assertSame('PLAT1', $last['headers']['Wechatpay-Serial']);

        // 用配对私钥解密，确认密文可还原为原始姓名
        $this->assertTrue(
            openssl_private_decrypt(
                base64_decode($encrypted),
                $plain,
                (string) self::$privateKey,
                OPENSSL_PKCS1_OAEP_PADDING,
            ),
        );
        $this->assertSame('张三', $plain);
    }

    /**
     * 缺少平台证书时加密姓名应报配置错误
     */
    public function testTransferWithoutPlatformCertificateThrows(): void
    {
        $gateway = $this->createGateway(['transfer/batches' => json_encode(['batch_id' => 'B1'])]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/平台证书/');

        $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 300000,
            'recipient' => ['account' => 'o1', 'name' => '张三'],
        ]);
    }

    /**
     * 批量转账明细为空时报参数错误
     */
    public function testBatchTransferRejectsEmptyList(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);

        $gateway->batchTransfer(['out_biz_no' => 'B1', 'transfer_detail_list' => []]);
    }

    /**
     * 查询转账批次命中 out-batch-no 端点
     */
    public function testQueryTransferHitsBatchEndpoint(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches/out-batch-no' => json_encode(['batch_status' => 'FINISHED']),
        ]);

        $gateway->queryTransfer('T1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('v3/transfer/batches/out-batch-no/T1', $last['url']);
        $this->assertSignedOver(
            $last['headers'],
            'GET',
            '/v3/transfer/batches/out-batch-no/T1?need_query_detail=false',
            '',
        );
    }

    /**
     * 申请电子回单命中 bill-receipt 端点
     */
    public function testTransferReceiptHitsBillReceiptEndpoint(): void
    {
        $gateway = $this->createGateway([
            'transfer/bill-receipt' => json_encode(['out_batch_no' => 'T1', 'signature_no' => 'S1']),
        ]);

        $gateway->transferReceipt('T1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/transfer/bill-receipt', $last['url']);
        $this->assertSame('T1', json_decode($last['data']['body'], true)['out_batch_no']);
    }

    /**
     * 交易对账单：先取元数据，再下载并解析 CSV
     */
    public function testDownloadBillFetchesAndParsesCsv(): void
    {
        $gateway = $this->createGateway([
            'bill/tradebill' => json_encode([
                'hash_type' => 'SHA1',
                'hash_value' => 'abc123',
                'download_url' => 'https://api.mch.weixin.qq.com/v3/billdownload/file?token=TK1',
            ]),
            'billdownload/file' => $this->sampleCsv(),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '2026-08-01']);

        $this->assertSame('2026-08-01', $result['bill_date']);
        $this->assertSame('ALL', $result['bill_type']);
        $this->assertSame('abc123', $result['hash_value']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('TXN1', $result['records'][0]['transaction_id']);

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertCount(2, $history);
        $this->assertStringContainsString('bill/tradebill', $history[0]['url']);
        $this->assertStringContainsString('billdownload/file', $history[1]['url']);
    }

    /**
     * 资金账单命中 fundflowbill 端点并纳入 account_type
     */
    public function testDownloadFundFlowUsesAccountType(): void
    {
        $gateway = $this->createGateway([
            'bill/fundflowbill' => json_encode(['download_url' => '', 'hash_value' => 'h1']),
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '2026-08-01', 'account_type' => 'OPERATION']);

        $this->assertSame('OPERATION', $result['account_type']);
        $this->assertSame([], $result['records']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('OPERATION', $last['data']['account_type']);
    }

    /**
     * 压缩格式账单不做解析，交由调用方解压后自行解析
     */
    public function testCompressedBillSkipsParsing(): void
    {
        $gateway = $this->createGateway([
            'bill/tradebill' => json_encode([
                'download_url' => 'https://api.mch.weixin.qq.com/v3/billdownload/file?token=TK1',
            ]),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '2026-08-01', 'tar_type' => 'GZIP']);

        $this->assertSame([], $result['records']);
        $this->assertCount(1, $this->getMockClient($gateway)->getHistory());
    }

    /**
     * 缺少 bill_date 时报参数错误
     */
    public function testDownloadBillRequiresBillDate(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);

        $gateway->downloadBill([]);
    }

    /**
     * parseBill 可独立解析 CSV
     */
    public function testParseBillParsesCsvDirectly(): void
    {
        $records = $this->createGateway()->parseBill($this->sampleCsv());

        $this->assertCount(1, $records);
        $this->assertSame('OUT1', $records[0]['out_trade_no']);
        $this->assertSame('SUCCESS', $records[0]['trade_state']);
    }

    /**
     * 网关声明的能力接口与实现一致
     */
    public function testImplementsDeclaredCapabilities(): void
    {
        $gateway = $this->createGateway();

        $this->assertInstanceOf(TransferCapableInterface::class, $gateway);
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $gateway);
        $this->assertSame('wechat_v3', WechatPayV3Gateway::getName());
    }

    /**
     * 对账单原始 CSV（含表头，解析以「总交易单数」行结束）
     */
    private function sampleCsv(): string
    {
        $header = implode(',', array_fill(0, 26, 'col'));

        $row = implode(',', [
            '2026-08-01 10:00:00', 'wx123', 'm1', 'sub1', 'dev1', 'TXN1', 'OUT1',
            'open1', 'NATIVE', 'SUCCESS', 'bank1', 'CNY', '100', '0', '', '', '0',
            '0', '', '', 'goods', '', '1', '', '0', '0',
        ]);

        return $header . "\n" . $row . "\n总交易单数,1\n";
    }
}
