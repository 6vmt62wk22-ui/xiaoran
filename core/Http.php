<?php
/**
 * 轻量 HTTP 客户端（零依赖，基于 stream context，支持 https）
 * 供验证码 / 短信 / 支付等对外调用厂商 API 使用
 */
class Http
{
    /**
     * POST JSON
     * @return array{status:int, body:string, json:array|null}
     */
    public static function postJson(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $hdrs = array_merge(['Content-Type: application/json'], $headers);
        return self::request('POST', $url, $payload, $hdrs, $timeout);
    }

    /**
     * POST 表单（application/x-www-form-urlencoded）
     */
    public static function postForm(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        $payload = http_build_query($data);
        $hdrs = array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers);
        return self::request('POST', $url, $payload, $hdrs, $timeout);
    }

    /**
     * GET
     */
    public static function get(string $url, array $headers = [], int $timeout = 10): array
    {
        return self::request('GET', $url, '', $headers, $timeout);
    }

    /**
     * 底层请求
     * @return array{status:int, body:string, json:array|null}
     */
    public static function request(string $method, string $url, string $payload, array $headers, int $timeout): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
                'timeout'       => $timeout,
                'ignore_errors' => true,   // 拿到 4xx/5xx 响应体而不是直接 false
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'SNI_enabled'      => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
        $json = null;
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) $json = $decoded;
        }
        return ['status' => $status, 'body' => (string)$body, 'json' => $json];
    }
}
