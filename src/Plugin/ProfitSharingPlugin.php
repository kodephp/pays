<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\ProfitSharingCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;
use Kode\Pays\Plugin\ProfitSharing\Receiver;

/**
 * 分账插件
 *
 * 为支持分账的网关提供统一的分账管理能力。
 * 分账用于将一笔订单金额按约定比例分给多个接收方（如平台、供应商、推广者等）。
 *
 * 支持网关：
 * - 微信支付（服务商模式分账）
 * - 支付宝（交易分账）
 * - Stripe（Connect 平台分账 / Transfer）
 * - 抖音支付（分账，网关原生方法实现）
 * - 云闪付（分账，网关原生方法实现）
 *
 * 设计说明：分账的平台组装逻辑已下沉到各网关原生方法（网关声明
 * {@see ProfitSharingCapableInterface}），本插件只做「参数校验 + 类型安全转发」，
 * 不再承载任何 `match($gateway::getName())` 平台内联分支。未实现
 * {@see ProfitSharingCapableInterface} 的网关调用分账方法会统一报「无此方法」；
 * 可选能力（如 `addProfitSharingReceiver` / `queryProfitSharingConfig`）若网关未实现，
 * 同样报「无此方法」。
 *
 * 使用示例：
 * ```php
 * $plugin = new ProfitSharingPlugin($wechatGateway);
 *
 * // 创建分账
 * $result = $plugin->create([
 *     'transaction_id' => '4200000000000000',
 *     'out_order_no' => 'SHARE_001',
 *     'receivers' => [
 *         ['type' => 'MERCHANT_ID', 'account' => '123456', 'amount' => 100, 'description' => '供应商分账'],
 *         ['type' => 'PERSONAL_OPENID', 'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', 'amount' => 50, 'description' => '推广者分账'],
 *     ],
 * ]);
 *
 * // 查询分账结果
 * $result = $plugin->query('SHARE_001');
 *
 * // 分账回退
 * $result = $plugin->return([
 *     'out_order_no' => 'SHARE_001',
 *     'out_return_no' => 'RETURN_001',
 *     'return_amount' => 100,
 * ]);
 *
 * // 解冻剩余资金
 * $result = $plugin->unfreeze('4200000000000000');
 * ```
 */
class ProfitSharingPlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需继承 AbstractGateway）
     */
    public function __construct(GatewayInterface $gateway)
    {
        self::assertHttpCapable($gateway);

        $this->gateway = $gateway;
    }

    /**
     * 创建分账订单
     *
     * 将一笔已支付订单的金额按接收方列表进行分账。
     *
     * @param array<string, mixed> $params 分账参数
     *        - transaction_id: 原支付订单号（微信 transaction_id / 支付宝 trade_no / Stripe payment_intent）
     *        - out_order_no: 商户分账订单号
     *        - receivers: 接收方列表
     *          微信: [{type: MERCHANT_ID/PERSONAL_OPENID, account, amount, description}]
     *          支付宝: [{trans_in_type: userId/loginName, trans_in, amount, desc}]
     *          Stripe: [{account: connect_account_id, amount, currency}]
     * @return array<string, mixed> 分账结果
     * @throws PayException
     */
    public function create(array $params): array
    {
        $this->validateRequired($params, ['transaction_id', 'out_order_no', 'receivers']);

        if (!is_array($params['receivers']) || empty($params['receivers'])) {
            throw PayException::paramError('receivers 必须是非空数组');
        }

        /** @var array<int, Receiver|array<string, mixed>> $receivers */
        $receivers = [];
        foreach ($params['receivers'] as $receiver) {
            if ($receiver instanceof Receiver) {
                if (!$receiver->amount->isPositive()) {
                    throw PayException::paramError('分账接收方金额必须大于 0');
                }
                $receivers[] = $receiver;
                continue;
            }

            if (!is_array($receiver)) {
                throw PayException::paramError('receivers 元素必须是数组或 Receiver 对象');
            }

            if (isset($receiver['amount']) && (int) $receiver['amount'] <= 0) {
                throw PayException::paramError('分账接收方金额必须大于 0');
            }
            $receivers[] = $receiver;
        }
        $params['receivers'] = $receivers;

        return $this->forwardToCapableGateway('createProfitSharing', $params);
    }

    /**
     * 查询分账结果
     *
     * @param string $outOrderNo 商户分账订单号
     * @param string|null $transactionId 原支付订单号（微信必填，其余平台忽略）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outOrderNo, ?string $transactionId = null): array
    {
        return $this->forwardToCapableGateway('queryProfitSharing', $outOrderNo, $transactionId);
    }

    /**
     * 分账回退
     *
     * 将已分账的金额退回给原订单付款方。
     *
     * @param array<string, mixed> $params 回退参数
     *        - out_order_no: 商户分账订单号
     *        - out_return_no: 商户回退单号
     *        - return_amount: 回退金额（微信单位为分）
     *        - description: 回退描述（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function return(array $params): array
    {
        $this->validateRequired($params, ['out_order_no', 'out_return_no', 'return_amount']);

        return $this->forwardToCapableGateway('returnProfitSharing', $params);
    }

    /**
     * 查询分账回退结果
     *
     * @param string $outReturnNo 商户回退单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryReturn(string $outReturnNo): array
    {
        return $this->forwardToCapableGateway('queryProfitSharingReturn', $outReturnNo);
    }

    /**
     * 查询分账配置（最大分账比例与分账关系）
     *
     * 目前微信支付提供该能力；其他网关未实现，调用会报「无此方法」。
     *
     * @param string $outOrderNo 商户分账订单号
     * @param string|null $transactionId 原支付订单号（可选，与 out_order_no 至少其一）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryConfig(string $outOrderNo, ?string $transactionId = null): array
    {
        return $this->forwardToCapableGateway('queryProfitSharingConfig', $outOrderNo, $transactionId);
    }

    /**
     * 解冻剩余资金
     *
     * 分账完成后，将未分账的剩余资金解冻给原订单收款方。
     *
     * @param string $transactionId 原支付订单号
     * @param string|null $outOrderNo 商户解冻单号（可选，缺省自动生成）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function unfreeze(string $transactionId, ?string $outOrderNo = null): array
    {
        return $this->forwardToCapableGateway('unfreezeProfitSharing', $transactionId, $outOrderNo);
    }

    /**
     * 添加分账接收方
     *
     * 在分账前将接收方添加到网关的接收方列表中。目前微信 / 支付宝支持。
     *
     * @param array<string, mixed> $receiver 接收方信息
     * @return array<string, mixed>
     * @throws PayException
     */
    public function addReceiver(array $receiver): array
    {
        return $this->forwardToCapableGateway('addProfitSharingReceiver', $receiver);
    }

    /**
     * 删除分账接收方
     *
     * @param array<string, mixed> $receiver 接收方信息
     * @return array<string, mixed>
     * @throws PayException
     */
    public function removeReceiver(array $receiver): array
    {
        return $this->forwardToCapableGateway('removeProfitSharingReceiver', $receiver);
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
     * 类型安全转发到支持分账的网关原生方法
     *
     * 分账的平台组装逻辑已下沉到各网关类内部（声明 {@see ProfitSharingCapableInterface}）。
     * 本插件只做能力断言与转发，不重复承载平台组装逻辑。可选能力（如
     * `addProfitSharingReceiver` / `queryProfitSharingConfig`）若网关未实现，同样报「无此方法」。
     *
     * @param string $method 网关原生分账方法名
     * @param mixed ...$args 透传参数
     * @return array<string, mixed>
     * @throws PayException 当网关未实现分账能力接口，或不支持指定方法时
     *
     * @phpstan-assert ProfitSharingCapableInterface $this->gateway
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof ProfitSharingCapableInterface) {
            throw PayException::invalidArgument(
                sprintf('网关 %s 未实现分账能力接口（ProfitSharingCapableInterface）', $this->gateway::getName()),
            );
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var mixed $gateway 允许转发可选方法（如 addProfitSharingReceiver / queryProfitSharingConfig） */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }
}
