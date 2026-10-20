<?php
require_once __DIR__ . '/../functions.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务协议 · 云盘</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
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
        <h1>服务协议</h1>
        <div class="updated">最后更新：2026 年 8 月 20 日</div>
        <div class="note">本页为示例文案，上线前请由法务替换为正式法律文本。</div>

        <h2>一、服务说明</h2>
        <p>云盘为您提供文件的存储、管理与分享服务。我们尽力保障服务稳定与数据安全，但不对不可抗力或第三方网络故障导致的不可用承担责任。</p>

        <h2>二、用户义务</h2>
        <ul>
            <li>您对上传内容拥有合法权利，不得上传侵权、违法或危害安全的内容；</li>
            <li>妥善保管账户凭证，因凭证泄露导致的损失由您自行承担；</li>
            <li>不得利用本服务从事攻击、滥用或干扰他人正常使用的活动。</li>
        </ul>

        <h2>三、禁止内容</h2>
        <p>我们禁止上传或传播色情、赌博、诈骗、侵犯他人权益及违反法律法规的内容。一经发现，我们有权删除内容并封禁账户。</p>

        <h2>四、免责声明</h2>
        <p>本服务按"现状"提供。在法律允许范围内，我们对间接、偶然或后果性损失不承担责任。建议您自行备份重要文件。</p>

        <h2>五、协议变更与终止</h2>
        <p>我们可能适时更新本协议，重大变更将提前通知。您可随时停止使用并注销账户；严重违约时我们有权终止服务。</p>
    </main>
    <footer class="footer">© 2026 云盘 · 安心存省心发</footer>
</body>
</html>
