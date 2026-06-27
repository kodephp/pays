<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\GatewayInterface;

/**
 * 订单监控守护进程
 *
 * 在独立进程内持续轮询多笔订单的支付状态，匹配成功后回调业务方并通知
 * {@see UnifiedQrRouter} 标记入口已支付。
 *
 * 这是用户强调"需要另外进程一直获取状态"的核心组件：所有待监控订单注册到
 * 守护进程后，由守护进程统一调度 queryOrder 抓取，避免每笔订单各自 fork
 * 子进程导致资源浪费与状态管理混乱。
 *
 * 工作流程：
 * 1. 业务方调用 {@see UnifiedQrRouter::route()} 下单后，调用 {@see register()} 注册监控
 * 2. 业务方调用 {@see run()}（前台）或 {@see runInBackground()}（后台）启动守护进程
 * 3. 守护进程按 interval 周期扫描所有待监控订单，逐一调用网关 queryOrder
 * 4. 抓取到终态（成功/失败）后调用对应回调并移除监控
 * 5. 单笔订单达到 max_attempts 或超过 timeout 自动关闭并触发 onTimeout 回调
 *
 * 注意：
 * - 本进程仅负责轮询抓取与回调触发，订单状态验证由 PersonalReceiveVerifier 完成
 * - 生产环境推荐通过 supervisor / systemd 托管，而非裸跑 pcntl_fork
 * - 进程内内存注册表仅适用于单进程；多进程需替换为 Redis 队列实现
 *
 * 使用示例：
 * ```php
 * $daemon = new OrderMonitorDaemon($router);
 *
 * // 业务方在路由下单后注册
 * $daemon->register('UR202606271234ABC', 'wechat', $orderData, [
 *     'interval' => 5,
 *     'timeout' => 600,
 *     'on_success' => function ($paymentData) use ($routerId) {
 *         // 通知业务系统、发货、发短信...
 *     },
 * ]);
 *
 * // 启动守护进程（前台阻塞或后台 fork）
 * $pid = $daemon->runInBackground();
 * // 或前台：$daemon->run();
 * ```
 */
class OrderMonitorDaemon
{
    /** 监控状态：注册待开始 */
    public const string STATE_PENDING = 'pending';

    /** 监控状态：运行中 */
    public const string STATE_RUNNING = 'running';

    /** 监控状态：已停止 */
    public const string STATE_STOPPED = 'stopped';

    /** 默认轮询间隔（秒） */
    protected const int DEFAULT_INTERVAL = 5;

    /** 默认单笔订单超时（秒） */
    protected const int DEFAULT_TIMEOUT = 600;

    /** 默认最大重试次数 */
    protected const int DEFAULT_MAX_ATTEMPTS = 120;

    /** 终态成功状态集合（各网关通用） */
    protected const array SUCCESS_STATES = ['SUCCESS', 'TRADE_SUCCESS', 'succeeded', 'paid', 'completed'];

    /** 终态失败状态集合 */
    protected const array FAILED_STATES = ['CLOSED', 'REVOKED', 'PAYERROR', 'FAILED', 'canceled', 'expired'];

    /**
     * 待监控订单注册表（router_id → 监控配置）
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $monitors = [];

    /**
     * 守护进程运行状态
     */
    protected string $state = self::STATE_PENDING;

    /**
     * 构造函数
     *
     * @param UnifiedQrRouter|null $router 统一收款码路由器（用于在收款成功后自动标记入口已支付）
     * @param array<string, GatewayInterface>|null $gatewayCache 预创建的网关实例缓存，key 为通道标识
     */
    public function __construct(
        protected readonly ?UnifiedQrRouter $router = null,
        protected ?array $gatewayCache = null,
    ) {
    }

    /**
     * 注册一笔待监控订单
     *
     * @param string $routerId 统一收款入口 ID（业务关联用）
     * @param string $channel 通道标识（如 wechat / alipay）
     * @param array<string, mixed> $order 本地订单数据，至少包含 out_trade_no 与金额
     * @param array{interval?: int, timeout?: int, max_attempts?: int, on_success?: callable, on_failure?: callable, on_timeout?: callable} $options
     *        interval 轮询间隔秒；timeout 总超时秒；max_attempts 最大重试次数；
     *        on_success 成功回调（参数：$paymentData, $routerId）；
     *        on_failure 失败回调（参数：$reason, $paymentData, $routerId）；
     *        on_timeout 超时回调（参数：$lastData, $routerId）
     * @throws PayException 当 routerId 已注册或缺少 out_trade_no 时
     */
    public function register(string $routerId, string $channel, array $order, array $options = []): void
    {
        if (isset($this->monitors[$routerId])) {
            throw PayException::paramError("监控任务已存在：{$routerId}");
        }

        $orderNo = (string) ($order['out_trade_no'] ?? $order['order_id'] ?? '');

        if ($orderNo === '') {
            throw PayException::paramError('订单数据缺少 out_trade_no / order_id 字段');
        }

        $this->monitors[$routerId] = [
            'router_id' => $routerId,
            'channel' => $channel,
            'order' => $order,
            'order_no' => $orderNo,
            'interval' => max(1, (int) ($options['interval'] ?? self::DEFAULT_INTERVAL)),
            'timeout' => max(1, (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT)),
            'max_attempts' => max(1, (int) ($options['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS)),
            'on_success' => $options['on_success'] ?? null,
            'on_failure' => $options['on_failure'] ?? null,
            'on_timeout' => $options['on_timeout'] ?? null,
            'attempts' => 0,
            'last_data' => null,
            'last_query_at' => 0,
            'registered_at' => time(),
        ];
    }

    /**
     * 注销监控任务（停止监控指定订单）
     *
     * @param string $routerId
     * @return bool 是否成功移除
     */
    public function unregister(string $routerId): bool
    {
        if (!isset($this->monitors[$routerId])) {
            return false;
        }

        unset($this->monitors[$routerId]);

        return true;
    }

    /**
     * 获取所有待监控订单列表
     *
     * @return array<string, array<string, mixed>>
     */
    public function getMonitors(): array
    {
        return $this->monitors;
    }

    /**
     * 获取监控统计摘要（用于监控面板/健康检查）
     *
     * @return array{total: int, running: bool, channels: array<string, int>, total_attempts: int}
     */
    public function getStats(): array
    {
        $channels = [];
        $totalAttempts = 0;

        foreach ($this->monitors as $monitor) {
            $channel = (string) $monitor['channel'];
            $channels[$channel] = ($channels[$channel] ?? 0) + 1;
            $totalAttempts += (int) $monitor['attempts'];
        }

        return [
            'total' => count($this->monitors),
            'running' => $this->state === self::STATE_RUNNING,
            'channels' => $channels,
            'total_attempts' => $totalAttempts,
        ];
    }

    /**
     * 清空所有监控任务（用于优雅停机/重启）
     *
     * @return int 清空前的任务数量
     */
    public function clear(): int
    {
        $count = count($this->monitors);
        $this->monitors = [];

        return $count;
    }

    /**
     * 获取单笔订单的监控状态
     *
     * @param string $routerId
     * @return array<string, mixed>|null
     */
    public function getMonitor(string $routerId): ?array
    {
        return $this->monitors[$routerId] ?? null;
    }

    /**
     * 前台运行守护进程（阻塞当前进程）
     *
     * 持续扫描所有注册的订单，直到所有监控任务完成或调用 stop()。
     *
     * @param array{max_loops?: int, idle_sleep?: int} $options
     *        max_loops 最大循环次数（0 = 无限，默认 0）；
     *        idle_sleep 无监控任务时的休眠秒数（默认 5）
     * @return array{completed: int, succeeded: int, failed: int, timed_out: int}
     *         完成统计
     * @throws PayException
     */
    public function run(array $options = []): array
    {
        $maxLoops = max(0, (int) ($options['max_loops'] ?? 0));
        $idleSleep = max(1, (int) ($options['idle_sleep'] ?? 5));

        $this->state = self::STATE_RUNNING;
        $stats = ['completed' => 0, 'succeeded' => 0, 'failed' => 0, 'timed_out' => 0];
        $loop = 0;

        while ($this->state === self::STATE_RUNNING) {
            if ($maxLoops > 0 && $loop >= $maxLoops) {
                break;
            }

            $loop++;

            if ($this->monitors === []) {
                sleep($idleSleep);
                continue;
            }

            $this->scanOnce($stats);

            // 若所有任务完成且无新任务，可退出（但守护进程通常应持续等待新任务）
            if ($this->monitors === [] && $maxLoops === 0) {
                // 默认行为：所有任务完成后退出
                break;
            }
        }

        $this->state = self::STATE_STOPPED;

        return $stats;
    }

    /**
     * 后台运行守护进程（pcntl_fork，需 pcntl 扩展）
     *
     * 父进程立即返回子进程 PID；pcntl 不可用或 fork 失败返回 false。
     * 子进程会一直运行直到所有任务完成。
     *
     * @param array{max_loops?: int, idle_sleep?: int} $options
     * @return int|false 子进程 PID 或 false
     */
    public function runInBackground(array $options = []): int|false
    {
        if (!function_exists('pcntl_fork')) {
            return false;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            // 子进程
            try {
                $this->run($options);
            } catch (\Throwable) {
                exit(1);
            }

            exit(0);
        }

        return $pid;
    }

    /**
     * 停止守护进程（让 run() 在下次循环退出）
     */
    public function stop(): void
    {
        $this->state = self::STATE_STOPPED;
    }

    /**
     * 单次扫描：对所有到期订单执行一次 queryOrder
     *
     * 暴露为 public 便于业务方自行驱动（如外部定时任务调度）。
     *
     * @param array{completed: int, succeeded: int, failed: int, timed_out: int}|null $stats 统计引用
     * @return int 本次扫描处理的订单数
     * @throws PayException
     */
    public function scanOnce(?array &$stats = null): int
    {
        $stats ??= ['completed' => 0, 'succeeded' => 0, 'failed' => 0, 'timed_out' => 0];

        $processed = 0;
        $now = time();

        foreach ($this->monitors as $routerId => $monitor) {
            // 判断是否到期轮询
            if ($now - $monitor['last_query_at'] < $monitor['interval']) {
                continue;
            }

            $processed++;

            // 检查总超时
            if ($now - $monitor['registered_at'] >= $monitor['timeout']) {
                $this->handleTimeout($routerId, $monitor, $stats);
                continue;
            }

            // 检查最大重试次数
            if ($monitor['attempts'] >= $monitor['max_attempts']) {
                $this->handleTimeout($routerId, $monitor, $stats);
                continue;
            }

            $this->monitors[$routerId]['attempts']++;
            $this->monitors[$routerId]['last_query_at'] = $now;

            try {
                $gateway = $this->getGateway((string) $monitor['channel']);
                $queryResult = $gateway->queryOrder((string) $monitor['order_no']);
                $this->monitors[$routerId]['last_data'] = $queryResult;

                $status = $this->extractStatus($queryResult);

                if (in_array($status, self::SUCCESS_STATES, true)) {
                    $this->handleSuccess($routerId, $monitor, $queryResult, $stats);
                } elseif (in_array($status, self::FAILED_STATES, true)) {
                    $this->handleFailure($routerId, $monitor, "收款失败：状态 {$status}", $queryResult, $stats);
                }
                // 非终态继续下一轮
            } catch (PayException $e) {
                // 订单不存在视为未生效，继续等待（与 PersonalReceiveVerifier 行为一致）
                if ($e->getCode() === PayException::ERROR_ORDER_NOT_FOUND) {
                    continue;
                }

                // 其他异常视为查询失败，记录但不中断守护进程
                $this->monitors[$routerId]['last_data'] = ['_error' => $e->getMessage()];
            }
        }

        return $processed;
    }

    /**
     * 处理收款成功
     *
     * @param string $routerId
     * @param array<string, mixed> $monitor
     * @param array<string, mixed> $paymentData
     * @param array{completed: int, succeeded: int, failed: int, timed_out: int} $stats
     */
    protected function handleSuccess(string $routerId, array $monitor, array $paymentData, array &$stats): void
    {
        $stats['completed']++;
        $stats['succeeded']++;

        // 自动标记统一收款入口已支付
        $this->router?->markPaid($routerId, $paymentData);

        // 触发业务回调
        if (isset($monitor['on_success']) && is_callable($monitor['on_success'])) {
            ($monitor['on_success'])($paymentData, $routerId);
        }

        unset($this->monitors[$routerId]);
    }

    /**
     * 处理收款失败
     *
     * @param string $routerId
     * @param array<string, mixed> $monitor
     * @param string $reason
     * @param array<string, mixed> $data
     * @param array{completed: int, succeeded: int, failed: int, timed_out: int} $stats
     */
    protected function handleFailure(string $routerId, array $monitor, string $reason, array $data, array &$stats): void
    {
        $stats['completed']++;
        $stats['failed']++;

        $this->router?->markClosed($routerId);

        if (isset($monitor['on_failure']) && is_callable($monitor['on_failure'])) {
            ($monitor['on_failure'])($reason, $data, $routerId);
        }

        unset($this->monitors[$routerId]);
    }

    /**
     * 处理超时
     *
     * @param string $routerId
     * @param array<string, mixed> $monitor
     * @param array{completed: int, succeeded: int, failed: int, timed_out: int} $stats
     */
    protected function handleTimeout(string $routerId, array $monitor, array &$stats): void
    {
        $stats['completed']++;
        $stats['timed_out']++;

        $this->router?->markClosed($routerId);

        if (isset($monitor['on_timeout']) && is_callable($monitor['on_timeout'])) {
            ($monitor['on_timeout'])($monitor['last_data'], $routerId);
        }

        unset($this->monitors[$routerId]);
    }

    /**
     * 获取网关实例（带缓存）
     *
     * @param string $channel
     * @return GatewayInterface
     * @throws PayException
     */
    protected function getGateway(string $channel): GatewayInterface
    {
        if (isset($this->gatewayCache[$channel])) {
            return $this->gatewayCache[$channel];
        }

        // 通过 UnifiedQrRouter 间接创建网关（复用其配置与 HttpClient）
        if ($this->router !== null) {
            // 反射调用 protected createGateway() 复用路由器内置的配置与 HttpClient
            $ref = new \ReflectionMethod($this->router, 'createGateway');
            $gateway = $ref->invoke($this->router, $channel);

            $this->gatewayCache[$channel] = $gateway;

            return $gateway;
        }

        throw PayException::configError("无法获取通道 {$channel} 的网关实例：缺少 router 与 gatewayCache");
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
}
