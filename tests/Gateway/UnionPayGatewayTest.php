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
 * 银联使用 RSA 签名，需要证书文件；测试在 setUp 中临时生成 RSA 私钥供签名使用，
 * tearDown 中清理，不落盘到仓库。
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
     * 发起分账：端点正确、请求携带 RSA 签名与接收方（金额按分）
     */
    public function testCreateProfitSharingPostsToCorrectEndpointAndSigns(): void
    {
        $ok = json_encode(['respCode' => '00', 'respMsg' => 'success']);
        $gateway = $this->createGateway(['profitSharing.do' => $ok]);

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
        $this->assertStringContainsString('gateway/api/profitSharing.do', $last['url']);
        $this->assertNotEmpty($last['data']['signature']);
        $this->assertSame('m1', $last['data']['merId']);
        $this->assertSame('SHARE_1', $last['data']['orderId']);
        $this->assertSame('T100', $last['data']['origQryId']);

        $receivers = json_decode((string) $last['data']['receivers'], true);
        $this->assertSame(200, $receivers[0]['amount']);
    }

    /**
     * 查询分账：转发到正确端点并携带分账单号
     */
    public function testQueryProfitSharing(): void
    {
        $ok = json_encode(['respCode' => '00']);
        $gateway = $this->createGateway(['queryProfitSharing.do' => $ok]);

        $gateway->queryProfitSharing('SHARE_1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('queryProfitSharing.do', $last['url']);
        $this->assertSame('SHARE_1', $last['data']['orderId']);
    }

    /**
     * 分账回退：转发到正确端点并携带回退金额
     */
    public function testReturnProfitSharing(): void
    {
        $ok = json_encode(['respCode' => '00']);
        $gateway = $this->createGateway(['backProfitSharing.do' => $ok]);

        $gateway->returnProfitSharing(['out_order_no' => 'SHARE_1', 'out_return_no' => 'R1', 'return_amount' => 50]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('backProfitSharing.do', $last['url']);
        $this->assertSame(50, $last['data']['txnAmt']);
    }

    /**
     * 解冻剩余资金：转发到正确端点并携带解冻单号与交易流水
     */
    public function testUnfreezeProfitSharing(): void
    {
        $ok = json_encode(['respCode' => '00']);
        $gateway = $this->createGateway(['finishProfitSharing.do' => $ok]);

        $gateway->unfreezeProfitSharing('T100', 'FINISH_9');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertStringContainsString('finishProfitSharing.do', $last['url']);
        $this->assertSame('FINISH_9', $last['data']['orderId']);
        $this->assertSame('T100', $last['data']['origQryId']);
    }

    /**
     * 分账参数校验：缺 out_order_no 抛异常
     */
    public function testCreateProfitSharingValidation(): void
    {
        $gateway = $this->createGateway(['profitSharing.do' => json_encode(['respCode' => '00'])]);

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
