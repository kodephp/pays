<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

use Kode\Pays\Contract\GatewayInterface;

/**
 * 统一收款码路由器
 *
 * 生成聚合入口码（一个二维码兼容多支付通道），用户扫码后选择通道，
 * 路由器调用对应网关 `createOrder` 下动态订单，再由 {@see OrderMonitorDaemon}
 * 后台进程持续抓取收款状态并验证订单匹配。
 *
 * 业务流程：
 * 1. 商家调用 {@see createEntry()} 出示统一收款码（静态入口 URL）
 * 2. 用户扫码进入 H5 → 选择通道（微信/支付宝/...）
 * 3. 后端调用 {@see route()} 路由到对应网关下单，返回动态订单二维码
 * 4. 用户扫码/长按支付
 * 5. {@see OrderMonitorDaemon} 后台进程持续 `queryOrder` 抓取状态
 * 6. 收款成功后通过 `PersonalReceiveVerifier` 验证 + 回调通知业务方
 *
 * 注意：本组件走正规商户扫码 API（非静态个人收款码），订单可关联。
 *
 * 使用示例：
 * ```php
 * $router = new UnifiedQrRouter([
 *     'wechat' => ['app_id' => 'wx1', 'mch_id' => 'm1', 'api_key' => 'k1'],
 *     'alipay' => ['app_id' => 'a1', 'private_key' => '...'],
 * ]);
 *
 * // 1. 商家出示统一收款码
 * $entry = $router->createEntry(['wechat', 'alipay'], 100, '商品付款');
 * // $entry['qr_content'] 用于渲染二维码图片
 *
 * // 2. 用户扫码选通道后，后端路由下单
 * $order = $router->route($entry['router_id'], 'wechat');
 * // $order['code_url'] 是微信 Native 扫码支付链接
 * ```
 */
class UnifiedQrRouter
{
    /** 入口状态：待支付 */
    public const string STATUS_PENDING = 'pending';

    /** 入口状态：已下单（用户已选通道并生成动态订单码） */
    public const string STATUS_ORDERED = 'ordered';

    /** 入口状态：已支付 */
    public const string STATUS_PAID = 'paid';

    /** 入口状态：已关闭/失败 */
    public const string STATUS_CLOSED = 'closed';

    /** 统一入口 URL 前缀（业务方可重写为自有域名） */
    protected const string ENTRY_URL_PREFIX = 'https://pay.kodephp.com/r/';

    /**
     * 待处理入口注册表（router_id → entry 数据）
     *
     * 生产环境应替换为持久化存储（Redis/DB），此处内存实现仅适用于单进程。
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $entries = [];

    /**
     * 构造函数
     *
     * @param array<string, array<string, mixed>> $gatewayConfigs 各通道网关配置，key 为通道标识
     *        例如 ['wechat' => [...], 'alipay' => [...]]
     * @param \Kode\Pays\Support\HttpClient|null $httpClient 自定义 HTTP 客户端（测试注入用）
     * @param string|null $entryUrlPrefix 自有域名入口前缀（默认使用内置）
     */
    public function __construct(
        protected readonly array $gatewayConfigs = [],
        protected readonly ?\Kode\Pays\Support\HttpClient $httpClient = null,
        protected readonly ?string $entryUrlPrefix = null,
    ) {
    }

    /**
     * 创建统一收款入口
     *
     * 生成 router_id 与入口 URL，商家将其渲染为二维码出示给用户扫码。
     *
     * @param array<int, string> $channels 支持的通道标识列表，如 ['wechat', 'alipay']
     * @param int $amount 收款金额（分）
     * @param string $description 收款说明
     * @param array<string, mixed>|null $attach 附加数据（原样存储，便于业务关联）
     * @return array{router_id: string, entry_url: string, qr_content: string, amount: int, channels: array<int, string>}
     * @throws PayException 当 channels 为空或包含未配置的通道时
     */
    public function createEntry(array $channels, int $amount, string $description, ?array $attach = null): array
    {
        if ($channels === []) {
            throw PayException::paramError('channels 不能为空');
        }

        // 校验所有通道均已配置
        foreach ($channels as $channel) {
            if (!isset($this->gatewayConfigs[$channel])) {
                throw PayException::configError("通道 {$channel} 未在 gatewayConfigs 中配置");
            }
        }

        if ($amount <= 0) {
            throw PayException::paramError('amount 必须大于 0');
        }

        $routerId = $this->generateRouterId();

        $this->entries[$routerId] = [
            'router_id' => $routerId,
            'channels' => array_values($channels),
            'amount' => $amount,
            'description' => $description,
            'attach' => $attach,
            'status' => self::STATUS_PENDING,
            'channel' => null,
            'out_trade_no' => null,
            'pay_url' => null,
            'created_at' => time(),
            'paid_at' => null,
        ];

        $entryUrl = $this->buildEntryUrl($routerId);

        return [
            'router_id' => $routerId,
            'entry_url' => $entryUrl,
            'qr_content' => $entryUrl,
            'amount' => $amount,
            'channels' => array_values($channels),
        ];
    }

    /**
     * 路由到具体通道下单
     *
     * 用户扫码进入 H5 选择通道后调用，由路由器调用对应网关 createOrder 生成动态订单码。
     *
     * @param string $routerId 统一入口 ID
     * @param string $channel 用户选择的通道标识
     * @return array{out_trade_no: string, pay_url: string, code_url: string, channel: string, amount: int}
     * @throws PayException 入口不存在/已下单/通道不在允许列表/下单失败
     */
    public function route(string $routerId, string $channel): array
    {
        $entry = $this->getEntry($routerId);

        if ($entry === null) {
            throw PayException::orderNotFound("统一收款入口不存在：{$routerId}");
        }

        if ($entry['status'] === self::STATUS_PAID) {
            throw PayException::gatewayError('入口已支付完成，无法重复下单');
        }

        if ($entry['status'] === self::STATUS_ORDERED) {
            // 已下单，返回已有订单信息（幂等）
            return [
                'out_trade_no' => (string) $entry['out_trade_no'],
                'pay_url' => (string) $entry['pay_url'],
                'code_url' => (string) $entry['pay_url'],
                'channel' => (string) $entry['channel'],
                'amount' => (int) $entry['amount'],
            ];
        }

        if (!in_array($channel, $entry['channels'], true)) {
            throw PayException::paramError("通道 {$channel} 不在该入口允许列表中");
        }

        // 创建网关实例
        $gateway = $this->createGateway($channel);

        // 调用网关下单
        $orderParams = [
            'out_trade_no' => $this->generateOrderNo($routerId),
            'total_fee' => $entry['amount'],
            'body' => $entry['description'],
            'attach' => $entry['attach'] !== null ? json_encode($entry['attach'], JSON_UNESCAPED_UNICODE) : null,
        ];

        $orderResult = $gateway->createOrder($orderParams);

        // 提取支付链接（兼容微信 code_url、支付宝 qr_code、Stripe payment_link 等）
        $payUrl = $orderResult['code_url']
            ?? $orderResult['qr_code']
            ?? $orderResult['pay_url']
            ?? $orderResult['payment_link']
            ?? '';

        // 更新入口状态
        $this->entries[$routerId]['status'] = self::STATUS_ORDERED;
        $this->entries[$routerId]['channel'] = $channel;
        $this->entries[$routerId]['out_trade_no'] = $orderParams['out_trade_no'];
        $this->entries[$routerId]['pay_url'] = $payUrl;

        return [
            'out_trade_no' => $orderParams['out_trade_no'],
            'pay_url' => $payUrl,
            'code_url' => $payUrl,
            'channel' => $channel,
            'amount' => $entry['amount'],
        ];
    }

    /**
     * 查询入口当前状态
     *
     * @param string $routerId
     * @return array<string, mixed>|null 入口数据或 null（不存在）
     */
    public function getStatus(string $routerId): ?array
    {
        return $this->getEntry($routerId);
    }

    /**
     * 标记入口已支付（由 OrderMonitorDaemon 验证通过后调用）
     *
     * @param string $routerId
     * @param array<string, mixed> $paymentData 支付数据
     * @return bool
     */
    public function markPaid(string $routerId, array $paymentData = []): bool
    {
        if (!isset($this->entries[$routerId])) {
            return false;
        }

        $this->entries[$routerId]['status'] = self::STATUS_PAID;
        $this->entries[$routerId]['paid_at'] = time();
        $this->entries[$routerId]['payment_data'] = $paymentData;

        return true;
    }

    /**
     * 标记入口已关闭
     *
     * @param string $routerId
     * @return bool
     */
    public function markClosed(string $routerId): bool
    {
        if (!isset($this->entries[$routerId])) {
            return false;
        }

        $this->entries[$routerId]['status'] = self::STATUS_CLOSED;

        return true;
    }

    /**
     * 获取所有未完成（非 paid/closed）的入口
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPendingEntries(): array
    {
        return array_filter(
            $this->entries,
            static fn (array $entry): bool => !in_array($entry['status'], [self::STATUS_PAID, self::STATUS_CLOSED], true),
        );
    }

    /**
     * 获取入口数据
     *
     * @param string $routerId
     * @return array<string, mixed>|null
     */
    public function getEntry(string $routerId): ?array
    {
        return $this->entries[$routerId] ?? null;
    }

    /**
     * 创建网关实例
     *
     * 子类可重写以支持自定义网关解析（如从容器获取）
     *
     * @param string $channel
     * @return GatewayInterface
     * @throws PayException
     */
    protected function createGateway(string $channel): GatewayInterface
    {
        $config = $this->gatewayConfigs[$channel] ?? null;

        if ($config === null) {
            throw PayException::configError("通道 {$channel} 未配置");
        }

        return GatewayFactory::create($channel, $config, $this->httpClient);
    }

    /**
     * 生成统一入口 ID（UR + 时间戳 base36 + 随机数）
     */
    protected function generateRouterId(): string
    {
        return 'UR' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * 生成商户订单号
     *
     * @param string $routerId
     */
    protected function generateOrderNo(string $routerId): string
    {
        return 'UO' . date('YmdHis') . substr($routerId, -6) . random_int(100, 999);
    }

    /**
     * 构造入口 URL
     */
    protected function buildEntryUrl(string $routerId): string
    {
        $prefix = $this->entryUrlPrefix ?? self::ENTRY_URL_PREFIX;

        return $prefix . $routerId;
    }
}
