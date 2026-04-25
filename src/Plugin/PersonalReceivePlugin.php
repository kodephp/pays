<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

/**
 * 个人收款插件
 *
 * 为个人/小微商户提供收款能力，无需企业资质。
 * 支持生成收款码、查询收款记录、提现到银行卡。
 *
 * 支持网关：
 * - 微信支付（个人收款码、赞赏码）
 * - 支付宝（个人收款码、转账到个人账户）
 * - Stripe（Payment Link 个人收款）
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
 * ]);
 * ```
 */
class PersonalReceivePlugin
{
    /**
     * 支付网关实例
     */
    protected GatewayInterface $gateway;

    /**
     * 构造函数
     *
     * @param GatewayInterface $gateway 支付网关
     */
    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 生成个人收款二维码
     *
     * @param array<string, mixed> $params 收款参数
     *        - amount: 收款金额（微信单位为分）
     *        - description: 收款说明/商品描述
     *        - attach: 附加数据（可选，会原样返回）
     *        - expire_seconds: 二维码过期时间（秒，可选）
     * @return array<string, mixed> 包含二维码 URL / 二维码内容
     * @throws PayException
     */
    public function createQrCode(array $params): array
    {
        $this->validateRequired($params, ['amount', 'description']);

        return match ($this->gateway::getName()) {
            'wechat' => $this->createWechatPersonalQrCode($params),
            'alipay' => $this->createAlipayPersonalQrCode($params),
            'stripe' => $this->createStripePaymentLink($params),
            default => throw PayException::invalidArgument('当前网关不支持个人收款码'),
        };
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
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatPersonalRecords($params),
            'alipay' => $this->queryAlipayPersonalRecords($params),
            'stripe' => $this->queryStripePaymentRecords($params),
            default => throw PayException::invalidArgument('当前网关不支持查询收款记录'),
        };
    }

    /**
     * 提现到银行卡
     *
     * @param array<string, mixed> $params 提现参数
     *        - amount: 提现金额（微信单位为分）
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

        return match ($this->gateway::getName()) {
            'wechat' => $this->withdrawWechatToBank($params),
            'alipay' => $this->withdrawAlipayToBank($params),
            default => throw PayException::invalidArgument('当前网关不支持提现到银行卡'),
        };
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
        return match ($this->gateway::getName()) {
            'wechat' => $this->queryWechatWithdraw($outBizNo),
            'alipay' => $this->queryAlipayWithdraw($outBizNo),
            default => throw PayException::invalidArgument('当前网关不支持查询提现'),
        };
    }

    /* ==================== 微信个人收款实现 ==================== */

    /**
     * 微信生成个人收款码（NATIVE 扫码支付）
     */
    protected function createWechatPersonalQrCode(array $params): array
    {
        $outTradeNo = 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999);

        $requestData = [
            'appid' => $this->getGatewayConfig('app_id'),
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'body' => $params['description'],
            'out_trade_no' => $outTradeNo,
            'total_fee' => (int) $params['amount'],
            'spbill_create_ip' => $params['client_ip'] ?? '127.0.0.1',
            'notify_url' => $params['notify_url'] ?? '',
            'trade_type' => 'NATIVE',
            'product_id' => $params['product_id'] ?? 'PERSONAL_PAY',
            'attach' => !empty($params['attach']) ? json_encode($params['attach'], JSON_UNESCAPED_UNICODE) : '',
        ];

        if (!empty($params['expire_seconds'])) {
            $requestData['time_expire'] = date('YmdHis', time() + (int) $params['expire_seconds']);
        }

        $response = $this->gateway->post('pay/unifiedorder', $requestData);

        return [
            'out_trade_no' => $outTradeNo,
            'code_url' => $response['code_url'] ?? '',
            'prepay_id' => $response['prepay_id'] ?? '',
            'amount' => $params['amount'],
            'description' => $params['description'],
        ];
    }

    /**
     * 查询微信个人收款记录
     */
    protected function queryWechatPersonalRecords(array $params): array
    {
        $requestData = [
            'appid' => $this->getGatewayConfig('app_id'),
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'nonce_str' => $this->generateNonceStr(),
            'bill_date' => date('Ymd', strtotime($params['start_time'] ?? 'today')),
            'bill_type' => 'ALL',
        ];

        return $this->gateway->post('pay/downloadbill', $requestData);
    }

    /**
     * 微信提现到银行卡（企业付款到银行卡）
     */
    protected function withdrawWechatToBank(array $params): array
    {
        return $this->gateway->post('mmpaymkttransfers/pay_bank', [
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'partner_trade_no' => $params['out_biz_no'],
            'nonce_str' => $this->generateNonceStr(),
            'enc_bank_no' => $this->encryptBankCard($params['bank_card_no']),
            'enc_true_name' => $this->encryptBankCard($params['real_name']),
            'bank_code' => $params['bank_code'] ?? '',
            'amount' => (int) $params['amount'],
            'desc' => $params['description'] ?? '个人提现',
        ]);
    }

    /**
     * 查询微信提现结果
     */
    protected function queryWechatWithdraw(string $outBizNo): array
    {
        return $this->gateway->post('mmpaymkttransfers/query_bank', [
            'mch_id' => $this->getGatewayConfig('mch_id'),
            'partner_trade_no' => $outBizNo,
            'nonce_str' => $this->generateNonceStr(),
        ]);
    }

    /* ==================== 支付宝个人收款实现 ==================== */

    /**
     * 支付宝生成个人收款码（当面付扫码）
     */
    protected function createAlipayPersonalQrCode(array $params): array
    {
        $outTradeNo = 'PERSONAL_' . date('YmdHis') . random_int(1000, 9999);

        $response = $this->gateway->post('', [
            'method' => 'alipay.trade.precreate',
            'biz_content' => json_encode([
                'out_trade_no' => $outTradeNo,
                'total_amount' => number_format($params['amount'] / 100, 2),
                'subject' => $params['description'],
                'body' => !empty($params['attach']) ? json_encode($params['attach'], JSON_UNESCAPED_UNICODE) : '',
                'timeout_express' => isset($params['expire_seconds']) ? ($params['expire_seconds'] . 's') : '30m',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'out_trade_no' => $outTradeNo,
            'qr_code' => $response['qr_code'] ?? '',
            'amount' => $params['amount'],
            'description' => $params['description'],
        ];
    }

    /**
     * 查询支付宝个人收款记录
     */
    protected function queryAlipayPersonalRecords(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.trade.query',
            'biz_content' => json_encode([
                'start_time' => $params['start_time'] ?? '',
                'end_time' => $params['end_time'] ?? '',
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 支付宝提现到银行卡（转账到银行卡）
     */
    protected function withdrawAlipayToBank(array $params): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.trans.uni.transfer',
            'biz_content' => json_encode([
                'out_biz_no' => $params['out_biz_no'],
                'trans_amount' => number_format($params['amount'] / 100, 2),
                'product_code' => 'TRANS_BANKCARD_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'order_title' => '个人提现',
                'payee_info' => [
                    'identity_type' => 'BANKCARD_ACCOUNT',
                    'identity' => $params['bank_card_no'],
                    'name' => $params['real_name'],
                    'bank_code' => $params['bank_code'] ?? '',
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询支付宝提现结果
     */
    protected function queryAlipayWithdraw(string $outBizNo): array
    {
        return $this->gateway->post('', [
            'method' => 'alipay.fund.trans.common.query',
            'biz_content' => json_encode([
                'product_code' => 'TRANS_BANKCARD_NO_PWD',
                'biz_scene' => 'DIRECT_TRANSFER',
                'out_biz_no' => $outBizNo,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ==================== Stripe Payment Link 实现 ==================== */

    /**
     * Stripe 创建 Payment Link（个人收款）
     */
    protected function createStripePaymentLink(array $params): array
    {
        $price = $this->gateway->post('v1/prices', [
            'unit_amount' => (int) $params['amount'],
            'currency' => strtolower($params['currency'] ?? 'usd'),
            'product_data' => [
                'name' => $params['description'],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);

        $link = $this->gateway->post('v1/payment_links', [
            'line_items' => [
                ['price' => $price['id'], 'quantity' => 1],
            ],
            'metadata' => array_merge(
                $params['attach'] ?? [],
                ['out_trade_no' => 'PERSONAL_' . date('YmdHis')]
            ),
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);

        return [
            'out_trade_no' => $link['metadata']['out_trade_no'] ?? '',
            'payment_link' => $link['url'] ?? '',
            'amount' => $params['amount'],
            'description' => $params['description'],
        ];
    }

    /**
     * 查询 Stripe Payment 记录
     */
    protected function queryStripePaymentRecords(array $params): array
    {
        $startTime = strtotime($params['start_time'] ?? '-30 days');
        $endTime = strtotime($params['end_time'] ?? 'now');

        return $this->gateway->get('v1/payment_intents', [
            'created[gte]' => $startTime,
            'created[lte]' => $endTime,
            'limit' => $params['limit'] ?? 100,
        ], [
            'Authorization' => 'Bearer ' . $this->getGatewayConfig('secret_key'),
        ]);
    }

    /* ==================== 通用工具方法 ==================== */

    /**
     * 加密银行卡信息（微信支付要求 RSA 加密）
     *
     * @param string $data 待加密数据
     * @return string Base64 编码的密文
     */
    protected function encryptBankCard(string $data): string
    {
        $publicKey = $this->getGatewayConfig('bank_public_key');

        if (empty($publicKey)) {
            return base64_encode($data);
        }

        openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        return base64_encode($encrypted);
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
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                throw PayException::paramError("缺少必填参数：{$field}");
            }
        }
    }

    /**
     * 获取网关配置项
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function getGatewayConfig(string $key, mixed $default = null): mixed
    {
        $reflection = new \ReflectionClass($this->gateway);

        if ($reflection->hasProperty('config')) {
            $property = $reflection->getProperty('config');
            $property->setAccessible(true);
            $config = $property->getValue($this->gateway);

            return $config[$key] ?? $default;
        }

        return $default;
    }

    /**
     * 生成随机字符串
     */
    protected function generateNonceStr(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
