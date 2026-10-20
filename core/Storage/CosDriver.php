<?php
/**
 * 腾讯云 COS 驱动：零依赖，纯 curl + V5 签名实现 REST API。
 * 对应你「wangpanphp」的生产存储方案。
 *
 * 注意：本文件签名逻辑已按腾讯云 COS V5 规范实现，但因沙箱无 COS 凭证，未实跑。
 * 上线前请填好 config/storage.php 的 cos 配置，并用一个小文件做一次 put + url 验证。
 */
class CosDriver implements StorageInterface {
    private array $cfg;

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    public function isRemote(): bool { return true; }

    // ===== 内部工具 =====

    private function host(): string {
        if (!empty($this->cfg['domain'])) return rtrim($this->cfg['domain'], '/');
        return 'https://' . $this->cfg['bucket'] . '.cos.' . $this->cfg['region'] . '.myqcloud.com';
    }

    /**
     * 生成 COS V5 签名（Authorization 值，不含前缀）
     */
    private function auth(string $method, string $path, array $params = [], array $headers = [], int $expire = 600): string {
        $secretId  = $this->cfg['secret_id'];
        $secretKey = $this->cfg['secret_key'];
        $now       = time();
        $signTime  = $now . ';' . ($now + $expire);

        $pKeys = array_keys($params); sort($pKeys);
        $hKeys = array_keys($headers); sort($hKeys);

        $urlParam = [];
        foreach ($pKeys as $k) $urlParam[] = $k . '=' . $params[$k];
        $urlParamStr = implode('&', $urlParam);

        $hdrStr = [];
        foreach ($hKeys as $k) $hdrStr[] = strtolower($k) . '=' . rawurlencode($headers[$k]);
        $hdrStrJoined = implode('&', $hdrStr);

        $httpString   = strtolower($method) . "\n" . $path . "\n" . $urlParamStr . "\n" . $hdrStrJoined . "\n";
        // V5 签名链：KeyTime -> SignKey -> HttpString -> StringToSign -> Signature
        $signKey      = hash_hmac('sha1', $signTime, $secretKey);
        $stringToSign = "sha1\n" . $signTime . "\n" . sha1($httpString) . "\n";
        $signature    = hash_hmac('sha1', $stringToSign, $signKey);

        return 'q-sign-algorithm=sha1&q-ak=' . $secretId
             . '&q-sign-time=' . $signTime
             . '&q-key-time=' . $signTime
             . '&q-header-list=' . implode(';', array_map('strtolower', $hKeys))
             . '&q-url-param-list=' . implode(';', array_map('strtolower', $pKeys))
             . '&q-signature=' . $signature;
    }

    // ===== 接口实现 =====

    /** 预签名下载直链（GET，带有效期） */
    public function url(string $key, int $expire = 600): string {
        $path = '/' . ltrim($key, '/');
        $host = $this->host();
        $headers = ['Host' => parse_url($host, PHP_URL_HOST)];
        return $host . $path . '?' . $this->auth('GET', $path, [], $headers, $expire);
    }

    /** 流式上传（不占内存，适配大文件） */
    public function put(string $localTmp, string $key): bool {
        $path = '/' . ltrim($key, '/');
        $host = $this->host();
        if (!is_file($localTmp)) return false;
        $size = filesize($localTmp);
        $fp   = fopen($localTmp, 'rb');

        $headers = ['Host' => parse_url($host, PHP_URL_HOST), 'Content-Length' => (string) $size];
        $auth    = $this->auth('PUT', $path, [], $headers, 600);

        $ch = curl_init($host . $path);
        curl_setopt_array($ch, [
            CURLOPT_PUT             => true,
            CURLOPT_INFILE          => $fp,
            CURLOPT_INFILESIZE      => $size,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => ['Authorization: ' . $auth, 'Content-Length: ' . $size],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_resource($fp)) fclose($fp);
        @unlink($localTmp);
        return $code >= 200 && $code < 300;
    }

    public function delete(string $key): bool {
        $path = '/' . ltrim($key, '/');
        $host = $this->host();
        $headers = ['Host' => parse_url($host, PHP_URL_HOST)];
        $auth = $this->auth('DELETE', $path, [], $headers, 600);
        $ch = curl_init($host . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: ' . $auth]]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    public function exists(string $key): bool {
        $path = '/' . ltrim($key, '/');
        $host = $this->host();
        $headers = ['Host' => parse_url($host, PHP_URL_HOST)];
        $auth = $this->auth('HEAD', $path, [], $headers, 600);
        $ch = curl_init($host . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'HEAD', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: ' . $auth]]);
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
