<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Contract\RefundCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatPayV3Gateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * 微信支付 V3 网关能力扩展单元测试
 *
 * 覆盖 v1.42.0 补齐的四类能力：退款、分账、自动结算、个人收款，
 * 重点验证 APIv3 真实端点、请求体组装与「无此方法」语义。
 */
class WechatPayV3CapabilityTest extends TestCase
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
     * 取出最后一次 POST 请求的 JSON 请求体
     *
     * @return array<string, mixed>
     */
    private function lastBody(WechatPayV3Gateway $gateway): array
    {
        $last = $this->getMockClient($gateway)->getLastRequest();

        $this->assertNotNull($last);
        $this->assertSame('POST_RAW', $last['method']);

        return (array) json_decode((string) $last['data']['body'], true);
    }

    /* ==================== 退款能力 ==================== */

    /**
     * 申请退款命中 APIv3 退款端点并按分组装金额
     */
    public function testApplyRefundHitsV3RefundEndpoint(): void
    {
        $gateway = $this->createGateway([
            'refund/domestic/refunds' => json_encode(['refund_id' => 'R1', 'status' => 'PROCESSING']),
        ]);

        $gateway->applyRefund([
            'out_refund_no' => 'REFUND1',
            'out_trade_no' => 'ORDER1',
            'refund_fee' => 500,
            'total_fee' => 1000,
            'refund_desc' => '用户取消',
            'notify_url' => 'https://example.com/refund',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/refund/domestic/refunds', $last['url']);

        $body = $this->lastBody($gateway);

        $this->assertSame('REFUND1', $body['out_refund_no']);
        $this->assertSame('ORDER1', $body['out_trade_no']);
        $this->assertSame('用户取消', $body['reason']);
        $this->assertSame(500, $body['amount']['refund']);
        $this->assertSame(1000, $body['amount']['total']);
        $this->assertSame('CNY', $body['amount']['currency']);
        $this->assertSame('https://example.com/refund', $body['notify_url']);
    }

    /**
     * 未提供商户订单号时回退到微信支付订单号
     */
    public function testApplyRefundFallsBackToTransactionId(): void
    {
        $gateway = $this->createGateway([
            'refund/domestic/refunds' => json_encode(['refund_id' => 'R1']),
        ]);

        $gateway->applyRefund([
            'out_refund_no' => 'REFUND1',
            'transaction_id' => '4200001234',
            'refund_fee' => 800,
        ]);

        $body = $this->lastBody($gateway);

        $this->assertSame('4200001234', $body['transaction_id']);
        $this->assertArrayNotHasKey('out_trade_no', $body);
        $this->assertSame(800, $body['amount']['refund']);
        $this->assertSame(800, $body['amount']['total']);
    }

    /**
     * APIv3 无取消退款接口，统一报「无此方法」
     */
    public function testCancelRefundNotSupported(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->cancelRefund('REFUND1');
    }

    /* ==================== 分账能力 ==================== */

    /**
     * 发起分账命中 APIv3 分账下单端点
     */
    public function testCreateProfitSharingHitsOrdersEndpoint(): void
    {
        $gateway = $this->createGateway([
            'profitsharing/orders' => json_encode(['order_id' => 'PS1', 'state' => 'PROCESSING']),
        ]);

        $gateway->createProfitSharing([
            'transaction_id' => '4200001234',
            'out_order_no' => 'SHARE1',
            'unfreeze_unsplit' => true,
            'receivers' => [
                ['type' => 'MERCHANT_ID', 'account' => '1900000110', 'amount' => 100, 'description' => '服务费'],
            ],
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/profitsharing/orders', $last['url']);

        $body = $this->lastBody($gateway);

        $this->assertSame('wx123', $body['appid']);
        $this->assertSame('4200001234', $body['transaction_id']);
        $this->assertSame('SHARE1', $body['out_order_no']);
        $this->assertTrue($body['unfreeze_unsplit']);
        $this->assertCount(1, $body['receivers']);
        $this->assertSame('MERCHANT_ID', $body['receivers'][0]['type']);
        $this->assertSame(100, $body['receivers'][0]['amount']);
        $this->assertArrayNotHasKey('name', $body['receivers'][0]);
    }

    /**
     * 接收方姓名属敏感字段，须以平台证书加密后传输
     */
    public function testCreateProfitSharingEncryptsReceiverName(): void
    {
        $gateway = $this->createGateway(
            ['profitsharing/orders' => json_encode(['order_id' => 'PS1'])],
            ['platform_certificate' => self::$publicKey],
        );

        $gateway->createProfitSharing([
            'transaction_id' => '4200001234',
            'out_order_no' => 'SHARE1',
            'receivers' => [
                ['type' => 'PERSONAL_OPENID', 'account' => 'openid_1', 'amount' => 100, 'name' => '张三'],
            ],
        ]);

        $body = $this->lastBody($gateway);

        $this->assertArrayHasKey('name', $body['receivers'][0]);
        $this->assertNotSame('张三', $body['receivers'][0]['name']);
        $this->assertFalse($body['unfreeze_unsplit']);
    }

    /**
     * 接收方列表为空时拒绝请求
     */
    public function testCreateProfitSharingRejectsEmptyReceivers(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->createProfitSharing([
            'transaction_id' => '4200001234',
            'out_order_no' => 'SHARE1',
            'receivers' => 'not-an-array',
        ]);
    }

    /**
     * 查询分账在提供原支付单号时纳入查询串
     */
    public function testQueryProfitSharingIncludesTransactionId(): void
    {
        $gateway = $this->createGateway([
            'profitsharing/orders' => json_encode(['out_order_no' => 'SHARE1', 'state' => 'FINISHED']),
        ]);

        $gateway->queryProfitSharing('SHARE1', '4200001234');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/profitsharing/orders/SHARE1', $last['url']);
        $this->assertSame(['transaction_id' => '4200001234'], $last['data']);
    }

    /**
     * 未提供原支付单号时不附加空查询串
     */
    public function testQueryProfitSharingWithoutTransactionId(): void
    {
        $gateway = $this->createGateway([
            'profitsharing/orders' => json_encode(['out_order_no' => 'SHARE1']),
        ]);

        $gateway->queryProfitSharing('SHARE1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame([], $last['data']);
    }

    /**
     * 分账回退命中 return-orders 端点，缺省回退到本商户号
     */
    public function testReturnProfitSharingHitsReturnOrders(): void
    {
        $gateway = $this->createGateway([
            'profitsharing/return-orders' => json_encode(['return_id' => 'RT1']),
        ]);

        $gateway->returnProfitSharing([
            'out_order_no' => 'SHARE1',
            'out_return_no' => 'RETURN1',
            'return_amount' => 60,
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/profitsharing/return-orders', $last['url']);

        $body = $this->lastBody($gateway);

        $this->assertSame('SHARE1', $body['out_order_no']);
        $this->assertSame('RETURN1', $body['out_return_no']);
        $this->assertSame('1900000109', $body['return_mchid']);
        $this->assertSame(60, $body['amount']);
    }

    /**
     * 查询分账回退命中 return-orders 明细端点
     */
    public function testQueryProfitSharingReturnHitsEndpoint(): void
    {
        $gateway = $this->createGateway([
            'profitsharing/return-orders' => json_encode(['out_return_no' => 'RETURN1']),
        ]);

        $gateway->queryProfitSharingReturn('RETURN1', 'SHARE1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/profitsharing/return-orders/RETURN1', $last['url']);
        $this->assertSame(['out_order_no' => 'SHARE1'], $last['data']);
    }

    /**
     * 解冻剩余资金命中 unfreeze 端点
     */
    public function testUnfreezeProfitSharingHitsUnfreezeEndpoint(): void
    {
        $gateway = $this->createGateway([
            'profitsharing/orders/unfreeze' => json_encode(['order_id' => 'PS1', 'state' => 'FINISHED']),
        ]);

        $gateway->unfreezeProfitSharing('4200001234', 'UNFREEZE1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/profitsharing/orders/unfreeze', $last['url']);

        $body = $this->lastBody($gateway);

        $this->assertSame('4200001234', $body['transaction_id']);
        $this->assertSame('UNFREEZE1', $body['out_order_no']);
    }

    /* ==================== 自动结算能力 ==================== */

    /**
     * 结算到零钱复用商家转账批次通道
     */
    public function testSettleToWalletReusesTransferBatch(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches' => json_encode(['batch_id' => 'B1']),
        ]);

        $gateway->settleToWallet([
            'out_biz_no' => 'SETTLE1',
            'amount' => 3000,
            'account' => 'openid_1',
            'description' => '日结',
        ]);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/transfer/batches', $last['url']);

        $body = $this->lastBody($gateway);

        $this->assertSame('SETTLE1', $body['out_batch_no']);
        $this->assertSame(3000, $body['total_amount']);
        $this->assertSame('openid_1', $body['transfer_detail_list'][0]['openid']);
    }

    /**
     * APIv3 无付款到银行卡通道，调用即报「无此方法」
     */
    public function testSettleToBankCardNotSupported(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->settleToBankCard([
            'out_biz_no' => 'SETTLE1',
            'amount' => 3000,
            'bank_card_no' => '6222000000000000',
            'real_name' => '张三',
        ]);
    }

    /**
     * 微信无外部账户 Payout 语义，调用即报「无此方法」
     */
    public function testSettleToPayoutNotSupported(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->settleToPayout(['out_biz_no' => 'SETTLE1', 'amount' => 3000]);
    }

    /**
     * 查询结算复用转账批次查询
     */
    public function testQuerySettlementReusesTransferQuery(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches/out-batch-no' => json_encode(['batch_status' => 'FINISHED']),
        ]);

        $gateway->querySettlement('SETTLE1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertSame(
            'https://api.mch.weixin.qq.com/v3/transfer/batches/out-batch-no/SETTLE1',
            $last['url'],
        );
    }

    /* ==================== 个人收款能力 ==================== */

    /**
     * 个人收款码走 Native 下单并回传 code_url
     */
    public function testCreateQrCodeReturnsCodeUrl(): void
    {
        $gateway = $this->createGateway([
            'pay/transactions/native' => json_encode(['code_url' => 'weixin://wxpay/bizpayurl?pr=abc']),
        ]);

        $result = $gateway->createQrCode([
            'out_trade_no' => 'PERSONAL1',
            'amount' => 199,
            'description' => '个人收款',
            'notify_url' => 'https://example.com/notify',
        ]);

        $this->assertSame('PERSONAL1', $result['out_trade_no']);
        $this->assertSame('weixin://wxpay/bizpayurl?pr=abc', $result['code_url']);
        $this->assertSame(199, $result['amount']);

        $body = $this->lastBody($gateway);
        $this->assertSame(199, $body['amount']['total']);
    }

    /**
     * 缺少回调地址时拒绝下单（APIv3 强制要求）
     */
    public function testCreateQrCodeRequiresNotifyUrl(): void
    {
        $this->expectException(PayException::class);

        $this->createGateway()->createQrCode(['amount' => 199, 'description' => '个人收款']);
    }

    /**
     * 收款记录复用交易对账单
     */
    public function testQueryRecordsReusesTradeBill(): void
    {
        $gateway = $this->createGateway([
            'bill/tradebill' => json_encode(['download_url' => '', 'hash_value' => 'H1']),
        ]);

        $result = $gateway->queryRecords(['start_time' => '2026-08-01']);

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('GET', $last['method']);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/bill/tradebill', $last['url']);
        $this->assertSame('2026-08-01', $last['data']['bill_date']);
        $this->assertSame('ALL', $last['data']['bill_type']);
        $this->assertSame([], $result['records']);
    }

    /**
     * 个人提现统一走商家转账到零钱
     */
    public function testWithdrawReusesTransferBatch(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches' => json_encode(['batch_id' => 'B1']),
        ]);

        $gateway->withdraw([
            'out_biz_no' => 'WD1',
            'amount' => 1500,
            'account' => 'openid_1',
        ]);

        $body = $this->lastBody($gateway);

        $this->assertSame('WD1', $body['out_batch_no']);
        $this->assertSame(1500, $body['total_amount']);
        $this->assertSame('openid_1', $body['transfer_detail_list'][0]['openid']);
    }

    /**
     * 查询提现复用转账批次查询
     */
    public function testQueryWithdrawReusesTransferQuery(): void
    {
        $gateway = $this->createGateway([
            'transfer/batches/out-batch-no' => json_encode(['batch_status' => 'FINISHED']),
        ]);

        $gateway->queryWithdraw('WD1');

        $last = $this->getMockClient($gateway)->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('https://api.mch.weixin.qq.com/v3/transfer/batches/out-batch-no/WD1', $last['url']);
    }

    /**
     * 新增能力接口均已声明
     */
    public function testImplementsExtendedCapabilities(): void
    {
        $gateway = $this->createGateway();

        $this->assertInstanceOf(RefundCapableInterface::class, $gateway);
        $this->assertInstanceOf(ProfitSharingCapableInterface::class, $gateway);
        $this->assertInstanceOf(SettlementCapableInterface::class, $gateway);
        $this->assertInstanceOf(PersonalReceiveCapableInterface::class, $gateway);
    }
}
