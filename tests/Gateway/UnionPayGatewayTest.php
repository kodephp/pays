<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\UnionPay\UnionPayGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 云闪付网关单元测试（含分账特色方法）
 *
 * 银联分账已对齐全渠道真实规范：
 * - 发起/回退/解冻分账 → 后台交易 gateway/api/backTransReq.do（内嵌 accSplitData 分账域）
 * - 查询分账/回退     → gateway/api/queryTrans.do
 * 使用 RSA 签名，需证书文件；测试在 setUp 中临时生成 RSA 私钥，tearDown 清理。
 */
class UnionPayGatewayTest extends TestCase
{
    /**
     * 临时证书文件路径
     */
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

        $this->certFile = tempnam(sys_get_temp_dir(), 'up_cert_');
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
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应
     * @param array<string, mixed> $config 网关配置
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

    /**
     * 获取网关内部的 MockHttpClient（用于断言请求历史）
     */
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

    /**
     * 发起分账：端点正确、请求携带 RSA 签名、accSplitData 分账域与真实字段
     */
    public function testCreateProfitSharingPostsToBackTransAndBuildsAccSplitData(): void
    {
        $ok = json_encode(['respCode' => '00', 'respMsg' => 'success']);
        $gateway = $this->createGateway(['backTransReq.do' => $ok]);

        $result = $gateway->createProfitSharing([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(200, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $this->assertSame('00', $result['respCode']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway/api/backTransReq.do', $last['url']);
        $this->assertNotEmpty($last['data']['signature']);
        $this->assertSame('m1', $last['data']['merId']);
        $this->assertSame('SHARE_1', $last['data']['orderId']);
        $this->assertSame('T100', $last['data']['origQryId']);
        // accSplitData 分账域：笔数^接收方(商户号|金额)…
        $this->assertSame('1^123|200', $last['data']['accSplitData']);
    }

    /**
     * 查询分账：转发到 queryTrans 端点并携带分账单号
     */
    public function testQueryProfitSharing(): void
    {
        $ok = json_encode(['respCode' => '00']);
        $gateway = $this->createGateway(['queryTrans.do' => $ok]);

        $gateway->queryProfitSharing('SHARE_1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('queryTrans.do', $last['url']);
        $this->assertSame('SHARE_1', $last['data']['orderId']);
    }

    /**
     * 分账回退：转发到 backTrans 端点并携带回退金额与空分账域
     */
    public function testReturnProfitSharing(): void
    {
        $ok = json_encode(['respCode' => '00']);
        $gateway = $this->createGateway(['backTransReq.do' => $ok]);

        $gateway->returnProfitSharing([
            'out_order_no' => 'SHARE_1',
            'out_return_no' => 'R1',
            'return_amount' => 50,
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('backTransReq.do', $last['url']);
        $this->assertSame('R1', $last['data']['orderId']);
        $this->assertSame('SHARE_1', $last['data']['origQryId']);
        $this->assertSame(50, $last['data']['txnAmt']);
        // 无分账接收方时 accSplitData 为 "0^"
        $this->assertSame('0^', $last['data']['accSplitData']);
    }

    /**
     * 解冻剩余资金：转发到 backTrans 端点并携带解冻单号与交易流水（无 accSplitData）
     */
    public function testUnfreezeProfitSharing(): void
    {
        $ok = json_encode(['respCode' => '00']);
        $gateway = $this->createGateway(['backTransReq.do' => $ok]);

        $gateway->unfreezeProfitSharing('T100', 'FINISH_9');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('backTransReq.do', $last['url']);
        $this->assertSame('FINISH_9', $last['data']['orderId']);
        $this->assertSame('T100', $last['data']['origQryId']);
        $this->assertArrayNotHasKey('accSplitData', $last['data']);
    }

    /**
     * 分账参数校验：缺 out_order_no 抛异常
     */
    public function testCreateProfitSharingValidation(): void
    {
        $gateway = $this->createGateway(['backTransReq.do' => json_encode(['respCode' => '00'])]);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：out_order_no');

        $gateway->createProfitSharing(['transaction_id' => 'T', 'receivers' => []]);
    }

    /**
     * 网关标识
     */
    public function testGetName(): void
    {
        $this->assertSame('unionpay', UnionPayGateway::getName());
    }
}
