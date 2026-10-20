<?php
/**
 * 腾讯云 TC3-HMAC-SHA256 签名客户端（官方规范实现）
 * 文档：https://cloud.tencent.com/document/api/213/30654
 * 被以下模块复用：
 *   - core/Captcha/TencentCaptcha.php（验证码 DescribeCaptchaResult）
 *   - core/Sms/TencentSms.php（短信 SendSms）
 */
class Tc3Client
{
    public function __construct(
        private string $secretId,
        private string $secretKey,
        private string $service,    // 如 captcha / sms
        private string $host,       // 如 captcha.tencentcloudapi.com
        private string $version,    // 如 2019-07-22
        private ?string $region = null   // 验证码不需要，短信需要（如 ap-guangzhou）
    ) {}

    /**
     * 调用 API（POST + JSON，腾讯云 TC3 标准格式）
     * @return array{status:int, json:array|null, body:string, error:string|null}
     */
    public function call(string $action, array $params): array
    {
        $timestamp = time();
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE);

        // ========== 第一步：拼接 CanonicalRequest ==========
        $httpRequestMethod = 'POST';
        $canonicalUri = '/';
        $canonicalQueryString = '';
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:" . $this->host . "\n";
        $signedHeaders = 'content-type;host';
        $hashedRequestPayload = hash('sha256', $payload);
        $canonicalRequest = $httpRequestMethod . "\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $hashedRequestPayload;

        // ========== 第二步：拼接待签名字符串 ==========
        $date = gmdate('Y-m-d', $timestamp);   // UTC 日期
        $credentialScope = $date . '/' . $this->service . '/tc3_request';
        $stringToSign = 'TC3-HMAC-SHA256' . "\n"
            . $timestamp . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        // ========== 第三步：计算签名 ==========
        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $this->secretKey, true);
        $secretService = hash_hmac('sha256', $this->service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

        // ========== 第四步：拼接 Authorization ==========
        $authorization = 'TC3-HMAC-SHA256 '
            . 'Credential=' . $this->secretId . '/' . $credentialScope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $this->host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . $this->version,
        ];
        if ($this->region) {
            $headers[] = 'X-TC-Region: ' . $this->region;
        }

        $url = 'https://' . $this->host . '/';
        $resp = Http::request('POST', $url, $payload, $headers, 15);

        $err = null;
        if ($resp['status'] === 0) {
            $err = '网络请求失败：无法连接 ' . $this->host;
        } elseif (!is_array($resp['json'])) {
            $err = '响应不是合法 JSON';
        } elseif (isset($resp['json']['Response']['Error'])) {
            $err = $resp['json']['Response']['Error']['Code'] . ': '
                 . ($resp['json']['Response']['Error']['Message'] ?? '');
        }

        return ['status' => $resp['status'], 'json' => $resp['json'], 'body' => $resp['body'], 'error' => $err];
    }
}
