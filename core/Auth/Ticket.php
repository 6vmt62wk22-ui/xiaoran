<?php
/**
 * 无状态 HMAC 票据：支撑未来 app / auth / io / dl 多子域集群的跨节点认证。
 * 结构与 JWT 类似（header.payload.signature，base64url 编码），但签名用共享密钥 HMAC-SHA256。
 * 服务端不存会话，只校验签名与过期时间，天然适配多节点横向扩展。
 *
 * 用法：
 *   $t = Ticket::issue($userId, ['role' => 'io'], 900);   // 签发 15 分钟票据
 *   $claims = Ticket::verify($t);                          // 校验，失败返回 null
 *   if ($claims) $uid = $claims['uid'];
 */
class Ticket {
    private static function secret(): string {
        return defined('TICKET_SECRET') ? TICKET_SECRET : 'change-me-in-config';
    }

    private static function b64url(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string {
        $s = strtr($s, '-_', '+/');
        return base64_decode(str_pad($s, strlen($s) % 4 === 0 ? 0 : 4 - strlen($s) % 4, '=', STR_PAD_RIGHT));
    }

    /** 签发票据 */
    public static function issue(int $userId, array $claims = [], int $ttl = 900): string {
        $header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'xc-ticket']));
        $now     = time();
        $payloadArr = array_merge([
            'uid' => $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
        ], $claims);
        $payload = self::b64url(json_encode($payloadArr));
        $sig     = self::b64url(hash_hmac('sha256', $header . '.' . $payload, self::secret(), true));
        return $header . '.' . $payload . '.' . $sig;
    }

    /** 校验票据，返回载荷数组（含 uid）或 null */
    public static function verify(string $ticket): ?array {
        $parts = explode('.', $ticket);
        if (count($parts) !== 3) return null;
        [$header, $payload, $sig] = $parts;
        $expected = self::b64url(hash_hmac('sha256', $header . '.' . $payload, self::secret(), true));
        if (!hash_equals($expected, $sig)) return null;
        $data = json_decode(self::b64urlDecode($payload), true);
        if (!$data || !isset($data['exp']) || $data['exp'] < time()) return null;
        return $data;
    }
}
