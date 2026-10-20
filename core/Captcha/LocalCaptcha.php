<?php
/**
 * 本地内置图形验证码（默认驱动，无外部依赖）
 * 复用 functions.php 的 issueCaptcha() / renderCaptchaSvg()，session 存储一次性校验
 */
class LocalCaptcha implements CaptchaInterface
{
    public function name(): string { return 'local'; }

    public function isConfigured(): bool { return true; }

    public function verify(array $input): bool
    {
        $in = strtolower(trim((string)($input['captcha'] ?? '')));
        if ($in === '' || empty($_SESSION['captcha'])) return false;
        $ok = hash_equals($_SESSION['captcha'], $in);
        unset($_SESSION['captcha']);   // 一次性，无论对错都作废
        return $ok;
    }

    public function renderHtml(): string
    {
        return '<div class="au-input-wrap au-captcha">'
            . '<input type="text" id="captchaInput" name="captcha" maxlength="4" placeholder="输入右侧验证码" autocomplete="off" />'
            . '<img class="captcha-img" src="' . url('captcha') . '.php?t=' . time() . '" alt="验证码" title="点击刷新" onclick="this.src=\'' . url('captcha') . '.php?t=\'+Date.now()" />'
            . '</div>';
    }

    public function renderJs(): string { return ''; }
}
