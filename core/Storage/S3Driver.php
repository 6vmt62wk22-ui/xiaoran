<?php
/**
 * AWS S3 / S3 兼容 驱动：零依赖，纯 curl + AWS Signature V4 实现。
 * 支持官方 S3 与兼容服务（Cloudflare R2、MinIO 等，填 endpoint 即可）。
 * 对应你 Cloudreve 规划里的「AWS S3」引擎。
 *
 * 注意：SigV4 按 AWS 规范实现，沙箱无凭证未实跑，上线前请用小文件做一次 put + url 验证。
 */
class S3Driver implements StorageInterface {
    private array $cfg;

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    public function isRemote(): bool { return true; }

    private function host(): string {
        if (!empty($this->cfg['endpoint'])) return rtrim($this->cfg['endpoint'], '/');
        return 'https://' . $this->cfg['bucket'] . '.s3.' . $this->cfg['region'] . '.amazonaws.com';
    }

    /** AWS SigV4 签名，返回预签名所需信息 */
    private function signV4(string $method, string $key, string $payloadHash, array $extraHeaders = [], array $query = []): array {
        $amzdate  = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        $region   = $this->cfg['region'];
        $service  = 's3';
        $scope    = $datestamp . '/' . $region . '/' . $service . '/aws4_request';
        $host     = parse_url($this->host(), PHP_URL_HOST);

        $headers = array_merge(['host' => $host], $extraHeaders);
        $hnames  = array_keys($headers); sort($hnames);
        $canonicalHeaders = '';
        foreach ($hnames as $n) $canonicalHeaders .= strtolower($n) . ':' . trim((string)$headers[$n]) . "\n";
        $signedHeaders = implode(';', array_map('strtolower', $hnames));

        ksort($query);
        $canonicalQuery = '';
        foreach ($query as $k => $v) $canonicalQuery .= $k . '=' . rawurlencode($v) . '&';
        $canonicalQuery = rtrim($canonicalQuery, '&');

        $canonicalRequest = $method . "\n/" . ltrim($key, '/') . "\n" . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $kDate   = hash_hmac('sha256', 'AWS4' . $this->cfg['secret_key'], $datestamp, true);
        $kRegion = hash_hmac('sha256', $kDate, $region, true);
        $kService = hash_hmac('sha256', $kRegion, $service, true);
        $kSigning = hash_hmac('sha256', $kService, 'aws4_request', true);
        $signature = hash_hmac('sha256', $kSigning, $stringToSign);

        return [$amzdate, $scope, $signedHeaders, $signature, $canonicalQuery];
    }

    /** 预签名下载直链（GET，URL 携带 SigV4 参数） */
    public function url(string $key, int $expire = 600): string {
        $amzdate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        $scope = $datestamp . '/' . $this->cfg['region'] . '/s3/aws4_request';
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->cfg['access_key'] . '/' . $scope,
            'X-Amz-Date' => $amzdate,
            'X-Amz-Expires' => (string)$expire,
            'X-Amz-SignedHeaders' => 'host',
        ];
        [, , , $signature, $canonicalQuery] = $this->signV4('GET', $key, 'UNSIGNED-PAYLOAD', [], $query);
        return $this->host() . '/' . ltrim($key, '/') . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    public function put(string $localTmp, string $key): bool {
        if (!is_file($localTmp)) return false;
        $body = file_get_contents($localTmp);
        $payloadHash = hash('sha256', $body);
        [, $scope, $signedHeaders, $signature] = $this->signV4('PUT', $key, $payloadHash,
            ['x-amz-content-sha256' => $payloadHash, 'x-amz-date' => gmdate('Ymd\THis\Z'), 'Content-Type' => 'application/octet-stream']);
        $auth = 'AWS4-HMAC-SHA256 Credential=' . $this->cfg['access_key'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $ch = curl_init($this->host() . '/' . ltrim($key, '/'));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
                'Authorization: ' . $auth,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . gmdate('Ymd\THis\Z'),
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($body),
            ]]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($localTmp);
        return $code >= 200 && $code < 300;
    }

    public function delete(string $key): bool {
        $payloadHash = hash('sha256', '');
        [, $scope, $signedHeaders, $signature] = $this->signV4('DELETE', $key, $payloadHash,
            ['x-amz-content-sha256' => $payloadHash, 'x-amz-date' => gmdate('Ymd\THis\Z')]);
        $auth = 'AWS4-HMAC-SHA256 Credential=' . $this->cfg['access_key'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $ch = curl_init($this->host() . '/' . ltrim($key, '/'));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'x-amz-content-sha256: ' . $payloadHash, 'x-amz-date: ' . gmdate('Ymd\THis\Z')]]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    public function exists(string $key): bool {
        $payloadHash = hash('sha256', '');
        [, $scope, $signedHeaders, $signature] = $this->signV4('HEAD', $key, $payloadHash,
            ['x-amz-content-sha256' => $payloadHash, 'x-amz-date' => gmdate('Ymd\THis\Z')]);
        $auth = 'AWS4-HMAC-SHA256 Credential=' . $this->cfg['access_key'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $ch = curl_init($this->host() . '/' . ltrim($key, '/'));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'HEAD', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'x-amz-content-sha256: ' . $payloadHash, 'x-amz-date: ' . gmdate('Ymd\THis\Z')]]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    public function stream(string $key, string $filename): void {
        header('Location: ' . $this->url($key));
        exit;
    }
}
