<?php
/**
 * 短信工厂：按后台设置 sms_driver 切换（off / aliyun / tencent）
 */
class SmsManager
{
    /** 短信功能是否开启且配置完整 */
    public static function enabled(): bool
    {
        return getSetting('sms_driver') === 'aliyun' || getSetting('sms_driver') === 'tencent';
    }

    public static function driver(): ?SmsInterface
    {
        $name = (string)(getSetting('sms_driver') ?: 'off');
        $driver = match ($name) {
            'aliyun'  => new AliyunSms(),
            'tencent' => new TencentSms(),
            default   => null,
        };
        if ($driver && !$driver->isConfigured()) return null;
        return $driver;
    }

    /** 统一发送出口 @return array{ok:bool, message:string} */
    public static function send(string $phone, array $params): array
    {
        $driver = self::driver();
        if ($driver === null) {
            return ['ok' => false, 'message' => '短信服务未开启或未配置完整'];
        }
        return $driver->send($phone, $params);
    }
}
