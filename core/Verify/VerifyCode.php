<?php
/**
 * 验证码存取（邮箱 / 短信通用）
 * 表 verify_codes：一次性使用 + 5 分钟过期 + 60 秒发码限频 + 单目标并发有效码只留最新
 */
class VerifyCode
{
    public const TTL_SECONDS   = 300;   // 验证码有效期 5 分钟
    public const RESEND_GAP    = 60;    // 同一目标 60 秒内只能发一次
    public const VERIFY_MAX    = 5;     // 同一目标最多试错 5 次（防爆破）

    /** 确保表存在（幂等，老库自动补建） */
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        db()->exec(
            "CREATE TABLE IF NOT EXISTS verify_codes (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                target     VARCHAR(191) NOT NULL COMMENT '邮箱或手机号',
                channel    ENUM('email','sms') NOT NULL,
                code       VARCHAR(10) NOT NULL,
                purpose    VARCHAR(32) NOT NULL DEFAULT 'register',
                tries      INT DEFAULT 0 COMMENT '已试错次数',
                expires_at INT UNSIGNED NOT NULL,
                used_at    INT UNSIGNED NULL DEFAULT NULL,
                ip         VARCHAR(45) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_target (target, purpose)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }

    /**
     * 生成并发送验证码（邮箱走 SMTP，短信走短信驱动）
     * @return array{ok:bool, message:string}
     */
    public static function send(string $target, string $purpose = 'register'): array
    {
        self::ensureTable();
        $target = strtolower(trim($target));
        $isEmail = filter_var($target, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = preg_match('/^1[3-9]\d{9}$/', $target) === 1;
        if (!$isEmail && !$isPhone) {
            return ['ok' => false, 'message' => '请输入正确的邮箱或手机号'];
        }
        $channel = $isEmail ? 'email' : 'sms';

        // 60 秒限频
        $stmt = db()->prepare('SELECT created_at FROM verify_codes WHERE target = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$target, $purpose]);
        $last = $stmt->fetch();
        if ($last && (time() - strtotime($last['created_at'])) < self::RESEND_GAP) {
            $wait = self::RESEND_GAP - (time() - strtotime($last['created_at']));
            return ['ok' => false, 'message' => '发送太频繁，请 ' . $wait . ' 秒后再试'];
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 发送（先发后存：发送失败不入库）
        if ($channel === 'email') {
            if (!Mailer::enabled()) {
                return ['ok' => false, 'message' => '邮件服务未配置，请联系管理员'];
            }
            try {
                Mailer::sendCode($target, $code, (int)(self::TTL_SECONDS / 60));
            } catch (RuntimeException $e) {
                return ['ok' => false, 'message' => '邮件发送失败：' . $e->getMessage()];
            }
        } else {
            $r = SmsManager::send($target, [$code]);
            if (!$r['ok']) return $r;
        }

        // 同目标旧验证码全部作废（只留最新一条有效）
        db()->prepare('UPDATE verify_codes SET used_at = ? WHERE target = ? AND purpose = ? AND used_at IS NULL')
            ->execute([time(), $target, $purpose]);
        db()->prepare('INSERT INTO verify_codes (target, channel, code, purpose, expires_at, ip, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$target, $channel, $code, $purpose, time() + self::TTL_SECONDS, $_SERVER['REMOTE_ADDR'] ?? '']);

        return ['ok' => true, 'message' => '验证码已发送至 ' . ($isEmail ? '邮箱' : '手机') . '，请注意查收'];
    }

    /**
     * 校验验证码（一次性：对错都作废当前码，防重放/防爆破）
     */
    public static function check(string $target, string $inputCode, string $purpose = 'register'): bool
    {
        self::ensureTable();
        $target = strtolower(trim($target));
        $stmt = db()->prepare('SELECT * FROM verify_codes WHERE target = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$target, $purpose]);
        $row = $stmt->fetch();
        if (!$row) return false;

        // 过期 / 超试错次数 → 作废
        if ($row['expires_at'] < time() || (int)$row['tries'] >= self::VERIFY_MAX) {
            db()->prepare('UPDATE verify_codes SET used_at = ? WHERE id = ?')->execute([time(), $row['id']]);
            return false;
        }

        if (hash_equals($row['code'], trim($inputCode))) {
            db()->prepare('UPDATE verify_codes SET used_at = ? WHERE id = ?')->execute([time(), $row['id']]);
            return true;
        }
        // 猜错：计一次试错
        db()->prepare('UPDATE verify_codes SET tries = tries + 1 WHERE id = ?')->execute([$row['id']]);
        return false;
    }
}
