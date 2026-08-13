<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

use Kode\Pays\Core\PayException;

/**
 * 转账（企业付款）能力接口
 *
 * 微信、支付宝、Stripe 等支付平台将「转账 / 企业付款」作为各自网关的「特色方法」
 * 直接实现于网关类内部（复用基类配置、签名与 HTTP 通道），而非依赖统一插件层。
 * 实现本接口的网关即可被统一入口 {@see \Kode\Pays\Facade\Pay::call()} 与
 * {@see \Kode\Pays\Plugin\TransferPlugin} 类型安全地调用其转账方法，满足
 * 「统一入口可调用各平台特色方法」的设计目标。
 *
 * 方法命名与微信 / 支付宝 / Stripe 的插件语义保持一致：
 * - singleTransfer : 单笔转账 / 企业付款到零钱 / Payout
 * - batchTransfer  : 批量转账
 * - queryTransfer  : 查询转账结果
 * - transferReceipt: 查询转账电子回单（Stripe 不支持时抛「无此方法」）
 */
interface TransferCapableInterface
{
    /**
     * 发起单笔转账
     *
     * @param array<string, mixed> $params 转账参数
     *        - out_biz_no: 商户转账单号
     *        - amount: 转账金额（微信 / 支付宝单位为分）
     *        - recipient: 接收方信息（平台差异见各网关实现）
     *        - description: 转账备注（可选）
     * @return array<string, mixed>
     * @throws PayException
     */
    public function singleTransfer(array $params): array;

    /**
     * 发起批量转账
     *
     * @param array<string, mixed> $params 批量转账参数
     *        - out_biz_no: 商户批量单号
     *        - transfer_detail_list: 明细列表 [{out_detail_no, amount, recipient, remark}]
     * @return array<string, mixed>
     * @throws PayException
     */
    public function batchTransfer(array $params): array;

    /**
     * 查询转账结果
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function queryTransfer(string $outBizNo): array;

    /**
     * 查询转账电子回单
     *
     * 各网关语义不同：微信 V3 会申请并下载、解密回单文件，返回含 file_content（解密后二进制）
     * 与 file_sha256；京东 / 美团等仅返回申请响应；Stripe 不支持时抛「无此方法」。
     *
     * @param string $outBizNo 商户转账单号
     * @return array<string, mixed>
     * @throws PayException
     */
    public function transferReceipt(string $outBizNo): array;
}
