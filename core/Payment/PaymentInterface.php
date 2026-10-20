<?php
/**
 * 支付渠道统一接口
 * 渠道实现方负责：下单（拿支付二维码）/ 查单（主动对账）/ 异步通知验签
 */
interface PaymentInterface
{
    /** 渠道名：wechat / alipay */
    public function name(): string;

    /**
     * 下单，返回支付二维码内容
     * @param string $orderNo 商户订单号（out_trade_no）
     * @param int    $amountFen 金额（分）
     * @param string $subject 商品描述
     * @return array{ok:bool, qr:string, message:string}
     */
    public function createOrder(string $orderNo, int $amountFen, string $subject): array;

    /**
     * 查询订单状态（主动对账，回调不可达时兜底）
     * @return array{ok:bool, paid:bool, trade_no:string, message:string}
     */
    public function queryOrder(string $orderNo): array;

    /**
     * 校验并解析异步通知
     * @param string $rawBody 原始 POST body
     * @return array{ok:bool, order_no:string, paid:bool, trade_no:string, reply:string, message:string}
     *         reply = 需要原样返回给厂商的应答文本
     */
    public function verifyNotify(string $rawBody): array;

    /** 渠道是否已配置可用 */
    public function isConfigured(): bool;
}
