<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\TransferCapableInterface;
use Kode\Pays\Core\FundConstraintValidator;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 转账插件
 *
 * 为支持转账 / 企业付款的网关提供统一的转账管理能力。
 * 支持单笔转账、批量转账、查询转账结果、获取电子回单。
 *
 * 架构说明（对齐「统一入口」设计）：
 * 各平台的转账逻辑已下沉到各自的网关类内部，实现 {@see TransferCapableInterface}
 * （微信 / 支付宝 / Stripe 均已实现）。本插件只做「参数校验 + 资金约束校验 +
 * 类型安全转发」，不重复承载平台组装逻辑，保证单一职责：
 * - 校验通过后，经 {@see forwardToCapableGateway()} 调用网关原生方法；
 * - 网关未实现 {@see TransferCapableInterface}（或不支持某方法）时，统一抛「无此方法」。
 *
 * 支持网关：微信支付、支付宝、Stripe。
 *
 * 使用示例：
 * ```php
 * $plugin = new TransferPlugin($wechatGateway);
 *
 * // 单笔转账到零钱
 * $result = $plugin->single([
 *     'out_biz_no'  => 'TRANSFER_' . date('YmdHis'),
 *     'amount'      => 100,
 *     'recipient'   => ['type' => 'openid', 'account' => 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', 'name' => '张三'],
 *     'description' => '佣金提现',
 * ]);
 *
 * // 批量转账
 * $result = $plugin->batch([
 *     'out_biz_no' => 'BATCH_' . date('YmdHis'),
 *     'transfer_detail_list' => [
 *         ['out_detail_no' => 'D001', 'amount' => 100, 'recipient' => [...], 'remark' => '佣金'],
 *         ['out_detail_no' => 'D002', 'amount' => 200, 'recipient' => [...], 'remark' => '奖励'],
 *     ],
 * ]);
 *
 * // 统一入口亦可：Pay::transferSingle('wechat', [...])
 * ```
 */
class TransferPlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力）
     *
     * @var GatewayInterface&HttpCapableInterface
     */
    protected GatewayInterface $gateway;

    /**
     * 资金约束验证器（可选）
     *
     * @var FundConstraintValidator|null
     */
    protected ?FundConstraintValidator $validator;

    /**
     * 构造函数
     *
     * @param GatewayInterface&HttpCapableInterface $gateway 支付网关（需实现 TransferCapableInterface）
     * @param FundConstraintValidator|null $validator 资金约束验证器
     */
    public function __construct(GatewayInterface $gateway, ?FundConstraintValidator $validator = null)
    {
        self::assertHttpCapable($gateway);

        $this->gateway = $gateway;
        $this->validator = $validator;
    }

    /**
     * 发起单笔转账
     *
     * @param array<string, mixed> $params 转账参数
     *        - out_biz_no: 商户转账单号
     *        - amount: 转账金额（微信 / 支付宝单位为分）
     *        - recipient: 接收方信息
     *          微信: {type: openid/bank_card, account, name}
     *          支付宝: {type: ALIPAY_USER_ID/ALIPAY_LOGON_ID, account, name}
     *          Stripe: {type: connect_account, account}
     *        - description: 转账备注 / 说明
     * @return array<string, mixed> 转账结果
     * @throws PayException
     */
    public function single(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'amount', 'recipient']);

        // 资金约束校验（可选）
        if ($this->validator !== null) {
            $recipient = $params['recipient']['account'] ?? '';
            $check = $this->validator->validateTransfer([
                'amount' => (int) $params['amount'],
                'user_id' => $params['user_id'] ?? '',
                'recipient_account' => $recipient,
            ]);
            if (!$check['valid']) {
                throw PayException::invalidArgument($check['message']);
            }
        }

        return $this->forwardToCapableGateway('singleTransfer', $params);
    }

    /**
     * 发起批量转账
     *
     * @param array<string, mixed> $params 批量转账参数
     *        - out_biz_no: 商户批量单号
     *        - transfer_detail_list: 明细列表
     *          [{out_detail_no, amount, recipient: {type, account, name}, remark}]
     * @return array<string, mixed>
     * @throws PayException
     */
    public function batch(array $params): array
    {
        $this->validateRequired($params, ['out_biz_no', 'transfer_detail_list']);

        if (!is_array($params['transfer_detail_list']) || empty($params['transfer_detail_list'])) {
            throw PayException::paramError('transfer_detail_list 必须是非空数组');
        }

        return $this->forwardToCapableGateway('batchTransfer', $params);
    }

    /**
     * 查询转账结果
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function query(string $outBizNo): array
    {
        return $this->forwardToCapableGateway('queryTransfer', $outBizNo);
    }

    /**
     * 查询转账电子回单
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function receipt(string $outBizNo): array
    {
        return $this->forwardToCapableGateway('transferReceipt', $outBizNo);
    }

    /**
     * 类型安全转发到支持转账的网关原生方法
     *
     * 微信 / 支付宝 / Stripe 的「转账」是其各自网关类内部实现的特色方法
     * （声明了 {@see TransferCapableInterface}）。插件在此只做校验与转发，不重复承载
     * 平台组装逻辑。网关不支持某方法时抛 {@see PayException::methodNotSupported}（无此方法）。
     *
     * @param string $method 网关原生转账方法名
     * @param mixed ...$args 透传参数
     * @return array<string, mixed>
     * @throws PayException 当网关未实现转账能力接口或不支持该方法时
     *
     * @phpstan-assert TransferCapableInterface $this->gateway
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof TransferCapableInterface) {
            throw PayException::invalidArgument(
                sprintf('网关 %s 未实现转账能力接口（TransferCapableInterface）', $this->gateway::getName()),
            );
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var TransferCapableInterface $gateway */
        $gateway = $this->gateway;

        return $gateway->$method(...$args);
    }

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
}
