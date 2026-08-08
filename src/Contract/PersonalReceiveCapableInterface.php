<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 个人收款能力接口
 *
 * 为个人/小微商户提供收款能力（无需企业资质）：生成收款码、查询收款记录、
 * 提现到银行卡及查询提现结果。
 *
 * 与分账/转账/红包/订阅一致，平台组装逻辑下沉到各网关原生方法，
 * 由 {@see \Kode\Pays\Facade\Pay::call()} 统一派发；网关未实现的方法调用时抛「无此方法」。
 */
interface PersonalReceiveCapableInterface
{
    /**
     * 生成个人收款二维码
     *
     * @param array<string, mixed> $params 收款参数
     * @return array<string, mixed> 包含二维码 URL / 内容
     */
    public function createQrCode(array $params): array;

    /**
     * 查询个人收款记录
     *
     * @param array<string, mixed> $params 查询参数
     * @return array<string, mixed> 收款记录列表
     */
    public function queryRecords(array $params): array;

    /**
     * 提现到银行卡
     *
     * @param array<string, mixed> $params 提现参数
     * @return array<string, mixed>
     */
    public function withdraw(array $params): array;

    /**
     * 查询提现结果
     *
     * @param string $outBizNo 商户提现单号
     * @return array<string, mixed>
     */
    public function queryWithdraw(string $outBizNo): array;
}
