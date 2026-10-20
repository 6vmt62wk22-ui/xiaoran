<?php
/**
 * 腾讯云短信驱动 —— 按官方 TC3-HMAC-SHA256 规范实现
 * 官方文档：https://cloud.tencent.com/document/product/382/55981
 *
 * API：SendSms（版本 2021-01-11）
 *   POST https://sms.tencentcloudapi.com
 *   Body: { PhoneNumberSet: ["+86137..."], SmsSdkAppId, SignName, TemplateId, TemplateParamSet: ["123456"] }
 *   成功：Response.SendStatusSet[0].Code === "Ok"
 */
class TencentSms implements SmsInterface
{
    public function name(): string { return 'tencent'; }

    public function isConfigured(): bool
    {
        return (string)(getSetting('tencent_secret_id') ?? '') !== ''
            && (string)(getSetting('tencent_secret_key') ?? '') !== ''
            && (string)(getSetting('tencent_sms_appid') ?? '') !== ''
            && (string)(getSetting('tencent_sms_sign') ?? '') !== ''
            && (string)(getSetting('tencent_sms_template') ?? '') !== '';
    }

    public function send(string $phone, array $params): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'message' => '腾讯云短信未配置完整'];

        $client = new Tc3Client(
            (string)(getSetting('tencent_secret_id') ?? ''),
            (string)(getSetting('tencent_secret_key') ?? ''),
            'sms', 'sms.tencentcloudapi.com', '2021-01-11',
            (string)(getSetting('tencent_sms_region') ?: 'ap-guangzhou')
        );
        $resp = $client->call('SendSms', [
            'PhoneNumberSet'   => ['+86' . $phone],   // 国内号转 E.164
            'SmsSdkAppId'      => (string)(getSetting('tencent_sms_appid') ?? ''),
            'SignName'         => (string)(getSetting('tencent_sms_sign') ?? ''),
            'TemplateId'       => (string)(getSetting('tencent_sms_template') ?? ''),
            'TemplateParamSet' => array_values($params),
        ]);
        if ($resp['error'] !== null) {
            return ['ok' => false, 'message' => '腾讯云短信：' . $resp['error']];
        }
        $status = $resp['json']['Response']['SendStatusSet'][0] ?? null;
        if ($status && ($status['Code'] ?? '') === 'Ok') {
            return ['ok' => true, 'message' => '短信已发送'];
        }
        $msg = $status ? ($status['Code'] . ': ' . ($status['Message'] ?? '')) : '响应缺少 SendStatusSet';
        return ['ok' => false, 'message' => '腾讯云短信发送失败 ' . $msg];
    }
}
