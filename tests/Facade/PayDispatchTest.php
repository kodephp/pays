<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Facade;

use Kode\Pays\Contract\ProfitSharingCapableInterface;
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
    }

    protected function tearDown(): void
    {
        Pay::clearCache();
        GatewayFactory::unregister('fakechan');
        GatewayFactory::unregister('profitgw');
        GatewayFactory::unregister('transgw');
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
}
