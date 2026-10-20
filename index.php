<?php
require_once 'functions.php';

// 首次部署检测：config_db.php 不存在 → 未走安装向导 → 引导到 install.php
// 之前用 DB 查询 settings.schema_version，但向导期间 DB 凭据未定、库可能都还没建
if (!file_exists(__DIR__ . '/config_db.php')) {
    header('Location: ' . url('install'));
    exit;
}

// 已登录用户访问首页直接进控制台
if (isLogin()) redirect(appUrl('dashboard'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TangWo 云盘 · 存储·分享·同步</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/font-awesome/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        html { scroll-behavior: smooth; }

        body {
            background: #f9fcff;
            color: #1a2332;
            line-height: 1.5;
            overflow-x: hidden;
        }

        /* ============ 导航 ============ */
        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 48px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #eef2f7;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 24px;
            font-weight: 700;
            color: #1a5cff;
            text-decoration: none;
            flex-shrink: 0;
        }
        .logo i {
            font-size: 28px;
            background: linear-gradient(135deg, #1a5cff, #6c8cff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .nav-brand {
            flex: 1;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 1.5px;
            color: #64748b;
            white-space: nowrap;
            user-select: none;
        }
        .nav-brand b { color: #1a5cff; font-weight: 700; }

        .nav-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-login {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 22px;
            border-radius: 30px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            color: #1a5cff;
            background: #fff;
            border: 1px solid #cfe0ff;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .nav-login:hover {
            background: #1a5cff;
            color: #fff;
            border-color: #1a5cff;
            box-shadow: 0 4px 12px rgba(26, 92, 255, 0.25);
        }

        /* ============ Hero ============ */
        .hero {
            position: relative;
            padding: 96px 48px 80px;
            max-width: 1280px;
            margin: 0 auto;
            text-align: center;
            overflow: hidden;
        }
        .hero-bg {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }
        .hero-bg .glow {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.55;
        }
        .hero-bg .glow-1 {
            width: 600px; height: 600px;
            background: radial-gradient(circle, #93c5fd 0%, transparent 70%);
            top: -200px; left: 50%; transform: translateX(-60%);
        }
        .hero-bg .glow-2 {
            width: 460px; height: 460px;
            background: radial-gradient(circle, #c4b5fd 0%, transparent 70%);
            top: 60px; right: -100px;
            opacity: 0.45;
        }
        .hero-bg .glow-3 {
            width: 380px; height: 380px;
            background: radial-gradient(circle, #a5f3fc 0%, transparent 70%);
            bottom: -100px; left: -60px;
            opacity: 0.4;
        }
        .hero-bg .grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(26,92,255,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(26,92,255,0.06) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 90% 80% at center, #000 40%, transparent 95%);
            -webkit-mask-image: radial-gradient(ellipse 90% 80% at center, #000 40%, transparent 95%);
        }
        /* 左右浮动装饰 */
        .float-card {
            position: absolute;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 16px;
            padding: 14px 18px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 2;
            font-size: 13px;
            font-weight: 500;
            color: #1e293b;
            animation: float 4s ease-in-out infinite;
        }
        .float-card .ic {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: grid; place-items: center;
            color: #fff;
            font-size: 16px;
            flex-shrink: 0;
        }
        .float-card .lbl { line-height: 1.3; }
        .float-card .lbl .t { font-weight: 700; color: #0b1a33; }
        .float-card .lbl .s { font-size: 11px; color: #64748b; }
        .float-card.fc-1 { top: 140px; left: 4%; animation-delay: 0s; }
        .float-card.fc-1 .ic { background: linear-gradient(135deg, #1a5cff, #6c8cff); }
        .float-card.fc-2 { top: 80px; right: 4%; animation-delay: 1s; }
        .float-card.fc-2 .ic { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .float-card.fc-3 { bottom: 140px; left: 6%; animation-delay: 2s; }
        .float-card.fc-3 .ic { background: linear-gradient(135deg, #06b6d4, #67e8f9); }
        .float-card.fc-4 { bottom: 100px; right: 6%; animation-delay: 0.5s; }
        .float-card.fc-4 .ic { background: linear-gradient(135deg, #10b981, #6ee7b7); }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .hero-content { position: relative; z-index: 1; }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(26,92,255,0.15);
            border-radius: 999px;
            font-size: 13px;
            font-weight: 500;
            color: #1a5cff;
            margin-bottom: 24px;
        }
        .hero-badge i { font-size: 11px; }
        .hero-text h1 {
            font-size: clamp(34px, 6.5vw, 60px);
            font-weight: 800;
            letter-spacing: -1.5px;
            line-height: 1.15;
            margin-bottom: 20px;
        }
        .hero-text h1 .grad {
            background: linear-gradient(135deg, #1a5cff 0%, #6c8cff 50%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero-text .sub {
            font-size: clamp(16px, 3vw, 19px);
            color: #475569;
            max-width: 580px;
            margin: 0 auto 12px;
            line-height: 1.7;
        }
        .hero-text .tagline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #64748b;
            margin-bottom: 36px;
            padding: 6px 14px;
            background: rgba(255,255,255,0.6);
            border-radius: 999px;
        }
        .hero-text .tagline i { color: #22c55e; }

        .hero-cta {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 56px;
        }
        .btn {
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1a5cff 0%, #2563eb 100%);
            color: #fff;
            padding: 16px 32px;
            font-size: 16px;
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(26,92,255,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(26,92,255,0.4);
        }
        .btn-ghost {
            background: rgba(255,255,255,0.7);
            color: #1a5cff;
            padding: 16px 28px;
            font-size: 16px;
            border-radius: 14px;
            border: 1px solid rgba(26,92,255,0.2);
            backdrop-filter: blur(8px);
        }
        .btn-ghost:hover {
            background: #fff;
            border-color: #1a5cff;
        }

        /* 数据徽章 */
        .hero-stats {
            display: flex;
            gap: 40px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .stat-item {
            text-align: center;
        }
        .stat-item .num {
            font-size: clamp(28px, 4vw, 36px);
            font-weight: 800;
            background: linear-gradient(135deg, #1a5cff 0%, #6c8cff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -.5px;
            line-height: 1.1;
        }
        .stat-item .lbl {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
            font-weight: 500;
        }

        /* ============ 功能亮点 ============ */
        .features {
            padding: 64px 48px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .features .section-head {
            text-align: center;
            margin-bottom: 48px;
        }
        .features h2 {
            font-size: clamp(28px, 4vw, 40px);
            font-weight: 700;
            letter-spacing: -.5px;
            margin-bottom: 10px;
        }
        .features .sub {
            color: #64748b;
            font-size: 17px;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
        }
        .feature-card {
            position: relative;
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 26px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            border: 1px solid #edf2f9;
            transition: all 0.3s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--accent, #1a5cff);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 40px rgba(0, 20, 80, 0.08);
            border-color: transparent;
        }
        .feature-card:hover::before { transform: scaleX(1); }

        .feature-card .icon-wrap {
            width: 56px; height: 56px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 24px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        .feature-card .icon-wrap::after {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--accent-soft, rgba(26,92,255,0.1));
            z-index: -1;
        }
        .feature-card .icon-wrap i {
            color: var(--accent, #1a5cff);
            font-size: 24px;
        }
        .feature-card .icon-wrap .bg {
            position: absolute;
            inset: 0;
            z-index: -1;
        }
        .feature-card .icon-wrap .bg {
            background: var(--accent-soft, rgba(26,92,255,0.1));
        }

        .feature-card h3 {
            font-size: 19px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #0b1a33;
        }
        .feature-card p {
            color: #64748b;
            font-size: 14.5px;
            line-height: 1.65;
        }

        /* 4 张卡的色彩主题 */
        .feature-card.c1 { --accent: #1a5cff; --accent-soft: linear-gradient(135deg, #dbeafe, #eff6ff); }
        .feature-card.c2 { --accent: #7c3aed; --accent-soft: linear-gradient(135deg, #ede9fe, #f5f3ff); }
        .feature-card.c3 { --accent: #06b6d4; --accent-soft: linear-gradient(135deg, #cffafe, #ecfeff); }
        .feature-card.c4 { --accent: #10b981; --accent-soft: linear-gradient(135deg, #d1fae5, #ecfdf5); }

        .feature-card.c1 .icon-wrap,
        .feature-card.c2 .icon-wrap,
        .feature-card.c3 .icon-wrap,
        .feature-card.c4 .icon-wrap {
            background: var(--accent-soft);
        }

        /* ============ CTA 区 ============ */
        .cta-section {
            padding: 64px 48px 80px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .cta-box {
            position: relative;
            background: linear-gradient(135deg, #1a5cff 0%, #4338ca 100%);
            border-radius: 28px;
            padding: 56px 48px;
            text-align: center;
            color: #fff;
            overflow: hidden;
            box-shadow: 0 24px 48px rgba(26,92,255,0.25);
        }
        .cta-box::before {
            content: '';
            position: absolute;
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            top: -180px; right: -100px;
            border-radius: 50%;
        }
        .cta-box::after {
            content: '';
            position: absolute;
            width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(167,139,250,0.3) 0%, transparent 70%);
            bottom: -140px; left: -80px;
            border-radius: 50%;
        }
        .cta-box h2 {
            font-size: clamp(26px, 4vw, 36px);
            font-weight: 800;
            margin-bottom: 12px;
            position: relative;
            letter-spacing: -.5px;
        }
        .cta-box p {
            font-size: 16px;
            opacity: 0.92;
            margin-bottom: 32px;
            max-width: 520px;
            margin-left: auto;
            margin-right: auto;
            position: relative;
        }
        .cta-box .actions {
            position: relative;
            display: inline-flex;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .btn-white {
            background: #fff;
            color: #1a5cff;
            padding: 16px 32px;
            font-size: 16px;
            border-radius: 14px;
        }
        .btn-white:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }
        .btn-outline {
            background: rgba(255,255,255,0.1);
            color: #fff;
            padding: 16px 28px;
            font-size: 16px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(8px);
        }
        .btn-outline:hover {
            background: rgba(255,255,255,0.2);
        }

        /* ============ 底部 ============ */
        .footer {
            background: #ffffff;
            border-top: 1px solid #edf2f9;
            padding: 32px 48px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            color: #64748b;
            font-size: 14px;
        }
        .footer .links {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .footer .links a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer .links a:hover { color: #1a5cff; }

        /* ===== 响应式 ===== */
        @media (max-width: 1100px) {
            .float-card { display: none; }
        }
        @media (max-width: 768px) {
            .navbar { padding: 12px 18px; }
            .nav-brand { display: none; }
            .hero { padding: 64px 20px 56px; }
            .features { padding: 40px 20px 32px; }
            .feature-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
            .cta-section { padding: 32px 20px 56px; }
            .cta-box { padding: 40px 24px; border-radius: 22px; }
            .footer { flex-direction: column; text-align: center; padding: 24px 20px; }
            .hero-stats { gap: 24px; }
        }
        @media (max-width: 480px) {
            .navbar { padding: 10px 14px; }
            .logo { font-size: 20px; gap: 8px; }
            .logo i { font-size: 22px; }
            .nav-login { padding: 7px 16px; font-size: 14px; }
            .hero { padding: 48px 16px 40px; }
            .hero-text h1 { letter-spacing: -.5px; }
            .features { padding: 28px 16px; }
            .features h2 { font-size: 26px; }
            .feature-grid { grid-template-columns: 1fr; gap: 14px; }
            .feature-card { padding: 24px 22px; }
            .cta-section { padding: 20px 16px 48px; }
            .cta-box { padding: 32px 20px; }
            .footer .links { gap: 16px; font-size: 13px; }
        }
    </style>
</head>
<body>

    <!-- 导航 -->
    <nav class="navbar">
        <a href="<?= appUrl('') ?>" class="logo">
            <i class="fas fa-cloud"></i>
            <span>TangWo</span>
        </a>
        <div class="nav-brand"><b>TangWo</b> CLOUD · 私有文件</div>
        <div class="nav-right">
            <a href="<?= appUrl('dashboard') ?>" class="nav-login"><i class="fas fa-sign-in-alt"></i> 登录</a>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero">
        <div class="hero-bg">
            <div class="grid"></div>
            <div class="glow glow-1"></div>
            <div class="glow glow-2"></div>
            <div class="glow glow-3"></div>
        </div>

        <!-- 左右浮动装饰 -->
        <div class="float-card fc-1">
            <div class="ic"><i class="fas fa-cloud-arrow-up"></i></div>
            <div class="lbl">
                <div class="t">高速上传</div>
                <div class="s">千兆带宽</div>
            </div>
        </div>
        <div class="float-card fc-2">
            <div class="ic"><i class="fas fa-shield-alt"></i></div>
            <div class="lbl">
                <div class="t">端到端加密</div>
                <div class="s">HTTPS 加密传输</div>
            </div>
        </div>
        <div class="float-card fc-3">
            <div class="ic"><i class="fas fa-share-nodes"></i></div>
            <div class="lbl">
                <div class="t">一键分享</div>
                <div class="s">免登录直链下载</div>
            </div>
        </div>
        <div class="float-card fc-4">
            <div class="ic"><i class="fas fa-arrows-rotate"></i></div>
            <div class="lbl">
                <div class="t">多端同步</div>
                <div class="s">实时备份</div>
            </div>
        </div>

        <div class="hero-content">
            <div class="hero-badge"><i class="fas fa-shield-alt"></i> 端到端加密 · 安全可靠</div>
            <h1>存储 · 分享 · 同步<br><span class="grad">极速且安全</span></h1>
            <p class="sub">下载不限速度，不限流量，不限次数<br>免登录下载，给您的数据一个安心</p>
            <div class="tagline"><i class="fas fa-circle"></i> 已稳定运行，为数万名用户提供云存储服务</div>

            <div class="hero-cta">
                <a href="<?= appUrl('dashboard') ?>" class="btn btn-primary">
                    <i class="fas fa-rocket"></i> 立即开始
                </a>
                <a href="#features" class="btn btn-ghost">
                    <i class="fas fa-info-circle"></i> 了解更多
                </a>
            </div>

            <div class="hero-stats">
                <div class="stat-item">
                    <div class="num">∞</div>
                    <div class="lbl">下载速度</div>
                </div>
                <div class="stat-item">
                    <div class="num">100%</div>
                    <div class="lbl">数据加密</div>
                </div>
                <div class="stat-item">
                    <div class="num">24/7</div>
                    <div class="lbl">在线服务</div>
                </div>
                <div class="stat-item">
                    <div class="num">0 元</div>
                    <div class="lbl">注册使用</div>
                </div>
            </div>
        </div>
    </section>

    <!-- 功能亮点 -->
    <section class="features" id="features">
        <div class="section-head">
            <h2>为什么选择我们</h2>
            <p class="sub">为个人和团队提供高效、安全的云存储服务</p>
        </div>
        <div class="feature-grid">
            <div class="feature-card c1">
                <div class="icon-wrap"><div class="bg"></div><i class="fas fa-rocket"></i></div>
                <h3>极速传输</h3>
                <p>上传下载不限速，千兆带宽跑满，无论何时何地，文件传输快人一步。</p>
            </div>
            <div class="feature-card c2">
                <div class="icon-wrap"><div class="bg"></div><i class="fas fa-shield-alt"></i></div>
                <h3>隐私安全</h3>
                <p>非对称加密存储 + HTTPS 加密传输，严防数据泄露，绝无"扫盘"行为。</p>
            </div>
            <div class="feature-card c3">
                <div class="icon-wrap"><div class="bg"></div><i class="fas fa-link"></i></div>
                <h3>免登分享</h3>
                <p>一键生成直链，接收方无需登录、无需客户端，浏览器直接下载。</p>
            </div>
            <div class="feature-card c4">
                <div class="icon-wrap"><div class="bg"></div><i class="fas fa-arrows-rotate"></i></div>
                <h3>多端同步</h3>
                <p>手机、电脑、云端自动同步，增量备份，重要文件永不丢失。</p>
            </div>
        </div>
    </section>

    <!-- 行动召唤 -->
    <section class="cta-section">
        <div class="cta-box">
            <h2>即刻开始，安全存储</h2>
            <p>注册即可获得 2GB 免费空间，无需信用卡、无需下载客户端。</p>
            <div class="actions">
                <a href="<?= appUrl('dashboard') ?>" class="btn btn-white">
                    <i class="fas fa-user-plus"></i> 立即注册
                </a>
                <a href="<?= appUrl('dashboard') ?>?view=upgrade" class="btn btn-outline">
                    <i class="fas fa-circle-arrow-up"></i> 查看套餐
                </a>
            </div>
        </div>
    </section>

    <!-- 底部 -->
    <footer class="footer">
        <span>© 2026 TangWo · TangWo 网盘（tangwo-disk 开源版）</span>
        <div class="links">
            <a href="<?= url('privacy') ?>">隐私政策</a>
            <a href="<?= url('terms') ?>">服务协议</a>
            <a href="<?= url('help') ?>">帮助中心</a>
            <a href="<?= url('contact') ?>">联系我们</a>
        </div>
    </footer>

</body>
</html>