<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Jd\JdGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;
use Kode\Pays\Plugin\TransferPlugin;
use Kode\Pays\Support\Money;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 京东支付网关单元测试（补齐 Transfer / ProfitSharing / RedPacket / Reconciliation / Settlement 能力）
 *
 * 与既有京东基础支付方法一致，所有特色方法沿用驼峰字段 + MD5（key=md5_key）签名与 api/* 端点风格。
 */
class JdGatewayTest extends TestCase
{
    private function createGateway(array $responses = [], array $config = []): JdGateway
    {
        $config = array_merge([
            'merchant_no' => 'jd_mch',
            'des_key' => 'jd_des',
            'md5_key' => 'jd_md5',
        ], $config);

        $responses = $responses === []
            ? ['jd.com' => json_encode(['resultCode' => '000000', 'outBizNo' => 'X'])]
            : $responses;

        return new JdGateway($config, new MockHttpClient($responses));
    }

    private function getMockClient(JdGateway $gateway): MockHttpClient
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
        $this->assertSame('jd_mch', $last['data']['merchantNo']);
        $this->assertSame('TF_1', $last['data']['outBizNo']);
        $this->assertSame(100, $last['data']['amount']);
        $this->assertSame('u_1', $last['data']['recipientAccount']);
        $this->assertSame('张三', $last['data']['recipientName']);
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
        $this->assertSame(300, $last['data']['totalAmount']);
        $this->assertSame(2, $last['data']['totalNum']);

        $details = json_decode((string) $last['data']['transferDetailList'], true);
        $this->assertCount(2, $details);
        $this->assertSame('u_1', $details[0]['recipientAccount']);
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
        $this->assertSame('T100', $last['data']['transactionId']);
        $this->assertSame('SHARE_1', $last['data']['outOrderNo']);

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
        $this->assertSame(50, $last['data']['returnAmount']);

        $gateway->queryProfitSharingReturn('R1');
        $this->assertStringContainsString('api/profitsharing/return/query', $this->getMockClient($gateway)->getLastRequest()['url']);

        $gateway->unfreezeProfitSharing('T100', 'FIN_9');
        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertStringContainsString('api/profitsharing/finish', $last['url']);
        $this->assertSame('FIN_9', $last['data']['outOrderNo']);
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
        $this->assertSame('RP_1', $last['data']['mchBillNo']);
        $this->assertSame(100, $last['data']['totalAmount']);
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
        $this->assertSame('RP_1', $last['data']['mchBillNo']);
    }

    /* ==================== 对账 ==================== */

    public function testDownloadBillParsesRecords(): void
    {
        $csv = "trade_no,out_trade_no,total_fee,status\nT1,ORDER1,100,SUCCESS\nT2,ORDER2,200,SUCCESS";
        $gateway = $this->createGateway([
            'api/bill/download' => json_encode(['resultCode' => '000000', 'billContent' => $csv]),
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
            'api/bill/fundflow' => json_encode(['resultCode' => '000000', 'billContent' => $csv]),
        ]);

        $result = $gateway->downloadFundFlow(['bill_date' => '20260809']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('100', $result['records'][0]['amount']);
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
        $this->assertSame('SET_1', $last['data']['outBizNo']);
        $this->assertSame('u_wallet', $last['data']['recipientAccount']);
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
        $this->assertSame('6222', $last['data']['bankCardNo']);
        $this->assertSame('赵六', $last['data']['realName']);
        $this->assertSame('ICBC', $last['data']['bankCode']);
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
        $this->assertSame('SET_1', $last['data']['outBizNo']);
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
        $this->assertSame('u_x', $last['data']['recipientAccount']);
    }

    public function testGetName(): void
    {
        $this->assertSame('jd', JdGateway::getName());
    }
}
