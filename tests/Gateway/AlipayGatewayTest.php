<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Alipay\AlipayGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 支付宝网关单元测试（含原生转账能力）
 */
class AlipayGatewayTest extends TestCase
{
    /**
     * 生成合法 RSA 私钥（Signer::rsa2 需要可加载的私钥，否则抛 configError）
     */
    private function makePrivateKey(): string
    {
        $resource = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ]);

        if ($resource === false) {
            $this->markTestSkipped('当前环境不支持 openssl_pkey_new 生成密钥对');
        }

        $pem = '';
        @openssl_pkey_export($resource, $pem);

        return $pem;
    }

    /**
     * 创建网关实例并注入 MockHttpClient
     *
     * @param array<string, string> $responses 预设响应
     * @param array<string, mixed> $config 网关配置
     */
    private function createGateway(array $responses = [], array $config = []): AlipayGateway
    {
        $config = array_merge([
            'app_id' => 'app123',
            'private_key' => $this->makePrivateKey(),
            'public_key' => 'public',
        ], $config);

        $mock = new MockHttpClient($responses);

        return new AlipayGateway($config, $mock);
    }

    /**
     * 获取网关内部的 MockHttpClient
     */
    private function getMockClient(AlipayGateway $gateway): MockHttpClient
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
     * 测试单笔转账：验证方法名、biz_content 与签名
     */
    public function testSingleTransfer(): void
    {
        $resp = json_encode(['alipay_fund_trans_uni_transfer_response' => ['code' => '10000', 'out_biz_no' => 'T1']]);

        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $result = $gateway->singleTransfer([
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['type' => 'ALIPAY_USER_ID', 'account' => 'uid123', 'name' => '张三'],
            'description' => '佣金',
        ]);

        $this->assertSame('10000', $result['code']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertStringContainsString('gateway.do', $last['url']);
        $this->assertSame('alipay.fund.trans.uni.transfer', $last['data']['method'] ?? '');
        $this->assertNotEmpty($last['data']['sign'] ?? '', '应携带 RSA2 签名');

        $biz = json_decode($last['data']['biz_content'] ?? '{}', true);
        $this->assertSame('T1', $biz['out_biz_no'] ?? '');
        $this->assertSame('uid123', $biz['payee_info']['identity'] ?? '');
        $this->assertSame('1.00', $biz['trans_amount'] ?? '');
    }

    /**
     * 测试单笔转账必填校验：缺 recipient 抛 PayException
     */
    public function testSingleTransferMissingRecipient(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $gateway->singleTransfer(['out_biz_no' => 'T1', 'amount' => 100]);
    }

    /**
     * 测试批量转账：验证总金额汇总与明细
     */
    public function testBatchTransfer(): void
    {
        $resp = json_encode(['alipay_fund_trans_batch_create_response' => ['code' => '10000']]);

        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->batchTransfer([
            'out_biz_no' => 'B1',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['account' => 'u1', 'name' => '张三'], 'remark' => '佣金'],
                ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['account' => 'u2', 'name' => '李四'], 'remark' => '奖励'],
            ],
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.batch.create', $last['data']['method'] ?? '');

        $biz = json_decode($last['data']['biz_content'] ?? '{}', true);
        $this->assertSame('3.00', $biz['total_trans_amount'] ?? '');
        $this->assertSame(2, $biz['total_count'] ?? 0);
        $this->assertCount(2, $biz['order_detail'] ?? []);
    }

    /**
     * 测试查询转账结果
     */
    public function testQueryTransfer(): void
    {
        $resp = json_encode(['alipay_fund_trans_common_query_response' => ['code' => '10000']]);

        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->queryTransfer('T1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.common.query', $last['data']['method'] ?? '');
    }

    /**
     * 测试查询转账电子回单
     */
    public function testTransferReceipt(): void
    {
        $resp = json_encode(['alipay_fund_trans_invoice_query_response' => ['code' => '10000']]);

        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->transferReceipt('T1');

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.invoice.query', $last['data']['method'] ?? '');
    }

    /**
     * 测试获取网关标识
     */
    public function testGetName(): void
    {
        $gateway = $this->createGateway();

        $this->assertSame('alipay', AlipayGateway::getName());
    }

    /* ==================== 分账能力（ProfitSharingCapableInterface） ==================== */

    /**
     * 发起分账：method 正确、Receiver DTO 金额转为主单位元
     */
    public function testCreateProfitSharingUsesSettleMethodAndYuanAmount(): void
    {
        $resp = json_encode(['alipay_trade_order_settle_response' => ['code' => '10000', 'msg' => 'Success']]);
        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->createProfitSharing([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);

        $data = $last['data'];
        $this->assertSame('alipay.trade.order.settle', $data['method']);
        $biz = json_decode($data['biz_content'], true);
        $this->assertSame('1.00', $biz['royalty_parameters'][0]['amount']);
    }

    /**
     * 绑定分账关系：端点正确
     */
    public function testAddProfitSharingReceiverUsesBindMethod(): void
    {
        $resp = json_encode(['alipay_trade_royalty_relation_bind_response' => ['code' => '10000', 'msg' => 'Success']]);
        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->addProfitSharingReceiver(['type' => 'userId', 'account' => '123', 'name' => '供应商']);

        $client = $this->getMockClient($gateway);
        $last = $client->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.trade.royalty.relation.bind', $last['data']['method']);
    }

    /**
     * 解冻剩余资金：支付宝无冻结，返回成功占位
     */
    public function testUnfreezeProfitSharingReturnsSuccessPlaceholder(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->unfreezeProfitSharing('T100');

        $this->assertSame('SUCCESS', $result['status']);
        $this->assertSame('T100', $result['trade_no']);
    }

    /* ==================== 自动结算能力 ==================== */

    /**
     * 结算到支付宝余额：复用单笔转账通道，验证方法名与金额换算
     */
    public function testSettleToWalletUsesUniTransfer(): void
    {
        $resp = json_encode(['alipay_fund_trans_uni_transfer_response' => ['code' => '10000']]);
        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->settleToWallet([
            'out_biz_no' => 'SETTLE_1',
            'amount' => 12345,
            'account' => '2088000000000001',
            'real_name' => '张三',
            'description' => '自动结算',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.uni.transfer', $last['data']['method']);

        $bizContent = json_decode($last['data']['biz_content'], true);
        $this->assertSame('SETTLE_1', $bizContent['out_biz_no']);
        $this->assertSame('123.45', $bizContent['trans_amount']);
        $this->assertSame('ALIPAY_USER_ID', $bizContent['payee_info']['identity_type']);
        $this->assertSame('2088000000000001', $bizContent['payee_info']['identity']);
        $this->assertSame('张三', $bizContent['payee_info']['name']);
    }

    /**
     * 结算到支付宝余额：缺 account 抛 PayException
     */
    public function testSettleToWalletMissingAccount(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：account');

        $gateway->settleToWallet(['out_biz_no' => 'SETTLE_1', 'amount' => 100]);
    }

    /**
     * 结算到银行卡：走 TRANS_BANKCARD_NO_PWD 产品码
     */
    public function testSettleToBankCardUsesBankCardProductCode(): void
    {
        $resp = json_encode(['alipay_fund_trans_uni_transfer_response' => ['code' => '10000']]);
        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->settleToBankCard([
            'out_biz_no' => 'SETTLE_2',
            'amount' => 10000,
            'bank_card_no' => '6222021234567890',
            'real_name' => '李四',
            'bank_code' => 'ICBC',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);

        $bizContent = json_decode($last['data']['biz_content'], true);
        $this->assertSame('TRANS_BANKCARD_NO_PWD', $bizContent['product_code']);
        $this->assertSame('BANKCARD_ACCOUNT', $bizContent['payee_info']['identity_type']);
        $this->assertSame('6222021234567890', $bizContent['payee_info']['identity']);
        $this->assertSame('ICBC', $bizContent['payee_info']['bank_code']);
        $this->assertSame('100.00', $bizContent['trans_amount']);
    }

    /**
     * 查询结算结果：复用转账查询
     */
    public function testQuerySettlementUsesCommonQuery(): void
    {
        $resp = json_encode(['alipay_fund_trans_common_query_response' => ['code' => '10000']]);
        $gateway = $this->createGateway(['gateway.do' => $resp]);

        $gateway->querySettlement('SETTLE_3');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('alipay.fund.trans.common.query', $last['data']['method']);

        $bizContent = json_decode($last['data']['biz_content'], true);
        $this->assertSame('SETTLE_3', $bizContent['out_biz_no']);
    }

    /**
     * 支付宝无外部账户 Payout 语义，调用即报「无此方法」
     */
    public function testSettleToPayoutNotSupported(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        $gateway->settleToPayout(['out_biz_no' => 'S', 'amount' => 1, 'account' => 'a']);
    }
}
