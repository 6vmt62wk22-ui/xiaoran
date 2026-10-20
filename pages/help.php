<?php
require_once __DIR__ . '/../functions.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帮助中心 · 云盘</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f9fcff; color: #1a2332; line-height: 1.7; }
        .topbar { display: flex; align-items: center; padding: 18px 48px; border-bottom: 1px solid #eef2f7; background: #fff; position: sticky; top: 0; z-index: 10; }
        .topbar a { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 700; color: #1a5cff; text-decoration: none; }
        .topbar a i { font-size: 26px; background: linear-gradient(135deg, #1a5cff, #6c8cff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .container { max-width: 860px; margin: 0 auto; padding: 56px 24px 80px; }
        .container h1 { font-size: 34px; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 6px; color: #0f1f3d; }
        .sub { color: #64748b; font-size: 17px; margin-bottom: 36px; }
        .faq { background: #fff; border: 1px solid #edf2f9; border-radius: 16px; padding: 22px 24px; margin-bottom: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.02); }
        .faq h3 { font-size: 17px; font-weight: 600; color: #0f1f3d; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .faq h3 i { color: #1a5cff; }
        .faq p { color: #475569; font-size: 15px; }
        .footer { text-align: center; color: #94a3b8; font-size: 14px; padding: 32px; border-top: 1px solid #edf2f9; }
        @media (max-width: 768px) { .topbar { padding: 14px 20px; } .container { padding: 36px 18px 60px; } .container h1 { font-size: 28px; } .sub { font-size: 15px; } }
        @media (max-width: 480px) { .topbar { padding: 12px 16px; } .topbar a { font-size: 18px; } .topbar a i { font-size: 22px; } .container { padding: 28px 14px 48px; } .container h1 { font-size: 24px; } .sub { font-size: 14px; } .faq { padding: 18px 16px; } .faq h3 { font-size: 15px; } .faq p { font-size: 14px; } .footer { font-size: 13px; padding: 24px 16px; } }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="<?= appUrl('') ?>"><i class="fas fa-cloud"></i><span>云盘</span></a>
    </header>
    <main class="container">
        <h1>帮助中心</h1>
        <p class="sub">常见问题与快速指引</p>

        <div class="faq">
            <h3><i class="fas fa-upload"></i> 如何上传文件？</h3>
            <p>登录后进入控制台，点击左上角「上传文件」按钮，或将文件拖拽到任意位置即可。支持 MD5 秒传，重复文件瞬间完成。</p>
        </div>
        <div class="faq">
            <h3><i class="fas fa-link"></i> 如何分享文件？</h3>
            <p>在文件列表中点击「复制链接」，即可生成免登录直链，对方无需注册即可在浏览器下载。</p>
        </div>
        <div class="faq">
            <h3><i class="fas fa-key"></i> 忘记密码怎么办？</h3>
            <p>当前版本暂未开放自助找回，请联系管理员为您重置账户密码。</p>
        </div>
        <div class="faq">
            <h3><i class="fas fa-weight-hanging"></i> 有文件大小或数量限制吗？</h3>
            <p>单文件大小与总配额以您的账户等级为准；下载不限速度、不限流量、不限次数。</p>
        </div>
        <div class="faq">
            <h3><i class="fas fa-shield-alt"></i> 我的文件安全吗？</h3>
            <p>传输全程 HTTPS 加密，存储采用加密保护，不上传即扫描、不擅自访问您的私有内容。</p>
        </div>
    </main>
    <footer class="footer">© 2026 云盘 · 安心存省心发</footer>
</body>
</html>
