<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway;

use Kode\Pays\Contract\VirtualPayCapableInterface;
use Kode\Pays\Core\CapabilityAuditor;
use Kode\Pays\Core\GatewayManifest;
use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatVirtualGateway;
use Kode\Pays\Support\Signer;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * VirtualPayCapableInterface 集中功能测试（v2.32.0 新增）
 *
 * 与 Webhook/QR/Refund/Transfer/ProfitSharing/Balance/Settlement/Subscription/
 * RedPacket/Reconciliation/PersonalReceive/Crypto 同定位：
 * 用 MockHttpClient 真实驱动 9 个 xpay 接口，逐字节校验双签名（pay_sig / signature）
 * 与请求体一致，并守护参数校验、诚实降级、Manifest 登记零漂移。
 */
class VirtualPayCapableTest extends TestCase
{
    protected const APP_KEY = 'prod-app-key-32bytes-long-value';
    protected const SANDBOX_KEY = 'sandbox-app-key-32bytes-value';
    protected const SESSION_KEY = 'session-key-086d823744d14494a4c5a0d5f76e8a72';
    protected const ACCESS_TOKEN = 'MOCK_ACCESS_TOKEN_123456';

    /**
     * 构造带 MockHttpClient 的虚拟支付网关
     */
    protected function makeGateway(array $config = [], array $responses = []): array
    {
        $client = new MockHttpClient($responses);
        $gateway = new WechatVirtualGateway(array_merge([
            'app_id' => 'wxAPPID1234567890',
            'app_secret' => 'secret-value',
            'offer_id' => 'off_123456',
            'app_key' => self::APP_KEY,
            'sandbox_app_key' => self::SANDBOX_KEY,
            'session_key' => self::SESSION_KEY,
            'openid' => 'oMockOpenId0000001',
            'user_ip' => '127.0.0.1',
            'access_token' => self::ACCESS_TOKEN,
        ], $config), $client);

        return [$gateway, $client];
    }

    /**
     * 解码 MockHttpClient 记录的请求 Query
     */
    protected function queryOf(MockHttpClient $client): array
    {
        $request = $client->getLastRequest();
        $this->assertNotNull($request);

        $query = [];
        parse_str($request['url'] !== null ? (string) parse_url($request['url'], PHP_URL_QUERY) : '', $query);

        return $query;
    }

    /**
     * 取 MockHttpClient 记录的原始请求体
     */
    protected function bodyOf(MockHttpClient $client): string
    {
        $request = $client->getLastRequest();
        $this->assertNotNull($request);

        return (string) ($request['data']['body'] ?? '');
    }

    /**
     * 统一成功响应
     */
    protected function okResponse(array $extra = []): string
    {
        return json_encode(array_merge(['errcode' => 0, 'errmsg' => ''], $extra));
    }

    // -----------------------------------------------------------------
    // 能力接口契约
    // -----------------------------------------------------------------

    public function testImplementsVirtualPayCapableInterface(): void
    {
        $gateway = $this->makeGateway()[0];

        $this->assertInstanceOf(VirtualPayCapableInterface::class, $gateway);
        $this->assertSame('wechat_virtual', WechatVirtualGateway::getName());
        $this->assertTrue(GatewayManifest::has('wechat_virtual'));
        $this->assertSame('微信小程序虚拟支付', GatewayManifest::get('wechat_virtual')['label']);
        $this->assertSame('virtual_pay', GatewayManifest::CAP_VIRTUAL_PAY);
        $this->assertSame('VPR', GatewayManifest::CAPABILITY_SHORT_CODES[GatewayManifest::CAP_VIRTUAL_PAY]);
        $this->assertSame('小程序虚拟支付', GatewayManifest::capabilityLabel(GatewayManifest::CAP_VIRTUAL_PAY));
    }

    // -----------------------------------------------------------------
    // createOrder：前端参数组装与双签名
    // -----------------------------------------------------------------

    public function testCreateOrderReturnsFrontendParams(): void
    {
        [$gateway, $client] = $this->makeGateway();

        $result = $gateway->createOrder([
            'out_trade_no' => 'ORD20260921001',
            'product_id' => 'VIP_MONTHLY',
            'goods_price' => 3000,
            'buy_quantity' => 1,
            'attach' => 'member_recharge',
        ]);

        $expectedSignData = '{"offerId":"off_123456","buyQuantity":1,"env":0,"currencyType":"CNY",'
            . '"productId":"VIP_MONTHLY","goodsPrice":3000,"outTradeNo":"ORD20260921001","attach":"member_recharge"}';

        $this->assertSame($expectedSignData, $result['signData']);
        $this->assertSame('short_series_goods', $result['mode']);
        $this->assertSame(0, $result['env']);
        $this->assertSame('off_123456', $result['offerId']);

        // 签名可由调用方独立复算（不依赖网关内部实现）
        $this->assertSame(
            Signer::hmacSha256Raw(WechatVirtualGateway::CLIENT_SIGN_URI . '&' . $expectedSignData, self::APP_KEY),
            $result['paySig']
        );
        $this->assertSame(
            Signer::hmacSha256Raw($expectedSignData, self::SESSION_KEY),
            $result['signature']
        );

        // 下单本身不发起任何网络请求
        $this->assertSame([], $client->getHistory());
    }

    public function testCreateOrderIncludesDiscountPriceWhenProvided(): void
    {
        [$gateway] = $this->makeGateway();

        $result = $gateway->createOrder([
            'out_trade_no' => 'ORD20260921002',
            'product_id' => 'COURSE_01',
            'goods_price' => 1000,
            'activity_selling_price' => 500,
        ]);

        $signData = json_decode($result['signData'], true);

        $this->assertSame(1000, $signData['goodsPrice']);
        $this->assertSame(500, $signData['activitySellingPrice']);
    }

    public function testCreateOrderAcceptsCallerProvidedSignData(): void
    {
        [$gateway] = $this->makeGateway();

        $custom = '{"offerId":"off_123456","buyQuantity":1,"env":0,"currencyType":"CNY","outTradeNo":"ORD20260921003"}';

        $result = $gateway->createOrder(['sign_data' => $custom]);

        $this->assertSame($custom, $result['signData']);
        $this->assertSame(
            Signer::hmacSha256Raw(WechatVirtualGateway::CLIENT_SIGN_URI . '&' . $custom, self::APP_KEY),
            $result['paySig']
        );
    }

    public function testCreateOrderUsesSandboxKeyForSandboxEnv(): void
    {
        [$gateway] = $this->makeGateway();

        $result = $gateway->createOrder([
            'out_trade_no' => 'ORD20260921004',
            'product_id' => 'P1',
            'goods_price' => 100,
            'env' => 1,
        ]);

        $signData = json_decode($result['signData'], true);
        $this->assertSame(1, $signData['env']);
        $this->assertSame(1, $result['env']);
        $this->assertSame(
            Signer::hmacSha256Raw(WechatVirtualGateway::CLIENT_SIGN_URI . '&' . $result['signData'], self::SANDBOX_KEY),
            $result['paySig']
        );
    }

    // -----------------------------------------------------------------
    // xpay 请求通道：逐字节一致与三档签名
    // -----------------------------------------------------------------

    public function testQueryOrderPaySigIsComputedOverActualRequestBody(): void
    {
        [$gateway, $client] = $this->makeGateway([], [
            'xpay/query_order' => $this->okResponse([
                'order' => ['order_id' => 'ORD20260921001', 'status' => 2, 'left_fee' => 3000],
            ]),
        ]);

        $result = $gateway->queryVirtualOrder('ORD20260921001');

        $this->assertSame(2, $result['order']['status']);

        $query = $this->queryOf($client);
        $body = $this->bodyOf($client);

        // pay_sig 必须对「实际发出的字节」签名，而非重新编码的结果
        $this->assertSame(
            Signer::hmacSha256Raw('/xpay/query_order&' . $body, self::APP_KEY),
            $query['pay_sig']
        );
        $this->assertSame(self::ACCESS_TOKEN, $query['access_token']);
        $this->assertArrayNotHasKey('signature', $query, 'query_order 仅需支付签名');

        $payload = json_decode($body, true);
        $this->assertSame('ORD20260921001', $payload['order_id']);
        $this->assertSame('oMockOpenId0000001', $payload['openid']);
        $this->assertSame(0, $payload['env']);
    }

    public function testSignBothChannelsIncludeUserSignature(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/query_user_balance' => $this->okResponse([
            'balance' => 500, 'present_balance' => 100, 'sum_cost' => 200, 'first_save_flag' => false,
        ])]);

        $result = $gateway->queryTokenBalance('oUser000000001');

        $this->assertSame(500, $result['balance']);

        $query = $this->queryOf($client);
        $body = $this->bodyOf($client);

        $this->assertArrayHasKey('signature', $query);
        $this->assertArrayHasKey('pay_sig', $query);
        $this->assertSame(Signer::hmacSha256Raw($body, self::SESSION_KEY), $query['signature']);
        $this->assertSame(
            Signer::hmacSha256Raw('/xpay/query_user_balance&' . $body, self::APP_KEY),
            $query['pay_sig']
        );

        $payload = json_decode($body, true);
        $this->assertSame('oUser000000001', $payload['openid']);
        $this->assertSame('127.0.0.1', $payload['user_ip']);
    }

    public function testSignNoneChannelOmitsAllSignatures(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/present_currency' => $this->okResponse([
            'order_id' => 'GIFT000001', 'balance' => 300, 'present_balance' => 300,
        ])]);

        $result = $gateway->giftTokens(['order_id' => 'GIFT000001', 'amount' => 300, 'openid' => 'oGift00000001']);

        $this->assertSame('GIFT000001', $result['order_id']);

        $query = $this->queryOf($client);
        $this->assertArrayHasKey('access_token', $query);
        $this->assertArrayNotHasKey('pay_sig', $query, 'present_currency 仅需 access_token');
        $this->assertArrayNotHasKey('signature', $query);
    }

    public function testRequestBodyPreservesUnicodeAndSlashes(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/currency_pay' => $this->okResponse([
            'order_id' => 'COIN000001', 'balance' => 700, 'used_present_amount' => 0,
        ])]);

        $gateway->deductTokens([
            'amount' => 100,
            'order_id' => 'COIN000001',
            'payitem' => [['productid' => 'VIP', 'unit_price' => 10, 'quantity' => 10]],
            'remark' => '会员充值/100点',
        ]);

        $body = $this->bodyOf($client);
        $this->assertStringContainsString('会员充值/100点', $body, '中文与斜杠不得被转义');
        $this->assertStringNotContainsString('\u', $body);
        $this->assertStringNotContainsString('\\/', $body);

        $payload = json_decode($body, true);
        $this->assertIsString($payload['payitem'], 'payitem 需编码为 JSON 字符串');
        $this->assertSame('VIP', json_decode($payload['payitem'], true)[0]['productid']);
    }

    // -----------------------------------------------------------------
    // 各接口真实调用
    // -----------------------------------------------------------------

    public function testQueryVirtualOrderByWxOrderId(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/query_order' => $this->okResponse([
            'order' => ['order_id' => 'ORD20260921001', 'status' => 4],
        ])]);

        $gateway->queryVirtualOrder('any-id', ['wx_order_id' => 'wxOrder999']);

        $payload = json_decode($this->bodyOf($client), true);
        $this->assertSame('wxOrder999', $payload['wx_order_id']);
        $this->assertArrayNotHasKey('order_id', $payload, '二选一，不应同时发送');
    }

    public function testRefundVirtualOrderSendsNormalizedPayload(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/refund_order' => $this->okResponse([
            'refund_order_id' => 'RF20260921001', 'refund_wx_order_id' => 'wxRF001',
        ])]);

        $result = $gateway->refundVirtualOrder([
            'refund_order_id' => 'RF20260921001',
            'order_id' => 'ORD20260921001',
            'left_fee' => 3000,
            'refund_fee' => 1500,
            'refund_reason' => '3',
            'req_from' => '2',
            'biz_meta' => 'user_requested',
        ]);

        $this->assertSame('RF20260921001', $result['refund_order_id']);

        $payload = json_decode($this->bodyOf($client), true);
        $this->assertSame(3000, $payload['left_fee']);
        $this->assertSame(1500, $payload['refund_fee']);
        $this->assertSame('3', $payload['refund_reason']);
        $this->assertSame('2', $payload['req_from']);
        $this->assertSame('user_requested', $payload['biz_meta']);
    }

    public function testNotifyProvideGoodsRequiresEitherOrderId(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/notify_provide_goods' => $this->okResponse()]);

        $result = $gateway->notifyProvideGoods(['order_id' => 'ORD20260921001']);

        $this->assertSame(0, $result['errcode']);
        $this->assertSame('ORD20260921001', json_decode($this->bodyOf($client), true)['order_id']);

        $query = $this->queryOf($client);
        $this->assertArrayNotHasKey('pay_sig', $query, 'notify_provide_goods 仅需 access_token');
    }

    public function testDownloadVirtualBillSendsDateRange(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/download_bill' => $this->okResponse([
            'url' => 'https://bill.example.com/xpay.zip',
        ])]);

        $result = $gateway->downloadVirtualBill(['begin_ds' => 20260901, 'end_ds' => 20260921]);

        $this->assertStringContainsString('xpay.zip', $result['url']);

        $payload = json_decode($this->bodyOf($client), true);
        $this->assertSame(20260901, $payload['begin_ds']);
        $this->assertSame(20260921, $payload['end_ds']);
    }

    public function testQueryTokenBalanceFallsBackToConfigOpenid(): void
    {
        [$gateway, $client] = $this->makeGateway([], ['xpay/query_user_balance' => $this->okResponse([
            'balance' => 0,
        ])]);

        $gateway->queryTokenBalance('', ['env' => 1]);

        $payload = json_decode($this->bodyOf($client), true);
        $this->assertSame('oMockOpenId0000001', $payload['openid']);
        $this->assertSame(1, $payload['env']);
    }

    // -----------------------------------------------------------------
    // 基础契约入口委派
    // -----------------------------------------------------------------

    public function testGatewayInterfaceDelegation(): void
    {
        [$gateway, $client] = $this->makeGateway([], [
            'xpay/query_order' => $this->okResponse(['order' => ['status' => 4, 'order_type' => 0]]),
            'xpay/refund_order' => $this->okResponse(['refund_order_id' => 'RF20260921001']),
        ]);

        $order = $gateway->queryOrder('ORD20260921001');
        $this->assertSame(4, $order['order']['status']);

        $refund = $gateway->refund([
            'refund_order_id' => 'RF20260921001', 'order_id' => 'ORD20260921001',
            'left_fee' => 100, 'refund_fee' => 100, 'refund_reason' => '0', 'req_from' => '1',
        ]);
        $this->assertSame('RF20260921001', $refund['refund_order_id']);

        // 退款单在 xpay 侧同样是订单，queryRefund 以退款单号查单追踪
        $refundStatus = $gateway->queryRefund('RF20260921001');
        $this->assertArrayHasKey('order', $refundStatus);

        $this->assertCount(3, $client->getHistory());
    }

    public function testCloseOrderHonestlyThrowsMethodNotSupported(): void
    {
        [$gateway] = $this->makeGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_METHOD_NOT_SUPPORTED);
        $gateway->closeOrder('ORD20260921001');
    }

    // -----------------------------------------------------------------
    // 参数校验（诚实报错，不伪造成功）
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{string, array, string}>
     */
    public static function invalidParamProvider(): array
    {
        return [
            'unknown mode' => ['createOrder', [['out_trade_no' => 'ORD20260921001', 'mode' => 'other']], '不支持的虚拟支付模式'],
            'non-cny currency' => ['createOrder', [['out_trade_no' => 'ORD20260921001', 'product_id' => 'P1', 'goods_price' => 100, 'currency_type' => 'USD']], '仅支持币种 CNY'],
            'trade no too short' => ['createOrder', [['out_trade_no' => 'ABC', 'product_id' => 'P1', 'goods_price' => 100]], 'out_trade_no 格式非法'],
            'trade no underscore prefix' => ['createOrder', [['out_trade_no' => '_ORD2026091', 'product_id' => 'P1', 'goods_price' => 100]], 'out_trade_no 格式非法'],
            'trade no bad char' => ['createOrder', [['out_trade_no' => 'ORD 20260901', 'product_id' => 'P1', 'goods_price' => 100]], 'out_trade_no 格式非法'],
            'missing product id' => ['createOrder', [['out_trade_no' => 'ORD20260921001', 'goods_price' => 100]], 'product_id 必填'],
            'zero goods price' => ['createOrder', [['out_trade_no' => 'ORD20260921001', 'product_id' => 'P1', 'goods_price' => 0]], 'goods_price 必填'],
            'discount exceeds price' => ['createOrder', [['out_trade_no' => 'ORD20260921001', 'product_id' => 'P1', 'goods_price' => 100, 'activity_selling_price' => 200]], 'activity_selling_price 需为'],
            'coin mode without sign data' => ['createOrder', [['out_trade_no' => 'ORD20260921001', 'mode' => 'short_series_coin']], '代币充值模式'],
            'refund no too short' => ['refundVirtualOrder', [['refund_order_id' => 'RF1']], 'refund_order_id 格式非法'],
            'refund missing original order' => ['refundVirtualOrder', [['refund_order_id' => 'RF20260921001', 'left_fee' => 100, 'refund_fee' => 100, 'refund_reason' => '0', 'req_from' => '1']], 'order_id 与 wx_order_id 需至少提供一个'],
            'refund fee exceeds left' => ['refundVirtualOrder', [['refund_order_id' => 'RF20260921001', 'order_id' => 'ORD20260921001', 'left_fee' => 100, 'refund_fee' => 200]], 'left_fee 必须不小于 refund_fee'],
            'refund fee zero' => ['refundVirtualOrder', [['refund_order_id' => 'RF20260921001', 'order_id' => 'ORD20260921001', 'left_fee' => 100, 'refund_fee' => 0]], 'refund_fee 必须大于 0'],
            'refund bad reason' => ['refundVirtualOrder', [['refund_order_id' => 'RF20260921001', 'order_id' => 'ORD20260921001', 'left_fee' => 100, 'refund_fee' => 100, 'refund_reason' => '9']], 'refund_reason 非法'],
            'refund bad source' => ['refundVirtualOrder', [['refund_order_id' => 'RF20260921001', 'order_id' => 'ORD20260921001', 'left_fee' => 100, 'refund_fee' => 100, 'refund_reason' => '0', 'req_from' => '9']], 'req_from 非法'],
            'notify without order' => ['notifyProvideGoods', [[]], 'order_id 与 wx_order_id 需至少提供一个'],
            'tokens zero amount' => ['deductTokens', [['amount' => 0, 'order_id' => 'COIN1']], 'amount 必须为正整数'],
            'tokens missing order' => ['deductTokens', [['amount' => 10]], 'order_id 必填'],
            'refund tokens missing pay order' => ['refundTokens', [['amount' => 10, 'order_id' => 'RF20260921001']], 'pay_order_id 必填'],
            'bill bad date' => ['downloadVirtualBill', [['begin_ds' => 2026, 'end_ds' => 20260921]], 'begin_ds 格式非法'],
            'bill reversed range' => ['downloadVirtualBill', [['begin_ds' => 20260921, 'end_ds' => 20260901]], 'begin_ds 不能晚于 end_ds'],
            'query order empty id' => ['queryVirtualOrder', ['', []], '订单号不能为空'],
            'payitem wrong type' => ['deductTokens', [['amount' => 1, 'order_id' => 'COIN1', 'payitem' => 42]], 'payitem 需为数组或 JSON 字符串'],
        ];
    }

    /**
     * @dataProvider invalidParamProvider
     */
    public function testRejectsInvalidParams(string $method, array $args, string $message): void
    {
        [$gateway] = $this->makeGateway();

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_PARAM);
        $this->expectExceptionMessage($message);

        $gateway->$method(...$args);
    }

    public function testMissingSessionKeyThrowsConfigError(): void
    {
        [$gateway] = $this->makeGateway(['session_key' => null]);

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_CONFIG);
        $this->expectExceptionMessage('session_key');

        $gateway->createOrder(['out_trade_no' => 'ORD20260921001', 'product_id' => 'P1', 'goods_price' => 100]);
    }

    public function testMissingOpenidThrowsParamError(): void
    {
        [$gateway] = $this->makeGateway(['openid' => null], ['xpay/query_order' => $this->okResponse()]);

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_PARAM);
        $this->expectExceptionMessage('openid 必填');

        $gateway->queryVirtualOrder('ORD20260921001');
    }

    // -----------------------------------------------------------------
    // access_token 获取
    // -----------------------------------------------------------------

    public function testAccessTokenIsFetchedWhenNotInjected(): void
    {
        $client = new MockHttpClient([
            'cgi-bin/token' => json_encode(['access_token' => 'FRESH_TOKEN', 'expires_in' => 7200]),
            'xpay/query_order' => $this->okResponse(['order' => ['status' => 1]]),
        ]);

        $gateway = new WechatVirtualGateway([
            'app_id' => 'wxAPPID1234567890',
            'app_secret' => 'secret-value',
            'offer_id' => 'off_123456',
            'app_key' => self::APP_KEY,
            'session_key' => self::SESSION_KEY,
            'openid' => 'oMockOpenId0000001',
        ], $client);

        $gateway->queryVirtualOrder('ORD20260921001');

        $this->assertCount(2, $client->getHistory());
        $this->assertSame('GET', $client->getHistory()[0]['method']);
        $this->assertStringContainsString('grant_type=client_credentials', $client->getHistory()[0]['url']);
        $this->assertSame('FRESH_TOKEN', $this->queryOf($client)['access_token']);
    }

    public function testAccessTokenFailureThrowsGatewayError(): void
    {
        $client = new MockHttpClient(['cgi-bin/token' => json_encode(['errcode' => 40013, 'errmsg' => 'invalid appid'])]);

        $gateway = new WechatVirtualGateway([
            'app_id' => 'bad', 'app_secret' => 's', 'offer_id' => 'o', 'app_key' => self::APP_KEY,
            'session_key' => self::SESSION_KEY, 'openid' => 'oMockOpenId0000001',
        ], $client);

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_GATEWAY);
        $gateway->queryVirtualOrder('ORD20260921001');
    }

    // -----------------------------------------------------------------
    // 响应处理
    // -----------------------------------------------------------------

    public function testNonZeroErrcodeThrowsGatewayError(): void
    {
        [$gateway] = $this->makeGateway([], ['xpay/query_order' => json_encode([
            'errcode' => 268490003, 'errmsg' => '订单不存在',
        ])]);

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_GATEWAY);
        $this->expectExceptionMessage('订单不存在');

        $gateway->queryVirtualOrder('ORD_NOT_EXIST1');
    }

    public function testMalformedResponseThrowsGatewayError(): void
    {
        [$gateway] = $this->makeGateway([], ['xpay/query_order' => '<html>502 Bad Gateway</html>']);

        $this->expectException(PayException::class);
        $this->expectExceptionCode(PayException::ERROR_GATEWAY);
        $this->expectExceptionMessage('响应解析失败');

        $gateway->queryVirtualOrder('ORD20260921001');
    }

    // -----------------------------------------------------------------
    // verifyNotify：小程序消息推送通道验签
    // -----------------------------------------------------------------

    public function testVerifyNotifyPlainSignature(): void
    {
        $token = 'VirtualPushToken123';
        [$gateway] = $this->makeGateway(['message_token' => $token]);

        $timestamp = '1758518400';
        $nonce = '12345678';
        $parts = [$token, $timestamp, $nonce];
        sort($parts);
        $signature = hash('sha1', implode('', $parts));

        $this->assertTrue(
            $gateway->verifyNotify(['signature' => $signature, 'timestamp' => $timestamp, 'nonce' => $nonce])
        );
        $this->assertFalse(
            $gateway->verifyNotify(['signature' => 'deadbeef', 'timestamp' => $timestamp, 'nonce' => $nonce])
        );
    }

    public function testVerifyNotifyAesMsgSignature(): void
    {
        $token = 'VirtualPushToken123';
        [$gateway] = $this->makeGateway(['message_token' => $token]);

        $timestamp = '1758518400';
        $nonce = '87654321';
        $encrypt = 'encrypted-payload-block';
        $parts = [$token, $timestamp, $nonce, $encrypt];
        sort($parts);
        $signature = hash('sha1', implode('', $parts));

        $this->assertTrue($gateway->verifyNotify([
            'msg_signature' => $signature, 'timestamp' => $timestamp, 'nonce' => $nonce, 'encrypt' => $encrypt,
        ]));
    }

    public function testVerifyNotifyHonestFalseWithoutToken(): void
    {
        [$gateway] = $this->makeGateway(['message_token' => null]);

        $this->assertFalse(
            $gateway->verifyNotify(['signature' => 'anything', 'timestamp' => '1', 'nonce' => '2'])
        );
    }

    public function testVerifyNotifyFalseWhenSignatureMissing(): void
    {
        [$gateway] = $this->makeGateway(['message_token' => 'VirtualPushToken123']);

        $this->assertFalse($gateway->verifyNotify(['timestamp' => '1', 'nonce' => '2']));
    }

    // -----------------------------------------------------------------
    // Manifest 登记守护
    // -----------------------------------------------------------------

    public function testManifestRegistersVirtualPayCapability(): void
    {
        $this->assertTrue(GatewayManifest::has('wechat_virtual'));
        $this->assertTrue(GatewayManifest::supports('wechat_virtual', GatewayManifest::CAP_VIRTUAL_PAY));
        $this->assertFalse(GatewayManifest::supports('wechat_v3', GatewayManifest::CAP_VIRTUAL_PAY));
        $this->assertFalse(GatewayManifest::supports('wechat', GatewayManifest::CAP_VIRTUAL_PAY));
        // CAP_CLOSE_ORDER 由 GatewayInterface 强制实现 + defaultCapabilities() 默认声明，
        // 「不支持关单」的语义由 closeOrder() 诚实抛 methodNotSupported 表达（见专门测试）
        $declaredExtensions = array_filter(
            GatewayManifest::capabilities('wechat_virtual'),
            static fn (bool $enabled, string $cap): bool => $enabled && isset(GatewayManifest::CAPABILITY_CONTRACTS[$cap]),
            ARRAY_FILTER_USE_BOTH
        );
        $this->assertSame(
            [GatewayManifest::CAP_VIRTUAL_PAY => true],
            $declaredExtensions,
            '本网关仅显式声明虚拟支付这一项扩展能力'
        );
        $this->assertFalse(GatewayManifest::supports('wechat_virtual', GatewayManifest::CAP_WEBHOOK), '无富契约实现，不得声明');

        $operations = GatewayManifest::capabilityOperations(GatewayManifest::CAP_VIRTUAL_PAY);
        $this->assertContains('createOrder', $operations);
        $this->assertContains('refundVirtualOrder', $operations);
        $this->assertContains('downloadVirtualBill', $operations);
    }

    public function testAuditReportsZeroDrift(): void
    {
        $this->assertSame([], CapabilityAuditor::audit());
        $this->assertContains(
            GatewayManifest::CAP_VIRTUAL_PAY,
            CapabilityAuditor::actualCapabilities('wechat_virtual')
        );
    }

    public function testConfigSchemaAndValidation(): void
    {
        $schema = GatewayManifest::configSchema('wechat_virtual');
        $this->assertSame(['app_id', 'app_secret', 'offer_id', 'app_key'], $schema['required']);
        $this->assertContains('sandbox_app_key', $schema['optional']);
        $this->assertContains('message_token', $schema['optional']);
    }

    public function testMatrixContainsVirtualPayColumn(): void
    {
        $matrix = GatewayManifest::matrix();
        $this->assertArrayHasKey('wechat_virtual', $matrix);
        $this->assertTrue($matrix['wechat_virtual']['capabilities'][GatewayManifest::CAP_VIRTUAL_PAY]['declared']);
        $this->assertTrue($matrix['wechat_virtual']['capabilities'][GatewayManifest::CAP_VIRTUAL_PAY]['actual']);
        $this->assertTrue($matrix['wechat_virtual']['capabilities'][GatewayManifest::CAP_VIRTUAL_PAY]['consistent']);

        $rendered = GatewayManifest::renderMatrix('markdown');
        $this->assertStringContainsString('VPR', $rendered);
        $this->assertStringContainsString('微信小程序虚拟支付', $rendered);
    }
}
