<?php
/**
 * 支付宝 · 当面付（扫码支付）—— 按官方规范实现（零 SDK，RSA2 签名）
 * 官方文档：
 *   precreate：https://opendocs.alipay.com/open/02ekfg
 *   异步通知验签：https://opendocs.alipay.com/open/270/105902
 *
 * 需要后台配置：
 *   alipay_appid           支付宝应用 APPID
 *   alipay_private_key     应用私钥（PKCS8，含 BEGIN 行的 PEM 全文或磁盘路径）
 *   alipay_public_key      支付宝公钥（验签用，非应用公钥！含 BEGIN 行或纯 base64 均可）
 *   alipay_gateway         网关（默认正式环境，沙箱传 https://openapi-sandbox.dl.alipaydev.com/gateway.do）
 *
 * 签名规则（RSA2 / SHA256withRSA）：
 *   1. 所有非 sign / sign_type 的参数按 key ASCII 升序
 *   2. 拼接 k=v&k=v（值不转义，空值不参与）
 *   3. sign = base64( SHA256withRSA( 应用私钥, 待签名串 ) )
 *   4. 验签用支付宝公钥对 notify 参数同规则验签
 */
class Alipay implements PaymentInterface
{
    private string $appId;
    private string $gateway;
    /** @var resource|null */
    private $privateKey;
    /** @var resource|null */
    private $alipayPublicKey;

    public function __construct()
    {
        $this->appId   = (string)(getSetting('alipay_appid') ?? '');
        $this->gateway = (string)(getSetting('alipay_gateway') ?: 'https://openapi.alipay.com/gateway.do');
        $this->privateKey     = $this->loadKey((string)(getSetting('alipay_private_key') ?? ''));
        $this->alipayPublicKey = $this->loadKey((string)(getSetting('alipay_public_key') ?? ''));
    }

    public function name(): string { return 'alipay'; }

    /** 兼容 PEM 全文 / 纯 base64 / 磁盘路径三种填法 */
    private function loadKey(string $key)
    {
        if ($key === '') return null;
        if (str_contains($key, 'BEGIN')) {
            $pem = str_replace(["\r\n", "\n", "\r"], "\n", trim($key));
            return openssl_pkey_get_public($pem) ?: openssl_pkey_get_private($pem);
        }
        $path = str_replace('\\', '/', $key);
        if (is_file($path)) {
            return openssl_pkey_get_public('file://' . $path) ?: openssl_pkey_get_private('file://' . $path);
        }
        // 纯 base64（支付宝后台复制出来常是这种）：补 PEM 头尾按公钥试
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem) ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->privateKey !== null && $this->alipayPublicKey !== null;
    }

    // ------------------------------------------------------------------

    /** 按官方规则拼待签名串并 RSA2 签名 */
    private function sign(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) continue;
            $pairs[] = $k . '=' . $v;
        }
        $stringToSign = implode('&', $pairs);
        openssl_sign($stringToSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    /** 验签（异步通知用，支付宝公钥） */
    private function verifySign(array $params): bool
    {
        $sign = (string)($params['sign'] ?? '');
        if ($sign === '' || $this->alipayPublicKey === null) return false;
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) continue;
            $pairs[] = $k . '=' . $v;
        }
        return openssl_verify(implode('&', $pairs), base64_decode($sign), $this->alipayPublicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** 请求网关（POST 表单，JSON 输出） */
    private function callGateway(array $bizParams): array
    {
        $params = [
            'app_id'      => $this->appId,
            'method'      => $bizParams['_method'],
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => shareUrl('pay_notify') . '?channel=alipay',
        ];
        unset($bizParams['_method']);
        $params['biz_content'] = json_encode($bizParams, JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->sign($params);

        $resp = Http::postForm($this->gateway, $params);
        return is_array($resp['json']) ? $resp['json'] : [];
    }

    // ------------------------------------------------------------------

    public function createOrder(string $orderNo, int $amountFen, string $subject): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'qr' => '', 'message' => '支付宝未配置完整'];

        $result = $this->callGateway([
            '_method'      => 'alipay.trade.precreate',
            'out_trade_no' => $orderNo,
            'total_amount' => number_format($amountFen / 100, 2, '.', ''),   // 元，两位小数
            'subject'      => $subject,
        ]);
        $resp = $result['alipay_trade_precreate_response'] ?? [];
        if (($resp['code'] ?? '') === '10000' && !empty($resp['qr_code'])) {
            return ['ok' => true, 'qr' => (string)$resp['qr_code'], 'message' => '下单成功'];
        }
        $msg = ($resp['sub_msg'] ?? '') ?: ($resp['msg'] ?? '网关无响应');
        return ['ok' => false, 'qr' => '', 'message' => '支付宝下单失败：' . $msg];
    }

    public function queryOrder(string $orderNo): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'paid' => false, 'trade_no' => '', 'message' => '未配置'];

        $result = $this->callGateway([
            '_method'      => 'alipay.trade.query',
            'out_trade_no' => $orderNo,
        ]);
        $resp = $result['alipay_trade_query_response'] ?? [];
        if (($resp['code'] ?? '') === '10000') {
            $paid = in_array($resp['trade_status'] ?? '', ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);
            return ['ok' => true, 'paid' => $paid, 'trade_no' => (string)($resp['trade_no'] ?? ''), 'message' => $resp['trade_status'] ?? ''];
        }
        // 40004 ACQ.TRADE_NOT_EXIST = 订单不存在
        return ['ok' => false, 'paid' => false, 'trade_no' => '', 'message' => '查单失败：' . ($resp['sub_msg'] ?? $resp['msg'] ?? '')];
    }

    /**
     * 异步通知验签：支付宝 POST 表单参数
     * 成功必须原样回写 "success"（小写）纯文本
     */
    public function verifyNotify(string $rawBody): array
    {
        $params = $_POST;
        $failReply = 'fail';
        if (empty($params['out_trade_no'])) {
            return ['ok' => false, 'order_no' => '', 'paid' => false, 'trade_no' => '', 'reply' => $failReply, 'message' => '通知缺少参数'];
        }
        if (!$this->verifySign($params)) {
            return ['ok' => false, 'order_no' => (string)$params['out_trade_no'], 'paid' => false, 'trade_no' => '', 'reply' => $failReply, 'message' => '支付宝通知验签失败'];
        }
        $paid = in_array($params['trade_status'] ?? '', ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);
        return [
            'ok'       => true,
            'order_no' => (string)$params['out_trade_no'],
            'paid'     => $paid,
            'trade_no' => (string)($params['trade_no'] ?? ''),
            'reply'    => 'success',
            'message'  => '',
        ];
    }
}
