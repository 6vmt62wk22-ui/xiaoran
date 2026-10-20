<?php
/**
 * 腾讯云验证码 2.0（TCaptcha）驱动 —— 按官方服务端部署规范实现
 * 官方文档：https://cloud.tencent.com/document/product/1110/36941
 *
 * 前端：加载 https://turing.captcha.qcloud.com/TCaptcha.js
 *       new TencentCaptcha(CaptchaAppId, callback) → 用户通过后回调拿 ticket / randstr
 * 服务端：调用 DescribeCaptchaResult（TC3-HMAC-SHA256 签名）
 *   POST https://captcha.tencentcloudapi.com
 *   X-TC-Action: DescribeCaptchaResult, X-TC-Version: 2019-07-22
 *   Body: { CaptchaType: 9, Ticket, Randstr, UserIp, CaptchaAppId, AppSecretKey }
 *   返回 Response.CaptchaCode === 1 即通过（7=票据已用/不存在，8=过期）
 */
class TencentCaptcha implements CaptchaInterface
{
    private string $appId;
    private string $appSecret;

    public function __construct()
    {
        $this->appId     = (string)(getSetting('tencent_captcha_appid') ?? '');
        $this->appSecret = (string)(getSetting('tencent_captcha_secret') ?? '');
    }

    public function name(): string { return 'tencent'; }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '';
    }

    public function verify(array $input): bool
    {
        $ticket  = trim((string)($input['tc_ticket'] ?? ''));
        $randstr = trim((string)($input['tc_randstr'] ?? ''));
        if ($ticket === '' || $randstr === '') return false;

        $client = new Tc3Client(
            (string)(getSetting('tencent_secret_id') ?? ''),
            (string)(getSetting('tencent_secret_key') ?? ''),
            'captcha', 'captcha.tencentcloudapi.com', '2019-07-22'
        );
        $resp = $client->call('DescribeCaptchaResult', [
            'CaptchaType'  => 9,                       // 9 = 滑块/智能验证
            'Ticket'       => $ticket,
            'Randstr'      => $randstr,
            'UserIp'       => $_SERVER['REMOTE_ADDR'] ?? '',
            'CaptchaAppId' => (int)$this->appId,
            'AppSecretKey' => $this->appSecret,
        ]);
        if ($resp['error'] !== null) return false;
        // CaptchaCode: 1 通过；7 票据不存在或已使用；8 票据过期；-1 参数错误
        return (int)($resp['json']['Response']['CaptchaCode'] ?? -1) === 1;
    }

    public function renderHtml(): string
    {
        return '<button type="button" class="tc-captcha-btn" id="tcCaptchaBtn">'
            . '<i class="fas fa-shield-halved"></i> 点击进行安全验证</button>'
            . '<input type="hidden" name="tc_ticket" id="tcTicket" />'
            . '<input type="hidden" name="tc_randstr" id="tcRandstr" />'
            . '<span class="tc-status" id="tcStatus"></span>';
    }

    public function renderJs(): string
    {
        $appId = $this->appId;
        return <<<HTML
<script src="https://turing.captcha.qcloud.com/TCaptcha.js"></script>
<script>
(function () {
    var passed = false;
    var btn = document.getElementById('tcCaptchaBtn');
    var captcha = new TencentCaptcha('{$appId}', function (res) {
        if (res.ret === 0) {
            passed = true;
            document.getElementById('tcTicket').value  = res.ticket;
            document.getElementById('tcRandstr').value = res.randstr;
            var s = document.getElementById('tcStatus');
            if (s) { s.textContent = '✓ 验证通过'; s.style.color = '#16a34a'; }
            if (btn) { btn.classList.add('ok'); btn.innerHTML = '<i class="fas fa-circle-check"></i> 验证通过'; }
        } else {
            passed = false;
            var s2 = document.getElementById('tcStatus');
            if (s2) { s2.textContent = ''; }
        }
    });
    if (btn) btn.addEventListener('click', function () { captcha.show(); });
    document.querySelectorAll('form[data-captcha="tencent"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!passed) { e.preventDefault(); captcha.show(); }
        });
    });
})();
</script>
HTML;
    }
}
