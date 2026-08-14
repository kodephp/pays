<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\BalanceCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Support\Encryptor;
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
     * 同一商户绑定多个 appid 时，JSAPI 可按请求指定绑定 appid，使其与 openid 来源一致
     */
    public function testCreateOrderJsapiAcceptsBoundAppIdOverride(): void
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
            'openid' => 'oMiniProgram',
            'app_id' => 'wxMiniProgram',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $decoded = json_decode($last['data']['body'], true);

        $this->assertSame('wxMiniProgram', $decoded['appid']);
        $this->assertNotSame('wx123', $decoded['appid']);
        $this->assertSame('oMiniProgram', $decoded['payer']['openid']);
    }

    /**
     * 配置驱动的服务商模式：退款 / 关单全链路自动透传 sub_ 字段
     */
    public function testServiceProviderFieldsFromConfig(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/out-trade-no/O1/close' => '{}',
            'refund/domestic/refunds' => json_encode(['refund_id' => 'R1']),
        ], [
            'sub_mchid' => '1900000000',
            'sub_appid' => 'wxSubAppid',
        ]);

        // 关单（POST）：sub_mchid 进入请求体
        $gateway->closeOrder('O1');
        $closeLast = $this->getMockClient($gateway)->getLastRequest();
        $closeDecoded = json_decode($closeLast['data']['body'], true);
        $this->assertSame('1900000000', $closeDecoded['sub_mchid']);

        // 退款（POST）：sub_mchid / sub_appid 进入请求体
        $gateway->refund([
            'out_refund_no' => 'R1',
            'out_trade_no' => 'O1',
            'amount' => ['refund' => 100, 'total' => 100],
        ]);
        $refundLast = $this->getMockClient($gateway)->getLastRequest();
        $decoded = json_decode($refundLast['data']['body'], true);
        $this->assertSame('1900000000', $decoded['sub_mchid']);
        $this->assertSame('wxSubAppid', $decoded['sub_appid']);
    }

    /**
     * JSAPI 二次签名（RSA）：返回前端所需字段，且 paySign 可用商户公钥验签通过
     */
    public function testBuildJsApiConfigSignsCorrectly(): void
    {
        $gateway = $this->createGateway();

        $config = $gateway->buildJsApiConfig('wx1234567890');

        $this->assertSame('wx123', $config['appId']);
        $this->assertSame('prepay_id=wx1234567890', $config['package']);
        $this->assertSame('RSA', $config['signType']);
        $this->assertNotEmpty($config['paySign']);

        // 用商户公钥复验签名，确保与微信前端调起要求一致
        $privateKey = openssl_pkey_get_private(self::$privateKey);
        $publicKey = openssl_pkey_get_details($privateKey)['key'];

        $message = $config['appId'] . "\n" . $config['timeStamp'] . "\n"
            . $config['nonceStr'] . "\n" . $config['package'] . "\n";

        $verified = openssl_verify($message, base64_decode($config['paySign']), $publicKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $verified, 'JSAPI paySign 应由商户私钥签发且可用公钥验签');
    }

    /**
     * 服务商模式：JSAPI 二次签名的 appId 应为子商户 sub_appid
     */
    public function testBuildJsApiConfigUsesSubAppIdInServiceProvider(): void
    {
        $gateway = $this->createGateway([], ['sub_appid' => 'wxSubAppid']);

        $config = $gateway->buildJsApiConfig('wx1234567890');

        $this->assertSame('wxSubAppid', $config['appId']);
        $this->assertSame('prepay_id=wx1234567890', $config['package']);

        // 用商户公钥复验签名，确认以 sub_appid 参与签名串
        $privateKey = openssl_pkey_get_private(self::$privateKey);
        $publicKey = openssl_pkey_get_details($privateKey)['key'];

        $message = $config['appId'] . "\n" . $config['timeStamp'] . "\n"
            . $config['nonceStr'] . "\n" . $config['package'] . "\n";

        $this->assertSame(1, openssl_verify($message, base64_decode($config['paySign']), $publicKey, OPENSSL_ALGO_SHA256));
    }

    /**
     * buildJsApiConfig 支持显式传入 appId（与 createOrder 指定的绑定 appid 一致），
     * 用于多 appid 商户的 JSAPI 二次签名
     */
    public function testBuildJsApiConfigUsesExplicitAppId(): void
    {
        $gateway = $this->createGateway();

        $config = $gateway->buildJsApiConfig('wx1234567890', 'wxMiniProgram');

        $this->assertSame('wxMiniProgram', $config['appId']);

        $privateKey = openssl_pkey_get_private(self::$privateKey);
        $publicKey = openssl_pkey_get_details($privateKey)['key'];
        $message = $config['appId'] . "\n" . $config['timeStamp'] . "\n"
            . $config['nonceStr'] . "\n" . $config['package'] . "\n";
        $this->assertSame(1, openssl_verify($message, base64_decode($config['paySign']), $publicKey, OPENSSL_ALGO_SHA256));
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
     * 完整链路：申请回单 → 下载密文 → 解密得到原始回单二进制
     */
    public function testTransferReceiptDecodesFile(): void
    {
        $aesKey = random_bytes(32);
        $nonce = random_bytes(12);
        $original = '%PDF-1.4 test receipt payload 测试回单内容';
        $tag = '';
        $ciphertext = openssl_encrypt($original, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $nonce, $tag);
        $this->assertNotFalse($ciphertext, 'AES-256-GCM 加密失败');
        $fileBlob = $nonce . $ciphertext . $tag;
        $encryptKey = Encryptor::rsaEncrypt($aesKey, self::$publicKey);

        $gateway = $this->createGateway([
            'receipt/file' => $fileBlob,
            'transfer/bill-receipt' => json_encode([
                'out_batch_no' => 'T1',
                'download_url' => 'https://download.example.com/receipt/file?token=ABC',
                'encrypt_key' => $encryptKey,
                'signature' => 'SIG_BASE64',
            ]),
        ]);

        $result = $gateway->transferReceipt('T1');

        $this->assertSame($original, $result['file_content']);
        $this->assertSame(hash('sha256', $original), $result['file_sha256']);
        $this->assertSame('SIG_BASE64', $result['signature']);
        $this->assertSame(2, count($this->getMockClient($gateway)->getHistory()));
    }

    /**
     * 回单未生成（无 download_url）时返回原元数据，file_content 为 null
     */
    public function testTransferReceiptReturnsMetaWhenNotReady(): void
    {
        $gateway = $this->createGateway([
            'transfer/bill-receipt' => json_encode(['out_batch_no' => 'T1', 'status' => 'PROCESSING']),
        ]);

        $result = $gateway->transferReceipt('T1');

        $this->assertNull($result['file_content']);
        $this->assertNull($result['file_sha256']);
        $this->assertSame('PROCESSING', $result['status']);
        $this->assertCount(1, $this->getMockClient($gateway)->getHistory());
    }

    /**
     * encrypt_key 解密后长度非 32 字节时抛错（防御异常密钥）
     */
    public function testTransferReceiptBadKeyLengthThrows(): void
    {
        $encryptKey = Encryptor::rsaEncrypt('short', self::$publicKey);

        $gateway = $this->createGateway([
            'transfer/bill-receipt' => json_encode([
                'out_batch_no' => 'T1',
                'download_url' => 'https://download.example.com/receipt/file?token=ABC',
                'encrypt_key' => $encryptKey,
            ]),
        ]);

        $this->expectException(PayException::class);

        $gateway->transferReceipt('T1');
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
     * 资金账单：下载加密 + GZIP 文件后，自动 AES-256-ECB 解密并解析
     *
     * 按微信 V3 规范构造「CSV → GZIP → AES-256-ECB 加密」的往返向量，断言：
     * 1. 命中 fundflowbill 端点并携带 account_type；
     * 2. 解密后 records 与原始 CSV 一致；
     * 3. 当返回的 hash_value 与明文 SHA1 匹配时通过完整性校验。
     */
    public function testDownloadFundFlowDecryptsGzipThenAes(): void
    {
        $key = str_repeat('k', 32); // 32 字节 APIv3 密钥
        $csv = $this->sampleCsv();
        $expectedHash = sha1($csv);

        // 模拟微信：先 GZIP 压缩，再 AES-256-ECB 加密（原始字节）
        $encrypted = openssl_encrypt(gzencode($csv), 'aes-256-ecb', $key, OPENSSL_RAW_DATA);

        $gateway = $this->createGateway([
            'bill/fundflowbill' => json_encode([
                'hash_type' => 'SHA1',
                'hash_value' => $expectedHash,
                'download_url' => 'https://api.mch.weixin.qq.com/v3/billdownload/flow?token=TK2',
            ]),
            'billdownload/flow' => $encrypted,
        ], ['api_v3_key' => $key]);

        $result = $gateway->downloadFundFlow(['bill_date' => '2026-08-01', 'account_type' => 'OPERATION']);

        $this->assertSame('OPERATION', $result['account_type']);
        $this->assertSame('SHA1', $result['hash_type']);
        $this->assertSame($expectedHash, $result['hash_value']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('TXN1', $result['records'][0]['transaction_id']);
        $this->assertSame('OUT1', $result['records'][0]['out_trade_no']);
    }

    /**
     * 资金账单哈希校验失败（hash_value 不匹配明文 SHA1）时抛 gatewayError
     */
    public function testDownloadFundFlowHashMismatchThrows(): void
    {
        $key = str_repeat('k', 32);
        $csv = $this->sampleCsv();
        $encrypted = openssl_encrypt(gzencode($csv), 'aes-256-ecb', $key, OPENSSL_RAW_DATA);

        $gateway = $this->createGateway([
            'bill/fundflowbill' => json_encode([
                'hash_value' => 'deadbeef',
                'download_url' => 'https://api.mch.weixin.qq.com/v3/billdownload/flow?token=TK3',
            ]),
            'billdownload/flow' => $encrypted,
        ], ['api_v3_key' => $key]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/哈希校验失败/');

        $gateway->downloadFundFlow(['bill_date' => '2026-08-01']);
    }

    /**
     * 缺少 api_v3_key 时资金账单解密抛 configError
     */
    public function testDownloadFundFlowThrowsWithoutApiV3Key(): void
    {
        $gateway = $this->createGateway([
            'bill/fundflowbill' => json_encode([
                'download_url' => 'https://api.mch.weixin.qq.com/v3/billdownload/flow?token=TK4',
            ]),
            'billdownload/flow' => 'arbitrary-bytes',
        ]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/api_v3_key/');

        $gateway->downloadFundFlow(['bill_date' => '2026-08-01']);
    }

    /**
     * 交易账单下载内容若以 GZIP 魔数开头，自动解压后解析（无需显式 tar_type）
     */
    public function testDownloadBillAutoGunzip(): void
    {
        $csv = $this->sampleCsv();

        $gateway = $this->createGateway([
            'bill/tradebill' => json_encode([
                'download_url' => 'https://api.mch.weixin.qq.com/v3/billdownload/file?token=TK5',
            ]),
            'billdownload/file' => gzencode($csv),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '2026-08-01']);

        $this->assertCount(1, $result['records']);
        $this->assertSame('TXN1', $result['records'][0]['transaction_id']);
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
     * 实时余额：GET /v3/merchant/fund/balance，带 account_type 与鉴权头。
     */
    public function testQueryBalanceHitsV3Endpoint(): void
    {
        $gateway = $this->createGateway([
            'merchant/fund/balance' => json_encode([
                'available_amount' => 8800,
                'pending_amount' => 1200,
                'currency' => 'CNY',
            ]),
        ]);

        $result = $gateway->queryBalance(['account_type' => 'OPERATION']);

        $this->assertSame(8800, $result['available_amount']);
        $this->assertSame(1200, $result['pending_amount']);
        $this->assertSame('CNY', $result['currency']);
        $this->assertSame('OPERATION', $result['account_type']);

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertStringContainsString('merchant/fund/balance', $history[0]['url']);
        $this->assertSame('OPERATION', $history[0]['data']['account_type'] ?? null);
        $this->assertArrayHasKey('Authorization', $history[0]['headers']);
    }

    /**
     * 默认账户类型为 BASIC。
     */
    public function testQueryBalanceDefaultsToBasic(): void
    {
        $gateway = $this->createGateway([
            'merchant/fund/balance' => json_encode(['available_amount' => 0, 'pending_amount' => 0]),
        ]);

        $gateway->queryBalance();

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertSame('BASIC', $history[0]['data']['account_type'] ?? null);
    }

    /**
     * 非法 account_type 抛参数错误。
     */
    public function testQueryBalanceRejectsInvalidAccountType(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->queryBalance(['account_type' => 'NOPE']);
    }

    /**
     * 日终余额：GET /v3/merchant/fund/dayendbalance/{date}。
     */
    public function testQueryDayEndBalanceHitsV3Endpoint(): void
    {
        $gateway = $this->createGateway([
            'merchant/fund/dayendbalance/2026-08-01' => json_encode([
                'available_amount' => 5000,
                'pending_amount' => 0,
                'day_end_balance' => 5000,
                'currency' => 'CNY',
            ]),
        ]);

        $result = $gateway->queryDayEndBalance('2026-08-01');

        $this->assertSame('2026-08-01', $result['date']);
        $this->assertSame(5000, $result['day_end_balance']);
        $this->assertSame(5000, $result['available_amount']);

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertStringContainsString('merchant/fund/dayendbalance/2026-08-01', $history[0]['url']);
        $this->assertArrayHasKey('Authorization', $history[0]['headers']);
    }

    /**
     * 服务商模式：余额查询自动注入 sub_mchid。
     */
    public function testQueryBalanceInjectsSubMchidInServiceProviderMode(): void
    {
        $gateway = $this->createGateway([
            'merchant/fund/balance' => json_encode(['available_amount' => 0, 'pending_amount' => 0]),
        ], ['sub_mchid' => 'SUB_MCH_001', 'sub_appid' => 'SUB_APP_001']);

        $gateway->queryBalance();

        $history = $this->getMockClient($gateway)->getHistory();
        $this->assertSame('SUB_MCH_001', $history[0]['data']['sub_mchid'] ?? null);
    }

    /**
     * 日终余额日期格式非法时抛参数错误。
     */
    public function testQueryDayEndBalanceRejectsInvalidDate(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->queryDayEndBalance('20260801');
    }

    /**
     * 网关声明的能力接口与实现一致
     */
    public function testImplementsDeclaredCapabilities(): void
    {
        $gateway = $this->createGateway();

        $this->assertInstanceOf(TransferCapableInterface::class, $gateway);
        $this->assertInstanceOf(ReconciliationCapableInterface::class, $gateway);
        $this->assertInstanceOf(BalanceCapableInterface::class, $gateway);
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

    /**
     * 解密通知 resource：用 Encryptor 造往返向量，断言返回原始明文数组
     *
     * 微信规范将 16 字节 GCM 认证标签拼接在密文末尾后整体 base64，
     * decryptResource 须按此拆分并用 api_v3_key 解密。
     */
    public function testDecryptResourceRoundTrip(): void
    {
        $key = str_repeat('a', 32); // 32 字节 APIv3 密钥
        $aad = 'transaction';
        $plain = ['out_trade_no' => 'ORDER_1', 'transaction_id' => 'T_1', 'amount' => 1];

        $enc = Encryptor::aesGcmEncrypt((string) json_encode($plain), $key, $aad);

        // 微信格式：密文 + 16 字节 tag，整体 base64
        $raw = base64_decode($enc['ciphertext'], true) . base64_decode($enc['tag'], true);
        $resource = [
            'ciphertext' => base64_encode($raw),
            'nonce' => $enc['nonce'],
            'associated_data' => $aad,
        ];

        $gateway = $this->createGateway([], ['api_v3_key' => $key]);
        $decrypted = $gateway->decryptResource($resource);

        $this->assertSame($plain, $decrypted);
    }

    /**
     * 缺少 api_v3_key 时抛 configError
     */
    public function testDecryptResourceThrowsWithoutApiV3Key(): void
    {
        $gateway = $this->createGateway();
        $resource = ['ciphertext' => base64_encode('x'), 'nonce' => base64_encode('n')];

        $this->expectException(PayException::class);
        $gateway->decryptResource($resource);
    }

    /**
     * 非法 / 过短 ciphertext 抛 paramError
     */
    public function testDecryptResourceThrowsOnMalformedCiphertext(): void
    {
        $gateway = $this->createGateway([], ['api_v3_key' => str_repeat('b', 32)]);
        $resource = ['ciphertext' => base64_encode('short'), 'nonce' => base64_encode('n')];

        $this->expectException(PayException::class);
        $gateway->decryptResource($resource);
    }

    /**
     * 缺 ciphertext / nonce 字段时抛 paramError
     */
    public function testDecryptResourceThrowsOnMissingFields(): void
    {
        $gateway = $this->createGateway([], ['api_v3_key' => str_repeat('c', 32)]);

        $this->expectException(PayException::class);
        $gateway->decryptResource(['nonce' => base64_encode('n')]);
    }
}
