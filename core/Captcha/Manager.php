<?php
/**
 * 验证码工厂：按后台设置 captcha_driver 切换驱动
 * local（默认）/ geetest（极验 v4）/ tencent（腾讯云 2.0）
 * 第三方驱动密钥未配置时自动回退 local，保证站点可用
 */
class CaptchaManager
{
    private static ?CaptchaInterface $driver = null;

    public static function driver(): CaptchaInterface
    {
        if (self::$driver !== null) return self::$driver;

        $name = (string)(getSetting('captcha_driver') ?: 'local');
        $driver = match ($name) {
            'geetest' => new GeeTestCaptcha(),
            'tencent' => new TencentCaptcha(),
            default   => new LocalCaptcha(),
        };
        // 第三方密钥没配全 → 回退本地，别把登录页搞挂
        if (!$driver->isConfigured()) {
            $driver = new LocalCaptcha();
        }
        return self::$driver = $driver;
    }

    /** 服务端统一校验入口（dashboard.php 登录/注册 POST 调用） */
    public static function verify(array $input): bool
    {
        return self::driver()->verify($input);
    }

    /** 表单 HTML（驱动感知） */
    public static function html(): string
    {
        return self::driver()->renderHtml();
    }

    /** 页面尾 JS（驱动感知） */
    public static function js(): string
    {
        return self::driver()->renderJs();
    }
}
