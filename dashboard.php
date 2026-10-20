<?php
require_once 'functions.php';
require_once __DIR__ . '/migrate.php';   // 让 db() 首次连接时自动跑 schema migration

// 确保会话已启动（图形验证码 / 注册验证码都依赖 $_SESSION）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 同步生成图形验证码并存入 session（避免 captcha.php 异步加载晚到，导致 POST 校验不到）
// 注意：只在 GET 请求时生成；POST 校验时不能再覆盖 session，否则用户输入的码永远对不上
if (!isLogin() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    issueCaptcha();
}

// 自动补齐 users.contact 字段（兼容已在运行的旧库，无需手动跑迁移）
function ensureUserContactColumn(): void {
    try {
        db()->exec("ALTER TABLE users ADD COLUMN contact VARCHAR(191) NULL COMMENT '手机号或邮箱（注册验证）'");
    } catch (PDOException $e) {
        $ignorable = (($e->errorInfo[1] ?? 0) == 1060) || stripos($e->getMessage(), 'Duplicate column') !== false;
        if (!$ignorable) throw $e;
    }
}

// 自动补齐 files.type / parent_id 字段（文件夹系统，兼容旧库）
function ensureFolderColumns(): void {
    $pdo = db();
    // type 列
    try {
        $pdo->exec("ALTER TABLE files ADD COLUMN type ENUM('file','folder') DEFAULT 'file' AFTER short_code");
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        if (!($code == 1060 || stripos($e->getMessage(), 'Duplicate') !== false)) throw $e;
    }
    // parent_id 列
    try {
        $pdo->exec("ALTER TABLE files ADD COLUMN parent_id INT DEFAULT NULL AFTER type");
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        if (!($code == 1060 || stripos($e->getMessage(), 'Duplicate') !== false)) throw $e;
    }
    // parent_id 索引
    try {
        $pdo->exec("ALTER TABLE files ADD INDEX idx_parent (parent_id)");
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        if (!($code == 1061 || stripos($e->getMessage(), 'Duplicate') !== false)) throw $e;
    }
    // short_code 允许 NULL（文件夹不需要短链）
    try {
        $pdo->exec("ALTER TABLE files MODIFY short_code VARCHAR(20) NULL");
    } catch (PDOException $e) {
        // MODIFY 失败通常无害（类型已一致），忽略
    }
    // 标记 schema 版本到 1004（让 update.php 知道已迁移过）
    try {
        $pdo->prepare("INSERT INTO settings (key_name, value, description) VALUES ('schema_version', '1004', 'schema version')
                       ON DUPLICATE KEY UPDATE value = '1004'")->execute();
    } catch (Exception $e) { /* 忽略 */ }
}

// 页面加载时自动修复缺失的列（兼容旧库，无需手动跑 update.php）
ensureFolderColumns();

// 登录 / 注册处理（访客态）
$error = '';
// 默认展示登录 tab；可被 ?tab=register 切换（兼容旧 login.php / register.php 重定向）
$tab = (($_GET['tab'] ?? '') === 'register') ? 'register' : 'login';
// PRG：错误码 → 错误文案（避免 POST 失败页面刷新触发“重新提交表单”弹窗）
$errMap = [
    'captcha'      => '验证码错误或已过期，请重新输入',
    'password'     => '用户名或密码错误',
    'register_len' => '用户名至少3位，密码至少6位',
    'register_contact' => '请填写正确的手机号或邮箱',
    'register_code'=> '验证码错误或已过期，请重新获取',
    'register_dup' => '用户名已存在',
];
$errCode = $_GET['err'] ?? '';
if ($errCode && isset($errMap[$errCode])) {
    $error = $errMap[$errCode];
    // 保留 register tab if error comes from register
    if (in_array($errCode, ['register_len', 'register_contact', 'register_code', 'register_dup'], true)) {
        $tab = 'register';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'login') {
        // 验证码校验走抽象层：本地图形码 / 极验 v4 / 腾讯云 2.0 按后台配置自动切换
        if (!CaptchaManager::verify($_POST)) {
            // PRG：失败跳 GET（err=captcha），避免刷新弹“重新提交表单”
            redirect(appUrl('dashboard') . '?tab=login&err=captcha');
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$_POST['username'] ?? '']);
            $user = $stmt->fetch();
            if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                startSessionTicket();
                // PRG：303 重定向，确保刷新/返回不再提示重新提交表单
                redirect(appUrl('dashboard'));
            } else {
                // PRG：失败跳 GET（err=password）
                redirect(appUrl('dashboard') . '?tab=login&err=password');
            }
        }
    } elseif ($action === 'sendcode') {
        // 发送注册验证码：邮箱走 SMTP、手机走短信厂商（阿里云/腾讯云）；
        // 均未配置时回退演示模式（页面直接提示验证码），保证开箱可用
        header('Content-Type: application/json; charset=utf-8');
        $contact = trim($_POST['contact'] ?? '');
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = preg_match('/^1[3-9]\d{9}$/', $contact) === 1;
        if (!$isEmail && !$isPhone) {
            echo json_encode(['error' => '请输入正确的手机号或邮箱']);
            exit;
        }
        $type = $isEmail ? 'email' : 'phone';
        // 真实通道已配置 → 走 VerifyCode（60 秒限频 + 5 分钟有效 + 一次性）
        if ($isEmail ? Mailer::enabled() : SmsManager::enabled()) {
            $r = VerifyCode::send($contact, 'register');
            echo json_encode(['ok' => $r['ok'], 'type' => $type, 'message' => $r['message']]);
            exit;
        }
        // 演示模式（未配置网关）：session 存码直接回显
        $code = (string) random_int(100000, 999999);
        $_SESSION['reg_code']      = $code;
        $_SESSION['reg_contact']   = $contact;
        $_SESSION['reg_code_exp']  = time() + 300;   // 5 分钟有效
        echo json_encode([
            'ok'   => true,
            'type' => $type,
            'code' => $code,        // 仅演示用，上线接真实网关后自动走真实通道
            'demo' => true,
        ]);
        exit;
    } elseif ($action === 'register') {
        ensureUserContactColumn();
        $user    = trim($_POST['username'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $contact = trim($_POST['contact'] ?? '');
        $code    = trim($_POST['code'] ?? '');
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;

        // 验证码校验：真实通道走 VerifyCode（库表），演示模式走 session
        $channelOn = $isEmail ? Mailer::enabled() : SmsManager::enabled();
        if ($channelOn) {
            $codeOk = VerifyCode::check($contact, $code, 'register');
        } else {
            $codeOk = !empty($_SESSION['reg_code'])
                && $contact === ($_SESSION['reg_contact'] ?? '')
                && strtolower($code) === strtolower($_SESSION['reg_code'])
                && time() <= ($_SESSION['reg_code_exp'] ?? 0);
            unset($_SESSION['reg_code'], $_SESSION['reg_contact'], $_SESSION['reg_code_exp']);
        }

        if (strlen($user) < 3 || strlen($pass) < 6) {
            redirect(appUrl('dashboard') . '?tab=register&err=register_len');
        } elseif (!preg_match('/^1[3-9]\d{9}$/', $contact) && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            redirect(appUrl('dashboard') . '?tab=register&err=register_contact');
        } elseif (!$codeOk) {
            redirect(appUrl('dashboard') . '?tab=register&err=register_code');
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            try {
                db()->prepare('INSERT INTO users (username, password, contact) VALUES (?, ?, ?)')
                    ->execute([$user, $hash, $contact]);
                $id = db()->lastInsertId();
                unset($_SESSION['reg_code'], $_SESSION['reg_contact'], $_SESSION['reg_code_exp']);
                $_SESSION['user_id'] = $id;
                startSessionTicket();
                // PRG：303 重定向，确保刷新/返回不再提示重新提交表单
                redirect(appUrl('dashboard'));
            } catch (PDOException $e) {
                redirect(appUrl('dashboard') . '?tab=register&err=register_dup');
            }
        }
    }
}

$isLogin = isLogin();
if ($isLogin) {
    $uid = currentUid();
    $quota = getQuota($uid);
    $isAdmin = ($uid === 1);

    // 当前所在目录（URL ?folder=ID）
    $folderId = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
    $currentFolder = null;
    if ($folderId) {
        $cf = db()->prepare('SELECT * FROM files WHERE id = ? AND user_id = ? AND type = "folder" AND status = 1');
        $cf->execute([$folderId, $uid]);
        $currentFolder = $cf->fetch();
        if (!$currentFolder) $folderId = null;
    }

    // 新建文件夹（POST，PRG 重定向）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mkdir') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            // 检查同目录下是否重名
            $dup = db()->prepare('SELECT id FROM files WHERE user_id = ? AND parent_id <=> ? AND file_name = ? AND type = "folder" AND status = 1');
            $dup->execute([$uid, $folderId, $name]);
            if (!$dup->fetch()) {
                $code = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);
                db()->prepare('INSERT INTO files (user_id, file_name, file_key, file_size, mime_type, short_code, type, parent_id) VALUES (?, ?, ?, 0, "folder", ?, "folder", ?)')
                    ->execute([$uid, $name, 'folder:' . $code, $code, $folderId]);
            }
        }
        $redir = appUrl('dashboard') . ($folderId ? '?folder=' . $folderId : '');
        redirect($redir);
    }

    // 当前目录下的文件与子文件夹
    $stmt = db()->prepare('SELECT * FROM files WHERE user_id = ? AND status = 1 AND parent_id <=> ? ORDER BY type DESC, id DESC');
    $stmt->execute([$uid, $folderId]);
    $files = $stmt->fetchAll();

    // 右侧面板用：当前用户全部有效文件数 + 回收站数
    $totalFiles = (int) db()->query('SELECT COUNT(*) FROM files WHERE user_id = ' . (int)$uid . ' AND status = 1 AND type = "file"')->fetchColumn();
    $trashCount = (int) db()->query('SELECT COUNT(*) FROM files WHERE user_id = ' . (int)$uid . ' AND status = 0')->fetchColumn();

    // 我的分享：只显示有效文件（不含文件夹）
    $shareStmt = db()->prepare('SELECT * FROM files WHERE user_id = ? AND status = 1 AND type = "file" ORDER BY id DESC');
    $shareStmt->execute([$uid]);
    $shareFiles = $shareStmt->fetchAll();

    // 回收站：已软删除（status=0）的文件/文件夹
    $trashStmt = db()->prepare('SELECT * FROM files WHERE user_id = ? AND status = 0 ORDER BY type DESC, id DESC');
    $trashStmt->execute([$uid]);
    $trash = $trashStmt->fetchAll();
    $breadcrumbs = [];
    if ($currentFolder) {
        $breadcrumbs[] = $currentFolder;
        $pid = $currentFolder['parent_id'];
        while ($pid) {
            $p = db()->prepare('SELECT id, file_name, parent_id FROM files WHERE id = ? AND user_id = ? AND type = "folder" AND status = 1');
            $p->execute([$pid, $uid]);
            $row = $p->fetch();
            if (!$row) break;
            array_unshift($breadcrumbs, $row);
            $pid = $row['parent_id'];
        }
    }

    $userStmt = db()->prepare('SELECT username FROM users WHERE id = ?');
    $userStmt->execute([$uid]);
    $username = $userStmt->fetchColumn() ?: '用户';

    $usedStr   = formatSize($quota['used']);
    $limitStr  = $quota['limit'] > 0 ? formatSize($quota['limit']) : '无限制';
    $remainStr = $quota['limit'] > 0 ? formatSize(max(0, $quota['limit'] - $quota['used'])) : '∞';
    $pct       = $quota['pct'];
    $maxUpload = formatSize((int) getSetting('max_file_size', 104857600));
}

// ============ 个人中心视图数据（嵌在 dashboard 内，?view=profile 触发显示）============
$pfActive = (($_GET['view'] ?? '') === 'profile');
$pfTab    = $_GET['tab'] ?? 'profile';
if (!in_array($pfTab, ['profile','plan','storage','security'], true)) $pfTab = 'profile';
$pwFlash = null;

if ($isLogin && $pfActive) {
    $user  = currentUser();
    if (!$user) { redirect(authUrl('logout')); }

    // 套餐配置（与个人中心/升级面板一致；可后续抽到公共文件）
    $MB = 1024 * 1024;
    $GB = 1024 * $MB;
    $PLANS = [
        'v0' => ['name' => 'V0', 'label' => '免费版', 'max' => 100 * $MB, 'storage' => 2  * $GB, 'price' => 0,  'free' => true],
        'v1' => ['name' => 'V1', 'label' => '基础版', 'max' => 200 * $MB, 'storage' => 10 * $GB, 'price' => 6,  'free' => false],
        'v2' => ['name' => 'V2', 'label' => '进阶版', 'max' => 300 * $MB, 'storage' => 30 * $GB, 'price' => 12, 'free' => false],
        'v3' => ['name' => 'V3', 'label' => '专业版', 'max' => 550 * $MB, 'storage' => 100* $GB, 'price' => 18, 'free' => false],
    ];
    $curPlan = 'custom';
    foreach ($PLANS as $k => $p) if ((int)$user['storage_limit'] === (int)$p['storage']) { $curPlan = $k; break; }
    $maxBytes = (int) getSetting('max_file_size', 104857600);
    $curPlanInfo = $curPlan === 'custom'
        ? ['name' => '自定义', 'label' => '容量', 'max' => $maxBytes, 'storage' => (int)$user['storage_limit'], 'price' => 0, 'free' => true]
        : $PLANS[$curPlan];

    // 文件统计
    $statStmt = db()->prepare('SELECT
        SUM(CASE WHEN type="file"   AND status=1 THEN 1 ELSE 0 END) AS file_count,
        SUM(CASE WHEN type="folder" AND status=1 THEN 1 ELSE 0 END) AS folder_count,
        SUM(CASE WHEN type="file"   AND status=1 THEN file_size ELSE 0 END) AS files_total,
        SUM(CASE WHEN status=1 THEN downloads ELSE 0 END) AS downloads
      FROM files WHERE user_id = ?');
    $statStmt->execute([$uid]);
    $stats = $statStmt->fetch() ?: ['file_count'=>0,'folder_count'=>0,'files_total'=>0,'downloads'=>0];
    foreach ($stats as $k => $v) $stats[$k] = (int)$v;

    // 文件类型 Top 5
    $typeStmt = db()->prepare('SELECT mime_type, COUNT(*) AS cnt, SUM(file_size) AS total
                               FROM files WHERE user_id = ? AND status = 1 AND type = "file" GROUP BY mime_type ORDER BY total DESC LIMIT 5');
    $typeStmt->execute([$uid]);
    $typeStats = $typeStmt->fetchAll();
    $typeTotal = array_sum(array_column($typeStats, 'total')) ?: 1;

    // 联系方式脱敏
    $maskContact = '—';
    if (!empty($user['contact'])) {
        $c = $user['contact'];
        if (preg_match('/^1[3-9]\d{9}$/', $c)) {
            $maskContact = mb_substr($c, 0, 3) . '****' . mb_substr($c, -4);
        } elseif (strpos($c, '@') !== false) {
            [$local, $dom] = explode('@', $c, 2);
            $local = mb_strlen($local) > 2 ? mb_substr($local, 0, 2) . '***' : $local;
            $maskContact = $local . '@' . $dom;
        } else {
            $maskContact = $c;
        }
    }

    // 头像（base64）：空时退到首字大写
    $pfAvatar    = !empty($user['avatar']) ? $user['avatar'] : '';
    $pfAvatarStr = $pfAvatar !== '' ? $pfAvatar : '';
    $pfInitial   = mb_strtoupper(mb_substr($user['username'] ?? '?', 0, 1));

    // 存储方式（driver 名 → 友好中文 + ico）
    static $_driverName = null;
    if ($_driverName === null) {
        try {
            $_storageCfg = require __DIR__ . '/config/storage.php';
            $_drv = $_storageCfg['driver'] ?? 'local';
        } catch (Throwable $e) { $_drv = 'local'; }
        $_driverName = [
            'local' => ['label' => '本机存储',  'desc' => '文件直接落到站点磁盘',           'icon' => 'fa-hdd'],
            'cos'   => ['label' => '腾讯云 COS', 'desc' => '文件落到腾讯云对象存储（COS）',   'icon' => 'fa-cloud'],
            'oss'   => ['label' => '阿里云 OSS', 'desc' => '文件落到阿里云对象存储（OSS）',   'icon' => 'fa-cloud'],
            's3'    => ['label' => 'AWS S3',    'desc' => '文件落到兼容 S3 协议的对象存储',  'icon' => 'fa-cloud'],
        ][$_drv] ?? ['label' => $_drv, 'desc' => '', 'icon' => 'fa-cloud'];
    }
    $pfDriver = $_driverName;

    // POST：头像上传 / 删除
    $pfFlash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['avatar_upload', 'avatar_remove'], true)) {
        // 仅 POST 后再 303 → GET，去除 Resubmit 弹窗
        $prgTarget = '?view=profile&pftab=profile&flash=' . urlencode('');
        if (($_POST['action'] ?? '') === 'avatar_upload') {
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $prgTarget = '?view=profile&pftab=profile&flash=err:' . urlencode('上传失败，请重试');
            } else {
                $av = $_FILES['avatar'];
                if ($av['size'] > 1024 * 1024) {
                    $prgTarget = '?view=profile&pftab=profile&flash=err:' . urlencode('头像不能超过 1MB');
                } elseif (!in_array(strtolower(pathinfo($av['name'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true)) {
                    $prgTarget = '?view=profile&pftab=profile&flash=err:' . urlencode('仅支持 JPG/PNG/GIF/WebP');
                } else {
                    $info = @getimagesize($av['tmp_name']);
                    if (!$info) {
                        $prgTarget = '?view=profile&pftab=profile&flash=err:' . urlencode('文件不是有效图片');
                    } else {
                        $bin = file_get_contents($av['tmp_name']);
                        $mime = $info['mime'] ?: 'image/png';
                        $b64 = 'data:' . $mime . ';base64,' . base64_encode($bin);
                        if (strlen($b64) > 600 * 1024) {  // 防止 DB 膨胀，限定 base64 编码后 < 600KB
                            $prgTarget = '?view=profile&pftab=profile&flash=err:' . urlencode('图片过大，编码后超过 600KB');
                        } else {
                            db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$b64, $uid]);
                            $pfAvatar = $b64;
                            $prgTarget = '?view=profile&pftab=profile&flash=ok:' . urlencode('头像已更新');
                        }
                    }
                }
            }
        } else { // avatar_remove
            db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$uid]);
            $pfAvatar = '';
            $prgTarget = '?view=profile&pftab=profile&flash=ok:' . urlencode('已恢复默认头像');
        }
        // 303 → GET 走地址栏 ?view=profile，彻底避免刷新表单
        header('Location: ' . appUrl('dashboard') . $prgTarget, true, 303);
        exit;
    }

    // PRG 后渲染：读 flash 并把它从 URL 拿掉（前端 toast 已展示）
    if (!empty($_GET['flash'])) {
        [$ft, $fm] = explode(':', rawurldecode($_GET['flash']), 2) + ['', ''];
        $pfFlash = ['type' => $ft === 'ok' ? 'success' : 'error', 'text' => $fm];
    }

    // POST：修改密码
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'changepw') {
        $cur     = $_POST['cur_pw']     ?? '';
        $new     = $_POST['new_pw']     ?? '';
        $confirm = $_POST['confirm_pw'] ?? '';
        if (strlen($new) < 6) {
            $pwFlash = ['type'=>'error', 'text'=>'新密码至少 6 位'];
        } elseif ($new !== $confirm) {
            $pwFlash = ['type'=>'error', 'text'=>'两次输入的新密码不一致'];
        } else {
            $stmt = db()->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($cur, $row['password'])) {
                $pwFlash = ['type'=>'error', 'text'=>'当前密码不正确'];
            } else {
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
                $pwFlash = ['type'=>'success', 'text'=>'密码已更新，下次登录请用新密码'];
            }
        }
    }
}

// ============ 升级套餐视图数据（嵌在 dashboard 内，?view=upgrade 触发显示；前台零刷新切换）============
$upActive = (($_GET['view'] ?? '') === 'upgrade');
if (!isset($MB)) { $MB = 1024 * 1024; $GB = 1024 * $MB; }
$payPlans = PayManager::plans();   // v1/v2/v3：name/quota/price(月价分)
$UP_PLANS = [
    'v0' => ['name' => 'V0', 'label' => '免费版', 'max' => 100 * $MB, 'storage' => 2  * $GB, 'price' => 0, 'free' => true],
    'v1' => ['name' => 'V1', 'label' => '基础版', 'max' => 200 * $MB, 'storage' => $payPlans['v1']['quota'], 'price' => (int) round($payPlans['v1']['price'] / 100), 'free' => false],
    'v2' => ['name' => 'V2', 'label' => '进阶版', 'max' => 300 * $MB, 'storage' => $payPlans['v2']['quota'], 'price' => (int) round($payPlans['v2']['price'] / 100), 'free' => false],
    'v3' => ['name' => 'V3', 'label' => '专业版', 'max' => 550 * $MB, 'storage' => $payPlans['v3']['quota'], 'price' => (int) round($payPlans['v3']['price'] / 100), 'free' => false],
];
$_upPeriods = PayManager::periods();
$UP_PERIODS = [];
foreach ($_upPeriods as $k => $pd) {
    $UP_PERIODS[] = ['key' => $k, 'name' => $pd['name'], 'months' => $pd['months'], 'discount' => $pd['discount'],
                     'badge' => $pd['discount'] < 1 ? (round($pd['discount'] * 10) . '折') : ''];
}
$UP_PAY_ENABLED = PayManager::enabled();
$UP_CHANNELS   = [
    'wechat' => PayManager::channel('wechat') !== null,
    'alipay' => PayManager::channel('alipay') !== null,
];
$upCurrentKey = null;
if ($isLogin) {
    $quota = getQuota($uid);
    foreach ($UP_PLANS as $k => $p) {
        if ((int) $quota['limit'] === (int) $p['storage']) { $upCurrentKey = $k; break; }
    }
}
$upApplied     = $_GET['applied'] ?? '';
$upAppliedName = $upApplied && isset($UP_PLANS[$upApplied]) ? $UP_PLANS[$upApplied]['name'] : '';

// 演示模式直接应用套餐（仅支付网关未启用；真实支付走 pay_create/pay_query）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply' && !$UP_PAY_ENABLED) {
    if (!$isLogin) { redirect(authUrl('login')); }
    $key  = $_POST['plan'] ?? '';
    $plan = $UP_PLANS[$key] ?? null;
    if ($plan && empty($plan['free'])) {
        db()->prepare('UPDATE users SET storage_limit = ? WHERE id = ?')
            ->execute([$plan['storage'], $uid]);
        setSetting('max_file_size', $plan['max']);
        redirect(appUrl('dashboard') . '?view=upgrade&applied=' . urlencode($key));
    }
}

function faIcon($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'png,jpg,jpeg,gif,webp,bmp,svg,ico'     => ['fa-image','#8b5cf6'],
        'mp4,mov,avi,mkv,webm,flv'              => ['fa-film','#ec4899'],
        'mp3,wav,flac,ogg,m4a'                  => ['fa-music','#6366f1'],
        'pdf'                                   => ['fa-file-pdf','#ef4444'],
        'doc,docx'                              => ['fa-file-word','#3b82f6'],
        'xls,xlsx,csv'                          => ['fa-file-excel','#22c55e'],
        'ppt,pptx'                              => ['fa-file-powerpoint','#f97316'],
        'zip,rar,7z,tar,gz'                     => ['fa-file-archive','#f59e0b'],
        'html,htm,css,js,php,json,py,java,go,ts,sql' => ['fa-file-code','#64748b'],
        'txt,md'                                => ['fa-file-lines','#94a3b8'],
    ];
    foreach ($map as $k => $v) if (in_array($ext, explode(',', $k))) return $v;
    return ['fa-file','#94a3b8'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isLogin ? '我的文件 · TangWo 云存储' : '登录 · TangWo 云存储' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/font-awesome/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f6f9fc;
            min-height: 100vh;
            display: flex;
            padding: 0;
            margin: 0;
        }

        /* ============================================================
           控制台（已登录）
           ============================================================ */
        .app-container {
            width: 100%;
            max-width: none;
            height: 100vh;
            max-height: none;
            background: #ffffff;
            border-radius: 0;
            box-shadow: none;
            display: flex;
            overflow: hidden;
        }

        .sidebar {
            width: 240px;
            min-width: 240px;
            background: #fafcff;
            border-right: 1px solid rgba(0, 0, 0, 0.04);
            padding: 28px 16px 24px 16px;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .sidebar-brand {
            padding: 0 8px 20px 8px;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #0b1a33;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-brand i { color: #3b82f6; font-size: 24px; }

        .sidebar-nav { flex: 1; display: flex; flex-direction: column; gap: 4px; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            color: #4a5a72;
            font-weight: 500;
            font-size: 15px;
            transition: all 0.15s ease;
            cursor: pointer;
            text-decoration: none;
        }
        .nav-item i { width: 20px; font-size: 18px; color: #6b7b93; transition: color 0.15s ease; }
        .nav-item:hover { background: #eef4ff; color: #1a2c4a; }
        .nav-item:hover i { color: #3b82f6; }
        .nav-item.active { background: #eef4ff; color: #1a2c4a; font-weight: 600; }
        .nav-item.active i { color: #3b82f6; }

        .sidebar-divider { margin: 16px 0 12px 0; border-top: 1px solid #e9edf4; }

        /* ============ 云存储卡片（美化）============ */
        .storage-box {
            position: relative;
            background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
            border-radius: 18px;
            padding: 18px 18px 16px;
            margin-top: 8px;
            border: 1px solid rgba(59,130,246,0.08);
            box-shadow: 0 2px 8px rgba(59,130,246,0.04);
            overflow: hidden;
        }
        .storage-box::before {
            content: '';
            position: absolute;
            top: -30px; right: -30px;
            width: 90px; height: 90px;
            background: radial-gradient(circle, rgba(59,130,246,0.12) 0%, transparent 70%);
            border-radius: 50%;
        }
        .storage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 12px;
        }
        .storage-header .ic {
            width: 32px; height: 32px;
            background: #3b82f6;
            border-radius: 10px;
            display: grid; place-items: center;
            color: #fff; font-size: 14px;
            box-shadow: 0 4px 10px rgba(59,130,246,0.3);
        }
        .storage-usage {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }
        .storage-usage b { color: #0b1a33; font-weight: 700; }
        .progress-bar {
            width: 100%; height: 8px;
            background: rgba(255,255,255,0.7);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.06);
        }
        .progress-fill {
            width: 0%; height: 100%;
            background: linear-gradient(90deg, #3b82f6 0%, #6366f1 100%);
            border-radius: 20px;
            transition: width 0.8s cubic-bezier(.4,0,.2,1);
            position: relative;
        }
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.4) 50%, transparent 100%);
            animation: shimmer 2.5s infinite;
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .storage-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .storage-hint .label { display: flex; align-items: center; gap: 6px; }

        /* ============ 侧栏底部（云存储卡）============ */
        .sidebar-bottom {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .sidebar-bottom-spacer { display: none; }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 28px 32px 32px 32px;
            background: #ffffff;
            overflow-y: auto;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .top-bar-left {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .top-bar-left .greeting {
            font-size: 16px;
            font-weight: 600;
            color: #0b1a33;
            letter-spacing: 0.2px;
        }
        .top-bar-left .date-line {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 450;
        }

        .top-actions { display: flex; align-items: center; gap: 12px; }
        .files-only.hidden-action { display: none !important; }

        .upload-trigger {
            background: #3b82f6; border: none; padding: 8px 18px;
            border-radius: 30px; font-size: 14px; font-weight: 600;
            color: #fff; display: inline-flex; align-items: center; gap: 8px;
            cursor: pointer; transition: all 0.15s ease;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
            font-family: 'Inter', sans-serif;
        }
        .upload-trigger i { font-size: 15px; }
        .upload-trigger:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3); }

        .new-folder-btn {
            background: #fff; border: 1px solid #dbeafe; padding: 8px 16px;
            border-radius: 30px; font-size: 14px; font-weight: 500;
            color: #3b82f6; display: inline-flex; align-items: center; gap: 8px;
            cursor: pointer; transition: all 0.15s ease;
            font-family: 'Inter', sans-serif;
        }
        .new-folder-btn i { font-size: 14px; }
        .new-folder-btn:hover { background: #eff6ff; border-color: #3b82f6; }

        /* ============ 文件视图：主列表 + 右侧面板 ============ */
        .files-layout { display: flex; gap: 20px; align-items: flex-start; }
        .files-layout > .files-section { flex: 1; min-width: 0; }
        .right-panel {
            width: 290px; flex-shrink: 0;
            display: flex; flex-direction: column; gap: 14px;
        }
        .right-panel .storage-box { margin-top: 0; }
        .right-panel.hidden-action { display: none !important; }

        .rp-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .rp-card-head {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 700; color: #0b1a33;
            margin-bottom: 12px;
        }
        .rp-card-head i { font-size: 14px; }

        .rp-tips { list-style: none; padding: 0; margin: 0; }
        .rp-tips li {
            position: relative; padding: 6px 0 6px 18px;
            font-size: 12.5px; color: #64748b; line-height: 1.55;
        }
        .rp-tips li::before {
            content: '•'; position: absolute; left: 4px; top: 6px;
            color: #3b82f6; font-weight: 700;
        }
        .rp-tips a { color: #3b82f6; text-decoration: none; }
        .rp-tips a:hover { text-decoration: underline; }
        .rp-tip b { color: #0b1a33; font-weight: 600; }

        .files-section { }
        .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; margin-bottom: 16px; flex-wrap: wrap; }
        .breadcrumb a { color: #3b82f6; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .bc-sep { color: #cbd5e1; }
        .bc-current { color: #0b1a33; font-weight: 500; }
        .files-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
        .file-card {
            background: #fff; border: 1px solid #eef2f7; border-radius: 16px;
            padding: 14px 16px; display: flex; align-items: center; gap: 14px; transition: all 0.15s ease;
        }
        .file-card:hover { border-color: #cfe0ff; box-shadow: 0 6px 18px rgba(59, 130, 246, 0.10); }
        .file-main { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; text-decoration: none; color: inherit; }
        .folder-card .file-icon { background: #f59e0b !important; }
        .file-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0; color: #fff;
        }
        .file-meta { flex: 1; min-width: 0; }
        .file-name { font-size: 14px; font-weight: 600; color: #0b1a33; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-sub { font-size: 12px; color: #94a3b8; margin-top: 3px; }
        .file-actions { display: flex; gap: 6px; flex-shrink: 0; }
        .file-actions a, .file-actions button {
            font-size: 12px; padding: 7px 12px; border-radius: 9px;
            border: 1px solid #eef2f7; color: #475569; text-decoration: none;
            cursor: pointer; transition: 0.15s ease; background: #fff;
        }
        .file-actions a:hover, .file-actions button:hover { border-color: #3b82f6; color: #3b82f6; background: #f5f9ff; }
        .file-actions .del:hover { border-color: #dc2626; color: #dc2626; background: #fef2f2; }
        .files-empty { text-align: center; color: #94a3b8; padding: 28px; font-size: 14px; }

        /* 视图切换面板（现在只有「我的文件」一个面板） */
        .view-panel { display: none; }
        .view-panel.active { display: block; }
        .share-badges { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
        .badge { font-size: 11px; padding: 2px 8px; border-radius: 8px; font-weight: 500; display: inline-flex; align-items: center; gap: 4px; }
        .badge-lock { background: #fef2f2; color: #dc2626; }
        .badge-exp  { background: #fff7ed; color: #ea580c; }
        .badge-exp-off { background: #f1f5f9; color: #94a3b8; }
        .file-actions .restore:hover { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

        #toast-wrap { position: fixed; top: 20px; right: 20px; z-index: 90; display: flex; flex-direction: column; gap: 10px; }
        .toast { min-width: 240px; max-width: 340px; background: #0b1a33; color: #fff; padding: 12px 14px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.18); display: flex; gap: 10px; align-items: flex-start; font-size: 14px; animation: slideIn .3s cubic-bezier(.16,1,.3,1) both; }
        .toast.out { animation: slideOut .3s ease forwards; }
        @keyframes slideIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:none} }
        @keyframes slideOut { to{opacity:0;transform:translateX(20px)} }
        .modal-mask { position: fixed; inset: 0; background: rgba(15,23,42,.45); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal { background: #fff; border-radius: 16px; padding: 24px; width: 340px; max-width: 90vw; box-shadow: 0 24px 60px rgba(0,0,0,.22); animation: popIn .2s cubic-bezier(.16,1,.3,1) both; }
        .modal h3 { margin: 0 0 8px; font-size: 16px; color: #0b1a33; }
        .modal p { margin: 0 0 20px; font-size: 14px; color: #94a3b8; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .btn-ghost { background: #fff; border: 1px solid #e2e8f0; color: #334155; padding: 9px 16px; border-radius: 10px; font-size: 14px; cursor: pointer; transition: .15s; }
        .btn-ghost:hover { border-color: #3b82f6; color: #3b82f6; }
        .btn-danger { background: #fff; border: 1px solid #e2e8f0; color: #e11d48; padding: 9px 16px; border-radius: 10px; font-size: 14px; cursor: pointer; transition: .15s; }
        .btn-danger:hover { background: #fef2f2; }
        .btn-primary { background: #3b82f6; border: 1px solid #3b82f6; color: #fff; padding: 9px 16px; border-radius: 10px; font-size: 14px; cursor: pointer; transition: .15s; }
        .btn-primary:hover { background: #2563eb; }
        .modal-input { width: 100%; box-sizing: border-box; padding: 11px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; color: #0b1a33; outline: none; margin: 8px 0 20px; transition: .15s; }
        .modal-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        @keyframes popIn { from{opacity:0;transform:scale(.96) translateY(8px)} to{opacity:1;transform:none} }

        /* ============================================================
           登录 / 注册（访客态）
           ============================================================ */
        .auth-wrap {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background:
                radial-gradient(ellipse at 15% 20%, rgba(59,130,246,0.06) 0%, transparent 45%),
                radial-gradient(ellipse at 85% 80%, rgba(59,130,246,0.05) 0%, transparent 45%),
                #f6f9fc;
        }
        .auth-card {
            width: 100%;
            max-width: 440px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(15,23,42,0.10), 0 8px 24px rgba(15,23,42,0.05);
            padding: 40px 40px 32px;
        }
        .auth-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }
        .auth-brand .logo-icon {
            width: 42px; height: 42px;
            background: #3b82f6;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 18px; color: #fff;
            box-shadow: 0 8px 24px rgba(59,130,246,0.30);
        }
        .auth-brand .logo-text { font-size: 22px; font-weight: 700; color: #0b1a33; letter-spacing: -0.5px; }
        .auth-brand .logo-text span { color: #3b82f6; }

        .auth-tabs {
            display: flex;
            background: #f1f5f9;
            border-radius: 30px;
            padding: 4px;
            margin-bottom: 28px;
        }
        .auth-tabs button {
            flex: 1;
            border: none;
            background: transparent;
            padding: 10px;
            border-radius: 26px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.25s cubic-bezier(0.22,1,0.36,1);
        }
        .auth-tabs button.active { background: #fff; color: #0b1a33; box-shadow: 0 2px 12px rgba(15,23,42,0.06); }

        .au-form { display: flex; flex-direction: column; gap: 18px; }
        .au-group { display: flex; flex-direction: column; gap: 7px; }
        .au-group label { font-size: 13px; font-weight: 600; color: #475569; }
        /* 隐藏 Edge/IE 自带的"显示密码"眼睛按钮，避免与自定义 .au-toggle 重叠 */
        .au-input-wrap input[type="password"]::-ms-reveal,
        .au-input-wrap input[type="password"]::-ms-clear {
            display: none;
        }
        .au-input-wrap { position: relative; display: flex; align-items: center; }
        .au-input-wrap .au-icon {
            position: absolute; left: 14px; font-size: 15px; color: #94a3b8; pointer-events: none;
        }
        .au-input-wrap input {
            width: 100%;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 13px 44px 13px 42px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            color: #0b1a33;
            background: #fff;
            outline: none;
            transition: all 0.2s ease;
        }
        .au-input-wrap input::placeholder { color: #aab4c4; }
        .au-input-wrap input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.12); }
        .au-input-wrap .au-toggle {
            position: absolute; right: 12px;
            background: none; border: none; cursor: pointer;
            font-size: 15px; color: #94a3b8; padding: 4px;
        }
        .au-input-wrap .au-toggle:hover { color: #3b82f6; }
        .au-input-wrap.au-captcha input { padding-right: 122px; }
        .captcha-img {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            height: 34px; width: 100px;
            border-radius: 8px; cursor: pointer;
            border: 1px solid #e2e8f0; background: #f1f5f9;
        }

        /* 第三方验证码（腾讯云验证码按钮 / 极验容器） */
        .tc-captcha-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 13px 16px;
            border: 1.5px solid #e2e8f0; border-radius: 12px;
            background: #fff; color: #475569;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: all 0.2s ease; font-family: inherit;
        }
        .tc-captcha-btn:hover { border-color: #93c5fd; color: #2563eb; background: #eff6ff; }
        .tc-captcha-btn.ok { border-color: #86efac; color: #16a34a; background: #f0fdf4; }
        .tc-status { display: block; font-size: 12px; margin-top: 6px; color: #16a34a; }
        .gt-captcha-box { min-height: 44px; }

        /* 注册：手机号/邮箱 + 验证码 */
        .au-input-wrap.au-code input { padding-right: 116px; }
        .au-code-btn {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            border: none; background: transparent; cursor: pointer;
            color: #3b82f6; font-size: 13px; font-weight: 600;
            padding: 6px 10px; border-radius: 9px; font-family: 'Inter', sans-serif;
            transition: all 0.15s ease; white-space: nowrap;
        }
        .au-code-btn:hover:not(:disabled) { background: #eff6ff; }
        .au-code-btn:disabled { color: #94a3b8; cursor: not-allowed; }
        .au-hint { font-size: 12px; min-height: 16px; color: #64748b; margin-top: 2px; }
        .au-hint.ok { color: #16a34a; }

        /* 轻量 toast */
        .toast {
            position: fixed; left: 50%; top: 24px; transform: translateX(-50%);
            background: #0f172a; color: #fff; padding: 11px 18px; border-radius: 12px;
            font-size: 13.5px; font-weight: 500; z-index: 9999; box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            opacity: 0; transition: opacity 0.25s ease, transform 0.25s ease; pointer-events: none;
            max-width: 90vw;
        }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast.ok { background: #15803d; }
        .toast.err { background: #b91c1c; }

        .au-submit {
            margin-top: 6px;
            background: #3b82f6;
            border: none;
            padding: 14px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            box-shadow: 0 6px 16px rgba(59,130,246,0.28);
        }
        .au-submit:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 10px 24px rgba(59,130,246,0.32); }
        .au-submit:active { transform: translateY(0); }

        .au-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 11px 14px;
            border-radius: 12px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .au-footer {
            margin-top: 22px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }
        .au-footer strong { color: #64748b; }
        .hidden { display: none !important; }

        /* ============================================================
           响应式 — 平板 (≤860px)
           ============================================================ */
        @media (max-width: 860px) {
            .app-container { flex-direction: column; height: auto; min-height: 100vh; }
            /* 侧栏 → 顶部横条 */
            .sidebar {
                width: 100%; min-width: unset; height: auto;
                border-right: none; border-bottom: 1px solid #e9edf4;
                padding: 14px 20px;
                flex-direction: column; gap: 0;
            }
            .sidebar-brand { padding: 0 0 10px; font-size: 20px; }
            .sidebar-brand i { font-size: 20px; }
            .sidebar-nav { flex-direction: row; flex-wrap: wrap; gap: 4px; margin: 0; }
            .nav-item { padding: 7px 12px; font-size: 13px; gap: 8px; }
            .nav-item i { font-size: 15px; }
            .sidebar-divider { display: none; }
            .sidebar-bottom { display: none; }
            /* 主区域 */
            .main-content { padding: 20px 16px 24px; }
            .top-bar { flex-direction: column; align-items: stretch; gap: 12px; }
            .top-bar-left { width: 100%; }
            /* 操作按钮行换行 */
            .top-actions { flex-wrap: wrap; gap: 8px; justify-content: flex-start; }
            .upload-trigger { padding: 7px 14px; font-size: 13px; }
            /* 文件网格 */
            .files-grid { grid-template-columns: 1fr; gap: 10px; }
            /* 文件视图布局：右侧面板沉到底部 */
            .files-layout { flex-direction: column; }
            .right-panel { width: 100%; }
        }

        /* ============================================================
           响应式 — 手机 (≤480px)
           ============================================================ */
        @media (max-width: 480px) {
            /* 控制台 */
            .sidebar { padding: 12px 14px; }
            .sidebar-brand { font-size: 18px; }
            .nav-item { padding: 6px 10px; font-size: 12px; }
            .main-content { padding: 16px 12px 20px; }
            .top-actions { gap: 6px; }
            .new-folder-btn { flex: 1; justify-content: center; font-size: 12px; padding: 7px 10px; }
            .upload-trigger { flex: 1; justify-content: center; }
            .new-folder-btn span, .upload-trigger span { display: none; } /* 只留图标 */
            .file-card { padding: 12px; gap: 10px; flex-wrap: wrap; }
            .file-icon { width: 38px; height: 38px; font-size: 16px; }
            .file-name { font-size: 13px; }
            .file-sub { font-size: 11px; }
            .file-actions { flex-wrap: wrap; gap: 4px; }
            .file-actions a, .file-actions button { font-size: 11px; padding: 6px 10px; }
            .files-empty { padding: 20px; font-size: 13px; }
            .storage-hint { font-size: 11px; }

            /* 登录/注册卡片 */
            .auth-wrap { padding: 20px 14px; }
            .auth-card { padding: 28px 20px 24px; border-radius: 18px; max-width: 100%; }
            .auth-brand { margin-bottom: 20px; gap: 10px; }
            .auth-brand .logo-icon { width: 38px; height: 38px; font-size: 16px; }
            .auth-brand .logo-text { font-size: 20px; }
            .auth-tabs { margin-bottom: 20px; padding: 3px; }
            .auth-tabs button { padding: 9px; font-size: 13px; }
            .au-form { gap: 14px; }
            .au-group label { font-size: 12px; }
            .au-input-wrap input { font-size: 14px; padding: 12px 40px 12px 38px; }
            .au-input-wrap .au-icon { left: 12px; font-size: 14px; }
            .au-submit { padding: 13px; font-size: 14px; border-radius: 12px; }
            /* 验证码图片缩小 */
            .au-input-wrap.au-captcha input { padding-right: 92px; font-size: 13px; }
            .captcha-img { width: 80px; height: 32px; }
            /* 发送码按钮文字简化 */
            .au-input-wrap.au-code input { padding-right: 96px; font-size: 13px; }
            .au-code-btn { font-size: 12px; padding: 5px 8px; }
            .au-footer { font-size: 11px; margin-top: 18px; }
        }

        /* ============================================================
           响应式 — 超小屏 (≤360px)
           ============================================================ */
        @media (max-width: 360px) {
            .sidebar-brand { font-size: 16px; }
            .nav-item { padding: 5px 8px; font-size: 11px; }
            .auth-card { padding: 24px 16px 20px; }
            .file-card { padding: 10px; }
            .file-icon { width: 34px; height: 34px; font-size: 14px; }
            /* 超小屏验证码图片进一步缩 */
            .captcha-img { width: 70px; height: 30px; }
            .au-input-wrap.au-captcha input { padding-right: 80px; }
            .au-input-wrap.au-code input { padding-right: 84px; }
            .au-code-btn { font-size: 11px; padding: 4px 6px; }
        }

    /* ============ 个人中心视图（从 dashboardupgradeprofile 平移）============ */
    .profile-view { --pf-brand: #3b82f6; --pf-brand-dark: #1d4ed8; --pf-brand-soft: #eef4ff;
                    --pf-ink: #0b1a33; --pf-muted: #6b7b93; --pf-line: #e5ecf5;
                    --pf-bg: #f5f7fb; --pf-green: #10b981; --pf-red: #ef4444; }
    .profile-view .pf-card { background:#fff; border:1px solid var(--pf-line); border-radius:14px; overflow:hidden; }
    .profile-view .pf-card + .pf-card { margin-top: 18px; }
    .profile-view .pf-card-title { font-size:15px; font-weight:700; padding:16px 22px 4px; display:flex; align-items:center; gap:8px; color:var(--pf-ink); }
    .profile-view .pf-card-title i { color:var(--pf-brand); }
    .profile-view .pf-card-sub { font-size:12px; color:var(--pf-muted); padding:0 22px 14px; border-bottom:1px dashed var(--pf-line); }

    .profile-view .pf-hero { background:linear-gradient(135deg,#fff,#f3f6fc); border:1px solid var(--pf-line);
        border-radius:18px; padding:22px 26px; display:flex; align-items:center; gap:22px;
        margin-bottom:18px; position:relative; overflow:hidden; }
    .profile-view .pf-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:180px; height:180px;
        background:radial-gradient(circle,rgba(59,130,246,.12),transparent 70%); }
    .profile-view .pf-hero-avatar { width:72px; height:72px; border-radius:50%;
        background:linear-gradient(135deg,var(--pf-brand),#60a5fa); color:#fff;
        display:grid; place-items:center; font-size:30px; font-weight:700;
        box-shadow:0 8px 24px rgba(59,130,246,.25); }
    .profile-view .pf-hero-info { flex:1; }
    .profile-view .pf-hero-name { font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .profile-view .pf-hero-role { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px;
        background:var(--pf-brand-soft); color:var(--pf-brand); font-weight:600; }
    .profile-view .pf-hero-role.is-admin { background:#fef3c7; color:#b45309; }
    .profile-view .pf-hero-sub { font-size:13px; color:var(--pf-muted); margin-top:4px; }
    .profile-view .pf-hero-upgrade { display:inline-flex; align-items:center; gap:6px; padding:9px 16px;
        background:var(--pf-brand); color:#fff; border-radius:10px; font-size:14px; font-weight:600;
        box-shadow:0 6px 16px rgba(59,130,246,.25); transition:.15s;
        position:relative; z-index:2; /* 浮在 ::before 装饰光斑之上 */ }
    .profile-view .pf-hero-upgrade:hover { background:var(--pf-brand-dark); transform:translateY(-1px); }
    .profile-view .pf-hero-upgrade.is-ghost { background:#fff; color:var(--pf-brand); border:1.5px solid var(--pf-brand); box-shadow:none; }

    .profile-view .pf-tabs { display:flex; gap:4px; background:#fff; border:1px solid var(--pf-line);
        border-radius:12px; padding:4px; margin-bottom:18px; overflow-x:auto; }
    .profile-view .pf-tab { flex:1; min-width:100px; padding:10px 14px; text-align:center; font-size:14px;
        color:#475569; border-radius:9px; cursor:pointer; transition:.15s;
        display:inline-flex; align-items:center; justify-content:center; gap:6px; white-space:nowrap;
        background:transparent; border:none; }
    .profile-view .pf-tab:hover { background:var(--pf-brand-soft); color:var(--pf-brand); }
    .profile-view .pf-tab.active { background:var(--pf-brand); color:#fff; font-weight:600;
        box-shadow:0 4px 12px rgba(59,130,246,.25); }
    .profile-view .pf-tab i { font-size:13px; }

    .profile-view .pf-row-list { padding:4px 0; }
    .profile-view .pf-row { display:flex; align-items:center; justify-content:space-between; padding:14px 22px;
        border-bottom:1px solid #f1f5f9; gap:16px; }
    .profile-view .pf-row:last-child { border-bottom:none; }
    .profile-view .pf-row .label { font-size:13px; color:var(--pf-muted); flex-shrink:0; min-width:100px; }
    .profile-view .pf-row .value { font-size:14px; color:var(--pf-ink); font-weight:500; text-align:right; word-break:break-all; }
    .profile-view .pf-row .value.mono { font-family:ui-monospace,"SF Mono",Menlo,monospace; font-size:13px; }
    .profile-view .pf-row .action { color:var(--pf-brand); font-size:13px; cursor:pointer; text-decoration:none; }
    .profile-view .pf-row .action:hover { text-decoration:underline; }

    .profile-view .pf-pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; }
    .profile-view .pf-pill.v0 { background:#f1f5f9; color:#64748b; }
    .profile-view .pf-pill.v1 { background:#dbeafe; color:#1d4ed8; }
    .profile-view .pf-pill.v2 { background:#ede9fe; color:#7c3aed; }
    .profile-view .pf-pill.v3 { background:#fef3c7; color:#b45309; }
    .profile-view .pf-pill.custom { background:#fce7f3; color:#be185d; }

    .profile-view .pf-cloud { position:relative; overflow:hidden; }
    .profile-view .pf-cloud::after { content:''; position:absolute; right:-50px; bottom:-50px; width:200px; height:200px;
        background:radial-gradient(circle,rgba(59,130,246,.10),transparent 70%); pointer-events:none; }
    .profile-view .pf-cloud-head { display:flex; align-items:center; gap:14px; margin-bottom:18px; position:relative; z-index:1; }
    .profile-view .pf-cloud-icon { width:48px; height:48px; border-radius:12px; flex-shrink:0;
        background:linear-gradient(135deg,var(--pf-brand),#60a5fa); color:#fff;
        display:grid; place-items:center; font-size:20px;
        box-shadow:0 6px 16px rgba(59,130,246,.3); }
    .profile-view .pf-cloud-titles { flex:1; min-width:0; }
    .profile-view .pf-cloud-name { font-size:16px; font-weight:700; color:var(--pf-ink); }
    .profile-view .pf-cloud-sub { font-size:12px; color:var(--pf-muted); margin-top:3px; }
    .profile-view .pf-cloud-cta { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:9px;
        background:var(--pf-brand); color:#fff; font-size:13px; font-weight:600; transition:.15s; text-decoration:none; }
    .profile-view .pf-cloud-cta:hover { background:var(--pf-brand-dark); transform:translateY(-1px); }
    .profile-view .pf-cloud-cta.is-ghost { background:#fff; color:var(--pf-brand); border:1.5px solid var(--pf-brand); }
    .profile-view .pf-cloud-cta.is-ghost:hover { background:var(--pf-brand-soft); }
    .profile-view .pf-cloud-big { font-size:30px; font-weight:700; color:var(--pf-ink); line-height:1.2; position:relative; z-index:1; }
    .profile-view .pf-cloud-big .of { font-size:16px; color:var(--pf-muted); font-weight:500; margin-left:4px; }
    .profile-view .pf-cloud-big .pct { font-size:13px; color:var(--pf-muted); font-weight:500; margin-left:6px; }
    .profile-view .pf-cloud-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:12px;
        margin-top:18px; padding-top:16px; border-top:1px dashed var(--pf-line); position:relative; z-index:1; }
    .profile-view .pf-cloud-stats .stat { text-align:center; }
    .profile-view .pf-cloud-stats .stat .n { font-size:18px; font-weight:700; color:var(--pf-ink); }
    .profile-view .pf-cloud-stats .stat .l { font-size:12px; color:var(--pf-muted); margin-top:2px; }

    .profile-view .pf-single .single-row { display:flex; align-items:center; gap:18px; margin-top:6px; }
    .profile-view .pf-single .single-num { font-size:28px; font-weight:700; color:var(--pf-brand); line-height:1;
        padding:10px 16px; background:var(--pf-brand-soft); border-radius:10px; }
    .profile-view .pf-single .single-meta { font-size:13px; color:var(--pf-muted); line-height:1.6; }
    .profile-view .pf-single .single-meta code { background:#f1f5f9; padding:1px 6px; border-radius:4px; font-size:12px; color:#475569; }

    .profile-view .pf-plan-card { margin:16px 22px; padding:18px 20px; border-radius:14px; border:1.5px solid var(--pf-line);
        display:flex; align-items:center; justify-content:space-between; gap:16px;
        background:linear-gradient(135deg,#f8fbff,#eef4ff); }
    .profile-view .pf-plan-card.is-free { background:linear-gradient(135deg,#fafafa,#f1f5f9); border-color:#e2e8f0; }
    .profile-view .pf-plan-card .pc-name { font-size:18px; font-weight:700; }
    .profile-view .pf-plan-card .pc-sub { font-size:12px; color:var(--pf-muted); margin-top:4px; }
    .profile-view .pf-plan-card .pc-price { font-size:28px; font-weight:700; color:var(--pf-brand); }
    .profile-view .pf-plan-card .pc-price small { font-size:14px; color:var(--pf-muted); margin-left:2px; }
    .profile-view .pf-plan-card .pc-tag { display:inline-block; margin-top:6px; padding:2px 10px; border-radius:999px;
        font-size:11px; font-weight:600; background:#d1fae5; color:#047857; }

    .profile-view .pf-perm { padding:4px 0; }
    .profile-view .pf-perm .perm-item { display:flex; align-items:center; justify-content:space-between;
        padding:14px 22px; border-bottom:1px solid #f1f5f9; }
    .profile-view .pf-perm .perm-item:last-child { border-bottom:none; }
    .profile-view .pf-perm .l { display:flex; align-items:center; gap:12px; }
    .profile-view .pf-perm .ic { width:36px; height:36px; border-radius:10px; background:var(--pf-brand-soft);
        color:var(--pf-brand); display:grid; place-items:center; }
    .profile-view .pf-perm .ttl { font-size:14px; font-weight:600; }
    .profile-view .pf-perm .sub { font-size:12px; color:var(--pf-muted); margin-top:2px; }
    .profile-view .pf-perm .check { color:var(--pf-green); font-size:20px; }
    .profile-view .pf-perm .off { color:#cbd5e1; font-size:20px; }
    .profile-view .pf-perm .val { font-size:13px; color:var(--pf-muted); }

    .profile-view .pf-type-list { padding:4px 0; }
    .profile-view .pf-type-list .type-row { display:flex; align-items:center; gap:12px; padding:12px 22px; }
    .profile-view .pf-type-list .tname { font-size:13px; width:100px; color:#475569; }
    .profile-view .pf-type-list .tbar { flex:1; height:8px; background:#f1f5f9; border-radius:999px; overflow:hidden; }
    .profile-view .pf-type-list .tfill { height:100%; background:linear-gradient(90deg,var(--pf-brand),#60a5fa); border-radius:999px; }
    .profile-view .pf-type-list .tnum { font-size:12px; color:var(--pf-muted); width:130px; text-align:right; }

    .profile-view .pf-pwform { padding:0 22px 18px; }
    .profile-view .pf-pwform .field { margin:12px 0; }
    .profile-view .pf-pwform label { display:block; font-size:12px; color:var(--pf-muted); margin-bottom:6px; }
    .profile-view .pf-pwform input { width:100%; padding:10px 12px; border:1px solid var(--pf-line); border-radius:9px;
        font-size:14px; outline:none; font-family:inherit; box-sizing:border-box; }
    .profile-view .pf-pwform input:focus { border-color:var(--pf-brand); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
    .profile-view .pf-pwform .row2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .profile-view .pf-pwform .actions { display:flex; gap:10px; margin-top:14px; }
    .profile-view .pf-pwform button { padding:9px 18px; border:none; border-radius:9px; cursor:pointer; font-size:14px; font-weight:600; background:var(--pf-brand); color:#fff; }
    .profile-view .pf-pwform button:hover { background:var(--pf-brand-dark); }

    .profile-view .pf-danger-row { color:var(--pf-red); }
    .profile-view .pf-danger-row .label { color:var(--pf-red); }
    .profile-view .pf-hint-text { font-size:12px; color:var(--pf-muted); padding:8px 22px 16px; }

    /* ============ Profile 通用 btn 三态（头像/安全中心/套餐 表内联用）============ */
    .profile-view .btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:9px; font-size:13px; font-weight:600; border:1px solid transparent; cursor:pointer; transition:all .15s ease; text-decoration:none; }
    .profile-view .btn i { font-size:12px; }
    .profile-view .btn-primary { background:var(--pf-brand); color:#fff; }
    .profile-view .btn-primary:hover { background:var(--pf-brand-dark); transform:translateY(-1px); }
    .profile-view .btn-ghost { background:#f1f5f9; color:#334155; border-color:#e2e8f0; }
    .profile-view .btn-ghost:hover { background:#e2e8f0; }
    .profile-view .btn-warn { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
    .profile-view .btn-warn:hover { background:#ffedd5; }
    .profile-view .btn-sm { padding:5px 11px; font-size:12px; }

    /* ============ Profile 头像卡 ============ */
    .profile-view .pf-avatar-card .pf-avatar-row { display:flex; align-items:center; gap:20px; padding:6px 0 4px; flex-wrap:wrap; }
    .profile-view .pf-avatar-box { position:relative; width:88px; height:88px; border-radius:18px; overflow:hidden; background:linear-gradient(135deg,var(--pf-brand),#60a5fa); box-shadow:0 4px 12px rgba(59,130,246,.25); flex-shrink:0; }
    .profile-view .pf-avatar-box .pf-avatar-img, .profile-view .pf-avatar-box .pf-avatar-initial { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
    .profile-view .pf-avatar-box .pf-avatar-initial { display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:34px; line-height:1; }
    .profile-view .pf-avatar-ops { display:flex; flex-direction:column; gap:8px; }
    .profile-view .pf-avatar-ops form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0; }

    /* ============ Profile 套餐单文件上限表 ============ */
    .profile-view .pf-table { width:100%; border-collapse:collapse; margin-top:10px; font-size:13px; }
    .profile-view .pf-table thead th { text-align:left; padding:9px 12px; background:var(--pf-brand-soft); color:var(--pf-brand); font-weight:600; font-size:12px; }
    .profile-view .pf-table thead th.r { text-align:right; }
    .profile-view .pf-table tbody td { padding:10px 12px; border-top:1px solid #f1f5f9; }
    .profile-view .pf-table tbody tr.is-current { background:#fffbeb; }
    .profile-view .pf-table .cur-tag { margin-left:6px; padding:1px 6px; background:#fde68a; color:#92400e; border-radius:999px; font-size:11px; font-weight:600; }
    .profile-view .pf-table .r { text-align:right; }
    .profile-view .pf-row .hint { color:var(--pf-muted); font-size:12px; margin-left:6px; }

    /* Profile 内的 confirm 弹层 */
    .profile-view .pf-mask { position:fixed; inset:0; background:rgba(15,23,42,.55); backdrop-filter:blur(3px); display:none; align-items:center; justify-content:center; z-index:99; padding:16px; }
    .profile-view .pf-mask.show { display:flex; }
    .profile-view .pf-modal { background:#fff; border-radius:16px; padding:24px 26px 20px; width:360px; max-width:100%; text-align:center; box-shadow:0 24px 60px rgba(15,23,42,.25); animation:pf-pop .18s ease; }
    @keyframes pf-pop { from { transform:scale(.94); opacity:0; } to { transform:scale(1); opacity:1; } }
    .profile-view .pf-modal h3 { font-size:16px; font-weight:700; color:#0f172a; margin-bottom:12px; }
    .profile-view .pf-modal h3 i { color:#ef4444; margin-right:6px; }
    .profile-view .pf-modal p { margin:0 0 18px; font-size:14px; line-height:1.65; color:#1e293b; }
    .profile-view .pf-modal .pf-actions { display:flex; gap:8px; }
    .profile-view .pf-modal .pf-actions button { flex:1; padding:9px 0; border-radius:9px; font-size:13.5px; font-weight:600; cursor:pointer; border:none; }
    .profile-view .pf-modal .pf-cancel { background:#f1f5f9; color:#475569; }
    .profile-view .pf-modal .pf-cancel:hover { background:#e2e8f0; }
    .profile-view .pf-modal .pf-ok { background:#ef4444; color:#fff; }
    .profile-view .pf-modal .pf-ok:hover { background:#dc2626; }

    @media (max-width:900px) {
        .profile-view .pf-cloud-stats { grid-template-columns:1fr; }
        .profile-view .pf-pwform .row2 { grid-template-columns:1fr; }
    }
    </style>
</head>
<body>

<?php if ($isLogin): ?>
<!-- ===================== 控制台（已登录） ===================== -->
<div class="app-container">

    <aside class="sidebar">
        <div class="sidebar-brand">
            <i class="fas fa-cloud"></i> TangWo
        </div>

        <nav class="sidebar-nav">
            <a class="nav-item active" data-label="我的文件" data-view="files" href="<?= appUrl('dashboard') ?>?view=files"><i class="fas fa-folder"></i> 我的文件</a>
            <a class="nav-item" data-label="我的分享" data-view="shares" href="<?= appUrl('dashboard') ?>?view=shares"><i class="fas fa-share-alt"></i> 我的分享</a>
            <a class="nav-item" data-label="回收站" data-view="trash" href="<?= appUrl('dashboard') ?>?view=trash"><i class="fas fa-trash-alt"></i> 回收站</a>
            <div class="sidebar-divider"></div>
            <?php if ($isAdmin): ?>
            <a class="nav-item" href="<?= appUrl('dashboardadmin') ?>" data-label="管理后台"><i class="fas fa-sliders-h"></i> 管理后台</a>
            <a class="nav-item" data-label="个人中心" data-view="profile" href="<?= appUrl('dashboard') ?>?view=profile"><i class="fas fa-user"></i> 个人中心</a>
            <?php else: ?>
            <a class="nav-item" data-label="个人中心" data-view="profile" href="<?= appUrl('dashboard') ?>?view=profile"><i class="fas fa-user"></i> 个人中心</a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="storage-box">
                <div class="storage-header">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div class="ic"><i class="fas fa-cloud"></i></div>
                        <div>
                            <div style="font-size:13px;font-weight:700;color:#0b1a33">云存储</div>
                            <div class="storage-usage">已用 <b><?= $usedStr ?></b></div>
                        </div>
                    </div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="storage-hint">
                    <span class="label"><i class="fas fa-database"></i> 共 <?= $totalFiles ?> 个文件</span>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">

        <div class="top-bar">
            <div class="top-actions">
                <button class="upload-trigger files-only" id="uploadBtn" type="button">
                    <i class="fas fa-cloud-upload-alt"></i> <span>上传文件</span>
                </button>
                <button class="new-folder-btn files-only" id="newFolderBtn" type="button">
                    <i class="fas fa-folder-plus"></i> <span>新建文件夹</span>
                </button>
            </div>
        </div>

        <div class="files-layout">
        <section class="files-section view-panel active" data-view="files">
            <?php if ($folderId || !empty($breadcrumbs)): ?>
            <nav class="breadcrumb">
                <a href="<?= appUrl('dashboard') ?>"><i class="fas fa-home"></i> 根目录</a>
                <?php foreach ($breadcrumbs as $bc): ?>
                <span class="bc-sep">/</span>
                <?php if ($bc['id'] == $folderId): ?>
                <span class="bc-current"><?= h($bc['file_name']) ?></span>
                <?php else: ?>
                <a href="<?= appUrl('dashboard') ?>?folder=<?= $bc['id'] ?>"><?= h($bc['file_name']) ?></a>
                <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <?php if (empty($files)): ?>
                <div class="files-empty"><?= $folderId ? '这个文件夹还是空的，点击「上传文件」添加内容吧 ✨' : '还没有文件，点击上方「上传文件」上传第一个文件吧 ✨' ?></div>
            <?php else: ?>
                <div class="files-grid" id="filesGrid">
                    <?php foreach ($files as $f):
                        $isFolder = ($f['type'] === 'folder');
                        [$icon, $color] = $isFolder ? ['fa-folder', '#f59e0b'] : faIcon($f['file_name']);
                        $link = $isFolder ? (appUrl('dashboard') . '?folder=' . $f['id']) : shareUrl('s/' . $f['short_code']);
                    ?>
                    <div class="file-card <?= $isFolder ? 'folder-card' : '' ?>"
                         data-name="<?= h($f['file_name']) ?>"
                         data-size="<?= (int)$f['file_size'] ?>"
                         data-time="<?= strtotime($f['created_at']) ?>">
                        <a href="<?= h($link) ?>" class="file-main" <?= $isFolder ? '' : 'target="_blank"' ?>>
                            <div class="file-icon" style="background:<?= $color ?>">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <div class="file-meta">
                                <div class="file-name" title="<?= h($f['file_name']) ?>"><?= h($f['file_name']) ?></div>
                                <div class="file-sub"><?= $isFolder ? '文件夹' : formatSize($f['file_size']) ?> · <?= h(date('Y-m-d H:i', strtotime($f['created_at']))) ?></div>
                            </div>
                        </a>
                        <div class="file-actions">
                            <?php if ($isFolder): ?>
                            <a href="<?= h($link) ?>">打开</a>
                            <?php else: ?>
                            <a href="<?= h($link) ?>" target="_blank">下载</a>
                            <button type="button" class="copy-link" data-link="<?= h($link) ?>">复制</button>
                            <?php endif; ?>
                            <a href="<?= ioUrl('delete?id=' . $f['id'] . ($folderId ? '&folder=' . $folderId : '')) ?>" data-del="<?= $f['id'] ?>" class="del">删除</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="right-panel files-only">
            <div class="rp-card rp-tip">
                <div class="rp-card-head"><i class="fas fa-lightbulb" style="color:#3b82f6"></i> 小贴士</div>
                <ul class="rp-tips">
                    <li>支持 <b>秒传</b>：MD5 一致的大文件无需重新上传</li>
                    <li>分享链接可设 <b>提取码</b> 与 <b>过期时间</b></li>
                    <li>删除的文件先进入 <b>回收站</b>，30 天内可恢复</li>
                    <li>遇到问题？<a href="<?= appUrl('help') ?>">查看帮助</a></li>
                </ul>
            </div>
        </aside>
        </div> <!-- /.files-layout -->

        <section class="files-section view-panel" data-view="shares">
            <?php if (empty($shareFiles)): ?>
                <div class="files-empty">还没有分享的文件，去「我的文件」上传后即可生成分享链接 ✨</div>
            <?php else: ?>
                <div class="files-grid" id="sharesGrid">
                    <?php foreach ($shareFiles as $f):
                        [$icon, $color] = faIcon($f['file_name']);
                        $link = shareUrl('s/' . $f['short_code']);
                        $locked = !empty($f['share_password']);
                        $expired = !empty($f['expires_at']) && (int)$f['expires_at'] < time();
                    ?>
                    <div class="file-card"
                         data-name="<?= h($f['file_name']) ?>"
                         data-size="<?= (int)$f['file_size'] ?>"
                         data-time="<?= strtotime($f['created_at']) ?>">
                        <div class="file-icon" style="background:<?= $color ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div class="file-meta">
                            <div class="file-name" title="<?= h($f['file_name']) ?>"><?= h($f['file_name']) ?></div>
                            <div class="file-sub"><?= formatSize($f['file_size']) ?> · <?= (int)$f['downloads'] ?> 次下载</div>
                            <div class="share-badges">
                                <?php if ($locked): ?><span class="badge badge-lock"><i class="fas fa-lock"></i> 加密</span><?php endif; ?>
                                <?php if (!empty($f['expires_at'])): ?>
                                    <span class="badge <?= $expired ? 'badge-exp-off' : 'badge-exp' ?>"><i class="fas fa-clock"></i> <?= $expired ? '已过期' : h(date('Y-m-d', (int)$f['expires_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="file-actions">
                            <a href="<?= h($link) ?>" target="_blank">访问</a>
                            <button type="button" class="copy-link" data-link="<?= h($link) ?>">复制</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="files-section view-panel" data-view="trash">
            <?php if (empty($trash)): ?>
                <div class="files-empty">回收站是空的 🗑️ 删除的文件会先放在这里，可随时恢复。</div>
            <?php else: ?>
                <div class="files-grid" id="trashGrid">
                    <?php foreach ($trash as $t):
                        $isFolder = ($t['type'] === 'folder');
                        [$icon, $color] = $isFolder ? ['fa-folder', '#f59e0b'] : faIcon($t['file_name']);
                    ?>
                    <div class="file-card <?= $isFolder ? 'folder-card' : '' ?>"
                         data-name="<?= h($t['file_name']) ?>"
                         data-size="<?= (int)$t['file_size'] ?>"
                         data-time="<?= strtotime($t['created_at']) ?>">
                        <div class="file-icon" style="background:<?= $color ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div class="file-meta">
                            <div class="file-name" title="<?= h($t['file_name']) ?>"><?= h($t['file_name']) ?></div>
                            <div class="file-sub"><?= $isFolder ? '文件夹' : formatSize($t['file_size']) ?> · <?= h(date('Y-m-d H:i', strtotime($t['created_at']))) ?> 删除</div>
                        </div>
                        <div class="file-actions">
                            <a href="<?= ioUrl('delete?id=' . $t['id'] . '&action=restore') ?>" class="restore">恢复</a>
                            <a href="<?= ioUrl('delete?id=' . $t['id'] . '&action=purge') ?>" data-del="<?= $t['id'] ?>" class="del">彻底删除</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <input type="file" id="fileInput" class="hidden" style="display:none" data-max="<?= (int)$maxBytes ?>">
    <script>window.MAX_FILE_BYTES = <?= (int)$maxBytes ?>; window.MAX_FILE_LABEL = <?= json_encode(formatSize($maxBytes), JSON_UNESCAPED_UNICODE) ?>;</script>

<?php if ($isLogin): ?>
        <!-- ============ 个人中心视图（从 dashboardupgradeprofile 平移过来，无刷新切换）============ -->
        <section class="profile-view view-panel" data-view="profile">
            <!-- Hero -->
            <div class="pf-hero">
                <div class="pf-hero-avatar"><?= h(mb_substr($user['username'], 0, 1)) ?></div>
                <div class="pf-hero-info">
                    <div class="pf-hero-name">
                        <?= h($user['username']) ?>
                        <span class="pf-hero-role <?= $isAdmin ? 'is-admin' : '' ?>"><?= $isAdmin ? '管理员' : '普通用户' ?></span>
                        <span class="pf-pill <?= h($curPlan) ?>"><?= h($curPlanInfo['name'] . ' · ' . $curPlanInfo['label']) ?></span>
                    </div>
                    <div class="pf-hero-sub">
                        加入于 <?= h(date('Y-m-d H:i', strtotime($user['created_at']))) ?> · UID #<?= (int)$user['id'] ?>
                    </div>
                </div>
                <?php if ($curPlan === 'v0'): ?>
                    <a class="pf-hero-upgrade" href="<?= appUrl('dashboard') ?>?view=upgrade" data-view="upgrade"><i class="fas fa-rocket"></i> 升级套餐</a>
                <?php else: ?>
                    <a class="pf-hero-upgrade is-ghost" href="<?= appUrl('dashboard') ?>?view=upgrade" data-view="upgrade"><i class="fas fa-arrow-up"></i> 续费 / 升级</a>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <div class="pf-tabs">
                <button type="button" class="pf-tab <?= $pfTab==='profile'  ? 'active':'' ?>" data-pftab="profile"><i class="fas fa-id-card"></i> 账号资料</button>
                <button type="button" class="pf-tab <?= $pfTab==='plan'     ? 'active':'' ?>" data-pftab="plan"><i class="fas fa-crown"></i> 套餐权限</button>
                <button type="button" class="pf-tab <?= $pfTab==='storage'  ? 'active':'' ?>" data-pftab="storage"><i class="fas fa-hard-drive"></i> 存储用量</button>
                <button type="button" class="pf-tab <?= $pfTab==='security' ? 'active':'' ?>" data-pftab="security"><i class="fas fa-shield-halved"></i> 安全中心</button>
            </div>

            <!-- Tab 面板：账号资料 -->
            <div class="pf-tab-pane" data-pfpane="profile" style="<?= $pfTab==='profile'?'':'display:none' ?>">

                <div class="pf-card pf-avatar-card">
                    <div class="pf-card-title"><i class="fas fa-image-portrait"></i> 我的头像</div>
                    <div class="pf-card-sub">支持 JPG/PNG/GIF/WebP，原图 ≤ 1MB（系统会自动编码存储）</div>

                    <div class="pf-avatar-row">
                        <div class="pf-avatar-box">
                            <?php if ($pfAvatar !== ''): ?>
                                <img class="pf-avatar-img" src="<?= h($pfAvatar) ?>" alt="avatar">
                            <?php else: ?>
                                <img class="pf-avatar-img" style="display:none" alt="avatar">
                            <?php endif; ?>
                            <span class="pf-avatar-initial" <?= $pfAvatar !== '' ? 'style="display:none"' : '' ?>><?= h($pfInitial) ?></span>
                        </div>

                        <div class="pf-avatar-ops">
                            <form method="POST" enctype="multipart/form-data" id="pf-avatar-form">
                                <input type="hidden" name="action" value="avatar_upload">
                                <label class="btn btn-primary">
                                    <i class="fas fa-camera"></i> 选择新头像
                                    <input type="file" name="avatar" id="pf-avatar-input" accept="image/png,image/jpeg,image/gif,image/webp" required style="display:none">
                                </label>
                                <button type="submit" class="btn btn-ghost"><i class="fas fa-cloud-arrow-up"></i> 上传</button>
                            </form>
                            <?php if ($pfAvatar !== ''): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="avatar_remove">
                                <button type="submit" class="btn btn-warn"><i class="fas fa-rotate-left"></i> 恢复默认</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas fa-id-card"></i> 基本信息</div>
                    <div class="pf-card-sub">账号创建后用户名不可修改</div>
                    <div class="pf-row-list">
                        <div class="pf-row"><div class="label">用户名</div><div class="value mono"><?= h($user['username']) ?></div></div>
                        <div class="pf-row"><div class="label">UID</div><div class="value mono">#<?= (int)$user['id'] ?></div></div>
                        <div class="pf-row"><div class="label">角色</div><div class="value"><?= $isAdmin ? '管理员（首个注册用户）' : '普通用户' ?></div></div>
                        <div class="pf-row"><div class="label">联系方式</div><div class="value"><?= h($maskContact) ?></div></div>
                        <div class="pf-row"><div class="label">注册时间</div><div class="value"><?= h(date('Y-m-d H:i', strtotime($user['created_at']))) ?></div></div>
                        <div class="pf-row"><div class="label">当前套餐</div>
                            <div class="value">
                                <span class="pf-pill <?= h($curPlan) ?>"><?= h($curPlanInfo['name'] . ' · ' . $curPlanInfo['label']) ?></span>
                                <a class="action" href="<?= appUrl('dashboard') ?>?view=upgrade" data-view="upgrade" style="margin-left:10px">变更套餐 →</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 面板：套餐权限 -->
            <div class="pf-tab-pane" data-pfpane="plan" style="<?= $pfTab==='plan'?'':'display:none' ?>">
                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas fa-crown"></i> 我的套餐</div>
                    <div class="pf-card-sub">套餐决定了上传大小、分享能力、存储空间</div>
                    <div class="pf-plan-card <?= $curPlan==='v0' ? 'is-free' : '' ?>" style="margin-top:14px">
                        <div class="pc-left">
                            <div class="pc-name"><?= h($curPlanInfo['name'] . ' · ' . $curPlanInfo['label']) ?></div>
                            <div class="pc-sub">
                                单文件最大 <?= formatSize($curPlanInfo['max']) ?> ·
                                云端存储 <?= formatSize($curPlanInfo['storage']) ?>
                            </div>
                            <div class="pc-tag">当前版本</div>
                        </div>
                        <div class="pc-right">
                            <div class="pc-price">¥<?= number_format($curPlanInfo['price'], 0) ?><small> /月</small></div>
                        </div>
                    </div>

                    <div class="pf-card-title" style="padding-top:22px"><i class="fas fa-list-check"></i> 当前权限</div>
                    <div class="pf-card-sub">基于当前套餐享有的功能与限额</div>
                    <div class="pf-perm">
                        <div class="perm-item">
                            <div class="l">
                                <div class="ic"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div><div class="ttl">单文件上传</div><div class="sub">单文件大小上限</div></div>
                            </div>
                            <div class="val"><?= formatSize($curPlanInfo['max']) ?></div>
                        </div>
                        <div class="perm-item">
                            <div class="l">
                                <div class="ic"><i class="fas fa-share-alt"></i></div>
                                <div><div class="ttl">外链分享</div><div class="sub">生成可公开访问的下载链接</div></div>
                            </div>
                            <div class="val"><i class="fas fa-circle-check check"></i> 已开启</div>
                        </div>
                        <div class="perm-item">
                            <div class="l">
                                <div class="ic"><i class="fas fa-lock"></i></div>
                                <div><div class="ttl">分享加密 / 有效期</div><div class="sub">为分享链接设置密码或过期时间</div></div>
                            </div>
                            <div class="val"><?= $curPlan !== 'v0' ? '<i class="fas fa-circle-check check"></i> 已开启' : '<i class="fas fa-circle-xmark off"></i> 需升级' ?></div>
                        </div>
                        <div class="perm-item">
                            <div class="l">
                                <div class="ic"><i class="fas fa-bolt"></i></div>
                                <div><div class="ttl">秒传 / 大文件</div><div class="sub">断点续传 + 哈希秒传</div></div>
                            </div>
                            <div class="val"><i class="fas fa-circle-check check"></i> 已开启</div>
                        </div>
                        <div class="perm-item">
                            <div class="l">
                                <div class="ic"><i class="fas fa-cloud"></i></div>
                                <div><div class="ttl">云端存储</div><div class="sub">所有文件占用的总空间</div></div>
                            </div>
                            <div class="val"><?= formatSize($curPlanInfo['storage']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 面板：存储用量 -->
            <div class="pf-tab-pane" data-pfpane="storage" style="<?= $pfTab==='storage'?'':'display:none' ?>">
                <div class="pf-card pf-cloud">
                    <div class="pf-cloud-head">
                        <div class="pf-cloud-icon"><i class="fas fa-cloud"></i></div>
                        <div class="pf-cloud-titles">
                            <div class="pf-cloud-name">云存储</div>
                            <div class="pf-cloud-sub">实时统计当前账号下的文件 / 文件夹 · 总容量 <?= formatSize($quota['limit']) ?>（<?= h($curPlanInfo['name'].' · '.$curPlanInfo['label']) ?>）</div>
                        </div>
                        <?php if ($curPlan === 'v0'): ?>
                            <a class="pf-cloud-cta" href="<?= appUrl('dashboard') ?>?view=upgrade" data-view="upgrade"><i class="fas fa-arrow-up"></i> 升级容量</a>
                        <?php else: ?>
                            <a class="pf-cloud-cta is-ghost" href="<?= appUrl('dashboard') ?>?view=upgrade" data-view="upgrade"><i class="fas fa-arrow-up"></i> 续费 / 升级</a>
                        <?php endif; ?>
                    </div>
                    <div class="pf-cloud-big">
                        <?= formatSize($quota['used']) ?>
                        <span class="of">/ <?= $quota['limit'] > 0 ? formatSize($quota['limit']) : '无限制' ?></span>
                        <span class="pct">（<?= $quota['limit'] > 0 ? $quota['pct'].'%' : '∞' ?>）</span>
                    </div>
                    <?php if ($quota['limit'] > 0): ?>
                        <div class="progress-bar big"><div class="progress-fill <?= $quota['pct']>=90?'full':($quota['pct']>=70?'warn':'') ?>" style="width:<?= $quota['pct'] ?>%"></div></div>
                    <?php endif; ?>
                    <div class="pf-cloud-stats">
                        <div class="stat"><div class="n"><?= number_format($stats['file_count']) ?></div><div class="l">个文件</div></div>
                        <div class="stat"><div class="n"><?= number_format($stats['folder_count']) ?></div><div class="l">个文件夹</div></div>
                        <div class="stat"><div class="n"><?= number_format($stats['downloads']) ?></div><div class="l">次下载</div></div>
                    </div>
                </div>

                <div class="pf-card pf-single">
                    <div class="pf-card-title"><i class="fas fa-file-arrow-up"></i> 单文件上传限制</div>
                    <div class="single-row">
                        <div class="single-num"><?= formatSize($maxBytes) ?></div>
                        <div class="single-meta">
                            这是「单个文件大小」的上限，并非你的云盘总容量<br>
                            可在「管理后台 → 接口配置」调整 <code>max_file_size</code>
                        </div>
                    </div>
                </div>

                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas <?= h($pfDriver['icon']) ?>"></i> 存储方式</div>
                    <div class="pf-card-sub">当前文件落到哪个引擎上</div>
                    <div class="pf-row-list">
                        <div class="pf-row">
                            <div class="label">驱动</div>
                            <div class="value"><strong><?= h($pfDriver['label']) ?></strong>
                                <?php if ($pfDriver['desc']): ?>
                                    <span class="hint">— <?= h($pfDriver['desc']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pf-row">
                            <div class="label">当前上限</div>
                            <div class="value mono"><?= formatSize($maxBytes) ?> / 单文件（<?= h($curPlanInfo['name'].' · '.$curPlanInfo['label']) ?>）</div>
                        </div>
                    </div>
                </div>

                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas fa-table-list"></i> 各套餐单文件上限</div>
                    <div class="pf-card-sub">会员等级不同，单文件允许体积不同；上传若超过套餐上限会被拒绝</div>
                    <table class="pf-table">
                        <thead><tr><th>套餐</th><th>云端总容量</th><th class="r">单文件上限</th><th>价格</th></tr></thead>
                        <tbody>
                        <?php foreach (['v0','v1','v2','v3'] as $pk):
                            $p = $PLANS[$pk];
                            $isCur = $pk === $curPlan;
                        ?>
                            <tr class="<?= $isCur ? 'is-current' : '' ?>">
                                <td>
                                    <span class="pf-pill <?= h($pk) ?>"><?= h($p['name'].' · '.$p['label']) ?></span>
                                    <?php if ($isCur): ?><span class="cur-tag">当前</span><?php endif; ?>
                                </td>
                                <td class="mono"><?= formatSize($p['storage']) ?></td>
                                <td class="r mono"><strong><?= formatSize($p['max']) ?></strong></td>
                                <td><?= $p['price'] > 0 ? '¥' . (int)$p['price'] . '/月' : '免费' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pf-card-sub" style="margin-top:10px">
                        <i class="fas fa-circle-info"></i>
                        拖入文件时若单文件超过上方的"单文件上传限制"，前端会立即拦截并提示；后端再次校验防止绕过。
                    </div>
                </div>

                <?php if (!empty($typeStats)): ?>
                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas fa-folder-tree"></i> 文件类型分布（Top 5）</div>
                    <div class="pf-card-sub">按占用空间排序</div>
                    <div class="pf-type-list">
                        <?php foreach ($typeStats as $t):
                            $mime = $t['mime_type'] ?: 'other';
                            $tPct = round($t['total'] / $typeTotal * 100, 1);
                        ?>
                        <div class="type-row">
                            <div class="tname" title="<?= h($mime) ?>"><?= h(mb_strimwidth($mime ?: 'other', 0, 14, '…')) ?></div>
                            <div class="tbar"><div class="tfill" style="width:<?= $tPct ?>%"></div></div>
                            <div class="tnum"><?= formatSize($t['total']) ?> · <?= $tPct ?>%</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab 面板：安全中心 -->
            <div class="pf-tab-pane" data-pfpane="security" style="<?= $pfTab==='security'?'':'display:none' ?>">
                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas fa-key"></i> 修改密码</div>
                    <div class="pf-card-sub">建议 6 位以上，包含字母和数字，定期更换</div>
                    <form class="pf-pwform" method="POST" autocomplete="off">
                        <input type="hidden" name="action" value="changepw">
                        <div class="field">
                            <label>当前密码</label>
                            <input type="password" name="cur_pw" required placeholder="请输入当前密码">
                        </div>
                        <div class="row2">
                            <div class="field">
                                <label>新密码</label>
                                <input type="password" name="new_pw" required minlength="6" placeholder="至少 6 位">
                            </div>
                            <div class="field">
                                <label>重复新密码</label>
                                <input type="password" name="confirm_pw" required minlength="6" placeholder="再输一次">
                            </div>
                        </div>
                        <div class="actions">
                            <button type="submit"><i class="fas fa-check"></i> 更新密码</button>
                        </div>
                    </form>
                </div>

                <div class="pf-card">
                    <div class="pf-card-title"><i class="fas fa-right-from-bracket"></i> 登录状态</div>
                    <div class="pf-row-list">
                        <div class="pf-row">
                            <div class="label">当前设备</div>
                            <div class="value">本浏览器已登录</div>
                        </div>
                        <div class="pf-row">
                            <div class="label">退出登录</div>
                            <div class="value">
                                <button type="button" class="btn btn-warn btn-sm" data-action="logout" data-href="<?= authUrl('logout') ?>">
                                    <i class="fas fa-right-from-bracket"></i> 退出当前账号
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pf-card">
                    <div class="pf-card-title" style="color:var(--pf-red)"><i class="fas fa-triangle-exclamation" style="color:var(--pf-red)"></i> 危险操作</div>
                    <div class="pf-row-list">
                        <div class="pf-row pf-danger-row">
                            <div class="label">注销账号</div>
                            <div class="value">
                                <span class="action" style="cursor:default;opacity:.5" title="暂未开放">即将开放</span>
                            </div>
                        </div>
                    </div>
                    <div class="pf-hint-text">注销后账号下的所有文件、分享链接、统计数据将被清除且不可恢复。</div>
                </div>
            </div>
        </section>
        <?php if ($pwFlash): ?>
        <script>setTimeout(() => toast(<?= json_encode($pwFlash['text'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($pwFlash['type']) ?>), 100);</script>
        <?php elseif ($pfFlash): ?>
        <script>setTimeout(() => toast(<?= json_encode($pfFlash['text'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($pfFlash['type']) ?>), 100);</script>
        <?php endif; ?>
<?php endif; ?>
    <?php include __DIR__ . '/partials/dashboard_upgrade_panel.php'; ?>
    </main>
</div>

<div id="toast-wrap"></div>

<script>window.BASE = '<?= basePath() ?>';</script>
<script>window.IO_URL = '<?= defined('IO_URL') && IO_URL ? IO_URL : '' ?>';</script>
<script>window.SITE_URL = '<?= SITE_URL ?>';</script>
<script src="assets/vendor/spark-md5/spark-md5.min.js"></script>
<script src="assets/app.js"></script>
<script>
(function () {
    const uploadBtn = document.getElementById('uploadBtn');
    const fileInput = document.getElementById('fileInput');
    const BASE     = window.BASE || '';
    const IO_BASE  = (window.IO_URL ? window.IO_URL.replace(/\/$/, '') : BASE);

    const pickFile = () => fileInput.click();
    uploadBtn.addEventListener('click', () => pickFile());

    document.getElementById('newFolderBtn')?.addEventListener('click', async () => {
        const name = await promptDialog('新建文件夹', '请输入文件夹名称', '新建文件夹');
        if (!name) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = location.href;
        const act = document.createElement('input'); act.type = 'hidden'; act.name = 'action'; act.value = 'mkdir';
        const nm = document.createElement('input'); nm.type = 'hidden'; nm.name = 'name'; nm.value = name;
        form.append(act, nm);
        document.body.appendChild(form);
        form.submit();
    });

    fileInput.addEventListener('change', () => {
        const f = fileInput.files[0];
        if (f) doUpload(f);
        fileInput.value = '';
    });

    async function doUpload(file) {
        if (!file) return;
        // 客户端拦截：超过套餐单文件上限立即提示，不再走网络请求
        const maxBytes = window.MAX_FILE_BYTES || 0;
        if (maxBytes > 0 && file.size > maxBytes) {
            const sizeMB = (file.size / 1024 / 1024).toFixed(2);
            toast(`「${file.name}」超出单文件上限（${sizeMB} MB > ${window.MAX_FILE_LABEL || ''}），已跳过`, 'error');
            return;
        }
        toast('上传中…', 'info');
        const md5 = await computeMd5(file);

        if (md5) {
            try {
                const pre = await fetch(IO_BASE + '/pre_upload', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `md5=${encodeURIComponent(md5)}&size=${file.size}&name=${encodeURIComponent(file.name)}`
                }).then(r => r.json());
                if (pre.success && pre.hit) {
                    toast('秒传成功', 'success');
                    setTimeout(() => location.reload(), 600);
                    return;
                }
                // 服务端再次拦截（防止篡改 / 多文件选项上传超大文件）
                if (pre && pre.success === false && /超过|超出|大小|exceed/i.test(pre.message || '')) {
                    toast(pre.message || '文件超过套餐限制', 'error');
                    return;
                }
            } catch (_) { /* 预检失败则走普通上传 */ }
        }

        const fd = new FormData();
        fd.append('file', file);
        if (md5) fd.append('md5', md5);
        const folderParams = new URLSearchParams(location.search);
        if (folderParams.get('folder')) fd.append('folder', folderParams.get('folder'));
        fetch(IO_BASE + '/upload', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    toast('上传成功', 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    toast(d.message || '上传失败', 'error');
                }
            })
            .catch(() => toast('网络错误', 'error'));
    }

    // 视图切换：我的文件 / 我的分享 / 回收站 / 个人中心 / 套餐升级
    const VIEW_ALLOWED = ['files', 'shares', 'trash', 'profile', 'upgrade'];
    function switchView(view) {
        if (!VIEW_ALLOWED.includes(view)) return;
        const nav = document.querySelector('.nav-item[data-view="' + view + '"]');
        if (nav) {
            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            nav.classList.add('active');
        }
        document.querySelectorAll('.view-panel').forEach(p => p.classList.toggle('active', p.dataset.view === view));
        document.querySelectorAll('.files-only').forEach(btn => {
            btn.classList.toggle('hidden-action', view !== 'files');
        });
        // 仅用 sessionStorage 记录当前视图，地址栏始终保持在 /dashboard（不带 # 后缀）
        try { sessionStorage.setItem('dashView', view); } catch (_) {}
    }
    window.switchView = switchView;   // 供内嵌片段（升级面板等）调用，避免整页跳转

    // 所有带 data-view 的链接（含侧栏 nav-item 与升级入口）统一本地切换，不整页跳转
    document.querySelectorAll('a[data-view]').forEach(item => {
        item.addEventListener('click', function (e) {
            const v = this.dataset.view;
            const here = location.pathname.replace(/\/$/, '').endsWith('/dashboard');
            if (here) {
                e.preventDefault();
                switchView(v);
                try { history.replaceState(null, '', '?view=' + v); } catch (_) {}
            }
        });
    });

    // 个人中心内部 4 tab 切换：纯前端，不刷新 URL
    document.querySelectorAll('.pf-tab').forEach(t => {
        t.addEventListener('click', () => {
            const tab = t.dataset.pftab;
            document.querySelectorAll('.pf-tab').forEach(x => x.classList.toggle('active', x === t));
            document.querySelectorAll('.pf-tab-pane').forEach(p => p.style.display = p.dataset.pfpane === tab ? '' : 'none');
            try { sessionStorage.setItem('dashPfTab', tab); } catch (_) {}
        });
    });

    // 初次加载定位视图：URL ?view= 最高优先 > sessionStorage > 默认 files
    (function () {
        let v = 'files';
        try {
            const u = new URL(location.href);
            const qv = u.searchParams.get('view');
            if (qv && ['files', 'shares', 'trash', 'profile', 'upgrade'].includes(qv)) {
                v = qv;
            } else {
                v = sessionStorage.getItem('dashView') || 'files';
            }
        } catch (_) {}
        if (['files', 'shares', 'trash', 'profile', 'upgrade'].includes(v)) switchView(v);
        // 恢复个人中心 tab（URL ?pftab= 优先 > sessionStorage > 默认 profile）
        if (v === 'profile') {
            try {
                const u2 = new URL(location.href);
                const qpt = u2.searchParams.get('pftab');
                const pt = (qpt && ['profile','plan','storage','security'].includes(qpt))
                            ? qpt : sessionStorage.getItem('dashPfTab') || 'profile';
                const tabBtn = document.querySelector('.pf-tab[data-pftab="' + pt + '"]');
                if (tabBtn) tabBtn.click();
                // 干掉 flash 参数，避免刷新再弹 toast
                if (u2.searchParams.has('flash')) {
                    u2.searchParams.delete('flash');
                    try { history.replaceState(null, '', u2.toString()); } catch (_) {}
                }
            } catch (_) {}
        }
    })();

    // 退出登录按钮：个人中心「安全中心」tab 内
    document.querySelectorAll('[data-action="logout"]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const ok = await confirmDialog('退出登录', '确认退出当前账号？');
            if (ok) location.href = btn.dataset.href || '<?= authUrl('logout') ?>';
        });
    });

    // 头像即时预览：选完文件立即显示缩略图
    const avatarInput = document.querySelector('#pf-avatar-input');
    if (avatarInput) {
        avatarInput.addEventListener('change', () => {
            const f = avatarInput.files && avatarInput.files[0];
            if (!f) return;
            const pf = document.querySelector('.pf-avatar-img');
            const init = document.querySelector('.pf-avatar-initial');
            const r = new FileReader();
            r.onload = ev => {
                if (pf)  { pf.src = ev.target.result; pf.style.display = 'block'; }
                if (init) init.style.display = 'none';
            };
            r.readAsDataURL(f);
        });
    }
    document.querySelectorAll('.copy-link').forEach(btn => {
        btn.addEventListener('click', () => {
            const link = btn.dataset.link;
            navigator.clipboard.writeText(link)
                .then(() => toast('链接已复制', 'success'))
                .catch(() => toast('复制失败', 'error'));
        });
    });

})();
</script>

<?php else: ?>
<!-- ===================== 登录 / 注册（访客态） ===================== -->
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="logo-icon">X</div>
            <div class="logo-text">XIAO<span>RAN</span></div>
        </div>

        <div class="auth-tabs">
            <button type="button" class="<?= $tab === 'login' ? 'active' : '' ?>" data-mode="login">登录</button>
            <button type="button" class="<?= $tab === 'register' ? 'active' : '' ?>" data-mode="register">注册</button>
        </div>

        <?php if ($error): ?>
        <div class="au-error"><i class="fas fa-circle-exclamation"></i> <?= h($error) ?></div>
        <?php endif; ?>

        <form class="au-form" id="authForm" method="POST" action="" novalidate style="margin-top:18px;" data-captcha="<?= h(CaptchaManager::driver()->name()) ?>">
            <input type="hidden" name="action" id="actionInput" value="<?= h($tab) ?>" />

            <!-- 登录字段 -->
            <div class="login-fields" id="loginFields">
                <div class="au-group">
                    <label for="loginUser">用户名</label>
                    <div class="au-input-wrap">
                        <span class="au-icon"><i class="fas fa-user"></i></span>
                        <input type="text" id="loginUser" name="username" placeholder="请输入用户名" autocomplete="username" />
                    </div>
                </div>
                <div class="au-group">
                    <label for="loginPwd">密码</label>
                    <div class="au-input-wrap">
                        <span class="au-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" id="loginPwd" name="password" placeholder="请输入密码" autocomplete="current-password" />
                        <button type="button" class="au-toggle" data-target="loginPwd"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <?php if (CaptchaManager::driver()->name() === 'local'): ?>
                <div class="au-group">
                    <label for="captchaInput">验证码</label>
                    <div class="au-input-wrap au-captcha">
                        <span class="au-icon"><i class="fas fa-shield-alt"></i></span>
                        <input type="text" id="captchaInput" name="captcha" maxlength="4" placeholder="输入右侧验证码" autocomplete="off" />
                        <img class="captcha-img" src="<?= url('captcha') ?>?t=<?= time() ?>" alt="验证码" title="点击刷新" onclick="this.src='<?= url('captcha') ?>?t='+Date.now()" />
                    </div>
                </div>
                <?php else: ?>
                <div class="au-group">
                    <label>安全验证</label>
                    <?= CaptchaManager::html() ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 注册字段 -->
            <div class="register-fields hidden" id="registerFields">
                <div class="au-group">
                    <label for="regUser">用户名</label>
                    <div class="au-input-wrap">
                        <span class="au-icon"><i class="fas fa-user"></i></span>
                        <input type="text" id="regUser" name="username" placeholder="至少 3 位字符" autocomplete="username" />
                    </div>
                </div>
                <div class="au-group">
                    <label for="regPwd">密码</label>
                    <div class="au-input-wrap">
                        <span class="au-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" id="regPwd" name="password" placeholder="至少 6 位字符" autocomplete="new-password" />
                        <button type="button" class="au-toggle" data-target="regPwd"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="au-group">
                    <label for="regContact">手机号 / 邮箱</label>
                    <div class="au-input-wrap">
                        <span class="au-icon"><i class="fas fa-mobile-alt"></i></span>
                        <input type="text" id="regContact" name="contact" placeholder="用于接收验证码，自动识别" autocomplete="off" />
                    </div>
                    <div class="au-hint" id="contactHint"></div>
                </div>
                <div class="au-group">
                    <label for="regCode">验证码</label>
                    <div class="au-input-wrap au-code">
                        <span class="au-icon"><i class="fas fa-shield-alt"></i></span>
                        <input type="text" id="regCode" name="code" maxlength="6" placeholder="6 位验证码" autocomplete="off" />
                        <button type="button" class="au-code-btn" id="sendCodeBtn">获取验证码</button>
                    </div>
                </div>
            </div>

            <button type="submit" class="au-submit" id="submitBtn"><?= $tab === 'register' ? '注 册' : '登 录' ?></button>
        </form>

        <div class="au-footer">
            <strong>TangWo</strong> · 让文件分享干净利落<br />
            TangWo团队原创出品
        </div>
    </div>
</div>

<script>
(function () {
    const loginFields = document.getElementById('loginFields');
    const registerFields = document.getElementById('registerFields');
    const tabs = document.querySelectorAll('.auth-tabs button');
    const submitBtn = document.getElementById('submitBtn');
    const actionInput = document.getElementById('actionInput');
    const form = document.getElementById('authForm');

    let currentMode = '<?= $tab ?>';

    function setMode(mode) {
        currentMode = mode;
        const isLogin = mode === 'login';
        tabs.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
        loginFields.classList.toggle('hidden', !isLogin);
        registerFields.classList.toggle('hidden', isLogin);
        submitBtn.textContent = isLogin ? '登 录' : '注 册';
        actionInput.value = mode;
        // 禁用非激活分组，避免重复提交
        loginFields.querySelectorAll('input').forEach(i => i.disabled = !isLogin);
        registerFields.querySelectorAll('input').forEach(i => i.disabled = isLogin);
    }

    tabs.forEach(btn => btn.addEventListener('click', () => {
        if (btn.dataset.mode === currentMode) return;
        setMode(btn.dataset.mode);
    }));

    // 密码显隐
    document.querySelectorAll('.au-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    });

    // 轻量 toast
    let toastTimer = null;
    function toast(msg, kind, dur) {
        let el = document.querySelector('.toast');
        if (!el) { el = document.createElement('div'); el.className = 'toast'; document.body.appendChild(el); }
        el.textContent = msg;
        el.className = 'toast show' + (kind ? ' ' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.className = 'toast' + (kind ? ' ' + kind : ''); }, dur || 3200);
    }

    // 手机号 / 邮箱 自动识别
    function detectContact(v) {
        if (/^1[3-9]\d{9}$/.test(v)) return 'phone';
        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'email';
        return '';
    }
    const regContact = document.getElementById('regContact');
    const contactHint = document.getElementById('contactHint');
    if (regContact) {
        regContact.addEventListener('input', () => {
            const t = detectContact(regContact.value.trim());
            if (regContact.value.trim() === '') { contactHint.textContent = ''; contactHint.classList.remove('ok'); }
            else if (t) { contactHint.textContent = '已识别为：' + (t === 'phone' ? '手机号' : '邮箱'); contactHint.classList.add('ok'); }
            else { contactHint.textContent = '请填写正确的手机号或邮箱'; contactHint.classList.remove('ok'); }
        });
    }

    // 发送注册验证码
    const sendCodeBtn = document.getElementById('sendCodeBtn');
    let codeCountdown = null;
    if (sendCodeBtn && regContact) {
        sendCodeBtn.addEventListener('click', () => {
            const val = regContact.value.trim();
            const type = detectContact(val);
            if (!type) { toast('请输入正确的手机号或邮箱', 'err'); return; }
            sendCodeBtn.disabled = true;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=sendcode&contact=' + encodeURIComponent(val)
            })
            .then(r => r.json())
            .then(d => {
                if (d.error) { toast(d.error, 'err'); sendCodeBtn.disabled = false; return; }
                if (d.demo) {
                    toast('【演示验证码】' + d.code + '（上线接入网关后不会显示）', 'ok', 7000);
                } else {
                    toast('验证码已发送，请查收', 'ok');
                }
                let n = 60;
                sendCodeBtn.textContent = n + 's 后重试';
                codeCountdown = setInterval(() => {
                    n--;
                    if (n <= 0) { clearInterval(codeCountdown); sendCodeBtn.disabled = false; sendCodeBtn.textContent = '获取验证码'; }
                    else { sendCodeBtn.textContent = n + 's 后重试'; }
                }, 1000);
            })
            .catch(() => { toast('发送失败，请重试', 'err'); sendCodeBtn.disabled = false; });
        });
    }

    // 初始禁用非激活分组
    setMode(currentMode);
})();
</script>

<?php endif; ?>

<?php // 第三方验证码（极验/腾讯云）前端组件：按后台配置的驱动注入，本地图形码无额外脚本 ?>
<?php if (!$isLogin && CaptchaManager::driver()->name() !== 'local') {
    echo CaptchaManager::js();
} ?>

    <footer style="text-align:center;padding:18px;color:#94a3b8;font-size:13px">
        © <?= date('Y') ?> TangWo · TangWo 网盘（tangwo-disk 开源版）
    </footer>

</body>
</html>
