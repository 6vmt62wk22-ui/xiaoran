<?php
/**
 * 邮件发送器（读后台 SMTP 设置，统一出口）
 * 设置项：smtp_enabled / smtp_host / smtp_port / smtp_secure / smtp_user / smtp_pass
 *         smtp_from / smtp_from_name
 */
class Mailer
{
    /** SMTP 是否已配置并启用 */
    public static function enabled(): bool
    {
        return getSetting('smtp_enabled') === '1'
            && self::host() !== '' && self::user() !== '' && self::pass() !== '';
    }

    public static function host(): string  { return (string)(getSetting('smtp_host') ?: ''); }
    public static function port(): int     { return (int)(getSetting('smtp_port') ?: 465); }
    public static function user(): string  { return (string)(getSetting('smtp_user') ?: ''); }
    public static function pass(): string  { return (string)(getSetting('smtp_pass') ?: ''); }
    public static function from(): string  { return (string)(getSetting('smtp_from') ?: self::user()); }
    public static function fromName(): string { return (string)(getSetting('smtp_from_name') ?: (getSetting('site_name') ?: 'TangWo 网盘')); }
    public static function secure(): string
    {
        $s = (string)(getSetting('smtp_secure') ?: 'ssl');
        return in_array($s, ['ssl', 'tls', 'none'], true) ? $s : 'ssl';
    }

    /**
     * 发送 HTML 邮件
     * @throws RuntimeException 失败时抛出（含 SMTP 详细错误）
     */
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $smtp = new SmtpClient(self::host(), self::port(), self::user(), self::pass(), self::secure());
        return $smtp->send(self::from(), self::fromName(), $to, $subject, $htmlBody);
    }

    /** 验证码邮件模板 */
    public static function sendCode(string $to, string $code, int $ttlMinutes = 5): bool
    {
        $site = self::fromName();
        $html = '<div style="max-width:480px;margin:0 auto;font-family:-apple-system,\'PingFang SC\',\'Microsoft YaHei\',sans-serif">'
            . '<h2 style="color:#1e40af">' . h($site) . '</h2>'
            . '<p>您好，您正在注册/验证 <b>' . h($site) . '</b> 账号，验证码为：</p>'
            . '<div style="font-size:32px;font-weight:700;letter-spacing:6px;color:#1e40af;background:#eff6ff;border-radius:12px;padding:16px 24px;text-align:center;margin:16px 0">' . h($code) . '</div>'
            . '<p style="color:#64748b;font-size:13px">验证码 ' . $ttlMinutes . ' 分钟内有效。如非本人操作请忽略本邮件。</p>'
            . '</div>';
        return self::send($to, '【' . $site . '】验证码 ' . $code, $html);
    }
}
