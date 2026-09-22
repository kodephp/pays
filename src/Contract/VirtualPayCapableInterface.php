<?php

declare(strict_types=1);

namespace Kode\Pays\Contract;

/**
 * 小程序虚拟支付能力接口
 *
 * 聚合「小程序虚拟支付」网关的统一能力契约。与常规微信支付（wechat / wechat_v3）
 * 在协议层完全不同：本能力对接微信开放平台的 xpay 服务端 API
 * （`https://api.weixin.qq.com/xpay/*`），采用 JSON 请求体 +
 * {@see \Kode\Pays\Support\Signer::hmacSha256Raw()} 双 HMAC-SHA256 签名，
 * 而非商户平台的 XML/MD5 或 V3/RSA 体系。
 *
 * 设计要点（与分账/转账/红包/订阅/个人收款/对账/退款一致）：
 * - 能力方法由各网关自行实现（含请求组装、签名、发请求）
 * - 插件只负责参数可信转发，未实现本接口的网关调用虚拟支付能力时统一报「无此方法」
 * - 网关方法仅承载「平台组装 + 签名 + 发请求 + 枚举校验」，不做业务侧幂等/发货决策
 *
 * 金额与代币统一以**最小整数单位**（分 / 个）传入，网关不做单位换算，
 * 因为 xpay 接口本身即以「分」与「个」为计量单位。
 */
interface VirtualPayCapableInterface
{
    /**
     * 组装小程序端调起虚拟支付的参数（服务端签名部分）
     *
     * 微信明确：下单与拉起支付由客户端 `wx.requestVirtualPayment` 完成，服务端职责是
     * 产出该接口所需的 `signData` 与两套签名（`paySig` / `signature`）。
     * 本方法据此组装并签名，调用方将返回值原样下发给小程序前端使用。
     *
     * @param array<string, mixed> $params 订单参数，识别字段：
     *        - out_trade_no string 必填：业务订单号（8-32 位，`[A-Za-z0-9_\-|*@]`，不能以 `_` 开头）
     *        - offer_id string 可选：虚拟支付 OfferId，缺省取网关配置 `offer_id`
     *        - currency_type string 可选：币种，默认 `CNY`（当前官方仅支持 CNY）
     *        - buy_quantity int 可选：购买数量，默认 1
     *        - mode string 可选：`short_series_goods`（道具直购，默认）或 `short_series_coin`（代币充值）
     *        - product_id string 可选：道具 ID，`mode=short_series_goods` 时必填
     *        - goods_price int 可选：道具单价（分），`mode=short_series_goods` 时必填
     *        - activity_selling_price int 可选：优惠单价（分），需与 goods_price 一起传入
     *        - attach string 可选：透传数据，发货通知时原样带回
     *        - env int 可选：环境（0 现网 / 1 沙箱），缺省取网关配置 `env`
     *        - sign_data string 可选：调用方自行组装好的 signData JSON 字符串；
     *          传入时网关不再重建字段，仅据此计算签名
     * @return array<string, mixed> 前端参数：signData / paySig / signature / mode / env / offerId
     */
    public function createOrder(array $params): array;

    /**
     * 查询虚拟支付订单（现金单）
     *
     * 对应 `/xpay/query_order`。微信的发货推送可能因客户端异常退出而丢失，
     * 官方建议以本接口做「兜底发货」轮询：`status=2`（已支付待发货）即触发发货。
     *
     * @param string $orderId 商户订单号（order_id）或微信内部单号（wx_order_id）
     * @param array<string, mixed> $context 调用上下文，识别字段：openid / env / wx_order_id
     * @return array<string, mixed> 订单信息（order 子对象含 status / left_fee / paid_time 等）
     */
    public function queryVirtualOrder(string $orderId, array $context = []): array;

    /**
     * 启动虚拟支付订单退款任务
     *
     * 对应 `/xpay/refund_order`。注意：本接口**仅确认退款任务已启动**，
     * 退款最终状态需经 {@see self::queryVirtualOrder()} 传入退款单号追踪
     * （退款单在 xpay 侧同样是 order_type=1 的订单）。
     *
     * 仅支持支付 365 天以内的订单；180 天以内的退款平台退还手续费。
     *
     * @param array<string, mixed> $params 退款参数，识别字段：
     *        - refund_order_id string 必填：本次退款单号（8-32 位，`[A-Za-z0-9_\-]`）
     *        - order_id string / wx_order_id string 必填其一：原支付单号
     *        - left_fee int 必填：当前单剩余可退金额（分），经 query_order 查得
     *        - refund_fee int 必填：本次退款金额（分），需满足 `0 < refund_fee <= left_fee`
     *        - biz_meta string 可选：商户自定义数据（≤1024），查单时原样返回
     *        - refund_reason string 必填：0-暂无描述 / 1-产品问题 / 2-售后问题 / 3-意愿问题 / 4-价格问题 / 5-其他原因
     *        - req_from string 必填：1-人工客服退款 / 2-用户自己发起 / 3-其它
     *        - openid string 可选 / env int 可选
     * @return array<string, mixed> 退款任务信息（refund_order_id / refund_wx_order_id 等）
     */
    public function refundVirtualOrder(array $params): array;

    /**
     * 通知虚拟支付订单已发货完成
     *
     * 对应 `/xpay/notify_provide_goods`。**仅适用于现金单**，用于发货推送
     * （`xpay_goods_deliver_notify`）失败时的手动补偿；若推送已成功返回
     * `ErrCode=0`，则无需调用本接口。
     *
     * @param array<string, mixed> $params 识别字段：
     *        - order_id string 与 wx_order_id 必填其一
     *        - env int 必填：0 现网 / 1 沙箱
     * @return array<string, mixed> 微信侧无返回体，成功时返回 `['errcode' => 0, 'errmsg' => '']`
     */
    public function notifyProvideGoods(array $params): array;

    /**
     * 查询用户代币余额
     *
     * 对应 `/xpay/query_user_balance`。
     *
     * @param string $openid 用户 openid
     * @param array<string, mixed> $context 识别字段：env / user_ip
     * @return array<string, mixed> balance / present_balance / sum_save / sum_cost / first_save_flag 等
     */
    public function queryTokenBalance(string $openid, array $context = []): array;

    /**
     * 扣减用户代币（代币支付）
     *
     * 对应 `/xpay/currency_pay`。金额单位为**代币个数**（非金额），
     * 且必须是整数；代币单价由商户后台的代币兑换比例决定。
     *
     * @param array<string, mixed> $params 识别字段：
     *        - amount int 必填：扣减代币数量（整数）
     *        - order_id string 必填：本次扣款订单号
     *        - openid string 必填 / user_ip string 必填
     *        - payitem string 可选：物品信息，记录到账户流水，
     *          形如 `[{"productid":"道具id","unit_price":单价,"quantity":数量}]`
     *        - remark string 可选：备注
     *        - env int 可选
     * @return array<string, mixed> order_id / balance / used_present_amount
     */
    public function deductTokens(array $params): array;

    /**
     * 代币支付退款
     *
     * 对应 `/xpay/cancel_currency_pay`（`currency_pay` 的逆操作）。
     * 注意与 {@see self::refundVirtualOrder()} 的区别：后者退现金单，本方法退代币扣款。
     *
     * @param array<string, mixed> $params 识别字段：
     *        - pay_order_id string 必填：原 `currency_pay` 时传入的 order_id
     *        - order_id string 必填：本次退款单号
     *        - amount int 必填：退款代币数量
     *        - openid string 必填 / user_ip string 必填
     *        - env int 可选
     * @return array<string, mixed> 退款 order_id
     */
    public function refundTokens(array $params): array;

    /**
     * 赠送代币
     *
     * 对应 `/xpay/present_currency`。微信**不支持按单号查询赠送记录**，
     * 官方建议业务侧重复调用直到返回 `errcode=0` 或 `268490004`（重复操作，
     * 表示此前已成功）。
     *
     * @param array<string, mixed> $params 识别字段：
     *        - amount int 必填：赠送代币数量
     *        - order_id string 必填：赠送单号
     *        - openid string 必填
     *        - env int 可选
     * @return array<string, mixed> balance / order_id / present_balance
     */
    public function giftTokens(array $params): array;

    /**
     * 申请下载虚拟支付账单
     *
     * 对应 `/xpay/download_bill`。首次调用触发生成下载地址，
     * 返回的 URL 有效期约半小时，未生成时可按原参数轮询重试。
     *
     * @param array<string, mixed> $params 识别字段：
     *        - begin_ds int 必填：起始日期，形如 `20230801`
     *        - end_ds int 必填：截止日期，形如 `20230810`
     *        - env int 可选
     * @return array<string, mixed> url（下载地址）
     */
    public function downloadVirtualBill(array $params): array;
}
