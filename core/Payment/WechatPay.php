<?php
/**
 * 微信支付 · Native 下单 v3 —— 按官方规范实现（零 SDK）
 * 官方文档：
 *   签名：https://pay.weixin.qq.com/doc/v3/merchant/development/interface-rules/signature-serialization
 *   Native 下单：https://pay.weixin.qq.com/doc/v3/merchant/apis/native-payment/direct-jsons/native-prepay.html
 *   回调：https://pay.weixin.qq.com/doc/v3/merchant/development/interface-rules/notify
 *
 * 需要后台配置：
 *   wechatpay_mchid        商户号
 *   wechatpay_appid        应用 AppID（公众号/小程序/APP 任一绑定商户号）
 *   wechatpay_serial       商户 API 证书序列号
 *   wechatpay_v3key        APIv3 密钥（32 字节，回调解密用）
 *   wechatpay_private_key  商户 API 私钥（apiclient_key.pem 内容或 PEM 路径）
 *
 * 签名算法（Authorization: WECHATPAY2-SHA256-RSA2048）：
 *   message = "POST\n{path}\n{timestamp}\n{nonce}\n{body}\n"
 *   sign    = base64( SHA256withRSA( private_key, message ) )
 *
 * 回调解密：AEAD_AES_256_GCM，key = APIv3 密钥，AAD = associated_data
 */
class WechatPay implements PaymentInterface
{
    private const GATEWAY = 'https://api.mch.weixin.qq.com';

    private string $mchid;
    private string $appid;
    private string $serial;
    private string $v3key;
    /** @var resource|string 私钥 */
    private $privateKey;

    public function __construct()
    {
        $this->mchid  = (string)(getSetting('wechatpay_mchid') ?? '');
        $this->appid  = (string)(getSetting('wechatpay_appid') ?? '');
        $this->serial = (string)(getSetting('wechatpay_serial') ?? '');
        $this->v3key  = (string)(getSetting('wechatpay_v3key') ?? '');
        $this->privateKey = $this->loadPrivateKey();
    }

    public function name(): string { return 'wechat'; }

    private function loadPrivateKey()
    {
        $key = (string)(getSetting('wechatpay_private_key') ?? '');
        if ($key === '') return null;
        // 支持两种填法：PEM 全文（含 BEGIN 行）或磁盘绝对路径
        if (str_contains($key, 'BEGIN')) {
            return openssl_pkey_get_private($key);
        }
        $path = str_replace('\\', '/', $key);
        if (is_file($path)) {
            return openssl_pkey_get_private('file://' . $path);
        }
        return null;
    }

    public function isConfigured(): bool
    {
        return $this->mchid !== '' && $this->appid !== '' && $this->serial !== ''
            && $this->v3key !== '' && $this->privateKey !== null;
    }

    // ------------------------------------------------------------------
    //  v3 签名 & 请求
    // ------------------------------------------------------------------

    /** 生成 WECHATPAY2-SHA256-RSA2048 Authorization 头 */
    private function authorization(string $method, string $path, string $body): string
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        openssl_sign($message, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $sign = base64_encode($signature);

        return 'WECHATPAY2-SHA256-RSA2048 '
            . 'mchid="' . $this->mchid . '",'
            . 'nonce_str="' . $nonce . '",'
            . 'signature="' . $sign . '",'
            . 'timestamp="' . $timestamp . '",'
            . 'serial_no="' . $this->serial . '"';
    }

    /** 发 v3 请求并解 JSON */
    private function request(string $method, string $path, ?array $data): array
    {
        $body = $data === null ? '' : json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Authorization: ' . $this->authorization($method, $path, $body),
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TangWo-disk/1.0',
        ];
        $resp = Http::request($method, self::GATEWAY . $path, $body, $headers, 20);
        return ['status' => $resp['status'], 'json' => $resp['json'], 'body' => $resp['body']];
    }

    // ------------------------------------------------------------------
    //  接口实现
    // ------------------------------------------------------------------

    public function createOrder(string $orderNo, int $amountFen, string $subject): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'qr' => '', 'message' => '微信支付未配置完整'];

        $resp = $this->request('POST', '/v3/pay/transactions/native', [
            'appid'        => $this->appid,
            'mchid'        => $this->mchid,
            'description'  => $subject,
            'out_trade_no' => $orderNo,
            'notify_url'   => shareUrl('pay_notify') . '?channel=wechat',
            'amount'       => ['total' => $amountFen, 'currency' => 'CNY'],
        ]);
        $json = $resp['json'];
        if ($resp['status'] === 200 && !empty($json['code_url'])) {
            return ['ok' => true, 'qr' => (string)$json['code_url'], 'message' => '下单成功'];
        }
        $msg = $json['message'] ?? ($json['code'] ?? ('HTTP ' . $resp['status']));
        $detail = $json['detail'] ?? '';
        return ['ok' => false, 'qr' => '', 'message' => '微信下单失败：' . $msg . ($detail ? ' - ' . $detail : '')];
    }

    public function queryOrder(string $orderNo): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'paid' => false, 'trade_no' => '', 'message' => '未配置'];

        $path = '/v3/pay/transactions/out-trade-no/' . $orderNo . '?mchid=' . $this->mchid;
        $resp = $this->request('GET', $path, null);
        $json = $resp['json'];
        if ($resp['status'] === 200) {
            $paid = ($json['trade_state'] ?? '') === 'SUCCESS';
            return ['ok' => true, 'paid' => $paid, 'trade_no' => (string)($json['transaction_id'] ?? ''), 'message' => $json['trade_state'] ?? ''];
        }
        // 404 = 订单不存在（还没下单或已关单）
        return ['ok' => false, 'paid' => false, 'trade_no' => '', 'message' => '查单失败：' . ($json['message'] ?? ('HTTP ' . $resp['status']))];
    }

    public function verifyNotify(string $rawBody): array
    {
        $fail = ['ok' => false, 'order_no' => '', 'paid' => false, 'trade_no' => '', 'reply' => json_encode(['code' => 'FAIL', 'message' => '失败']), 'message' => ''];

        $json = json_decode($rawBody, true);
        if (!is_array($json) || ($json['event_type'] ?? '') !== 'TRANSACTION.SUCCESS') {
            // 非支付成功事件（如退款）直接确认收到，不当作失败
            $ack = json_encode(['code' => 'SUCCESS', 'message' => '成功']);
            return ['ok' => true, 'order_no' => '', 'paid' => false, 'trade_no' => '', 'reply' => $ack, 'message' => '忽略非支付事件'];
        }
        $resource = $json['resource'] ?? [];
        $plain = $this->decryptResource(
            (string)($resource['ciphertext'] ?? ''),
            (string)($resource['nonce'] ?? ''),
            (string)($resource['associated_data'] ?? '')
        );
        if ($plain === null) {
            $fail['message'] = '回调解密失败（APIv3 密钥不匹配？）';
            return $fail;
        }
        $data = json_decode($plain, true);
        if (!is_array($data) || ($data['trade_state'] ?? '') !== 'SUCCESS') {
            $fail['message'] = '回调订单状态非 SUCCESS';
            return $fail;
        }
        return [
            'ok'       => true,
            'order_no' => (string)($data['out_trade_no'] ?? ''),
            'paid'     => true,
            'trade_no' => (string)($data['transaction_id'] ?? ''),
            'reply'    => json_encode(['code' => 'SUCCESS', 'message' => '成功']),
            'message'  => '',
        ];
    }

    /**
     * AEAD_AES_256_GCM 解密回调报文（v3 规范：key=APIv3密钥, nonce=12字节, AAD=associated_data）
     */
    private function decryptResource(string $ciphertextB64, string $nonce, string $aad): ?string
    {
        $ciphertext = base64_decode($ciphertextB64, true);
        if ($ciphertext === false || $nonce === '' || strlen($this->v3key) !== 32) return null;
        $tag = substr($ciphertext, -16);
        $data = substr($ciphertext, 0, -16);
        $plain = openssl_decrypt($data, 'aes-256-gcm', $this->v3key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        return $plain === false ? null : $plain;
    }
}
