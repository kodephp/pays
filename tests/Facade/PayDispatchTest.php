<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Facade;

use Kode\Pays\Contract\CryptoCapableInterface;
use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\ReconciliationCapableInterface;
use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\GatewayFactory;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Core\PayException;
use Kode\Pays\Facade\Pay;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 统一入口（Pay 门面 call / gateway / extend / verify）单元测试
 */

/**
 * 支持分账能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class ProfitSharingCapableFakeGateway extends FakeGateway implements ProfitSharingCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $psCalls = [];

    public static function getName(): string
    {
        return 'profitgw';
    }

    public function createProfitSharing(array $params): array
    {
        $this->psCalls[] = ['create', $params];

        return ['ok' => true];
    }

    public function queryProfitSharing(string $outOrderNo): array
    {
        $this->psCalls[] = ['query', $outOrderNo];

        return ['ok' => true];
    }

    public function returnProfitSharing(array $params): array
    {
        $this->psCalls[] = ['return', $params];

        return ['ok' => true];
    }

    public function queryProfitSharingReturn(string $outReturnNo): array
    {
        $this->psCalls[] = ['queryReturn', $outReturnNo];

        return ['ok' => true];
    }

    public function unfreezeProfitSharing(string $transactionId, ?string $outOrderNo = null): array
    {
        $this->psCalls[] = ['unfreeze', $transactionId, $outOrderNo];

        return ['ok' => true];
    }
}

/**
 * 支持转账能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class TransferCapableFakeGateway extends FakeGateway implements TransferCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $transferCalls = [];

    public static function getName(): string
    {
        return 'transgw';
    }

    public function singleTransfer(array $params): array
    {
        $this->transferCalls[] = ['single', $params];

        return ['ok' => true, 'out_biz_no' => $params['out_biz_no'] ?? ''];
    }

    public function batchTransfer(array $params): array
    {
        $this->transferCalls[] = ['batch', $params];

        return ['ok' => true];
    }

    public function queryTransfer(string $outBizNo): array
    {
        $this->transferCalls[] = ['query', $outBizNo];

        return ['ok' => true];
    }

    public function transferReceipt(string $outBizNo): array
    {
        $this->transferCalls[] = ['receipt', $outBizNo];

        return ['ok' => true];
    }
}

/**
 * 支持红包能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class RedPacketCapableFakeGateway extends FakeGateway implements RedPacketCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $redPacketCalls = [];

    public static function getName(): string
    {
        return 'redgw';
    }

    public function sendRedPacket(array $params): array
    {
        $this->redPacketCalls[] = ['send', $params];

        return ['ok' => true, 'mch_billno' => $params['mch_billno'] ?? ''];
    }

    public function groupRedPacket(array $params): array
    {
        $this->redPacketCalls[] = ['group', $params];

        return ['ok' => true];
    }

    public function queryRedPacket(string $mchBillNo): array
    {
        $this->redPacketCalls[] = ['query', $mchBillNo];

        return ['ok' => true];
    }
}

/**
 * 支持订阅能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class SubscriptionCapableFakeGateway extends FakeGateway implements SubscriptionCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $subCalls = [];

    public static function getName(): string
    {
        return 'subgw';
    }

    public function createPlan(array $params): array
    {
        $this->subCalls[] = ['createPlan', $params];

        return ['ok' => true, 'id' => 'plan_1'];
    }

    public function createSubscription(array $params): array
    {
        $this->subCalls[] = ['createSubscription', $params];

        return ['ok' => true, 'id' => 'sub_1'];
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        $this->subCalls[] = ['cancelSubscription', $subscriptionId];

        return ['ok' => true];
    }

    public function pauseSubscription(string $subscriptionId): array
    {
        $this->subCalls[] = ['pauseSubscription', $subscriptionId];

        return ['ok' => true];
    }

    public function resumeSubscription(string $subscriptionId): array
    {
        $this->subCalls[] = ['resumeSubscription', $subscriptionId];

        return ['ok' => true];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $this->subCalls[] = ['getSubscription', $subscriptionId];

        return ['ok' => true];
    }
}

/**
 * 支持个人收款能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class PersonalReceiveCapableFakeGateway extends FakeGateway implements PersonalReceiveCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $receiveCalls = [];

    public static function getName(): string
    {
        return 'recvgw';
    }

    public function createQrCode(array $params): array
    {
        $this->receiveCalls[] = ['createQrCode', $params];

        return ['ok' => true, 'out_trade_no' => 'PERSONAL_1'];
    }

    public function queryRecords(array $params): array
    {
        $this->receiveCalls[] = ['queryRecords', $params];

        return ['ok' => true];
    }

    public function withdraw(array $params): array
    {
        $this->receiveCalls[] = ['withdraw', $params];

        return ['ok' => true];
    }

    public function queryWithdraw(string $outBizNo): array
    {
        $this->receiveCalls[] = ['queryWithdraw', $outBizNo];

        return ['ok' => true];
    }
}

/**
 * 支持对账能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class ReconciliationCapableFakeGateway extends FakeGateway implements ReconciliationCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $reconCalls = [];

    public static function getName(): string
    {
        return 'recongw';
    }

    public function downloadBill(array $params): array
    {
        $this->reconCalls[] = ['downloadBill', $params];

        return ['ok' => true, 'bill_date' => $params['bill_date'] ?? ''];
    }

    public function downloadFundFlow(array $params): array
    {
        $this->reconCalls[] = ['downloadFundFlow', $params];

        return ['ok' => true];
    }

    public function parseBill(string $rawData): array
    {
        $this->reconCalls[] = ['parseBill', $rawData];

        return [['row' => $rawData]];
    }
}

/**
 * 支持退款能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class RefundCapableFakeGateway extends FakeGateway implements RefundCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $refundCalls = [];

    public static function getName(): string
    {
        return 'refundgw';
    }

    public function applyRefund(array $params): array
    {
        $this->refundCalls[] = ['applyRefund', $params];

        return ['ok' => true, 'out_refund_no' => $params['out_refund_no'] ?? ''];
    }

    public function queryRefund(string $outRefundNo): array
    {
        $this->refundCalls[] = ['queryRefund', $outRefundNo];

        return ['ok' => true];
    }

    public function cancelRefund(string $outRefundNo): array
    {
        $this->refundCalls[] = ['cancelRefund', $outRefundNo];

        return ['ok' => true];
    }
}

/**
 * 支持加密货币能力的假网关：用于验证统一入口对「特色方法」的动态派发
 */
class CryptoCapableFakeGateway extends FakeGateway implements CryptoCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $cryptoCalls = [];

    public static function getName(): string
    {
        return 'cryptogw';
    }

    public function createOrder(array $params): array
    {
        $this->cryptoCalls[] = ['createOrder', $params];

        return ['ok' => true, 'charge_id' => 'chg_1'];
    }

    public function createCryptoOrder(array $params): array
    {
        $this->cryptoCalls[] = ['createCryptoOrder', $params];

        return ['ok' => true];
    }

    public function getPaymentAddresses(string $orderId): array
    {
        $this->cryptoCalls[] = ['getPaymentAddresses', $orderId];

        return ['ok' => true];
    }

    public function getConfirmations(string $orderId): array
    {
        $this->cryptoCalls[] = ['getConfirmations', $orderId];

        return ['ok' => true];
    }

    public function getExchangeRate(string $cryptoCurrency, string $fiatCurrency = 'USD'): array
    {
        $this->cryptoCalls[] = ['getExchangeRate', $cryptoCurrency, $fiatCurrency];

        return ['rate' => '1'];
    }

    public function queryOrder(string $orderId): array
    {
        $this->cryptoCalls[] = ['queryOrder', $orderId];

        return ['ok' => true];
    }

    public function refund(array $params): array
    {
        $this->cryptoCalls[] = ['refund', $params];

        return ['ok' => true];
    }

    public function verifyNotify(array $data): bool
    {
        $this->cryptoCalls[] = ['verifyNotify', $data];

        return true;
    }
}

class PayDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GatewayFactory::register('fakechan', FakeGateway::class);
        Pay::registerConfig('fakechan', []);

        GatewayFactory::register('profitgw', ProfitSharingCapableFakeGateway::class);
        Pay::registerConfig('profitgw', []);

        GatewayFactory::register('transgw', TransferCapableFakeGateway::class);
        Pay::registerConfig('transgw', []);

        GatewayFactory::register('redgw', RedPacketCapableFakeGateway::class);
        Pay::registerConfig('redgw', []);

        GatewayFactory::register('subgw', SubscriptionCapableFakeGateway::class);
        Pay::registerConfig('subgw', []);

        GatewayFactory::register('recvgw', PersonalReceiveCapableFakeGateway::class);
        Pay::registerConfig('recvgw', []);

        GatewayFactory::register('recongw', ReconciliationCapableFakeGateway::class);
        Pay::registerConfig('recongw', []);

        GatewayFactory::register('refundgw', RefundCapableFakeGateway::class);
        Pay::registerConfig('refundgw', []);

        GatewayFactory::register('cryptogw', CryptoCapableFakeGateway::class);
        Pay::registerConfig('cryptogw', []);
    }

    protected function tearDown(): void
    {
        Pay::clearCache();
        GatewayFactory::unregister('fakechan');
        GatewayFactory::unregister('profitgw');
        GatewayFactory::unregister('transgw');
        GatewayFactory::unregister('redgw');
        GatewayFactory::unregister('subgw');
        GatewayFactory::unregister('recvgw');
        GatewayFactory::unregister('recongw');
        GatewayFactory::unregister('refundgw');
        GatewayFactory::unregister('cryptogw');
        GatewayFactory::unregister('samplegw');
        GatewayManifest::unregister('samplegw');

        parent::tearDown();
    }

    /**
     * 统一入口 call 可调用任意已接入平台的标准方法
     */
    public function testCallDispatchesStandardMethod(): void
    {
        $result = Pay::call('fakechan', 'createOrder', ['out_trade_no' => 'T1']);

        $this->assertArrayHasKey('code_url', $result);
        $this->assertStringContainsString('T1', $result['code_url']);
    }

    /**
     * 语义化快捷方法 createOrder 等效于 call
     */
    public function testCreateOrderHelper(): void
    {
        $result = Pay::createOrder('fakechan', ['out_trade_no' => 'T2']);

        $this->assertStringContainsString('T2', $result['code_url']);
    }

    /**
     * 统一入口可调用各平台「特色方法」（接口之外的方法）
     */
    public function testCallReachesPlatformSpecificMethod(): void
    {
        $name = Pay::call('fakechan', 'getName');

        $this->assertSame('fakechan', $name);
    }

    /**
     * gateway() 返回强类型实例，可继续调用特色方法
     */
    public function testGatewayReturnsInstance(): void
    {
        $gateway = Pay::gateway('fakechan');

        $this->assertInstanceOf(FakeGateway::class, $gateway);
        $this->assertSame('fakechan', $gateway->getName());
    }

    /**
     * 调用不存在的方法应抛出「无此方法」异常
     */
    public function testCallUnknownMethodThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        Pay::call('fakechan', 'noSuchMethod');
    }

    /**
     * 统一分账入口 profitSharingCreate 经 call 派发到网关原生方法
     */
    public function testProfitSharingUnifiedEntry(): void
    {
        $result = Pay::profitSharingCreate('profitgw', [
            'out_order_no' => 'S1',
            'transaction_id' => 'T1',
            'receivers' => [],
        ]);

        $this->assertSame(['ok' => true], $result);

        $gateway = Pay::gateway('profitgw');
        $this->assertSame('create', $gateway->psCalls[0][0]);
        $this->assertSame('S1', $gateway->psCalls[0][1]['out_order_no']);
    }

    /**
     * 统一分账查询入口派发到网关原生 queryProfitSharing
     */
    public function testProfitSharingQueryUnifiedEntry(): void
    {
        Pay::profitSharingQuery('profitgw', 'S1');

        $gateway = Pay::gateway('profitgw');
        $this->assertSame('query', $gateway->psCalls[0][0]);
        $this->assertSame('S1', $gateway->psCalls[0][1]);
    }

    /**
     * 统一分账回退查询入口 profitSharingQueryReturn 经 call 派发到网关原生方法
     */
    public function testProfitSharingQueryReturnUnifiedEntry(): void
    {
        Pay::profitSharingQueryReturn('profitgw', 'R1');

        $gateway = Pay::gateway('profitgw');
        $this->assertSame('queryReturn', $gateway->psCalls[0][0]);
        $this->assertSame('R1', $gateway->psCalls[0][1]);
    }

    /**
     * 安全入口 verify：先过 NotifyGuard，再走平台级验签
     */
    public function testVerifyPassesWithSign(): void
    {
        $this->assertTrue(Pay::verify('fakechan', ['sign' => 'x']));
    }

    /**
     * 安全入口 verify：缺少签名字段即拦截
     */
    public function testVerifyBlocksMissingSign(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少签名字段');

        Pay::verify('fakechan', []);
    }

    /**
     * 一次登记新平台后，统一入口与清单查询均可用
     */
    public function testExtendRegistersPlatform(): void
    {
        Pay::extend(
            'samplegw',
            [
                'label' => 'Sample Gateway',
                'region' => GatewayManifest::REGION_DOMESTIC,
                'signature' => GatewayManifest::SIGN_MD5,
                'capabilities' => [GatewayManifest::CAP_PROFIT_SHARING => true],
            ],
            FakeGateway::class,
        );

        $this->assertTrue(Pay::has('samplegw'));
        $this->assertTrue(GatewayManifest::supports('samplegw', GatewayManifest::CAP_PROFIT_SHARING));
        $this->assertSame('Sample Gateway', GatewayManifest::get('samplegw')['label']);

        // 统一入口可立即调用（需先登记配置）
        Pay::registerConfig('samplegw', []);
        $result = Pay::call('samplegw', 'createOrder', ['out_trade_no' => 'S1']);
        $this->assertStringContainsString('S1', $result['code_url']);
    }

    /**
     * 统一转账入口 transferSingle 经 call 派发到网关原生方法
     */
    public function testTransferSingleUnifiedEntry(): void
    {
        $result = Pay::transferSingle('transgw', [
            'out_biz_no' => 'T1',
            'amount' => 100,
            'recipient' => ['account' => 'a'],
        ]);

        $this->assertSame(['ok' => true, 'out_biz_no' => 'T1'], $result);

        $gateway = Pay::gateway('transgw');
        $this->assertSame('single', $gateway->transferCalls[0][0]);
        $this->assertSame('T1', $gateway->transferCalls[0][1]['out_biz_no']);
    }

    /**
     * 统一转账查询入口 transferQuery 派发到网关原生 queryTransfer
     */
    public function testTransferQueryUnifiedEntry(): void
    {
        Pay::transferQuery('transgw', 'T1');

        $gateway = Pay::gateway('transgw');
        $this->assertSame('query', $gateway->transferCalls[0][0]);
        $this->assertSame('T1', $gateway->transferCalls[0][1]);
    }

    /**
     * 统一红包发放入口 redPacketSend 派发到网关原生 sendRedPacket
     */
    public function testRedPacketSendUnifiedEntry(): void
    {
        $result = Pay::redPacketSend('redgw', [
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => 'oXxx',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);

        $this->assertSame(['ok' => true, 'mch_billno' => 'REDPACK_1'], $result);

        $gateway = Pay::gateway('redgw');
        $this->assertSame('send', $gateway->redPacketCalls[0][0]);
        $this->assertSame('REDPACK_1', $gateway->redPacketCalls[0][1]['mch_billno']);
    }

    /**
     * 统一红包查询入口 redPacketQuery 派发到网关原生 queryRedPacket
     */
    public function testRedPacketQueryUnifiedEntry(): void
    {
        Pay::redPacketQuery('redgw', 'REDPACK_1');

        $gateway = Pay::gateway('redgw');
        $this->assertSame('query', $gateway->redPacketCalls[0][0]);
        $this->assertSame('REDPACK_1', $gateway->redPacketCalls[0][1]);
    }

    /**
     * 统一订阅计划入口 subscriptionCreatePlan 派发到网关原生 createPlan
     */
    public function testSubscriptionCreatePlanUnifiedEntry(): void
    {
        $result = Pay::subscriptionCreatePlan('subgw', [
            'name' => '月度会员',
            'amount' => 9900,
            'currency' => 'usd',
            'interval' => 'month',
        ]);

        $this->assertSame(['ok' => true, 'id' => 'plan_1'], $result);

        $gateway = Pay::gateway('subgw');
        $this->assertSame('createPlan', $gateway->subCalls[0][0]);
        $this->assertSame('月度会员', $gateway->subCalls[0][1]['name']);
    }

    /**
     * 统一订阅创建入口 subscriptionCreate 派发到网关原生 createSubscription
     */
    public function testSubscriptionCreateUnifiedEntry(): void
    {
        Pay::subscriptionCreate('subgw', ['customer_id' => 'cus_1', 'plan_id' => 'plan_1']);

        $gateway = Pay::gateway('subgw');
        $this->assertSame('createSubscription', $gateway->subCalls[0][0]);
        $this->assertSame('plan_1', $gateway->subCalls[0][1]['plan_id']);
    }

    /**
     * 统一订阅取消入口 subscriptionCancel 派发到网关原生 cancelSubscription
     */
    public function testSubscriptionCancelUnifiedEntry(): void
    {
        Pay::subscriptionCancel('subgw', 'sub_1');

        $gateway = Pay::gateway('subgw');
        $this->assertSame('cancelSubscription', $gateway->subCalls[0][0]);
        $this->assertSame('sub_1', $gateway->subCalls[0][1]);
    }

    /**
     * 统一订阅暂停入口 subscriptionPause 派发到网关原生 pauseSubscription
     */
    public function testSubscriptionPauseUnifiedEntry(): void
    {
        Pay::subscriptionPause('subgw', 'sub_1');

        $gateway = Pay::gateway('subgw');
        $this->assertSame('pauseSubscription', $gateway->subCalls[0][0]);
        $this->assertSame('sub_1', $gateway->subCalls[0][1]);
    }

    /**
     * 统一订阅恢复入口 subscriptionResume 派发到网关原生 resumeSubscription
     */
    public function testSubscriptionResumeUnifiedEntry(): void
    {
        Pay::subscriptionResume('subgw', 'sub_1');

        $gateway = Pay::gateway('subgw');
        $this->assertSame('resumeSubscription', $gateway->subCalls[0][0]);
        $this->assertSame('sub_1', $gateway->subCalls[0][1]);
    }

    /**
     * 统一订阅查询入口 subscriptionGet 派发到网关原生 getSubscription
     */
    public function testSubscriptionGetUnifiedEntry(): void
    {
        Pay::subscriptionGet('subgw', 'sub_1');

        $gateway = Pay::gateway('subgw');
        $this->assertSame('getSubscription', $gateway->subCalls[0][0]);
        $this->assertSame('sub_1', $gateway->subCalls[0][1]);
    }

/**
 * 统一入口调用未实现的方法应抛「无此方法」
 */
public function testSubscriptionMethodNotSupported(): void
{
    $this->expectException(PayException::class);
    $this->expectExceptionMessage('无此方法');

    // fakechan 未实现 SubscriptionCapableInterface，无 createPlan 方法
    Pay::subscriptionCreatePlan('fakechan', ['name' => 'x', 'amount' => 1, 'currency' => 'usd', 'interval' => 'month']);
}

    /**
     * 统一个人收款二维码入口 personalReceiveQrCode 派发到网关原生 createQrCode
     */
    public function testPersonalReceiveQrCodeUnifiedEntry(): void
    {
        $result = Pay::personalReceiveQrCode('recvgw', [
            'amount' => 100,
            'description' => '商品付款',
        ]);

        $this->assertSame(['ok' => true, 'out_trade_no' => 'PERSONAL_1'], $result);

        $gateway = Pay::gateway('recvgw');
        $this->assertSame('createQrCode', $gateway->receiveCalls[0][0]);
        $this->assertSame('商品付款', $gateway->receiveCalls[0][1]['description']);
    }

    /**
     * 统一个人收款记录查询入口 personalReceiveQueryRecords 派发到网关原生 queryRecords
     */
    public function testPersonalReceiveQueryRecordsUnifiedEntry(): void
    {
        Pay::personalReceiveQueryRecords('recvgw', [
            'start_time' => '2024-04-01 00:00:00',
            'end_time' => '2024-04-25 23:59:59',
        ]);

        $gateway = Pay::gateway('recvgw');
        $this->assertSame('queryRecords', $gateway->receiveCalls[0][0]);
        $this->assertSame('2024-04-01 00:00:00', $gateway->receiveCalls[0][1]['start_time']);
    }

    /**
     * 统一个人收款提现入口 personalReceiveWithdraw 派发到网关原生 withdraw
     */
    public function testPersonalReceiveWithdrawUnifiedEntry(): void
    {
        Pay::personalReceiveWithdraw('recvgw', [
            'amount' => 5000,
            'bank_card_no' => '6222',
            'real_name' => '张三',
            'out_biz_no' => 'WD_1',
        ]);

        $gateway = Pay::gateway('recvgw');
        $this->assertSame('withdraw', $gateway->receiveCalls[0][0]);
        $this->assertSame('WD_1', $gateway->receiveCalls[0][1]['out_biz_no']);
    }

    /**
     * 统一个人收款提现查询入口 personalReceiveQueryWithdraw 派发到网关原生 queryWithdraw
     */
    public function testPersonalReceiveQueryWithdrawUnifiedEntry(): void
    {
        Pay::personalReceiveQueryWithdraw('recvgw', 'WD_1');

        $gateway = Pay::gateway('recvgw');
        $this->assertSame('queryWithdraw', $gateway->receiveCalls[0][0]);
        $this->assertSame('WD_1', $gateway->receiveCalls[0][1]);
    }

    /**
     * 统一对账下载入口 reconciliationDownloadBill 派发到网关原生 downloadBill
     */
    public function testReconciliationDownloadBillUnifiedEntry(): void
    {
        $result = Pay::reconciliationDownloadBill('recongw', ['bill_date' => '20240425', 'bill_type' => 'ALL']);

        $this->assertSame(['ok' => true, 'bill_date' => '20240425'], $result);

        $gateway = Pay::gateway('recongw');
        $this->assertSame('downloadBill', $gateway->reconCalls[0][0]);
        $this->assertSame('20240425', $gateway->reconCalls[0][1]['bill_date']);
    }

    /**
     * 统一对账资金账单入口 reconciliationDownloadFundFlow 派发到网关原生 downloadFundFlow
     */
    public function testReconciliationDownloadFundFlowUnifiedEntry(): void
    {
        Pay::reconciliationDownloadFundFlow('recongw', ['bill_date' => '20240425']);

        $gateway = Pay::gateway('recongw');
        $this->assertSame('downloadFundFlow', $gateway->reconCalls[0][0]);
        $this->assertSame('20240425', $gateway->reconCalls[0][1]['bill_date']);
    }

    /**
     * 统一对账解析入口 reconciliationParseBill 派发到网关原生 parseBill
     */
    public function testReconciliationParseBillUnifiedEntry(): void
    {
        $result = Pay::reconciliationParseBill('recongw', 'RAW_CSV');

        $this->assertSame([['row' => 'RAW_CSV']], $result);

        $gateway = Pay::gateway('recongw');
        $this->assertSame('parseBill', $gateway->reconCalls[0][0]);
        $this->assertSame('RAW_CSV', $gateway->reconCalls[0][1]);
    }

    /**
     * 统一入口调用未实现对账能力的网关应抛「无此方法」
     */
    public function testReconciliationMethodNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        // fakechan 未实现对账能力接口，无 downloadBill 方法
        Pay::reconciliationDownloadBill('fakechan', ['bill_date' => '20240425']);
    }

    /**
     * 统一退款申请入口 refundApply 派发到网关原生 applyRefund
     */
    public function testRefundApplyUnifiedEntry(): void
    {
        $result = Pay::refundApply('refundgw', [
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 50,
        ]);

        $this->assertSame(['ok' => true, 'out_refund_no' => 'REFUND_001'], $result);

        $gateway = Pay::gateway('refundgw');
        $this->assertSame('applyRefund', $gateway->refundCalls[0][0]);
        $this->assertSame('REFUND_001', $gateway->refundCalls[0][1]['out_refund_no']);
    }

    /**
     * 统一退款查询入口 refundQuery 派发到网关原生 queryRefund
     */
    public function testRefundQueryUnifiedEntry(): void
    {
        Pay::refundQuery('refundgw', 'REFUND_001');

        $gateway = Pay::gateway('refundgw');
        $this->assertSame('queryRefund', $gateway->refundCalls[0][0]);
        $this->assertSame('REFUND_001', $gateway->refundCalls[0][1]);
    }

    /**
     * 统一退款取消入口 refundCancel 派发到网关原生 cancelRefund
     */
    public function testRefundCancelUnifiedEntry(): void
    {
        Pay::refundCancel('refundgw', 'REFUND_001');

        $gateway = Pay::gateway('refundgw');
        $this->assertSame('cancelRefund', $gateway->refundCalls[0][0]);
        $this->assertSame('REFUND_001', $gateway->refundCalls[0][1]);
    }

    /**
     * 统一入口调用未实现退款能力的网关应抛「无此方法」
     */
    public function testRefundMethodNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        // fakechan 未实现退款能力接口，无 applyRefund 方法
        Pay::refundApply('fakechan', [
            'out_trade_no' => 'ORDER_001',
            'out_refund_no' => 'REFUND_001',
            'refund_fee' => 50,
        ]);
    }

    /**
     * 统一加密货币下单入口 cryptoCreateOrder 派发到网关原生 createOrder
     */
    public function testCryptoCreateOrderUnifiedEntry(): void
    {
        $result = Pay::cryptoCreateOrder('cryptogw', ['out_trade_no' => 'C1', 'total_amount' => 100]);

        $this->assertSame(['ok' => true, 'charge_id' => 'chg_1'], $result);

        $gateway = Pay::gateway('cryptogw');
        $this->assertSame('createOrder', $gateway->cryptoCalls[0][0]);
        $this->assertSame(['out_trade_no' => 'C1', 'total_amount' => 100], $gateway->cryptoCalls[0][1]);
    }

    /**
     * 统一加密货币链上状态入口 cryptoGetOnChainStatus 派发到网关原生 getConfirmations
     */
    public function testCryptoGetOnChainStatusUnifiedEntry(): void
    {
        Pay::cryptoGetOnChainStatus('cryptogw', 'chg_1');

        $gateway = Pay::gateway('cryptogw');
        $this->assertSame('getConfirmations', $gateway->cryptoCalls[0][0]);
        $this->assertSame('chg_1', $gateway->cryptoCalls[0][1]);
    }

    /**
     * 统一入口调用未实现加密货币能力的网关应抛「无此方法」
     */
    public function testCryptoMethodNotSupported(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('无此方法');

        // fakechan 未实现加密货币能力接口，无加密货币专属方法 createCryptoOrder
        Pay::cryptoCreateCryptoOrder('fakechan', ['out_trade_no' => 'C1']);
    }
}
