<?php
/**
 * 极验（GeeTest）行为验 4.0 驱动 —— 按官方服务端部署规范实现
 * 官方文档：https://docs.geetest.com/gt4/deploy/server/php
 *
 * 前端：加载 https://static.geetest.com/v4/gt4.js，initGeetest4({captchaId, product:'popup'})
 *       用户通过后回调拿 lot_number / captcha_output / pass_token / gen_time，随表单提交
 * 服务端：HMAC-SHA256 生成 sign_token，POST 二次校验接口
 *   POST https://openapi.geetest.com/do/v4/captcha/validate?captcha_id={captcha_id}
 *   Body: { lot_number, captcha_output, pass_token, gen_time, sign_token }
 *   sign_token = hex( HMAC-SHA256( captcha_key, lot_number + captcha_output + pass_token + gen_time ) )
 *   返回 status === "success" && result === "success" 即通过
 */
class GeeTestCaptcha implements CaptchaInterface
{
    private string $captchaId;
    private string $captchaKey;

    public function __construct()
    {
        $this->captchaId  = (string)(getSetting('geetest_captcha_id') ?? '');
        $this->captchaKey = (string)(getSetting('geetest_captcha_key') ?? '');
    }

    public function name(): string { return 'geetest'; }

    public function isConfigured(): bool
    {
        return $this->captchaId !== '' && $this->captchaKey !== '';
    }

    public function verify(array $input): bool
    {
        $lot      = trim((string)($input['lot_number'] ?? ''));
        $output   = trim((string)($input['captcha_output'] ?? ''));
        $passToken= trim((string)($input['pass_token'] ?? ''));
        $genTime  = trim((string)($input['gen_time'] ?? ''));
        if ($lot === '' || $output === '' || $passToken === '' || $genTime === '') return false;

        // 官方规定的签名算法：四参数按序拼接后 HMAC-SHA256
        $signToken = hash_hmac('sha256', $lot . $output . $passToken . $genTime, $this->captchaKey);

        $resp = Http::postJson(
            'https://openapi.geetest.com/do/v4/captcha/validate?captcha_id=' . urlencode($this->captchaId),
            [
                'lot_number'     => $lot,
                'captcha_output' => $output,
                'pass_token'     => $passToken,
                'gen_time'       => $genTime,
                'sign_token'     => $signToken,
            ]
        );
        $json = $resp['json'];
        return is_array($json)
            && ($json['status'] ?? '') === 'success'
            && ($json['result'] ?? '') === 'success';
    }

    public function renderHtml(): string
    {
        // 占位容器：gt4.js 会把验证按钮渲染进来；隐藏字段承接通过后的四参数
        return '<div class="gt-captcha-box" id="gtCaptchaBox" style="margin-bottom:14px"></div>'
            . '<input type="hidden" name="lot_number" id="gtLot" />'
            . '<input type="hidden" name="captcha_output" id="gtOutput" />'
            . '<input type="hidden" name="pass_token" id="gtPass" />'
            . '<input type="hidden" name="gen_time" id="gtGen" />';
    }

    public function renderJs(): string
    {
        $id = $this->captchaId;
        return <<<HTML
<script src="https://static.geetest.com/v4/gt4.js"></script>
<script>
(function () {
    var passed = false;
    function initGt() {
        if (typeof initGeetest4 === 'undefined') { setTimeout(initGt, 300); return; }
        initGeetest4({
            captchaId: '{$id}',
            product: 'popup'
        }, function (captchaObj) {
            captchaObj.onSuccess(function () {
                var v = captchaObj.getValidate();
                passed = true;
                document.getElementById('gtLot').value    = v.lot_number;
                document.getElementById('gtOutput').value = v.captcha_output;
                document.getElementById('gtPass').value   = v.pass_token;
                document.getElementById('gtGen').value    = v.gen_time;
            });
            captchaObj.onFail(function () { passed = false; });
            captchaObj.onClose(function () { passed = false; });
            captchaObj.onReady(function () {
                var box = document.getElementById('gtCaptchaBox');
                if (box) captchaObj.appendTo('#gtCaptchaBox');
            });
            // 拦截表单提交：未通过验证先弹出验证窗口
            document.querySelectorAll('form[data-captcha="geetest"]').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    if (!passed) { e.preventDefault(); captchaObj.showCaptcha(); }
                });
            });
        });
    }
    initGt();
})();
</script>
HTML;
    }
}
