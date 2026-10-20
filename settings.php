<?php
require_once 'functions.php';
// 简单鉴权：第一个注册用户（id=1）为管理员
if (!isLogin() || currentUid() != 1) {
    exit('权限不足');
}
$siteName = getSetting('site_name') ?: 'TangWo 网盘';

// 保存：注意单位统一——库里存「字节」，表单填「MB」
if ($_POST) {
    setSetting('site_name',      trim($_POST['site_name'] ?? ''));
    setSetting('allow_guest',    isset($_POST['allow_guest']) ? '1' : '0');
    setSetting('custom_domain',  trim($_POST['custom_domain'] ?? ''));
    if (is_numeric($_POST['max_file_size_mb'])) {
        setSetting('max_file_size', (int)$_POST['max_file_size_mb'] * 1024 * 1024);
    }
    // PRG：保存后 303 重定向，避免刷新时重复提交
    redirect(appUrl('settings'));
}

$curName   = getSetting('site_name', 'TangWo 网盘');
$curMaxMB  = (int)getSetting('max_file_size', 104857600) / 1024 / 1024;
$curGuest  = getSetting('allow_guest', '1') === '1';
$curDomain = getSetting('custom_domain', '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>个人中心 · <?= h($siteName) ?></title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<nav class="nav">
  <div class="logo"><span class="dot">☁</span> <?= h($siteName) ?></div>
  <div class="spacer"></div>
  <a class="link" href="<?= appUrl('dashboard') ?>">我的文件</a>
  <a class="btn btn-ghost" href="<?= authUrl('logout') ?>">退出</a>
</nav>

<div class="wrap" style="max-width:560px">
  <h2 style="margin:8px 0 16px">个人中心</h2>
  <div class="card">
    <form method="POST">
      <div class="field">
        <label>网站名称</label>
        <input name="site_name" value="<?= h($curName) ?>">
      </div>
      <div class="field">
        <label>单文件上限（MB）</label>
        <input name="max_file_size_mb" type="number" min="1" value="<?= $curMaxMB ?>">
      </div>
      <div class="field">
        <label>自定义下载域名（留空则用本站）</label>
        <input name="custom_domain" value="<?= h($curDomain) ?>" placeholder="https://cdn.example.com">
      </div>
      <div class="field" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="allow_guest" <?= $curGuest ? 'checked' : '' ?> style="width:auto">
        <label style="margin:0">允许游客上传</label>
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center">保存</button>
    </form>
  </div>
</div>
</body>
</html>
