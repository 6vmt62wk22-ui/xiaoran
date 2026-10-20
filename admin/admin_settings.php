<?php
/**
 * 管理后台 · 接口配置（验证码 / 邮箱 / 短信 / 支付网关）
 * 全部读写 settings 表；PRG 模式；含 SMTP 连通性测试
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

// ---------- 保存 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'saveSettings') {
    $keys = [
        // 验证码
        'captcha_driver', 'geetest_captcha_id', 'geetest_captcha_key',
        'tencent_captcha_appid', 'tencent_captcha_secret',
        'tencent_secret_id', 'tencent_secret_key',
        // 邮箱
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass',
        'smtp_from', 'smtp_from_name', 'register_verify_email',
        // 短信
        'sms_driver', 'register_verify_sms',
        'aliyun_sms_key_id', 'aliyun_sms_key_secret', 'aliyun_sms_sign', 'aliyun_sms_template',
        'tencent_sms_appid', 'tencent_sms_sign', 'tencent_sms_template', 'tencent_sms_region',
        // 支付
        'pay_wechat', 'pay_alipay',
        'wechatpay_mchid', 'wechatpay_appid', 'wechatpay_serial', 'wechatpay_v3key', 'wechatpay_private_key',
        'alipay_appid', 'alipay_private_key', 'alipay_public_key', 'alipay_gateway',
    ];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            setSetting($k, trim((string)$_POST[$k]));
        }
    }
    // 复选框未勾选时不会出现在 POST 里，显式归零
    foreach (['smtp_enabled', 'register_verify_email', 'register_verify_sms', 'pay_wechat', 'pay_alipay'] as $k) {
        if (!isset($_POST[$k])) setSetting($k, '0');
    }
    $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => '接口配置已保存'];
    redirect(appUrl('dashboardadminsettings'));
}

// ---------- SMTP 连通性测试 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'testSmtp') {
    // 测试优先用表单里刚填的值（保存前也能测）
    $host = trim((string)($_POST['smtp_host'] ?? getSetting('smtp_host')));
    $port = (int)($_POST['smtp_port'] ?? getSetting('smtp_port'));
    $user = trim((string)($_POST['smtp_user'] ?? getSetting('smtp_user')));
    $pass = (string)($_POST['smtp_pass'] ?? getSetting('smtp_pass'));
    $secure = (string)($_POST['smtp_secure'] ?? getSetting('smtp_secure') ?: 'ssl');
    $to = trim((string)($_POST['test_to'] ?? ''));
    try {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('请填写有效的收件测试邮箱');
        }
        $smtp = new SmtpClient($host, $port, $user, $pass, $secure);
        $ok = $smtp->send($user ?: $to, getSetting('site_name') ?: 'TangWo 网盘', $to,
            'SMTP 连通性测试', '<p>这是一封 SMTP 配置测试邮件，收到即说明邮箱验证通道正常。</p><p style="color:#94a3b8;font-size:12px">' . date('Y-m-d H:i:s') . '</p>');
        $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => '测试邮件已发送至 ' . $to . '，请查收（含垃圾箱）'];
    } catch (RuntimeException $e) {
        $_SESSION['admin_flash'] = ['type' => 'err', 'text' => 'SMTP 测试失败：' . $e->getMessage()];
    }
    redirect(appUrl('dashboardadminsettings'));
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$tab = (string)($_GET['tab'] ?? 'captcha');
$tabs = [
    'captcha' => ['icon' => 'fa-shield-halved', 'label' => '验证码'],
    'mail'    => ['icon' => 'fa-envelope', 'label' => '邮箱验证'],
    'sms'     => ['icon' => 'fa-comment-sms', 'label' => '短信验证'],
    'pay'     => ['icon' => 'fa-wallet', 'label' => '支付网关'],
];
$pageTitle = '接口配置';
$activeNav = 'settings';
require __DIR__ . '/admin_layout_header.php';

$v = fn(string $k, string $d = '') => h((string)(getSetting($k) ?: $d));
$chk = fn(string $k) => getSetting($k) === '1' ? 'checked' : '';
?>

<style>
.set-card { background:#fff; border:1px solid rgba(0,0,0,.06); border-radius:14px; padding:22px 24px; margin-bottom:18px; }
.set-card h3 { font-size:14px; font-weight:700; color:#0b1a33; margin:0 0 4px; display:flex; align-items:center; gap:8px; }
.set-card h3 i { color:#3b82f6; }
.set-card .desc { font-size:12px; color:#94a3b8; margin:0 0 16px; line-height:1.6; }
.set-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; }
.set-item.full { grid-column:1 / -1; }
.set-item label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px; }
.set-item input, .set-item select, .set-item textarea {
    width:100%; padding:9px 12px; border:1px solid #e2e8f0; border-radius:10px;
    font-size:13px; color:#1e293b; background:#f8fafc; box-sizing:border-box; font-family:inherit;
}
.set-item input:focus, .set-item textarea:focus, .set-item select:focus { outline:none; border-color:#3b82f6; background:#fff; }
.set-item textarea { min-height:96px; resize:vertical; font-family:Consolas,monospace; font-size:12px; line-height:1.5; }
.set-item .hint { font-size:11px; color:#94a3b8; margin-top:5px; line-height:1.5; }
.switch-row { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:16px; }
.switch-row input[type=checkbox] { width:16px; height:16px; accent-color:#3b82f6; }
.switch-row .sw-label { font-size:13px; font-weight:600; color:#1e293b; }
.switch-row .sw-desc { font-size:12px; color:#94a3b8; }
.save-bar { position:sticky; bottom:0; background:linear-gradient(180deg,transparent,#f4f6fa 30%); padding:16px 0 4px; display:flex; gap:10px; }
.btn-save { background:#3b82f6; color:#fff; border:none; border-radius:10px; padding:10px 26px; font-size:13px; font-weight:600; cursor:pointer; }
.btn-save:hover { background:#2563eb; }
.btn-ghost-s { background:#fff; color:#475569; border:1px solid #e2e8f0; border-radius:10px; padding:10px 18px; font-size:13px; cursor:pointer; }
</style>

<?php if ($flash): ?>
<div style="background:<?= $flash['type']==='ok'?'#f0fdf4':'#fef2f2' ?>;border:1px solid <?= $flash['type']==='ok'?'#bbf7d0':'#fecaca' ?>;color:<?= $flash['type']==='ok'?'#166534':'#991b1b' ?>;padding:12px 18px;border-radius:12px;margin-bottom:18px;font-size:13px">
    <?= h($flash['text']) ?>
</div>
<?php endif; ?>

<!-- Tab 切换 -->
<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <?php foreach ($tabs as $tk => $ti): ?>
    <a href="<?= appUrl('dashboardadminsettings') ?>?tab=<?= $tk ?>" class="filter-chip <?= $tab===$tk?'active':'' ?>">
        <i class="fas <?= $ti['icon'] ?>"></i> <?= $ti['label'] ?>
    </a>
    <?php endforeach; ?>
</div>

<form method="post" autocomplete="off">
<input type="hidden" name="action" value="saveSettings">

<?php if ($tab === 'captcha'): ?>
<!-- ================= 验证码 ================= -->
<div class="set-card">
    <h3><i class="fas fa-shield-halved"></i> 验证码驱动</h3>
    <p class="desc">登录 / 注册的验证码来源。可随时切换，第三方密钥没配全时自动回退本地图形码，不影响站点可用。</p>
    <div class="set-grid">
        <div class="set-item full">
            <label>当前驱动</label>
            <select name="captcha_driver">
                <option value="local" <?= $v('captcha_driver','local')==='local'?'selected':'' ?>>本地内置图形验证码（默认，零依赖）</option>
                <option value="geetest" <?= $v('captcha_driver')==='geetest'?'selected':'' ?>>极验 GeeTest 行为验 4.0（第三方）</option>
                <option value="tencent" <?= $v('captcha_driver')==='tencent'?'selected':'' ?>>腾讯云验证码 2.0 TCaptcha（第三方）</option>
            </select>
            <div class="hint">腾讯云驱动复用下方「短信验证」页的 SecretId/SecretKey（同一账号密钥），验证码 AppID/Secret 在 <a href="https://console.cloud.tencent.com/captcha" target="_blank" style="color:#3b82f6">验证码控制台</a> 获取。</div>
        </div>
    </div>
</div>

<div class="set-card">
    <h3><i class="fas fa-fingerprint"></i> 极验 GeeTest 4.0</h3>
    <p class="desc">极验后台「行为验 4.0」→ 验证管理 中获取。服务端会按官方规范做 HMAC-SHA256 二次校验。</p>
    <div class="set-grid">
        <div class="set-item">
            <label>Captcha ID</label>
            <input name="geetest_captcha_id" value="<?= $v('geetest_captcha_id') ?>" placeholder="如 647f5cd2b8xxxxxxxxxxxx" />
        </div>
        <div class="set-item">
            <label>Captcha Key</label>
            <input name="geetest_captcha_key" value="<?= $v('geetest_captcha_key') ?>" placeholder="服务端密钥" />
        </div>
    </div>
</div>

<div class="set-card">
    <h3><i class="fas fa-cloud"></i> 腾讯云验证码 2.0</h3>
    <p class="desc">验证码控制台新建验证后，列表页可见 CaptchaAppId 与 AppSecretKey。服务端调用 DescribeCaptchaResult 校验票据。</p>
    <div class="set-grid">
        <div class="set-item">
            <label>CaptchaAppId</label>
            <input name="tencent_captcha_appid" value="<?= $v('tencent_captcha_appid') ?>" placeholder="如 190000000" />
        </div>
        <div class="set-item">
            <label>AppSecretKey</label>
            <input name="tencent_captcha_secret" value="<?= $v('tencent_captcha_secret') ?>" placeholder="验证码密钥" />
        </div>
        <div class="set-item">
            <label>腾讯云 SecretId（共用）</label>
            <input name="tencent_secret_id" value="<?= $v('tencent_secret_id') ?>" placeholder="访问密钥，与短信共用" />
        </div>
        <div class="set-item">
            <label>腾讯云 SecretKey（共用）</label>
            <input name="tencent_secret_key" value="<?= $v('tencent_secret_key') ?>" placeholder="访问密钥" />
        </div>
    </div>
</div>

<?php elseif ($tab === 'mail'): ?>
<!-- ================= 邮箱 ================= -->
<div class="set-card">
    <h3><i class="fas fa-envelope"></i> SMTP 邮箱验证</h3>
    <p class="desc">原生 SMTP 客户端（无第三方依赖），支持 465 隐式 SSL / 587 STARTTLS。QQ 邮箱、163、阿里云邮、企业邮箱通用。开启后注册页可要求邮箱验证码。</p>
    <div class="switch-row">
        <input type="checkbox" id="smtp_enabled" name="smtp_enabled" value="1" <?= $chk('smtp_enabled') ?>>
        <label for="smtp_enabled" class="sw-label">启用 SMTP 发信</label>
        <span class="sw-desc">关闭后注册页不出现邮箱验证码选项</span>
    </div>
    <div class="switch-row">
        <input type="checkbox" id="register_verify_email" name="register_verify_email" value="1" <?= $chk('register_verify_email') ?>>
        <label for="register_verify_email" class="sw-label">注册强制邮箱验证码</label>
        <span class="sw-desc">开启后注册必须填邮箱 + 收码验证</span>
    </div>
    <div class="set-grid">
        <div class="set-item">
            <label>SMTP 服务器</label>
            <input name="smtp_host" value="<?= $v('smtp_host') ?>" placeholder="如 smtp.qq.com" />
        </div>
        <div class="set-item">
            <label>端口</label>
            <input name="smtp_port" value="<?= $v('smtp_port','465') ?>" placeholder="465(SSL) / 587(STARTTLS)" />
        </div>
        <div class="set-item">
            <label>加密方式</label>
            <select name="smtp_secure">
                <option value="ssl" <?= $v('smtp_secure','ssl')==='ssl'?'selected':'' ?>>SSL（465 端口）</option>
                <option value="tls" <?= $v('smtp_secure')==='tls'?'selected':'' ?>>STARTTLS（587 端口）</option>
                <option value="none" <?= $v('smtp_secure')==='none'?'selected':'' ?>>不加密（仅内网调试）</option>
            </select>
        </div>
        <div class="set-item">
            <label>发件账号（用户名）</label>
            <input name="smtp_user" value="<?= $v('smtp_user') ?>" placeholder="如 xxx@qq.com" />
        </div>
        <div class="set-item">
            <label>密码 / 授权码</label>
            <input name="smtp_pass" type="password" value="<?= $v('smtp_pass') ?>" placeholder="QQ邮箱填授权码（设置-账户-生成）" />
        </div>
        <div class="set-item">
            <label>发件地址（回信）</label>
            <input name="smtp_from" value="<?= $v('smtp_from') ?>" placeholder="留空默认用发件账号" />
        </div>
        <div class="set-item full">
            <label>发件人名称</label>
            <input name="smtp_from_name" value="<?= $v('smtp_from_name') ?>" placeholder="如 TangWo 网盘" />
        </div>
    </div>
</div>
<?php elseif ($tab === 'sms'): ?>
<!-- ================= 短信 ================= -->
<div class="set-card">
    <h3><i class="fas fa-comment-sms"></i> 短信验证</h3>
    <p class="desc">支持阿里云短信 / 腾讯云短信（均按官方 API 规范签名实现）。开启后注册页支持手机号验证码。短信模板需一个变量（验证码，占位 ${code}）。</p>
    <div class="switch-row">
        <input type="checkbox" id="register_verify_sms" name="register_verify_sms" value="1" <?= $chk('register_verify_sms') ?>>
        <label for="register_verify_sms" class="sw-label">注册强制手机验证码</label>
        <span class="sw-desc">开启后注册必须填手机号 + 收码验证</span>
    </div>
    <div class="set-grid">
        <div class="set-item full">
            <label>短信厂商</label>
            <select name="sms_driver">
                <option value="off" <?= $v('sms_driver','off')==='off'?'selected':'' ?>>关闭短信功能</option>
                <option value="aliyun" <?= $v('sms_driver')==='aliyun'?'selected':'' ?>>阿里云短信（dysmsapi）</option>
                <option value="tencent" <?= $v('sms_driver')==='tencent'?'selected':'' ?>>腾讯云短信（SendSms）</option>
            </select>
        </div>
    </div>
</div>

<div class="set-card">
    <h3><i class="fas fa-a"></i> 阿里云短信</h3>
    <p class="desc">RAM 账号 AccessKey + 已审核的签名、模板（模板类型：验证码）。</p>
    <div class="set-grid">
        <div class="set-item"><label>AccessKey ID</label><input name="aliyun_sms_key_id" value="<?= $v('aliyun_sms_key_id') ?>" /></div>
        <div class="set-item"><label>AccessKey Secret</label><input name="aliyun_sms_key_secret" type="password" value="<?= $v('aliyun_sms_key_secret') ?>" /></div>
        <div class="set-item"><label>签名名称</label><input name="aliyun_sms_sign" value="<?= $v('aliyun_sms_sign') ?>" placeholder="如 TangWo" /></div>
        <div class="set-item"><label>模板 Code</label><input name="aliyun_sms_template" value="<?= $v('aliyun_sms_template') ?>" placeholder="如 SMS_12345678" /></div>
    </div>
</div>

<div class="set-card">
    <h3><i class="fas fa-cloud"></i> 腾讯云短信</h3>
    <p class="desc">短信控制台的 SdkAppId、已审核签名与模板。SecretId/Key 与验证码页共用。</p>
    <div class="set-grid">
        <div class="set-item"><label>SmsSdkAppId</label><input name="tencent_sms_appid" value="<?= $v('tencent_sms_appid') ?>" placeholder="如 1400xxxxxx" /></div>
        <div class="set-item"><label>签名内容</label><input name="tencent_sms_sign" value="<?= $v('tencent_sms_sign') ?>" placeholder="如 TangWo" /></div>
        <div class="set-item"><label>模板 ID</label><input name="tencent_sms_template" value="<?= $v('tencent_sms_template') ?>" placeholder="如 449739" /></div>
        <div class="set-item"><label>地域</label><input name="tencent_sms_region" value="<?= $v('tencent_sms_region','ap-guangzhou') ?>" placeholder="默认 ap-guangzhou" /></div>
    </div>
</div>

<?php elseif ($tab === 'pay'): ?>
<!-- ================= 支付 ================= -->
<div class="set-card">
    <h3><i class="fas fa-wallet"></i> 支付网关</h3>
    <p class="desc">用户在「升级空间」页扫码付款，支付成功自动升级配额（无需人工）。回调地址需公网可访问：<code><?= h(shareUrl('pay_notify')) ?></code></p>
    <div class="set-grid" style="margin-bottom:4px">
        <div class="switch-row" style="margin-bottom:0">
            <input type="checkbox" id="pay_wechat" name="pay_wechat" value="1" <?= $chk('pay_wechat') ?>>
            <label for="pay_wechat" class="sw-label">启用微信支付（Native 扫码）</label>
        </div>
        <div class="switch-row" style="margin-bottom:0">
            <input type="checkbox" id="pay_alipay" name="pay_alipay" value="1" <?= $chk('pay_alipay') ?>>
            <label for="pay_alipay" class="sw-label">启用支付宝（当面付扫码）</label>
        </div>
    </div>
</div>

<div class="set-card">
    <h3><i class="fab fa-weixin" style="color:#16a34a"></i> 微信支付 v3（Native）</h3>
    <p class="desc">商户平台（pay.weixin.qq.com）→ API 安全：申请 API 证书拿序列号与 apiclient_key.pem，设置 APIv3 密钥（32 位）。</p>
    <div class="set-grid">
        <div class="set-item"><label>商户号 mchid</label><input name="wechatpay_mchid" value="<?= $v('wechatpay_mchid') ?>" placeholder="如 1900000000" /></div>
        <div class="set-item"><label>AppID（绑定商户号的应用）</label><input name="wechatpay_appid" value="<?= $v('wechatpay_appid') ?>" /></div>
        <div class="set-item"><label>商户证书序列号</label><input name="wechatpay_serial" value="<?= $v('wechatpay_serial') ?>" placeholder="45 位十六进制" /></div>
        <div class="set-item"><label>APIv3 密钥</label><input name="wechatpay_v3key" value="<?= $v('wechatpay_v3key') ?>" placeholder="32 位，回调解密用" /></div>
        <div class="set-item full">
            <label>商户 API 私钥（apiclient_key.pem）</label>
            <textarea name="wechatpay_private_key" placeholder="粘贴 PEM 全文（-----BEGIN PRIVATE KEY----- 开头）或填服务器上的绝对路径"><?= $v('wechatpay_private_key') ?></textarea>
        </div>
    </div>
</div>

<div class="set-card">
    <h3><i class="fab fa-alipay" style="color:#1677ff"></i> 支付宝当面付</h3>
    <p class="desc">开放平台创建应用 → 签约「当面付」→ 配置应用私钥（PKCS8）与支付宝公钥（注意不是应用公钥）。</p>
    <div class="set-grid">
        <div class="set-item"><label>AppID</label><input name="alipay_appid" value="<?= $v('alipay_appid') ?>" /></div>
        <div class="set-item"><label>网关地址</label><input name="alipay_gateway" value="<?= $v('alipay_gateway','https://openapi.alipay.com/gateway.do') ?>" /></div>
        <div class="set-item full">
            <label>应用私钥（PKCS8）</label>
            <textarea name="alipay_private_key" placeholder="粘贴 PEM 全文或绝对路径"><?= $v('alipay_private_key') ?></textarea>
        </div>
        <div class="set-item full">
            <label>支付宝公钥（验签用）</label>
            <textarea name="alipay_public_key" placeholder="粘贴支付宝公钥（可 PEM 可纯 base64）"><?= $v('alipay_public_key') ?></textarea>
        </div>
    </div>
</div>

<div class="set-card" style="background:linear-gradient(135deg,#eff6ff,#f5f3ff);border-color:#c7d2fe">
    <h3 style="font-size:13px;color:#1e3a8a"><i class="fas fa-tags" style="color:#6366f1"></i> 套餐定价已独立</h3>
    <p class="desc" style="color:#475569">套餐月价（10GB / 30GB / 100GB）已移至侧栏独立入口「套餐定价」，便于独立维护。修改后立即在升级页生效。</p>
    <a href="<?= appUrl('dashboardadminpricing') ?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#3b82f6;color:#fff;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none">
        <i class="fas fa-yen-sign"></i> 前往套餐定价
    </a>
</div>

<?php endif; ?>

<div class="save-bar">
    <button type="submit" class="btn-save"><i class="fas fa-check"></i> 保存配置</button>
    <?php if ($tab === 'mail'): ?>
    <button type="submit" name="action" value="testSmtp" formnovalidate class="btn-ghost-s"
        onclick="var f=this.form;f.action.value='testSmtp';setTimeout(function(){f.action.value='saveSettings'},100)">
        <i class="fas fa-paper-plane"></i> 发送测试邮件
    </button>
    <input type="email" name="test_to" placeholder="测试收件邮箱" style="padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;width:200px" />
    <?php endif; ?>
</div>
</form>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
