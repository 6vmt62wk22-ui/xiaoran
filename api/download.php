<?php
require_once __DIR__ . '/../functions.php';

$code = $_GET['code'] ?? '';
if (!$code) {
    http_response_code(404);
    exit('无效链接');
}

$stmt = db()->prepare('SELECT * FROM files WHERE short_code = ? AND status = 1 LIMIT 1');
$stmt->execute([$code]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    exit('文件不存在或已禁用');
}
if ($file['type'] === 'folder') {
    http_response_code(404);
    exit('文件夹暂不支持分享下载');
}

// 过期拦截
if (!empty($file['expires_at']) && (int)$file['expires_at'] < time()) {
    http_response_code(410);
    exit('分享链接已过期');
}

// 密码门（share_password 为空则跳过）
if (!empty($file['share_password'])) {
    $ok = !empty($_SESSION['file_pw'][$file['id']]);
    $err = '';
    if (!$ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
        if (password_verify($_POST['pw'], $file['share_password'])) {
            $_SESSION['file_pw'][$file['id']] = true;
            $ok = true;
        } else {
            $err = '密码错误，请重试';
        }
    }
    if (!$ok) {
        $siteName = getSetting('site_name') ?: 'TangWo 网盘';
        ?>
        <!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>访问密码 · <?= h($siteName) ?></title>
        <link rel="stylesheet" href="<?= basePath() ?>/assets/style.css"></head>
        <body><div class="form-card card">
          <h2 style="margin:0 0 8px">🔒 受保护文件</h2>
          <p style="color:var(--muted);font-size:14px;margin:0 0 20px"><?= h($file['file_name']) ?></p>
          <?php if ($err): ?><p style="color:#e11d48;font-size:14px"><?= h($err) ?></p><?php endif; ?>
          <form method="POST">
            <div class="field"><label>提取密码</label>
              <input type="password" name="pw" required autofocus placeholder="请输入分享者提供的密码"></div>
            <button class="btn btn-primary" style="width:100%;justify-content:center">验证并下载</button>
          </form>
        </div></body></html>
        <?php
        exit;
    }
}

// 更新下载次数
db()->prepare('UPDATE files SET downloads = downloads + 1 WHERE id = ?')->execute([$file['id']]);

$storage = Storage::instance();

// 自定义下载域名：仅远程驱动生效（指向你绑定的 COS/OSS/S3 自定义域名）
$customDomain = getSetting('custom_domain');
if ($customDomain && $storage->isRemote()) {
    header('Location: ' . rtrim($customDomain, '/') . '/' . ltrim($file['file_key'], '/'));
    exit;
}

// 远程对象存储：302 跳转到预签名直链（大文件不占 PHP 内存）
if ($storage->isRemote()) {
    header('Location: ' . $storage->url($file['file_key']));
    exit;
}

// 本机存储：优先走 Nginx X-Accel-Redirect（大文件不经 PHP，省内存）；未开启或异常时回退 PHP 流式
if (!$storage->isRemote() && defined('X_ACCEL_REDIRECT') && X_ACCEL_REDIRECT && $storage instanceof LocalDriver) {
    $storage->internalRedirect($file['file_key'], $file['file_name'], X_ACCEL_LOCATION);
}

// 本机存储：PHP 流式输出（兜底）
$storage->stream($file['file_key'], $file['file_name']);
