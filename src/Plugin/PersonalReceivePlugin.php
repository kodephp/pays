<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Contract\HttpCapableInterface;
use Kode\Pays\Contract\PersonalReceiveCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\Concerns\InteractsWithGateway;

/**
 * 个人收款插件
 *
 * 为个人/小微商户提供收款能力（无需企业资质）：生成收款码、查询收款记录、提现到银行卡。
 *
 * 平台组装逻辑已下沉到各网关原生方法（网关声明 {@see PersonalReceiveCapableInterface}），
 * 本插件仅负责「参数校验 + 类型安全转发」，不承载平台组装逻辑。
 *
 * 支持网关：
 * - 微信支付（个人收款码、赞赏码、企业付款到银行卡）
 * - 支付宝（个人收款码、转账到银行卡）
 * - Stripe（Payment Link 个人收款；提现能力暂未提供，调用会报「无此方法」）
 *
 * 使用示例：
 * ```php
 * $plugin = new PersonalReceivePlugin($wechatGateway);
 *
 * // 生成个人收款码
 * $result = $plugin->createQrCode([
 *     'amount'      => 100,
 *     'description' => '商品付款',
 *     'attach'      => ['product_id' => '123'],
 * ]);
 *
 * // 查询收款记录
 * $records = $plugin->queryRecords([
 *     'start_time' => '2024-04-01 00:00:00',
 *     'end_time'   => '2024-04-25 23:59:59',
 * ]);
 *
 * // 提现到银行卡
 * $result = $plugin->withdraw([
 *     'amount'       => 5000,
 *     'bank_card_no' => '622202************',
 *     'real_name'    => '张三',
 *     'out_biz_no'   => 'WD_20240425000001',
 * ]);
 *
 * // 统一入口等价写法
 * \Kode\Pays\Facade\Pay::personalReceiveQrCode('wechat', $params);
 * ```
 */
class PersonalReceivePlugin
{
    use InteractsWithGateway;

    /**
     * 支付网关实例（必须具备 HTTP 通道能力，并实现个人收款能力接口）
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
     * 生成个人收款二维码
     *
     * @param array<string, mixed> $params 收款参数
     *        - amount: 收款金额（微信/支付宝单位为分）
     *        - description: 收款说明/商品描述
     *        - attach: 附加数据（可选，会原样返回）
     *        - expire_seconds: 二维码过期时间（秒，可选）
     * @return array<string, mixed> 包含二维码 URL / 内容
     * @throws PayException
     */
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        return $this->forwardToCapableGateway('createQrCode', $params);
    }

    /**
     * 查询个人收款记录
     *
     * @param array<string, mixed> $params 查询参数
     *        - start_time: 开始时间（格式：Y-m-d H:i:s）
     *        - end_time: 结束时间（格式：Y-m-d H:i:s）
     *        - page: 页码（可选）
     *        - limit: 每页数量（可选）
     * @return array<string, mixed> 收款记录列表
     * @throws PayException
     */
    public function queryRecords(array $params): array
    {
        return $this->forwardToCapableGateway('queryRecords', $params);
    }

    /**
     * 提现到银行卡
     *
     * @param array<string, mixed> $params 提现参数
     *        - amount: 提现金额（微信/支付宝单位为分）
     *        - bank_card_no: 银行卡号
     *        - real_name: 真实姓名
     *        - bank_code: 银行编码（可选）
     *        - out_biz_no: 商户提现单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function withdraw(array $params): array
    {
        $this->validateRequired($params, ['amount', 'bank_card_no', 'real_name', 'out_biz_no']);

        return $this->forwardToCapableGateway('withdraw', $params);
    }

    /**
     * 查询提现结果
     *
     * @param string $outBizNo 商户提现单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryWithdraw(string $outBizNo): array
    {
        return $this->forwardToCapableGateway('queryWithdraw', $outBizNo);
    }

    /**
     * 类型安全地转发到网关原生方法
     *
     * @param mixed ...$args
     * @return array<string, mixed>
     * @throws PayException
     */
    protected function forwardToCapableGateway(string $method, mixed ...$args): array
    {
        if (!$this->gateway instanceof PersonalReceiveCapableInterface) {
            throw PayException::invalidArgument(sprintf(
                '网关 %s 未实现个人收款能力接口（PersonalReceiveCapableInterface）',
                $this->gateway::getName(),
            ));
        }

        if (!method_exists($this->gateway, $method)) {
            throw PayException::methodNotSupported($this->gateway::getName(), $method);
        }

        /** @var PersonalReceiveCapableInterface $gateway */
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
