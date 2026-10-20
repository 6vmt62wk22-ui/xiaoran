<?php
/**
 * 短信驱动统一接口
 */
interface SmsInterface
{
    /** 驱动名：aliyun / tencent */
    public function name(): string;

    /**
     * 发送短信
     * @param string $phone 国内手机号（11 位），驱动内部转 E.164
     * @param array  $params 模板变量（按序，如 ['123456']）
     * @return array{ok:bool, message:string}
     */
    public function send(string $phone, array $params): array;

    /** 驱动是否已配置可用 */
    public function isConfigured(): bool;
}
