<?php
/**
 * 安装向导（单页表单 + 一步到位）
 *
 * 流程：
 *   1. 环境检查（PHP 版本、必需扩展、uploads/ 可写）
 *   2. 表单：数据库凭据 / 站点信息 / 管理员账号
 *   3. 提交 → 测试连接 → 写 config_db.php → 建库 → 跑迁移 → 建管理员 → 完成
 *
 * 幂等：已安装（config_db.php 存在且库已建）会显示"已安装"页 + 提示删除本文件
 *
 * 设计要点：
 *   - 故意不 require config.php：向导期间 DB_* 尚未确定，要用用户提交的临时凭据测试连接
 *   - 向导写出的 config_db.php 包含 DB_HOST/DB_NAME/DB_USER/DB_PASS/SITE_URL 五个常量
 *   - config.php 启动时优先 require config_db.php（已修）
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');

// 会话（用于步骤间暂存 + 一次性 token 防 CSRF）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ====== 工具：HTML 转义 ======
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ====== 工具：检测是否已安装 ======
function isInstalled(): bool {
    return file_exists(__DIR__ . '/config_db.php');
}

// ====== 工具：默认值（自动猜测） ======
function defaultDbName(): string {
    // 默认 = 站点 host 的第一段（如 192.168.1.12 → phpdisk_192_168_1_12），避免与已有库冲突
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $seg  = preg_split('/[.:]/', $host, -1, PREG_SPLIT_NO_EMPTY)[0] ?? 'phpdisk';
    return 'phpdisk_' . preg_replace('/[^a-z0-9]/i', '_', strtolower($seg));
}
function defaultSiteUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ====== 环境检查 ======
$checks = [
    ['PHP 版本 ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), '当前：' . PHP_VERSION],
    ['PDO MySQL 扩展',  extension_loaded('pdo_mysql'),             extension_loaded('pdo_mysql') ? '已加载' : '请在 php.ini 启用 extension=pdo_mysql'],
    ['JSON 扩展',      extension_loaded('json'),                   extension_loaded('json') ? '已加载' : '请在 php.ini 启用 extension=json'],
    ['uploads/ 可写',  is_writable(__DIR__ . '/uploads/'),          is_writable(__DIR__ . '/uploads/') ? '可写' : '请 chmod 755/777 uploads/'],
    ['config_db.php 可写', is_writable(__DIR__),                   is_writable(__DIR__) ? '可写（安装器将写入）' : '请 chmod 755/777 当前目录'],
];
$envOk = !in_array(false, array_column($checks, 1), true);

// ====== CSRF token ======
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['install_token'];

// ====== 已安装：显示提示页 ======
if (isInstalled() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>已安装 · TangWo 云盘</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#f9fcff;color:#1a2332;position:relative;overflow:hidden}
    .orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.5;pointer-events:none}
    .orb1{width:420px;height:420px;background:#dceaff;top:-120px;left:-100px}
    .orb2{width:380px;height:380px;background:#efe3ff;bottom:-120px;right:-80px}
    .box{position:relative;max-width:520px;width:100%;border:1px solid #e3ecf7;border-radius:20px;padding:40px;background:rgba(255,255,255,.9);backdrop-filter:blur(12px);box-shadow:0 20px 50px rgba(26,92,255,.1)}
    .icon{width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,#1a5cff,#6d5cff);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;margin:0 auto 20px;box-shadow:0 10px 24px rgba(26,92,255,.35)}
    h1{text-align:center;font-size:22px;color:#0f172a;margin-bottom:8px}
    .sub{text-align:center;color:#64748b;font-size:14px;margin-bottom:24px}
    .list{background:#f8fafc;border-radius:12px;padding:18px 22px;margin-bottom:24px}
    .list ol{margin-left:18px;color:#334155;font-size:14px;line-height:2}
    code{background:#e8f0fe;color:#1d4ed8;padding:2px 8px;border-radius:6px;font-size:12.5px}
    .btns{display:flex;gap:12px}
    .btns a{flex:1;text-align:center;text-decoration:none;font-weight:600;font-size:14px;padding:13px;border-radius:12px;transition:.2s}
    .b1{background:linear-gradient(135deg,#1a5cff,#6d5cff);color:#fff;box-shadow:0 8px 20px rgba(26,92,255,.3)}
    .b1:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(26,92,255,.4)}
    .b2{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .b2:hover{border-color:#cbd5e1;background:#f8fafc}
    </style></head><body>
    <div class="orb orb1"></div><div class="orb orb2"></div>
    <div class="box">
        <div class="icon">✓</div>
        <h1>已安装</h1>
        <p class="sub">检测到 <code>config_db.php</code> 已存在，系统运行正常</p>
        <div class="list">
            <strong style="font-size:13px;color:#b45309">⚠ 如需重新安装：</strong>
            <ol>
                <li>删除 <code>config_db.php</code></li>
                <li>（可选）清空数据库所有表</li>
                <li>重新访问本页，向导会再次出现</li>
            </ol>
        </div>
        <div class="btns">
            <a class="b1" href="/">进入首页</a>
            <a class="b2" href="/dashboard">控制台</a>
        </div>
    </div></body></html>
    <?php
    exit;
}

// ====== 处理 POST 提交 ======
$err = '';
$ok  = '';
$formData = [
    'db_host'    => '127.0.0.1',
    'db_port'    => '3306',
    'db_name'    => defaultDbName(),
    'db_user'    => 'root',
    'db_pass'    => '',
    'site_name'  => 'TangWo 网盘',
    'site_url'   => defaultSiteUrl(),
    'admin_user' => 'admin',
    'admin_pass' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 校验
    if (($_POST['_token'] ?? '') !== $token) {
        $err = '会话已过期，请刷新页面重试';
    } else {
        // 收集表单数据
        foreach ($formData as $k => $_) {
            $formData[$k] = trim((string)($_POST[$k] ?? ''));
        }
        // 必填项
        if (!$formData['db_host'] || !$formData['db_name'] || !$formData['db_user']) {
            $err = '数据库主机 / 库名 / 用户名 不能为空';
        } elseif (strlen($formData['admin_pass']) < 6) {
            $err = '管理员密码至少 6 位';
        } elseif (!$formData['site_url']) {
            $err = '站点 URL 不能为空';
        } else {
            // 测试连接（不指定 DB，先验证账号能登录 MySQL）
            try {
                $port = $formData['db_port'] ? ';port=' . (int)$formData['db_port'] : '';
                $pdoSrv = new PDO(
                    'mysql:host=' . $formData['db_host'] . $port . ';charset=utf8mb4',
                    $formData['db_user'],
                    $formData['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
            } catch (PDOException $e) {
                $err = '数据库连接失败：' . $e->getMessage();
            }

            if (!$err) {
                // 建库（IF NOT EXISTS，幂等）
                try {
                    $pdoSrv->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $formData['db_name'])
                        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (PDOException $e) {
                    $err = '建库失败：' . $e->getMessage();
                }
            }

            if (!$err) {
                // 切换到目标库，跑迁移
                try {
                    $pdo = new PDO(
                        'mysql:host=' . $formData['db_host'] . $port . ';dbname=' . $formData['db_name'] . ';charset=utf8mb4',
                        $formData['db_user'],
                        $formData['db_pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    // 加载迁移内核
                    require_once __DIR__ . '/migrate.php';
                    createSchema($pdo);
                    runMigrations($pdo);
                    // 写入站点设置
                    $pdo->prepare("INSERT INTO settings (key_name, value, description) VALUES ('site_name', ?, '网站名称')
                                   ON DUPLICATE KEY UPDATE value = ?")
                        ->execute([$formData['site_name'], $formData['site_name']]);
                    // 创建管理员
                    $hash = password_hash($formData['admin_pass'], PASSWORD_DEFAULT);
                    $pdo->prepare('INSERT INTO users (id, username, password) VALUES (1, ?, ?)
                                   ON DUPLICATE KEY UPDATE password = ?')
                        ->execute([$formData['admin_user'], $hash, $hash]);
                } catch (PDOException $e) {
                    $err = '迁移失败：' . $e->getMessage();
                } catch (Throwable $e) {
                    $err = '安装失败：' . $e->getMessage();
                }
            }

            if (!$err) {
                // 写 config_db.php（这一步要在最后，因为写完后系统进入"已安装"态）
                $cfgContent = "<?php\n"
                    . "// === 由安装器自动写入，请勿手动编辑 ===\n"
                    . "// 如需重新安装：删除本文件后访问 /install。\n"
                    . "define('DB_HOST', " . var_export($formData['db_host'], true) . ");\n"
                    . "define('DB_NAME', " . var_export($formData['db_name'], true) . ");\n"
                    . "define('DB_USER', " . var_export($formData['db_user'], true) . ");\n"
                    . "define('DB_PASS', " . var_export($formData['db_pass'], true) . ");\n"
                    . "define('SITE_URL', " . var_export(rtrim($formData['site_url'], '/'), true) . ");\n";
                if (@file_put_contents(__DIR__ . '/config_db.php', $cfgContent) === false) {
                    $err = '写入 config_db.php 失败，请检查目录权限';
                } else {
                    $ok = '安装完成';
                    // 清空表单避免页面残留密码
                    $formData['db_pass'] = '';
                    $formData['admin_pass'] = '';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 · TangWo 云盘</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <style>
        :root {
            --brand: #1a5cff; --brand-2: #6d5cff; --brand-dark: #0e3fd8;
            --card: #fff; --bd: #e3ecf7; --muted: #64748b;
            --ok: #16a34a; --err: #dc2626; --warn: #b45309;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        body {
            background: #f9fcff; min-height: 100vh; padding: 40px 16px 60px; color: #0f172a;
            position: relative; overflow-x: hidden;
        }
        /* ===== 背景装饰：双光晕 + 网格 ===== */
        .orb { position: absolute; border-radius: 50%; filter: blur(90px); opacity: .55; pointer-events: none; }
        .orb-1 { width: 480px; height: 480px; background: #dceaff; top: -140px; left: -120px; }
        .orb-2 { width: 420px; height: 420px; background: #efe3ff; bottom: -140px; right: -100px; }
        .bg-grid {
            position: absolute; inset: 0; pointer-events: none;
            background-image: linear-gradient(rgba(26,92,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(26,92,255,.045) 1px, transparent 1px);
            background-size: 44px 44px;
            -webkit-mask-image: radial-gradient(ellipse 90% 60% at 50% 0%, #000 40%, transparent 100%);
            mask-image: radial-gradient(ellipse 90% 60% at 50% 0%, #000 40%, transparent 100%);
        }
        .wrap { max-width: 720px; margin: 0 auto; position: relative; z-index: 1; }

        /* ===== 顶部 Hero ===== */
        .header { text-align: center; margin-bottom: 32px; }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,.8); backdrop-filter: blur(8px);
            border: 1px solid #dbe7fb; border-radius: 999px;
            padding: 7px 18px; font-size: 13px; font-weight: 600; color: var(--brand);
            box-shadow: 0 4px 14px rgba(26,92,255,.1); margin-bottom: 18px;
        }
        .badge .dot { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
        .header h1 { font-size: 32px; font-weight: 800; letter-spacing: -.02em; color: #0f172a; }
        .header h1 .grad {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        .header p { color: var(--muted); margin-top: 10px; font-size: 15px; }

        /* ===== 步骤条 ===== */
        .steps { display: flex; align-items: center; justify-content: center; margin: 26px 0 30px; }
        .step { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #94a3b8; }
        .step .n {
            width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            background: #eef2f7; color: #94a3b8; font-size: 12px; font-weight: 700; transition: .2s;
        }
        .step.active { color: var(--brand); }
        .step.active .n { background: linear-gradient(135deg, var(--brand), var(--brand-2)); color: #fff; box-shadow: 0 4px 12px rgba(26,92,255,.35); }
        .step-line { width: 42px; height: 2px; background: #e2e8f0; margin: 0 10px; border-radius: 2px; }

        /* ===== 卡片 ===== */
        .card {
            background: var(--card); border: 1px solid var(--bd); border-radius: 18px;
            padding: 26px 28px; margin-bottom: 18px; position: relative;
            box-shadow: 0 4px 20px rgba(15,23,42,.04); overflow: hidden;
            transition: box-shadow .2s;
        }
        .card:hover { box-shadow: 0 8px 30px rgba(15,23,42,.07); }
        .card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--brand), var(--brand-2));
            opacity: 0; transition: opacity .2s;
        }
        .card:hover::before { opacity: 1; }
        .card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .icon-chip {
            width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 16px; flex-shrink: 0;
        }
        .chip-1 { background: linear-gradient(135deg, #1a5cff, #4f8bff); box-shadow: 0 6px 14px rgba(26,92,255,.3); }
        .chip-2 { background: linear-gradient(135deg, #8b5cf6, #a78bfa); box-shadow: 0 6px 14px rgba(139,92,246,.3); }
        .chip-3 { background: linear-gradient(135deg, #0ea5e9, #38bdf8); box-shadow: 0 6px 14px rgba(14,165,233,.3); }
        .chip-4 { background: linear-gradient(135deg, #10b981, #34d399); box-shadow: 0 6px 14px rgba(16,185,129,.3); }
        .card-head h2 { font-size: 16px; font-weight: 700; color: #0f172a; }
        .card-head .sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

        /* ===== 环境检查 ===== */
        .check { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; font-size: 14px; border-radius: 10px; margin-bottom: 6px; background: #f8fafc; border: 1px solid transparent; }
        .check:last-child { margin-bottom: 0; }
        .check .lbl { color: #334155; display: flex; align-items: center; gap: 10px; }
        .check .lbl i { width: 18px; text-align: center; font-size: 14px; color: var(--muted); }
        .check .val { color: var(--muted); font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .check.ok { background: #f0fdf4; border-color: #dcfce7; }
        .check.ok .val { color: var(--ok); font-weight: 600; }
        .check.ok .lbl i { color: var(--ok); }
        .check.fail { background: #fef2f2; border-color: #fee2e2; }
        .check.fail .val { color: var(--err); font-weight: 600; }
        .check.fail .lbl i { color: var(--err); }

        /* ===== 提示框 ===== */
        .alert { padding: 15px 20px; border-radius: 14px; font-size: 14px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.6; }
        .alert i { margin-top: 2px; }
        .alert.err  { background: #fef2f2; color: var(--err);  border: 1px solid #fecaca; }
        .alert.ok   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .alert.warn { background: #fffbeb; color: var(--warn); border: 1px solid #fde68a; }

        /* ===== 表单 ===== */
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } .header h1 { font-size: 26px; } .steps { display: none; } }
        .field { margin-bottom: 14px; }
        .field:last-child { margin-bottom: 0; }
        .field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 7px; }
        .field label .req { color: var(--err); margin-left: 2px; }
        .field .input-wrap { position: relative; }
        .field .input-wrap i.lead { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; transition: color .15s; }
        .field input {
            width: 100%; padding: 11px 12px 11px 36px; border: 1.5px solid var(--bd); border-radius: 11px;
            font-size: 14px; background: #fbfdff; transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .field input:focus { outline: 0; border-color: var(--brand); background: #fff; box-shadow: 0 0 0 4px rgba(26,92,255,.1); }
        .field .input-wrap:focus-within i.lead { color: var(--brand); }
        .field .hint { font-size: 12px; color: var(--muted); margin-top: 5px; display: flex; align-items: center; gap: 5px; }
        input[type="password"]::-ms-reveal, input[type="password"]::-ms-clear { display: none; }

        /* ===== 提交按钮 ===== */
        .submit {
            width: 100%; padding: 15px; border: 0; border-radius: 13px;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
            color: #fff; font-size: 15.5px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 10px 26px rgba(26,92,255,.35);
            transition: transform .15s, box-shadow .15s;
            margin-top: 22px;
        }
        .submit:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(26,92,255,.45); }
        .submit:active { transform: translateY(0); }
        .submit:disabled { background: #94a3b8; cursor: not-allowed; box-shadow: none; transform: none; }

        /* ===== 成功页 ===== */
        .actions { display: flex; gap: 12px; margin-top: 24px; }
        .actions a {
            flex: 1; padding: 14px; border-radius: 13px; text-align: center; text-decoration: none;
            font-weight: 700; font-size: 14.5px; transition: transform .15s, box-shadow .15s;
        }
        .btn-primary { background: linear-gradient(135deg, var(--brand), var(--brand-2)); color: #fff; box-shadow: 0 10px 24px rgba(26,92,255,.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(26,92,255,.4); }
        .btn-secondary { background: #fff; color: #334155; border: 1.5px solid var(--bd); }
        .btn-secondary:hover { border-color: #cbd5e1; background: #f8fafc; }
        .success-icon {
            width: 76px; height: 76px; margin: 8px auto 22px; border-radius: 22px;
            background: linear-gradient(135deg, #10b981, #34d399); color: #fff; font-size: 34px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 14px 34px rgba(16,185,129,.4);
        }
        .ok-detail { background: #f8fafc; border-radius: 12px; padding: 14px 18px; margin-top: 14px; font-size: 13.5px; color: #475569; line-height: 2; text-align: left; }
        .ok-detail code { background: #e8f0fe; color: #1d4ed8; padding: 2px 8px; border-radius: 6px; font-size: 12.5px; }
        .footer-note { text-align: center; color: var(--muted); font-size: 12.5px; margin-top: 26px; }
        .footer-note code { background: #eef2f7; padding: 2px 7px; border-radius: 5px; font-size: 11.5px; }
    </style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="bg-grid"></div>

<div class="wrap">

    <div class="header">
        <div class="badge"><span class="dot"></span> 安装向导 · 全程约 1 分钟</div>
        <h1>TangWo <span class="grad">云盘</span></h1>
        <p>填写关键信息，自动建库建表并创建管理员账号</p>
    </div>

    <?php if ($err): ?>
        <div class="alert err"><i class="fas fa-exclamation-circle"></i><div><?= h($err) ?></div></div>
    <?php endif; ?>

    <?php if ($ok): ?>
        <div class="card" style="text-align:center">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <h2 style="font-size:22px;color:#0f172a;margin-bottom:8px">安装完成</h2>
            <p style="color:var(--muted);font-size:14px">数据库已就绪，管理员账号已创建</p>
            <div class="ok-detail">
                管理员账号：<code><?= h($formData['admin_user']) ?></code>（密码为你填写的那个）<br>
                站点地址：<code><?= h($formData['site_url']) ?></code><br>
                数据库：<code><?= h($formData['db_name']) ?></code> · schema 1004
            </div>
            <div class="actions">
                <a class="btn-primary" href="/dashboard"><i class="fas fa-sign-in-alt"></i> 进入控制台</a>
                <a class="btn-secondary" href="/">返回首页</a>
            </div>
        </div>
        <div class="footer-note">
            <i class="fas fa-shield-alt" style="color:#94a3b8"></i>
            安全提示：部署稳定后建议删除 <code>install.php</code>，防止被重复触发
        </div>
    </div></body></html>
        <?php exit; ?>
    <?php endif; ?>

    <!-- 步骤指示 -->
    <div class="steps">
        <div class="step active"><span class="n">1</span> 环境</div>
        <div class="step-line"></div>
        <div class="step active"><span class="n">2</span> 数据库</div>
        <div class="step-line"></div>
        <div class="step active"><span class="n">3</span> 站点</div>
        <div class="step-line"></div>
        <div class="step active"><span class="n">4</span> 管理员</div>
    </div>

    <form method="POST" action="">

        <!-- 1 环境检查 -->
        <div class="card">
            <div class="card-head">
                <span class="icon-chip chip-1"><i class="fas fa-heart-pulse"></i></span>
                <div>
                    <h2>环境检查</h2>
                    <div class="sub">运行环境自检</div>
                </div>
            </div>
            <?php foreach ($checks as $c): ?>
                <div class="check <?= $c[1] ? 'ok' : 'fail' ?>">
                    <span class="lbl"><i class="fas fa-<?= $c[1] ? 'check-circle' : 'times-circle' ?>"></i><?= h($c[0]) ?></span>
                    <span class="val"><?= h($c[2]) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$envOk): ?>
                <div class="alert warn" style="margin-top:14px;margin-bottom:0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>环境检查未全部通过，仍可继续尝试安装（部分功能可能异常）。</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2 数据库配置 -->
        <div class="card">
            <div class="card-head">
                <span class="icon-chip chip-2"><i class="fas fa-database"></i></span>
                <div>
                    <h2>数据库配置</h2>
                    <div class="sub">MySQL 连接信息（宝塔面板可查）</div>
                </div>
            </div>
            <div class="grid2">
                <div class="field">
                    <label>数据库主机<span class="req">*</span></label>
                    <div class="input-wrap">
                        <input name="db_host" value="<?= h($formData['db_host']) ?>" required>
                        <i class="fas fa-server lead"></i>
                    </div>
                    <div class="hint"><i class="fas fa-info-circle"></i>本地填 127.0.0.1</div>
                </div>
                <div class="field">
                    <label>端口</label>
                    <div class="input-wrap">
                        <input name="db_port" value="<?= h($formData['db_port']) ?>">
                        <i class="fas fa-plug lead"></i>
                    </div>
                    <div class="hint"><i class="fas fa-info-circle"></i>默认 3306</div>
                </div>
            </div>
            <div class="field">
                <label>数据库名<span class="req">*</span></label>
                <div class="input-wrap">
                    <input name="db_name" value="<?= h($formData['db_name']) ?>" required>
                    <i class="fas fa-folder-tree lead"></i>
                </div>
                <div class="hint"><i class="fas fa-magic"></i>不存在会自动创建（utf8mb4）</div>
            </div>
            <div class="grid2">
                <div class="field">
                    <label>用户名<span class="req">*</span></label>
                    <div class="input-wrap">
                        <input name="db_user" value="<?= h($formData['db_user']) ?>" required>
                        <i class="fas fa-user lead"></i>
                    </div>
                </div>
                <div class="field">
                    <label>密码</label>
                    <div class="input-wrap">
                        <input type="password" name="db_pass" value="<?= h($formData['db_pass']) ?>" placeholder="宝塔数据库密码">
                        <i class="fas fa-key lead"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3 站点信息 -->
        <div class="card">
            <div class="card-head">
                <span class="icon-chip chip-3"><i class="fas fa-globe"></i></span>
                <div>
                    <h2>站点信息</h2>
                    <div class="sub">对外访问地址</div>
                </div>
            </div>
            <div class="field">
                <label>站点名称</label>
                <div class="input-wrap">
                    <input name="site_name" value="<?= h($formData['site_name']) ?>">
                    <i class="fas fa-signature lead"></i>
                </div>
            </div>
            <div class="field">
                <label>站点 URL<span class="req">*</span></label>
                <div class="input-wrap">
                    <input name="site_url" value="<?= h($formData['site_url']) ?>" required>
                    <i class="fas fa-link lead"></i>
                </div>
                <div class="hint"><i class="fas fa-info-circle"></i>结尾不带斜杠</div>
            </div>
        </div>

        <!-- 4 管理员账号 -->
        <div class="card">
            <div class="card-head">
                <span class="icon-chip chip-4"><i class="fas fa-user-shield"></i></span>
                <div>
                    <h2>管理员账号</h2>
                    <div class="sub">第一个账号，拥有全部权限</div>
                </div>
            </div>
            <div class="grid2">
                <div class="field">
                    <label>管理员用户名<span class="req">*</span></label>
                    <div class="input-wrap">
                        <input name="admin_user" value="<?= h($formData['admin_user']) ?>" required>
                        <i class="fas fa-user-gear lead"></i>
                    </div>
                </div>
                <div class="field">
                    <label>管理员密码<span class="req">*</span></label>
                    <div class="input-wrap">
                        <input type="password" name="admin_pass" required placeholder="至少 6 位">
                        <i class="fas fa-lock lead"></i>
                    </div>
                    <div class="hint"><i class="fas fa-info-circle"></i>至少 6 位，请妥善保管</div>
                </div>
            </div>

            <input type="hidden" name="_token" value="<?= h($token) ?>">
            <button type="submit" class="submit" id="installBtn">
                <i class="fas fa-rocket"></i> 开始安装
            </button>
        </div>

    </form>

    <script>
    // 点击后立即给加载反馈（建库建表需数秒，避免"看起来没反应"）
    document.getElementById('installBtn').addEventListener('click', function () {
        if (this.disabled) return;
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> 正在安装，请稍候…';
    });
    </script>

</div>
</body>
</html>
