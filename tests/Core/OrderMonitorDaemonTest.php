<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\OrderMonitorDaemon;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\UnifiedQrRouter;
use Kode\Pays\Tests\TestCase;

/**
 * 订单监控守护进程单元测试
 *
 * 覆盖注册/注销、scanOnce 终态处理、超时、回调触发、多订单并发扫描等场景。
 */
class OrderMonitorDaemonTest extends TestCase
{
    /**
     * 构造一个 mock 网关
     *
     * @param array<string, mixed>|null $queryResult queryOrder 返回值
     * @param array<int, array<string, mixed>|null>|null $querySequence 多次 queryOrder 返回序列（null 抛订单不存在异常）
     */
    private function createMockGateway(?array $queryResult = null, ?array $querySequence = null): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);

        if ($querySequence !== null) {
            $args = [];
            foreach ($querySequence as $value) {
                $args[] = $value === null
                    ? $this->throwException(PayException::orderNotFound('订单不存在'))
                    : $value;
            }
            $gateway->method('queryOrder')->willReturnOnConsecutiveCalls(...$args);
        } elseif ($queryResult !== null) {
            $gateway->method('queryOrder')->willReturn($queryResult);
        } else {
            $gateway->method('queryOrder')->willReturn(['trade_state' => 'NOTPAY']);
        }

        $gateway->method('verifyNotify')->willReturn(true);

        return $gateway;
    }

    /**
     * 构造 OrderMonitorDaemon，注入预创建的网关缓存
     *
     * @param array<string, GatewayInterface> $gateways
     * @param UnifiedQrRouter|null $router
     */
    private function createDaemon(array $gateways = [], ?UnifiedQrRouter $router = null): OrderMonitorDaemon
    {
        return new OrderMonitorDaemon($router, $gateways);
    }

    /**
     * 测试 register：监控任务正确注册
     */
    public function testRegister(): void
    {
        $daemon = $this->createDaemon();

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1', 'total_fee' => 100], [
            'interval' => 3,
            'timeout' => 60,
        ]);

        $monitor = $daemon->getMonitor('R1');

        $this->assertNotNull($monitor);
        $this->assertSame('R1', $monitor['router_id']);
        $this->assertSame('wechat', $monitor['channel']);
        $this->assertSame('O1', $monitor['order_no']);
        $this->assertSame(3, $monitor['interval']);
        $this->assertSame(60, $monitor['timeout']);
        $this->assertSame(0, $monitor['attempts']);
    }

    /**
     * 测试 register：重复注册抛异常
     */
    public function testRegisterDuplicateThrows(): void
    {
        $daemon = $this->createDaemon();

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('监控任务已存在：R1');

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O2']);
    }

    /**
     * 测试 register：订单缺少订单号抛异常
     */
    public function testRegisterWithoutOrderNoThrows(): void
    {
        $daemon = $this->createDaemon();

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('订单数据缺少 out_trade_no / order_id 字段');

        $daemon->register('R1', 'wechat', ['total_fee' => 100]);
    }

    /**
     * 测试 unregister：成功移除监控
     */
    public function testUnregister(): void
    {
        $daemon = $this->createDaemon();
        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);

        $this->assertTrue($daemon->unregister('R1'));
        $this->assertNull($daemon->getMonitor('R1'));
        $this->assertFalse($daemon->unregister('R1'));
    }

    /**
     * 测试 scanOnce：抓取到 SUCCESS 状态触发 on_success 回调
     */
    public function testScanOnceTriggersSuccessCallback(): void
    {
        $gateway = $this->createMockGateway([
            'trade_state' => 'SUCCESS',
            'out_trade_no' => 'O1',
            'transaction_id' => 'T1',
        ]);

        $called = ['router_id' => '', 'transaction_id' => ''];
        $daemon = $this->createDaemon(['wechat' => $gateway]);

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1'], [
            'on_success' => function ($data, $routerId) use (&$called) {
                $called = ['router_id' => $routerId, 'transaction_id' => $data['transaction_id'] ?? ''];
            },
        ]);

        $stats = ['completed' => 0, 'succeeded' => 0, 'failed' => 0, 'timed_out' => 0];
        $processed = $daemon->scanOnce($stats);

        $this->assertSame(1, $processed);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['succeeded']);
        $this->assertSame(0, $stats['failed']);
        $this->assertNull($daemon->getMonitor('R1'));

        $this->assertSame('R1', $called['router_id']);
        $this->assertSame('T1', $called['transaction_id']);
    }

    /**
     * 测试 scanOnce：抓取到 FAILED 状态触发 on_failure 回调
     */
    public function testScanOnceTriggersFailureCallback(): void
    {
        $gateway = $this->createMockGateway([
            'trade_state' => 'CLOSED',
        ]);

        $called = ['reason' => '', 'router_id' => ''];
        $daemon = $this->createDaemon(['wechat' => $gateway]);

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1'], [
            'on_failure' => function ($reason, $data, $routerId) use (&$called) {
                $called = ['reason' => $reason, 'router_id' => $routerId];
            },
        ]);

        $stats = ['completed' => 0, 'succeeded' => 0, 'failed' => 0, 'timed_out' => 0];
        $daemon->scanOnce($stats);

        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['failed']);
        $this->assertStringContainsString('CLOSED', $called['reason']);
    }

    /**
     * 测试 scanOnce：订单不存在异常不中断守护进程，继续等待
     */
    public function testScanOnceContinuesOnOrderNotFound(): void
    {
        $gateway = $this->createMockGateway(querySequence: [null]);

        $daemon = $this->createDaemon(['wechat' => $gateway]);
        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);

        $stats = ['completed' => 0, 'succeeded' => 0, 'failed' => 0, 'timed_out' => 0];
        $daemon->scanOnce($stats);

        // 订单不存在时不应移除监控，等待下次重试
        $this->assertSame(0, $stats['completed']);
        $this->assertNotNull($daemon->getMonitor('R1'));
        $this->assertSame(1, $daemon->getMonitor('R1')['attempts']);
    }

    /**
     * 测试 scanOnce：interval 未到期不轮询
     */
    public function testScanOnceSkipsWhenIntervalNotDue(): void
    {
        $gateway = $this->createMockGateway();
        $daemon = $this->createDaemon(['wechat' => $gateway]);

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1'], [
            'interval' => 60,
        ]);

        // 第一次扫描会执行 queryOrder 并更新 last_query_at
        $daemon->scanOnce();
        $firstAttempt = $daemon->getMonitor('R1')['attempts'];

        // 第二次扫描因 interval 未到期应跳过
        $daemon->scanOnce();
        $secondAttempt = $daemon->getMonitor('R1')['attempts'];

        $this->assertSame(1, $firstAttempt);
        $this->assertSame(1, $secondAttempt);
    }

    /**
     * 测试 scanOnce：达到 max_attempts 触发 on_timeout 回调
     */
    public function testScanOnceTriggersTimeoutOnMaxAttempts(): void
    {
        $gateway = $this->createMockGateway(['trade_state' => 'NOTPAY']);
        $daemon = $this->createDaemon(['wechat' => $gateway]);

        $called = false;
        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1'], [
            'interval' => 1,
            'max_attempts' => 2,
            'on_timeout' => function () use (&$called) {
                $called = true;
            },
        ]);

        $daemon->scanOnce();   // attempts → 1

        // 通过反射重置 last_query_at 模拟 interval 已到期，避免真实 sleep
        $ref = new \ReflectionProperty($daemon, 'monitors');
        $monitors = $ref->getValue($daemon);
        $monitors['R1']['last_query_at'] = 0;
        $ref->setValue($daemon, $monitors);

        $daemon->scanOnce();   // attempts → 2

        $ref = new \ReflectionProperty($daemon, 'monitors');
        $monitors = $ref->getValue($daemon);
        $monitors['R1']['last_query_at'] = 0;
        $ref->setValue($daemon, $monitors);

        $daemon->scanOnce();   // attempts(2) >= max_attempts(2) 触发超时

        $this->assertTrue($called);
        $this->assertNull($daemon->getMonitor('R1'));
    }

    /**
     * 测试 scanOnce：总超时触发 on_timeout 回调
     */
    public function testScanOnceTriggersTimeoutOnTimeout(): void
    {
        $gateway = $this->createMockGateway(['trade_state' => 'NOTPAY']);
        $daemon = $this->createDaemon(['wechat' => $gateway]);

        $called = false;
        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1'], [
            'interval' => 1,
            'timeout' => 1,
            'on_timeout' => function () use (&$called) {
                $called = true;
            },
        ]);

        // 让 registered_at 距 now 超过 timeout
        $ref = new \ReflectionProperty($daemon, 'monitors');
        $monitors = $ref->getValue($daemon);
        $monitors['R1']['registered_at'] = time() - 100;
        $ref->setValue($daemon, $monitors);

        $daemon->scanOnce();

        $this->assertTrue($called);
        $this->assertNull($daemon->getMonitor('R1'));
    }

    /**
     * 测试 scanOnce：多订单并发扫描
     */
    public function testScanOnceProcessesMultipleOrders(): void
    {
        $g1 = $this->createMockGateway(['trade_state' => 'SUCCESS', 'out_trade_no' => 'O1']);
        $g2 = $this->createMockGateway(['trade_state' => 'SUCCESS', 'out_trade_no' => 'O2']);

        $daemon = $this->createDaemon(['wechat' => $g1, 'alipay' => $g2]);

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);
        $daemon->register('R2', 'alipay', ['out_trade_no' => 'O2']);

        $stats = ['completed' => 0, 'succeeded' => 0, 'failed' => 0, 'timed_out' => 0];
        $processed = $daemon->scanOnce($stats);

        $this->assertSame(2, $processed);
        $this->assertSame(2, $stats['completed']);
        $this->assertSame(2, $stats['succeeded']);
        $this->assertSame([], $daemon->getMonitors());
    }

    /**
     * 测试 scanOnce：未提供 stats 时内部自动初始化
     */
    public function testScanOnceAutoInitStats(): void
    {
        $gateway = $this->createMockGateway(['trade_state' => 'SUCCESS']);
        $daemon = $this->createDaemon(['wechat' => $gateway]);
        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);

        $daemon->scanOnce();

        $this->assertNull($daemon->getMonitor('R1'));
    }

    /**
     * 测试 scanOnce：成功后自动调用 router->markPaid
     */
    public function testScanOnceMarksRouterPaidOnSuccess(): void
    {
        // 使用真实 UnifiedQrRouter 实例，gatewayCache 提供网关避免依赖 createGateway
        $router = new UnifiedQrRouter(
            gatewayConfigs: ['wechat' => []],
            entryUrlPrefix: 'https://test.kodephp.com/r/',
        );

        $entry = $router->createEntry(['wechat'], 100, '商品');

        $gateway = $this->createMockGateway([
            'trade_state' => 'SUCCESS',
            'out_trade_no' => 'O1',
            'transaction_id' => 'T1',
        ]);

        $daemon = $this->createDaemon(['wechat' => $gateway], $router);

        $daemon->register($entry['router_id'], 'wechat', ['out_trade_no' => 'O1']);
        $daemon->scanOnce();

        $status = $router->getStatus($entry['router_id']);
        $this->assertSame(UnifiedQrRouter::STATUS_PAID, $status['status']);
        $this->assertSame('T1', $status['payment_data']['transaction_id']);
    }

    /**
     * 测试 getMonitors：返回所有待监控订单
     */
    public function testGetMonitors(): void
    {
        $daemon = $this->createDaemon();

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);
        $daemon->register('R2', 'alipay', ['out_trade_no' => 'O2']);

        $monitors = $daemon->getMonitors();

        $this->assertCount(2, $monitors);
        $this->assertArrayHasKey('R1', $monitors);
        $this->assertArrayHasKey('R2', $monitors);
    }

    /**
     * 测试 run：所有任务完成后退出循环
     */
    public function testRunExitsWhenAllMonitorsDone(): void
    {
        $gateway = $this->createMockGateway(['trade_state' => 'SUCCESS']);
        $daemon = $this->createDaemon(['wechat' => $gateway]);

        $daemon->register('R1', 'wechat', ['out_trade_no' => 'O1']);

        $stats = $daemon->run();

        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['succeeded']);
    }

    /**
     * 测试 run：空监控任务时使用 max_loops 避免死循环
     */
    public function testRunWithEmptyMonitorsAndMaxLoops(): void
    {
        $daemon = $this->createDaemon();

        $stats = $daemon->run(['max_loops' => 3, 'idle_sleep' => 0]);

        $this->assertSame(0, $stats['completed']);
    }

    /**
     * 测试 stop：在 max_loops 内退出
     */
    public function testStop(): void
    {
        $daemon = $this->createDaemon();

        // 通过 max_loops=1 让循环跑一次后退出，然后调用 stop 检查状态
        $daemon->run(['max_loops' => 1]);

        $ref = new \ReflectionProperty($daemon, 'state');
        $ref->setAccessible(true);

        $this->assertSame(OrderMonitorDaemon::STATE_STOPPED, $ref->getValue($daemon));
    }
}
