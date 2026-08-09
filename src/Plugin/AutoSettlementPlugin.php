<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\SettlementCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\WalletManager;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 自动结算插件
 *
 * 支付成功后自动将资金结算到用户绑定的钱包账户。
 * 支持实时结算、定时结算、按金额阈值结算等多种模式。
 * 自动关联对应渠道：微信支付→微信零钱、支付宝→支付宝余额、银行卡→银行卡转账。
 *
 * 设计说明：各平台的结算报文组装、签名与端点已下沉到网关原生方法（网关声明
 * {@see SettlementCapableInterface}），本插件只承担「编排」职责：
 * 1. 通过 {@see WalletManager} 判定结算条件与目标账户；
 * 2. 把领域语义的结算目标类型（钱包 / 银行卡 / 外部账户）映射到网关能力方法；
 * 3. 归一化结算结果、附加业务上下文并触发回调。
 *
 * 插件内不再存在任何 `match($gateway::getName())` 平台内联分支，也不再通过反射
 * 读取网关私有配置。未实现 {@see SettlementCapableInterface} 的网关调用结算方法
 * 会统一报「无此方法」，平台不支持的结算语义由网关自身抛出同类异常。
 *
 * 使用示例：
 * ```php
 * $walletManager = new WalletManager();
 * $walletManager->bind('user_001', 'wechat_wallet', [
 *     'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o',
 *     'real_name' => '张三',
 *     'auto_settlement' => true,
 *     'min_amount' => 100,
 *     'settlement_type' => 'realtime',
 * ]);
 *
 * $plugin = new AutoSettlementPlugin($wechatGateway, $walletManager);
 *
 * // 支付成功后自动触发结算
 * $order = $gateway->createOrder([...]);
 * $result = $plugin->settle('user_001', [
 *     'transaction_id' => $order['transaction_id'],
 *     'amount' => $order['total_fee'],
 *     'out_biz_no' => 'SETTLE_' . date('YmdHis'),
 * ]);
 * ```
 */
class AutoSettlementPlugin
{
    use InteractsWithGateway;

    /**
     * 结算目标类型 → 网关能力方法映射
     *
     * 键为 {@see WalletManager} 定义的领域目标类型，值为
     * {@see SettlementCapableInterface} 声明的原生方法名。
     * 该映射描述的是「结算语义」而非「网关品牌」，因此新增网关无需改动此表。
     *
     * @var array<string, string>
     */
    protected const TARGET_METHOD_MAP = [
        'wechat_wallet' => 'settleToWallet',
        'alipay_balance' => 'settleToWallet',
        'bank_card' => 'settleToBankCard',
        'stripe_connect' => 'settleToPayout',
        'paypal_wallet' => 'settleToPayout',
    ];

    /**
     * 支付网关实例（必须具备 HTTP 通道能力）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 钱包管理器
     */
    protected WalletManager $walletManager;

    /**
     * 结算成功回调
     *
     * @var callable|null
     */
    protected $onSettlementSuccess = null;

    /**
     * 结算失败回调
     *
     * @var callable|null
     */
    protected $onSettlementFailed = null;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需继承 AbstractGateway）
     * @param WalletManager $walletManager 钱包管理器
     */
    public function __construct(GatewayInterface $gateway, WalletManager $walletManager)
    {
        self::assertHttpCapable($gateway);

        $this->gateway = $gateway;
        $this->walletManager = $walletManager;
    }

    /**
     * 执行自动结算
     *
     * 根据用户配置的钱包规则，自动将资金结算到对应账户。
     *
     * @param string $userId 用户 ID
     * @param array<string, mixed> $params 结算参数
     *        - transaction_id: 原支付交易号
     *        - amount: 结算金额（分）
     *        - out_biz_no: 商户结算单号
     *        - description: 结算说明（可选）
     *        - force: 是否强制结算（跳过条件检查，可选）
     * @return array<string, mixed> 结算结果
     * @throws PayException
     */
    public function settle(string $userId, array $params): array
    {
        $this->validateRequired($params, ['transaction_id', 'amount', 'out_biz_no']);

        $amount = (int) $params['amount'];
        $gatewayName = $this->gateway::getName();

        // 检查是否强制结算
        $force = $params['force'] ?? false;

        if (!$force) {
            $target = $this->walletManager->checkSettlementCondition($userId, $gatewayName, $amount);
        } else {
            $target = $this->walletManager->getAutoSettlementTarget($userId, $gatewayName);
        }

        if ($target === null) {
            return [
                'success' => false,
                'settled' => false,
                'reason' => '未满足自动结算条件（未绑定钱包或金额不足）',
                'user_id' => $userId,
                'amount' => $amount,
            ];
        }

        $result = $this->dispatchSettlement($target, $params, $amount);

        $result['settled'] = $result['success'] ?? false;
        $result['target_type'] = $target['type'];
        $result['target_account'] = $target['account'];
        $result['amount'] = $amount;
        $result['user_id'] = $userId;

        // 触发回调
        if ($result['settled'] && $this->onSettlementSuccess !== null) {
            ($this->onSettlementSuccess)($result);
        } elseif (!$result['settled'] && $this->onSettlementFailed !== null) {
            ($this->onSettlementFailed)($result);
        }

        return $result;
    }

    /**
     * 批量结算
     *
     * 对多个用户或订单进行批量结算。
     *
     * @param array<int, array<string, mixed>> $batch 结算批次
     *        [{user_id, transaction_id, amount, out_biz_no, description}]
     * @return array<int, array<string, mixed>> 每条结算结果
     */
    public function settleBatch(array $batch): array
    {
        $results = [];

        foreach ($batch as $item) {
            try {
                $results[] = $this->settle($item['user_id'], $item);
            } catch (PayException $e) {
                $results[] = [
                    'success' => false,
                    'settled' => false,
                    'reason' => $e->getMessage(),
                    'user_id' => $item['user_id'] ?? '',
                    'amount' => $item['amount'] ?? 0,
                ];
            }
        }

        return $results;
    }

    /**
     * 查询结算结果
     *
     * @param string $outBizNo 商户结算单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outBizNo): array
    {
        if ($outBizNo === '') {
            throw PayException::paramError('缺少必填参数：out_biz_no');
        }

        return $this->forwardToCapableGateway('querySettlement', $outBizNo);
    }

    /**
     * 注册结算成功回调
     *
     * @param callable $callback 回调函数(array $result): void
     */
    public function onSettlementSuccess(callable $callback): void
    {
        $this->onSettlementSuccess = $callback;
    }

    /**
     * 注册结算失败回调
     *
     * @param callable $callback 回调函数(array $result): void
     */
    public function onSettlementFailed(callable $callback): void
    {
        $this->onSettlementFailed = $callback;
    }

    /* ==================== 结算编排 ==================== */

    /**
     * 按结算目标语义派发到网关原生结算方法
     *
     * @param array<string, mixed> $target 钱包管理器解析出的结算目标
     * @param array<string, mixed> $params 结算参数
     * @param int $amount 结算金额（分）
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function dispatchSettlement(array $target, array $params, int $amount): array
    {
        $type = (string) $target['type'];
        $method = self::TARGET_METHOD_MAP[$type] ?? null;

        if ($method === null) {
            throw PayException::invalidArgument("不支持的结算目标类型：{$type}");
        }

        return $this->forwardToCapableGateway(
            $method,
            $this->buildSettlementPayload($method, $target, $params, $amount),
        );
    }

    /**
     * 构造网关结算入参（统一口径：金额为分，账户信息来自钱包绑定）
     *
     * @param string $method 目标网关方法名
     * @param array<string, mixed> $target 结算目标
     * @param array<string, mixed> $params 结算参数
     * @param int $amount 结算金额（分）
     * @return array<string, mixed>
     */
    protected function buildSettlementPayload(string $method, array $target, array $params, int $amount): array
    {
        $payload = [
            'out_biz_no' => $params['out_biz_no'],
            'amount' => $amount,
            'description' => $params['description'] ?? '自动结算',
        ];

        if ($method === 'settleToBankCard') {
            $payload['bank_card_no'] = $target['account'];
            $payload['real_name'] = $target['real_name'] ?? '';
            $payload['bank_code'] = $target['bank_code'] ?? '';

            return $payload;
        }

        $payload['account'] = $target['account'];
        $payload['real_name'] = $target['real_name'] ?? '';

        if (isset($target['currency'])) {
            $payload['currency'] = $target['currency'];
        }

        return $payload;
    }

    /* ==================== 通用工具方法 ==================== */

    /**
     * 验证必填参数
     *
     * @param array<string, mixed> $params
     * @param string[] $required
     * @throws PayException
     */
    protected function validateRequired(array $params, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }

    /**
     * 类型安全转发到支持结算的网关原生方法
     *
     * 平台组装逻辑已下沉到各网关类内部（声明 {@see SettlementCapableInterface}）。
     * 本插件只做能力断言与转发，不重复承载平台组装逻辑，也不通过反射读取网关配置。
     *
     * @param string $method 网关原生结算方法名
     * @param mixed ...$args 透传参数
     * @return array<string, mixed>
     * @throws PayException
     *
     * @phpstan-assert SettlementCapableInterface $this->gateway
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof SettlementCapableInterface) {
            throw PayException::invalidArgument(
                sprintf('网关 %s 未实现结算能力接口（SettlementCapableInterface）', $this->gateway::getName()),
            );
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var mixed $gateway 允许转发接口声明之外的网关扩展结算方法 */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }
}
