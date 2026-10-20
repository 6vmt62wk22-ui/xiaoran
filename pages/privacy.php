<?php
require_once __DIR__ . '/../functions.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>隐私政策 · 云盘</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/font-awesome/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f9fcff; color: #1a2332; line-height: 1.75; }
        .topbar { display: flex; align-items: center; padding: 18px 48px; border-bottom: 1px solid #eef2f7; background: #fff; position: sticky; top: 0; z-index: 10; }
        .topbar a { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 700; color: #1a5cff; text-decoration: none; }
        .topbar a i { font-size: 26px; background: linear-gradient(135deg, #1a5cff, #6c8cff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .container { max-width: 860px; margin: 0 auto; padding: 56px 24px 80px; }
        .container h1 { font-size: 34px; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 6px; color: #0f1f3d; }
        .updated { color: #94a3b8; font-size: 14px; margin-bottom: 36px; }
        .container h2 { font-size: 20px; font-weight: 600; margin: 34px 0 12px; color: #1a5cff; }
        .container p { color: #475569; font-size: 16px; margin-bottom: 14px; }
        .container ul { margin: 0 0 14px 22px; color: #475569; font-size: 16px; }
        .container li { margin-bottom: 8px; }
        .note { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 12px 16px; font-size: 14px; margin-bottom: 28px; }
        .footer { text-align: center; color: #94a3b8; font-size: 14px; padding: 32px; border-top: 1px solid #edf2f9; }
        @media (max-width: 768px) { .topbar { padding: 14px 20px; } .container { padding: 36px 18px 60px; } .container h1 { font-size: 28px; } .container h2 { font-size: 18px; } .container p { font-size: 15px; } }
        @media (max-width: 480px) { .topbar { padding: 12px 16px; } .topbar a { font-size: 18px; } .topbar a i { font-size: 22px; } .container { padding: 28px 14px 48px; } .container h1 { font-size: 24px; } .container h2 { font-size: 17px; margin: 24px 0 10px; } .container p { font-size: 14px; } .container ul { font-size: 14px; } .note { padding: 10px 12px; font-size: 13px; } .footer { font-size: 13px; padding: 24px 16px; } }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="<?= appUrl('') ?>"><i class="fas fa-cloud"></i><span>云盘</span></a>
    </header>
    <main class="container">
        <h1>隐私政策</h1>
        <div class="updated">最后更新：2026 年 8 月 20 日</div>
        <div class="note">本页为示例文案，上线前请由法务替换为正式法律文本。</div>

        <h2>一、我们收集的信息</h2>
        <p>为提供存储与分享服务，我们可能收集以下信息：</p>
        <ul>
            <li><strong>账户信息</strong>：注册时提供的用户名及登录凭证（密码经哈希存储，服务端不可见明文）。</li>
            <li><strong>文件内容</strong>：您主动上传的文件，用于为您存储与分发。</li>
            <li><strong>使用日志</strong>：访问时间、IP、操作类型等，用于安全审计与故障排查。</li>
        </ul>

        <h2>二、我们如何使用信息</h2>
        <p>我们仅将信息用于：提供、维护与改进服务；保障账户与数据安全；在您授权范围内响应您的请求。</p>

        <h2>三、存储与安全</h2>
        <p>文件传输全程 HTTPS 加密，静态存储采用加密保护。我们实行最小权限原则，严禁内部"扫盘"或擅自访问您的私有文件。</p>

        <h2>四、信息共享</h2>
        <p>我们<strong>不会出售</strong>您的个人信息。仅在以下情形披露：获得您的明确同意、法律法规要求、或为保护本服务与用户的合法权益所必需。</p>

        <h2>五、您的权利</h2>
        <ul>
            <li>随时访问、更正您的账户信息；</li>
            <li>删除指定文件或注销账户（注销后相关数据按约定清除）；</li>
            <li>就个人信息处理向我们提出疑问。</li>
        </ul>

        <h2>六、联系我们</h2>
        <p>如对本政策有疑问，可通过页面底部「联系我们」与我们沟通。</p>
    </main>
    <footer class="footer">© 2026 云盘 · 安心存省心发</footer>
</body>
</html>
