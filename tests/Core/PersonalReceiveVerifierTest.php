<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\PersonalReceiveVerifier;
use Kode\Pays\Tests\TestCase;

/**
 * 个人收款验证器单元测试
 *
 * 覆盖验证维度（签名/订单号/金额/时间戳）与进程内/后台监控流程。
 */
class PersonalReceiveVerifierTest extends TestCase
{
    /**
     * 构造一个可控的网关 mock
     *
     * @param bool $verifyResult verifyNotify 返回值
     * @param array<string, mixed>|null $queryResult queryOrder 返回值（null 表示抛订单不存在异常）
     * @param array<string, mixed>|null $querySequence 多次 queryOrder 的返回序列（优先于 $queryResult）
     */
    private function createGateway(
        bool $verifyResult = true,
        ?array $queryResult = null,
        ?array $querySequence = null,
    ): GatewayInterface {
        $gateway = $this->createMock(GatewayInterface::class);

        $gateway->method('verifyNotify')->willReturn($verifyResult);

        if ($querySequence !== null) {
            // null 元素表示该次调用抛"订单不存在"异常（模拟订单尚未生成）
            $args = [];
            foreach ($querySequence as $value) {
                $args[] = $value === null
                    ? $this->throwException(PayException::orderNotFound('订单不存在'))
                    : $value;
            }
            $gateway->method('queryOrder')->willReturnOnConsecutiveCalls(...$args);
        } elseif ($queryResult === null) {
            // 抛订单不存在异常，模拟订单尚未生成
            $gateway->method('queryOrder')
                ->willThrowException(PayException::orderNotFound('订单不存在'));
        } else {
            $gateway->method('queryOrder')->willReturn($queryResult);
        }

        return $gateway;
    }

    /**
     * 测试验证成功：签名通过、订单号匹配、金额匹配、时间戳有效
     */
    public function testVerifySuccess(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        $order = ['out_trade_no' => 'O1', 'total_fee' => 100];
        $received = [
            'out_trade_no' => 'O1',
            'total_fee' => 100,
            'time_end' => date('YmdHis'),
        ];

        $result = $verifier->verify($order, $received);

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
        $this->assertSame('验证通过', $result['message']);
    }

    /**
     * 测试签名验证失败
     */
    public function testVerifyFailsOnSignError(): void
    {
        $gateway = $this->createGateway(verifyResult: false);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->verify(['out_trade_no' => 'O1'], ['out_trade_no' => 'O1']);

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('签名验证失败', $result['message']);
    }

    /**
     * 测试订单号不匹配
     */
    public function testVerifyFailsOnOrderNoMismatch(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->verify(
            ['out_trade_no' => 'O1'],
            ['out_trade_no' => 'O2'],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('订单号不匹配', $result['message']);
    }

    /**
     * 测试本地订单缺少订单号字段
     */
    public function testVerifyFailsOnMissingLocalOrderNo(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->verify([], ['out_trade_no' => 'O1']);

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('缺少订单号字段', $result['message']);
    }

    /**
     * 测试金额不匹配
     */
    public function testVerifyFailsOnAmountMismatch(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->verify(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['out_trade_no' => 'O1', 'total_fee' => 200, 'time_end' => date('YmdHis')],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('金额不匹配', $result['message']);
    }

    /**
     * 测试支付宝元金额转分后匹配（total_amount: 1.00 元 → 100 分）
     */
    public function testVerifyAlipayAmountConversion(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        // 本地订单金额单位为分，支付宝通知金额单位为元
        $result = $verifier->verify(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            [
                'out_trade_no' => 'O1',
                'total_amount' => '1.00',
                'gmt_payment' => date('Y-m-d H:i:s'),
            ],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
    }

    /**
     * 测试时间戳过期（重放攻击）
     */
    public function testVerifyFailsOnExpiredTimestamp(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        // 重放窗口设为 0 秒，任何时间戳都判定过期
        $verifier = new PersonalReceiveVerifier($gateway, replayWindow: 0);

        $result = $verifier->verify(
            ['out_trade_no' => 'O1'],
            ['out_trade_no' => 'O1', 'time_end' => date('YmdHis', time() - 100)],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('重放', $result['message']);
    }

    /**
     * 测试无法提取时间戳时放行（部分网关通知不携带时间字段）
     */
    public function testVerifyPassWithoutTimestamp(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway, replayWindow: 0);

        $result = $verifier->verify(
            ['out_trade_no' => 'O1'],
            ['out_trade_no' => 'O1'],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
    }

    /**
     * 测试进程内监控：首次查询即收款成功
     */
    public function testMonitorInProcessSuccessOnFirstQuery(): void
    {
        $queryResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'SUCCESS',
            'total_fee' => 100,
            'time_end' => date('YmdHis'),
        ];

        $gateway = $this->createGateway(verifyResult: true, queryResult: $queryResult);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->monitorInProcess(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['interval' => 1, 'max_attempts' => 3, 'timeout' => 5],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
    }

    /**
     * 测试进程内监控：状态从 NOTPAY → SUCCESS，第二次查询成功
     */
    public function testMonitorInProcessSuccessOnSecondQuery(): void
    {
        $pendingResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'NOTPAY',
        ];
        $successResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'SUCCESS',
            'total_fee' => 100,
            'time_end' => date('YmdHis'),
        ];

        $gateway = $this->createGateway(
            verifyResult: true,
            querySequence: [$pendingResult, $successResult],
        );

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->monitorInProcess(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['interval' => 1, 'max_attempts' => 3, 'timeout' => 10],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
    }

    /**
     * 测试进程内监控：订单不存在时继续轮询不抛异常
     */
    public function testMonitorInProcessContinuesOnOrderNotFound(): void
    {
        $successResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'SUCCESS',
            'total_fee' => 100,
            'time_end' => date('YmdHis'),
        ];

        $gateway = $this->createGateway(
            verifyResult: true,
            querySequence: [null, $successResult],
        );

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->monitorInProcess(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['interval' => 1, 'max_attempts' => 3, 'timeout' => 10],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
    }

    /**
     * 测试进程内监控：终态失败状态立即返回
     */
    public function testMonitorInProcessFailsOnClosedState(): void
    {
        $closedResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'CLOSED',
        ];

        $gateway = $this->createGateway(verifyResult: true, queryResult: $closedResult);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->monitorInProcess(
            ['out_trade_no' => 'O1'],
            ['interval' => 1, 'max_attempts' => 3, 'timeout' => 10],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('CLOSED', $result['message']);
    }

    /**
     * 测试进程内监控：达到最大轮询次数返回失败
     */
    public function testMonitorInProcessFailsOnMaxAttempts(): void
    {
        $pendingResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'NOTPAY',
        ];

        $gateway = $this->createGateway(verifyResult: true, queryResult: $pendingResult);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->monitorInProcess(
            ['out_trade_no' => 'O1'],
            ['interval' => 1, 'max_attempts' => 2, 'timeout' => 10],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('最大轮询次数', $result['message']);
    }

    /**
     * 测试进程内监控：本地订单缺少订单号抛 PayException
     */
    public function testMonitorInProcessThrowsOnMissingOrderNo(): void
    {
        $gateway = $this->createGateway();

        $verifier = new PersonalReceiveVerifier($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少订单号字段');

        $verifier->monitorInProcess(['total_fee' => 100]);
    }

    /**
     * 测试监控成功回调被触发
     */
    public function testMonitorInProcessTriggersOnSuccessCallback(): void
    {
        $queryResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'SUCCESS',
            'total_fee' => 100,
            'time_end' => date('YmdHis'),
        ];

        $gateway = $this->createGateway(verifyResult: true, queryResult: $queryResult);

        $verifier = new PersonalReceiveVerifier($gateway);

        $called = false;
        $receivedData = null;

        $verifier->monitorInProcess(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['interval' => 1, 'max_attempts' => 3, 'timeout' => 5],
            onSuccess: function (array $data) use (&$called, &$receivedData): void {
                $called = true;
                $receivedData = $data;
            },
        );

        $this->assertTrue($called);
        $this->assertSame('O1', $receivedData['out_trade_no'] ?? '');
    }

    /**
     * 测试后台进程监控：pcntl 不可用时返回 false（不抛异常）
     *
     * 注：本测试在无 pcntl 扩展环境下验证降级行为；有 pcntl 时验证返回值为整数 PID。
     */
    public function testMonitorInBackgroundReturnsPidOrFalse(): void
    {
        $queryResult = [
            'out_trade_no' => 'O1',
            'trade_state' => 'SUCCESS',
            'total_fee' => 100,
            'time_end' => date('YmdHis'),
        ];

        $gateway = $this->createGateway(verifyResult: true, queryResult: $queryResult);

        $verifier = new PersonalReceiveVerifier($gateway);

        $pid = $verifier->monitorInBackground(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['interval' => 1, 'max_attempts' => 1, 'timeout' => 3],
        );

        if ($pid === false) {
            // pcntl 不可用，符合预期
            $this->assertFalse($pid);
        } else {
            // pcntl 可用，回收子进程避免僵尸
            $this->assertIsInt($pid);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * 测试 verifyAndConfirm 成功路径触发 onSuccess 回调
     */
    public function testVerifyAndConfirmTriggersOnSuccess(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        $called = false;

        $verifier->verifyAndConfirm(
            ['out_trade_no' => 'O1', 'total_fee' => 100],
            ['out_trade_no' => 'O1', 'total_fee' => 100, 'time_end' => date('YmdHis')],
            onSuccess: function () use (&$called): void {
                $called = true;
            },
        );

        $this->assertTrue($called);
    }

    /**
     * 测试 verifyAndConfirm 失败路径触发 onFailure 回调
     */
    public function testVerifyAndConfirmTriggersOnFailure(): void
    {
        $gateway = $this->createGateway(verifyResult: false);

        $verifier = new PersonalReceiveVerifier($gateway);

        $called = false;
        $failureMessage = null;

        $verifier->verifyAndConfirm(
            ['out_trade_no' => 'O1'],
            ['out_trade_no' => 'O1'],
            onFailure: function (string $message) use (&$called, &$failureMessage): void {
                $called = true;
                $failureMessage = $message;
            },
        );

        $this->assertTrue($called);
        $this->assertStringContainsString('签名验证失败', $failureMessage ?? '');
    }

    /**
     * 测试 Stripe metadata.out_trade_no 提取
     */
    public function testExtractOrderNoFromStripeMetadata(): void
    {
        $gateway = $this->createGateway(verifyResult: true);

        $verifier = new PersonalReceiveVerifier($gateway);

        $result = $verifier->verify(
            ['out_trade_no' => 'O1', 'amount' => 500],
            [
                'metadata' => ['out_trade_no' => 'O1'],
                'amount' => 500,
                'created' => time(),
            ],
        );

        $this->assertSame(PersonalReceiveVerifier::STATUS_VERIFIED, $result['status']);
    }
}
