<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Meituan\MeituanGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Plugin\TransferPlugin;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 美团支付网关单元测试（补齐 Transfer / ProfitSharing / RedPacket / Reconciliation / Settlement 能力）
 *
 * 与既有美团基础支付方法一致，所有特色方法沿用 JSON + MD5（key=app_secret）签名与 api/* 端点风格。
 */
class MeituanGatewayTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): MeituanGateway
    {
        $config = array_merge([
            'app_id' => 'mt_app',
            'app_secret' => 'mt_secret',
            'merchant_id' => 'mt_mch',
        ], $config);

        $responses = $responses === []
            ? ['meituan.com' => json_encode(['status' => 'SUCCESS', 'out_biz_no' => 'X'])]
            : $responses;

        return new MeituanGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(MeituanGateway $gateway): MockHttpClient
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

    /* ==================== 转账 ==================== */

    public function testSingleTransferPostsToSingleEndpointAndSigns(): void
    {
        $gateway = $this->createGateway();

        $gateway->singleTransfer([
            'out_biz_no' => 'TF_1',
            'amount' => 100,
            'recipient' => ['type' => 'openid', 'account' => 'u_1', 'name' => '张三'],
            'description' => '佣金',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/transfer/single', $last['url']);
        $this->assertArrayHasKey('sign', $last['data']);
        $this->assertSame('TF_1', $last['data']['out_biz_no']);
        $this->assertSame(100, $last['data']['amount']);
        $this->assertSame('u_1', $last['data']['recipient_account']);
        $this->assertSame('张三', $last['data']['recipient_name']);
        $this->assertSame('佣金', $last['data']['description']);
    }

    public function testBatchTransferMapsDetailsAndPostsToBatchEndpoint(): void
    {
        $gateway = $this->createGateway();

        $gateway->batchTransfer([
            'out_biz_no' => 'BTF_1',
            'transfer_detail_list' => [
                ['out_detail_no' => 'D1', 'amount' => 100, 'recipient' => ['type' => 'openid', 'account' => 'u_1', 'name' => '张三'], 'remark' => 'a'],
                ['out_detail_no' => 'D2', 'amount' => 200, 'recipient' => ['type' => 'openid', 'account' => 'u_2', 'name' => '李四'], 'remark' => 'b'],
            ],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/transfer/batch', $last['url']);
        $this->assertSame(300, $last['data']['total_amount']);
        $this->assertSame(2, $last['data']['total_num']);

        $details = json_decode((string) $last['data']['transfer_detail_list'], true);
        $this->assertCount(2, $details);
        $this->assertSame('u_1', $details[0]['recipient_account']);
        $this->assertSame(200, $details[1]['amount']);
    }

    public function testQueryTransferAndReceipt(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryTransfer('TF_1');
        $this->assertStringContainsString('api/transfer/query', $this->getMockClient($gateway)->getLastRequest()['url']);

        $gateway->transferReceipt('TF_1');
        $this->assertStringContainsString('api/transfer/receipt', $this->getMockClient($gateway)->getLastRequest()['url']);
    }

    public function testSingleTransferValidation(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：recipient');

        $gateway->singleTransfer(['out_biz_no' => 'TF_1', 'amount' => 100]);
    }

    /* ==================== 分账 ==================== */

    public function testCreateProfitSharingMapsReceiversAndPosts(): void
    {
        $gateway = $this->createGateway();

        $gateway->createProfitSharing([
            'transaction_id' => 'T100',
            'out_order_no' => 'SHARE_1',
            'receivers' => [
                new Receiver('MERCHANT_ID', '123', '供应商', Money::fromMinor(100, 'CNY'), '分账', 'SERVICE_PROVIDER'),
            ],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/profitsharing/create', $last['url']);
        $this->assertArrayHasKey('sign', $last['data']);
        $this->assertSame('T100', $last['data']['transaction_id']);
        $this->assertSame('SHARE_1', $last['data']['out_order_no']);

        $receivers = json_decode((string) $last['data']['receivers'], true);
        $this->assertSame('123', $receivers[0]['account']);
        $this->assertSame(100, $receivers[0]['amount']);
    }

    public function testProfitSharingQueryReturnAndUnfreeze(): void
    {
        $gateway = $this->createGateway();

        $gateway->queryProfitSharing('SHARE_1');
        $this->assertStringContainsString('api/profitsharing/query', $this->getMockClient($gateway)->getLastRequest()['url']);

        $gateway->returnProfitSharing(['out_order_no' => 'SHARE_1', 'out_return_no' => 'R1', 'return_amount' => 50]);
        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/profitsharing/return', $last['url']);
        $this->assertSame(50, $last['data']['return_amount']);

        $gateway->queryProfitSharingReturn('R1');
        $this->assertStringContainsString('api/profitsharing/return/query', $this->getMockClient($gateway)->getLastRequest()['url']);

        $gateway->unfreezeProfitSharing('T100', 'FIN_9');
        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/profitsharing/finish', $last['url']);
        $this->assertSame('T100', $last['data']['transaction_id']);
        $this->assertSame('FIN_9', $last['data']['out_order_no']);
    }

    /* ==================== 红包 ==================== */

    public function testSendRedPacketPostsToSendEndpoint(): void
    {
        $gateway = $this->createGateway();

        $gateway->sendRedPacket([
            'mch_billno' => 'RP_1',
            'send_name' => '商户',
            're_openid' => 'u_1',
            'total_amount' => 100,
            'wishing' => '恭喜',
            'act_name' => '活动',
            'remark' => '备注',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/redpacket/send', $last['url']);
        $this->assertSame('RP_1', $last['data']['mch_billno']);
        $this->assertSame(100, $last['data']['total_amount']);
        $this->assertArrayHasKey('sign', $last['data']);
    }

    public function testGroupRedPacketRequiresTotalNumAtLeastThree(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('裂变红包 total_num 必须 >= 3');

        $gateway->groupRedPacket([
            'mch_billno' => 'RP_2',
            'send_name' => '商户',
            're_openid' => 'u_1',
            'total_amount' => 300,
            'total_num' => 2,
            'wishing' => '恭喜',
            'act_name' => '活动',
            'remark' => '备注',
        ]);
    }

    public function testQueryRedPacket(): void
    {
        $gateway = $this->createGateway();
        $gateway->queryRedPacket('RP_1');
        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/redpacket/query', $last['url']);
        $this->assertSame('RP_1', $last['data']['mch_billno']);
    }

    /* ==================== 对账 ==================== */

    public function testDownloadBillParsesRecords(): void
    {
        $csv = "trade_no,out_trade_no,total_fee,status\nT1,ORDER1,100,SUCCESS\nT2,ORDER2,200,SUCCESS";
        $gateway = $this->createGateway([
            'api/bill/download' => json_encode(['status' => 'SUCCESS', 'bill_content' => $csv]),
        ]);

        $result = $gateway->downloadBill(['bill_date' => '20260809']);
        $this->assertSame('20260809', $result['bill_date']);
        $this->assertCount(2, $result['records']);
        $this->assertSame('ORDER1', $result['records'][0]['out_trade_no']);
        $this->assertSame('200', $result['records'][1]['total_fee']);
    }

    public function testDownloadFundFlow(): void
    {
        $csv = "trade_no,amount\nT1,100";
        $gateway = $this->createGateway([
            'api/bill/fundflow' => json_encode(['status' => 'SUCCESS', 'bill_content' => $csv]),
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260809']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('100', $result['records'][0]['amount']);
    }

    public function testParseBillHandlesMalformedCsv(): void
    {
        $gateway = $this->createGateway();
        $this->assertSame([], $gateway->parseBill(''));
        $this->assertSame([], $gateway->parseBill("only_header\n"));
    }

    /* ==================== 结算 ==================== */

    public function testSettleToWalletDelegatesToSingleTransfer(): void
    {
        $gateway = $this->createGateway();

        $gateway->settleToWallet([
            'out_biz_no' => 'SET_1',
            'amount' => 150,
            'account' => 'u_wallet',
            'real_name' => '王五',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/transfer/single', $last['url']);
        $this->assertSame('SET_1', $last['data']['out_biz_no']);
        $this->assertSame('u_wallet', $last['data']['recipient_account']);
        $this->assertSame('王五', $last['data']['recipient_name']);
    }

    public function testSettleToBankCardPostsToBankcardEndpoint(): void
    {
        $gateway = $this->createGateway();

        $gateway->settleToBankCard([
            'out_biz_no' => 'SET_B1',
            'amount' => 200,
            'bank_card_no' => '6222',
            'real_name' => '赵六',
            'bank_code' => 'ICBC',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/settle/bankcard', $last['url']);
        $this->assertSame('6222', $last['data']['bank_card_no']);
        $this->assertSame('赵六', $last['data']['real_name']);
        $this->assertSame('ICBC', $last['data']['bank_code']);
    }

    public function testSettleToPayoutThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(PayException::class);
        $gateway->settleToPayout(['out_biz_no' => 'X', 'amount' => 1, 'account' => 'a']);
    }

    public function testQuerySettlement(): void
    {
        $gateway = $this->createGateway();
        $gateway->querySettlement('SET_1');
        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/settle/query', $last['url']);
        $this->assertSame('SET_1', $last['data']['out_biz_no']);
    }

    /* ==================== 插件集成（端到端转发） ==================== */

    public function testTransferPluginForwardsToGateway(): void
    {
        $gateway = $this->createGateway();
        $plugin = new TransferPlugin($gateway);

        $plugin->single([
            'out_biz_no' => 'TF_P',
            'amount' => 99,
            'recipient' => ['type' => 'openid', 'account' => 'u_x', 'name' => '甲'],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/transfer/single', $last['url']);
        $this->assertSame('u_x', $last['data']['recipient_account']);
    }

    public function testGetName(): void
    {
        $this->assertSame('meituan', MeituanGateway::getName());
    }
}
