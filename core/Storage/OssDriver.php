<?php
/**
 * 阿里云 OSS 驱动：零依赖，纯 curl + V1 签名实现 REST API。
 * 对应你 Cloudreve 规划里的「阿里云 OSS」引擎。
 *
 * 注意：签名按 OSS V1 规范实现，沙箱无凭证未实跑，上线前请用小文件做一次 put + url 验证。
 */
class OssDriver implements StorageInterface {
    private array $cfg;

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    public function isRemote(): bool { return true; }

    private function host(): string {
        return 'https://' . $this->cfg['bucket'] . '.' . $this->cfg['endpoint'];
    }

    private function gmt(): string {
        return gmdate('D, d M Y H:i:s \G\M\T');
    }

    /** OSS V1 签名（Authorization 值） */
    private function auth(string $method, string $key, string $date, string $contentType = '', string $contentMd5 = ''): string {
        $resource = '/' . $this->cfg['bucket'] . '/' . ltrim($key, '/');
        $stringToSign = $method . "\n" . $contentMd5 . "\n" . $contentType . "\n" . $date . "\n" . $resource;
        $sig = base64_encode(hash_hmac('sha1', $stringToSign, $this->cfg['access_key_secret'], true));
        return 'OSS ' . $this->cfg['access_key_id'] . ':' . $sig;
    }

    /** 预签名下载直链（GET，URL 携带签名） */
    public function url(string $key, int $expire = 600): string {
        $host = $this->host();
        $resource = '/' . $this->cfg['bucket'] . '/' . ltrim($key, '/');
        $expires = time() + $expire;
        $sig = base64_encode(hash_hmac('sha1', "GET\n\n\n{$expires}\n{$resource}", $this->cfg['access_key_secret'], true));
        return $host . '/' . ltrim($key, '/')
            . '?OSSAccessKeyId=' . rawurlencode($this->cfg['access_key_id'])
            . '&Expires=' . $expires
            . '&Signature=' . rawurlencode($sig);
    }

    public function put(string $localTmp, string $key): bool {
        if (!is_file($localTmp)) return false;
        $host = $this->host();
        $url  = $host . '/' . ltrim($key, '/');
        $date = $this->gmt();
        $size = filesize($localTmp);
        $fp   = fopen($localTmp, 'rb');
        $auth = $this->auth('PUT', $key, $date, 'application/octet-stream');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $auth,
                'Date: ' . $date,
                'Content-Type: application/octet-stream',
                'Content-Length: ' . $size,
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_resource($fp)) fclose($fp);
        @unlink($localTmp);
        return $code >= 200 && $code < 300;
    }

    public function delete(string $key): bool {
        $host = $this->host();
        $url  = $host . '/' . ltrim($key, '/');
        $date = $this->gmt();
        $auth = $this->auth('DELETE', $key, $date);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Date: ' . $date]]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    public function exists(string $key): bool {
        $host = $this->host();
        $url  = $host . '/' . ltrim($key, '/');
        $date = $this->gmt();
        $auth = $this->auth('HEAD', $key, $date);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'HEAD', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Date: ' . $date]]);
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
