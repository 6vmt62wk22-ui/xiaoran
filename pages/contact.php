<?php
require_once __DIR__ . '/../functions.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>联系我们 · 云盘</title>
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
        .card { background: #fff; border: 1px solid #edf2f9; border-radius: 16px; padding: 28px 30px; box-shadow: 0 4px 16px rgba(0,0,0,0.02); }
        .row { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f1f5f9; }
        .row:last-child { border-bottom: none; }
        .row i { width: 38px; height: 38px; border-radius: 10px; background: #eff5ff; color: #1a5cff; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .row .label { font-size: 13px; color: #94a3b8; }
        .row .value { font-size: 16px; color: #1a2332; font-weight: 500; }
        .row a { color: #1a5cff; text-decoration: none; font-weight: 600; }
        .footer { text-align: center; color: #94a3b8; font-size: 14px; padding: 32px; border-top: 1px solid #edf2f9; }
        @media (max-width: 768px) { .topbar { padding: 14px 20px; } .container { padding: 36px 18px 60px; } .container h1 { font-size: 28px; } .sub { font-size: 15px; } }
        @media (max-width: 480px) { .topbar { padding: 12px 16px; } .topbar a { font-size: 18px; } .topbar a i { font-size: 22px; } .container { padding: 28px 14px 48px; } .container h1 { font-size: 24px; } .sub { font-size: 14px; } .card { padding: 20px 16px; } .row { gap: 10px; padding: 12px 0; } .row i { width: 34px; height: 34px; font-size: 15px; } .row .value { font-size: 15px; } .footer { font-size: 13px; padding: 24px 16px; } }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="<?= appUrl('') ?>"><i class="fas fa-cloud"></i><span>云盘</span></a>
    </header>
    <main class="container">
        <h1>联系我们</h1>
        <p class="sub">遇到问题或合作意向，欢迎随时联系</p>
        <div class="card">
            <div class="row">
                <i class="fas fa-envelope"></i>
                <div>
                    <div class="label">邮箱</div>
                    <div class="value"><a href="mailto:hi@tangwo.example">hi@tangwo.example</a></div>
                </div>
            </div>
            <div class="row">
                <i class="fab fa-qq"></i>
                <div>
                    <div class="label">QQ</div>
                    <div class="value">待补充</div>
                </div>
            </div>
            <div class="row">
                <i class="fab fa-weixin"></i>
                <div>
                    <div class="label">微信</div>
                    <div class="value">待补充</div>
                </div>
            </div>
            <div class="row">
                <i class="fas fa-comments"></i>
                <div>
                    <div class="label">社区</div>
                    <div class="value">待补充</div>
                </div>
            </div>
        </div>
    </main>
    <footer class="footer">© 2026 云盘 · 安心存省心发</footer>
</body>
</html>
