<?php
/**
 * 阿里云短信驱动 —— 按官方 RPC 签名规范实现（HMAC-SHA1）
 * 官方文档：https://help.aliyun.com/document_detail/101414.html
 *
 * 要点（RPC API V1 签名规则）：
 *   1. 所有请求参数按 key ASCII 升序排列
 *   2. 拼接 k=v&...（值不做 URL 编码的原文）
 *   3. 待签名字符串 = "POST&%2F&" + urlencode(拼接串)   ← 注意 %2F 是转义后的 /
 *   4. 签名 = base64( HMAC-SHA1( AccessKeySecret + "&", 待签名字符串 ) )
 *   5. URL 编码用 RFC3986（PHP rawurlencode 会把 ~ 编成 %7E，必须还原）
 */
class AliyunSms implements SmsInterface
{
    public function name(): string { return 'aliyun'; }

    private string $accessKeyId;
    private string $accessKeySecret;
    private string $signName;
    private string $templateCode;

    public function __construct()
    {
        $this->accessKeyId     = (string)(getSetting('aliyun_sms_key_id') ?? '');
        $this->accessKeySecret = (string)(getSetting('aliyun_sms_key_secret') ?? '');
        $this->signName        = (string)(getSetting('aliyun_sms_sign') ?? '');
        $this->templateCode    = (string)(getSetting('aliyun_sms_template') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->accessKeyId !== '' && $this->accessKeySecret !== ''
            && $this->signName !== '' && $this->templateCode !== '';
    }

    public function send(string $phone, array $params): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'message' => '阿里云短信未配置完整'];

        $bizParams = [
            'PhoneNumbers'  => $phone,
            'SignName'      => $this->signName,
            'TemplateCode'  => $this->templateCode,
            'TemplateParam' => json_encode($params ? ['code' => $params[0]] : new stdClass(), JSON_UNESCAPED_UNICODE),
            'OutId'         => '',
        ];

        // ---------- 组装公共参数 ----------
        $apiParams = array_merge([
            'AccessKeyId'      => $this->accessKeyId,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'RegionId'         => 'cn-hangzhou',
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),   // UTC ISO8601
            'Version'          => '2017-05-25',
        ], $bizParams);

        // ---------- 计算 RPC 签名 ----------
        ksort($apiParams);
        $sortedQuery = '';
        foreach ($apiParams as $k => $v) {
            $sortedQuery .= '&' . self::encode($k) . '=' . self::encode((string)$v);
        }
        $stringToSign = 'POST&' . self::encode('/') . '&' . self::encode(substr($sortedQuery, 1));
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));
        $apiParams['Signature'] = $signature;

        // ---------- 发起请求 ----------
        $resp = Http::postForm('https://dysmsapi.aliyuncs.com/', $apiParams);
        $json = $resp['json'];
        if (!is_array($json)) {
            return ['ok' => false, 'message' => '阿里云短信接口无响应（HTTP ' . $resp['status'] . '）'];
        }
        if (($json['Code'] ?? '') === 'OK') {
            return ['ok' => true, 'message' => '短信已发送'];
        }
        return ['ok' => false, 'message' => '阿里云短信[' . ($json['Code'] ?? '?') . '] ' . ($json['Message'] ?? '发送失败')];
    }

    /** RFC3986 编码（rawurlencode 会错误转义 ~，需还原） */
    private static function encode(string $s): string
    {
        return str_replace('%7E', '~', rawurlencode($s));
    }
}
