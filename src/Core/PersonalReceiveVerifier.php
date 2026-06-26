<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\GatewayInterface;

/**
 * 个人收款订单验证器
 *
 * 在进程内或后台子进程中抓取/轮询收款数据，验证收款是否与本地订单匹配。
 *
 * 验证维度：
 * 1. 签名验证（调用网关 verifyNotify）
 * 2. 订单号匹配（out_trade_no / order_id）
 * 3. 金额匹配（total_fee / total_amount / amount）
 * 4. 时间戳防重放（默认 5 分钟窗口）
 *
 * 三种使用方式：
 * - {@see verify()}：对给定的异步通知数据做一次性校验
 * - {@see monitorInProcess()}：进程内轮询抓取收款结果（阻塞当前进程）
 * - {@see monitorInBackground()}：pcntl_fork 后台进程持续监控（父进程立即返回）
 *
 * 使用示例：
 * ```php
 * $verifier = new PersonalReceiveVerifier($gateway, $idempotencyGuard);
 *
 * // 方式一：验证异步通知
 * $result = $verifier->verify($order, $_POST);
 *
 * // 方式二：进程内轮询监控
 * $result = $verifier->monitorInProcess($order, ['interval' => 3, 'timeout' => 60]);
 *
 * // 方式三：后台进程监控
 * $pid = $verifier->monitorInBackground($order);
 * ```
 */
class PersonalReceiveVerifier
{
    /** 验证状态：通过 */
    public const string STATUS_VERIFIED = 'verified';

    /** 验证状态：失败 */
    public const string STATUS_FAILED = 'failed';

    /** 验证状态：超时 */
    public const string STATUS_TIMEOUT = 'timeout';

    /** 默认重放窗口（秒） */
    protected const int DEFAULT_REPLAY_WINDOW = 300;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关实例
     * @param IdempotencyGuard|null $guard 幂等保护器（可选，防止重复确认）
     * @param int $replayWindow 通知时间戳有效窗口（秒），默认 300
     */
    public function __construct(
        protected readonly GatewayInterface $gateway,
        protected readonly ?IdempotencyGuard $guard = null,
        protected readonly int $replayWindow = self::DEFAULT_REPLAY_WINDOW,
    ) {
    }

    /**
     * 验证收款数据是否与本地订单匹配（一次性校验，不轮询）
     *
     * @param array<string, mixed> $order 本地订单数据，需包含 out_trade_no 与金额
     * @param array<string, mixed> $receivedData 收到的通知数据或查询返回数据
     * @return array{status: string, message: string, data: array<string, mixed>}
     * @throws PayException
     */
    public function verify(array $order, array $receivedData): array
    {
        // 1. 签名验证（由网关实现具体算法）
        if (!$this->gateway->verifyNotify($receivedData)) {
            return $this->fail('签名验证失败', $receivedData);
        }

        // 2. 订单号匹配
        $expectedOrderNo = $this->extractOrderNo($order);
        $receivedOrderNo = $this->extractOrderNo($receivedData);

        if ($expectedOrderNo === '') {
            return $this->fail('本地订单缺少订单号字段', $receivedData);
        }

        if ($receivedOrderNo === '' || strcasecmp($expectedOrderNo, $receivedOrderNo) !== 0) {
            return $this->fail(
                "订单号不匹配：期望 {$expectedOrderNo}，实际 {$receivedOrderNo}",
                $receivedData,
            );
        }

        // 3. 金额匹配（若本地订单声明了金额，则必须一致）
        $expectedAmount = $this->extractAmount($order);
        $receivedAmount = $this->extractAmount($receivedData);

        if ($expectedAmount !== null && $receivedAmount !== null && $expectedAmount !== $receivedAmount) {
            return $this->fail(
                "金额不匹配：期望 {$expectedAmount}，实际 {$receivedAmount}",
                $receivedData,
            );
        }

        // 4. 时间戳防重放
        if (!$this->checkTimestamp($receivedData)) {
            return $this->fail('通知时间戳超出有效窗口，可能为重放攻击', $receivedData);
        }

        return $this->success($receivedData);
    }

    /**
     * 验证并确认收款（带幂等保护与回调）
     *
     * @param array<string, mixed> $order 本地订单数据
     * @param array<string, mixed> $receivedData 收到的通知数据
     * @param callable|null $onSuccess 验证通过回调，参数为收款数据
     * @param callable|null $onFailure 验证失败回调，参数为失败信息
     * @return array{status: string, message: string, data: array<string, mixed>}
     * @throws PayException
     */
    public function verifyAndConfirm(
        array $order,
        array $receivedData,
        ?callable $onSuccess = null,
        ?callable $onFailure = null,
    ): array {
        $orderNo = $this->extractOrderNo($order);

        // 幂等检查
        if ($this->guard !== null && $orderNo !== '') {
            if ($this->guard->isSuccess($orderNo)) {
                return $this->fail('订单已处理成功，无需重复确认', $receivedData);
            }

            if (!$this->guard->acquire($orderNo)) {
                return $this->fail('订单正在处理中', $receivedData);
            }
        }

        try {
            $result = $this->verify($order, $receivedData);

            if ($result['status'] === self::STATUS_VERIFIED) {
                $this->guard?->markSuccess($orderNo, $result['data']);

                if ($onSuccess !== null) {
                    $onSuccess($result['data']);
                }
            } else {
                $this->guard?->markFailed($orderNo, $result['message']);

                if ($onFailure !== null) {
                    $onFailure($result['message']);
                }
            }

            return $result;
        } finally {
            $this->guard?->release($orderNo);
        }
    }

    /**
     * 进程内轮询抓取收款数据并验证（阻塞当前进程）
     *
     * 持续调用网关 queryOrder 抓取最新收款状态，匹配后验证数据一致性。
     *
     * @param array<string, mixed> $order 本地订单数据
     * @param array{interval?: int, max_attempts?: int, timeout?: int} $options 轮询选项
     * @param callable|null $onSuccess 收款成功回调
     * @param callable|null $onFailure 收款失败/超时回调
     * @return array{status: string, message: string, data: array<string, mixed>}
     * @throws PayException
     */
    public function monitorInProcess(
        array $order,
        array $options = [],
        ?callable $onSuccess = null,
        ?callable $onFailure = null,
    ): array {
        $interval = max(1, (int) ($options['interval'] ?? 3));
        $maxAttempts = max(1, (int) ($options['max_attempts'] ?? 20));
        $timeout = max(1, (int) ($options['timeout'] ?? 60));

        $orderNo = $this->extractOrderNo($order);

        if ($orderNo === '') {
            throw PayException::paramError('本地订单缺少订单号字段，无法轮询');
        }

        $startTime = time();
        $lastResult = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // 检查总超时
            if (time() - $startTime >= $timeout) {
                $result = $this->fail('监控超时', ['attempts' => $attempt - 1, 'last_result' => $lastResult]);

                $onFailure !== null and $onFailure(self::STATUS_TIMEOUT, $result);

                return $result;
            }

            try {
                $queryResult = $this->gateway->queryOrder($orderNo);
                $lastResult = $queryResult;

                $status = $this->extractStatus($queryResult);

                // 收款成功，验证数据匹配
                if (in_array($status, ['SUCCESS', 'TRADE_SUCCESS', 'succeeded', 'paid'], true)) {
                    $verifyResult = $this->verify($order, $queryResult);

                    if ($verifyResult['status'] === self::STATUS_VERIFIED) {
                        $onSuccess !== null and $onSuccess($queryResult);

                        return $verifyResult;
                    }

                    // 数据不匹配，视为失败
                    $onFailure !== null and $onFailure('mismatch', $verifyResult);

                    return $verifyResult;
                }

                // 终态失败状态，停止轮询
                if (in_array($status, ['CLOSED', 'REVOKED', 'PAYERROR', 'FAILED', 'canceled'], true)) {
                    $result = $this->fail("收款失败：状态 {$status}", $queryResult);

                    $onFailure !== null and $onFailure($status, $result);

                    return $result;
                }
            } catch (PayException $e) {
                // 订单不存在可能意味着还未创建成功，继续轮询
                if ($e->getCode() !== PayException::ERROR_ORDER_NOT_FOUND) {
                    throw $e;
                }
            }

            // 最后一次不等待
            if ($attempt < $maxAttempts) {
                sleep($interval);
            }
        }

        $result = $this->fail('达到最大轮询次数', ['attempts' => $maxAttempts, 'last_result' => $lastResult]);

        $onFailure !== null and $onFailure('max_attempts', $result);

        return $result;
    }

    /**
     * 后台进程监控（pcntl_fork，需 pcntl 扩展）
     *
     * 父进程立即返回子进程 PID，子进程在后台持续监控收款状态。
     * 适用于 Web 请求中需要立即响应客户端、后台异步确认收款的场景。
     *
     * @param array<string, mixed> $order 本地订单数据
     * @param array{interval?: int, max_attempts?: int, timeout?: int} $options 轮询选项
     * @param callable|null $onSuccess 收款成功回调（在子进程执行）
     * @param callable|null $onFailure 收款失败回调（在子进程执行）
     * @return int|false 返回子进程 PID；pcntl 不可用或 fork 失败返回 false
     * @throws PayException 当 pcntl 不可用时不抛异常，返回 false
     */
    public function monitorInBackground(
        array $order,
        array $options = [],
        ?callable $onSuccess = null,
        ?callable $onFailure = null,
    ): int|false {
        if (!function_exists('pcntl_fork')) {
            return false;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            // 子进程：持续监控
            try {
                $this->monitorInProcess($order, $options, $onSuccess, $onFailure);
            } catch (\Throwable $e) {
                // 子进程异常不应影响父进程
                exit(1);
            }

            exit(0);
        }

        // 父进程：返回子进程 PID，立即继续执行
        return $pid;
    }

    /**
     * 从订单/通知数据中提取订单号
     *
     * 兼容微信（out_trade_no）、支付宝（out_trade_no）、Stripe（metadata.out_trade_no / id）
     *
     * @param array<string, mixed> $data
     */
    protected function extractOrderNo(array $data): string
    {
        if (isset($data['out_trade_no']) && is_string($data['out_trade_no'])) {
            return $data['out_trade_no'];
        }

        if (isset($data['order_id']) && is_string($data['order_id'])) {
            return $data['order_id'];
        }

        if (isset($data['metadata']['out_trade_no']) && is_string($data['metadata']['out_trade_no'])) {
            return $data['metadata']['out_trade_no'];
        }

        if (isset($data['partner_trade_no']) && is_string($data['partner_trade_no'])) {
            return $data['partner_trade_no'];
        }

        return '';
    }

    /**
     * 从订单/通知数据中提取金额（统一为最小货币单位整数）
     *
     * 兼容微信（total_fee，单位分）、支付宝（total_amount，单位元需 ×100）、Stripe（amount，单位分）
     *
     * @param array<string, mixed> $data
     * @return int|null 金额（分）或 null（无法识别）
     */
    protected function extractAmount(array $data): ?int
    {
        // 微信：total_fee（分）
        if (isset($data['total_fee'])) {
            return (int) $data['total_fee'];
        }

        // 支付宝：total_amount（元，字符串浮点）
        if (isset($data['total_amount'])) {
            return (int) round(((float) $data['total_amount']) * 100);
        }

        // Stripe：amount（分）
        if (isset($data['amount'])) {
            return (int) $data['amount'];
        }

        // 通知中可能用 settlement_amount / buyer_pay_amount
        if (isset($data['settlement_amount'])) {
            return (int) $data['settlement_amount'];
        }

        return null;
    }

    /**
     * 从查询结果中提取支付状态
     *
     * 兼容各网关状态字段：trade_state / trade_status / status / state
     *
     * @param array<string, mixed> $result
     */
    protected function extractStatus(array $result): string
    {
        return (string) (
            $result['trade_state']
            ?? $result['trade_status']
            ?? $result['status']
            ?? $result['state']
            ?? 'UNKNOWN'
        );
    }

    /**
     * 检查通知时间戳是否在有效窗口内（防重放）
     *
     * 兼容微信（time_end，yyyyMMddHHmmss）、支付宝（notify_time / gmt_payment）、Stripe（created）
     *
     * @param array<string, mixed> $data
     */
    protected function checkTimestamp(array $data): bool
    {
        $timestamp = $this->extractTimestamp($data);

        if ($timestamp === null) {
            // 无法提取时间戳时放行（部分网关通知不携带时间）
            return true;
        }

        return abs(time() - $timestamp) <= $this->replayWindow;
    }

    /**
     * 提取通知时间戳（Unix 秒）
     *
     * @param array<string, mixed> $data
     * @return int|null
     */
    protected function extractTimestamp(array $data): ?int
    {
        // 微信 time_end：yyyyMMddHHmmss
        if (isset($data['time_end']) && is_string($data['time_end'])) {
            $dt = \DateTime::createFromFormat('YmdHis', $data['time_end']);

            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }

        // Unix 时间戳
        if (isset($data['timestamp']) && is_numeric($data['timestamp'])) {
            return (int) $data['timestamp'];
        }

        // Stripe created
        if (isset($data['created']) && is_numeric($data['created'])) {
            return (int) $data['created'];
        }

        // 支付宝 gmt_payment / notify_time（Y-m-d H:i:s）
        foreach (['gmt_payment', 'notify_time', 'gmt_create'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $data[$field]);

                if ($dt !== false) {
                    return $dt->getTimestamp();
                }
            }
        }

        return null;
    }

    /**
     * 构造成功结果
     *
     * @param array<string, mixed> $data
     * @return array{status: string, message: string, data: array<string, mixed>}
     */
    protected function success(array $data): array
    {
        return [
            'status' => self::STATUS_VERIFIED,
            'message' => '验证通过',
            'data' => $data,
        ];
    }

    /**
     * 构造失败结果
     *
     * @param string $message
     * @param array<string, mixed> $data
     * @return array{status: string, message: string, data: array<string, mixed>}
     */
    protected function fail(string $message, array $data = []): array
    {
        return [
            'status' => self::STATUS_FAILED,
            'message' => $message,
            'data' => $data,
        ];
    }
}
