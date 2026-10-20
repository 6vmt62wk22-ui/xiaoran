<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Http.php';                    // 轻量 HTTP 客户端（厂商 API 调用）
require_once __DIR__ . '/core/Storage/Manager.php';         // 加载存储内核（Storage 类）
require_once __DIR__ . '/core/Auth/Ticket.php';             // 加载无状态票据（Ticket 类）
require_once __DIR__ . '/core/Tc3Client.php';               // 腾讯云 TC3-HMAC-SHA256 签名客户端
require_once __DIR__ . '/core/Captcha/CaptchaInterface.php';// 验证码驱动接口
require_once __DIR__ . '/core/Captcha/LocalCaptcha.php';    // 本地图形验证码（默认）
require_once __DIR__ . '/core/Captcha/GeeTestCaptcha.php';  // 极验行为验 4.0
require_once __DIR__ . '/core/Captcha/TencentCaptcha.php';  // 腾讯云验证码 2.0
require_once __DIR__ . '/core/Captcha/Manager.php';         // 验证码工厂（可切换）
require_once __DIR__ . '/core/Mail/SmtpClient.php';         // 原生 SMTP 客户端
require_once __DIR__ . '/core/Mail/Mailer.php';             // 邮件发送器
require_once __DIR__ . '/core/Sms/SmsInterface.php';        // 短信驱动接口
require_once __DIR__ . '/core/Sms/AliyunSms.php';           // 阿里云短信
require_once __DIR__ . '/core/Sms/TencentSms.php';          // 腾讯云短信
require_once __DIR__ . '/core/Sms/Manager.php';             // 短信工厂
require_once __DIR__ . '/core/Verify/VerifyCode.php';       // 邮箱/短信验证码存取
require_once __DIR__ . '/core/Payment/PaymentInterface.php';// 支付渠道接口
require_once __DIR__ . '/core/Payment/WechatPay.php';       // 微信支付 Native v3
require_once __DIR__ . '/core/Payment/Alipay.php';          // 支付宝当面付
require_once __DIR__ . '/core/Payment/Manager.php';         // 支付工厂 + 订单履约

/**
 * 当前部署路径前缀（支持子目录部署，如 /tangwo-disk）
 * 返回 ''（根目录）或 '/tangwo-disk'（无末尾斜杠）
 *
 * 说明：以「本文件所在目录（项目根）相对站点 DOCUMENT_ROOT」计算，
 * 与当前执行脚本位于根目录还是子目录（api/、admin/、pages/ …）无关，
 * 这样把接口/页面拆分子目录后，全站生成的链接依旧正确。
 */
function basePath(): string {
    $norm = static function ($p) { return str_replace('\\', '/', rtrim((string) $p, '/')); };
    $root = $norm(dirname(__FILE__));
    $doc  = $norm($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($doc !== '' && $root !== '' && strpos($root, $doc) === 0) {
        $b = substr($root, strlen($doc));
        return ($b === '' || $b === '/') ? '' : rtrim($b, '/');
    }
    // 回退：按当前脚本 URL 目录（兼容 DOCUMENT_ROOT 缺失的环境）
    $b = $norm(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return ($b === '' || $b === '/') ? '' : $b;
}

/**
 * 生成干净短链（无 .php 后缀），自动适配子目录
 * 例：url('dashboard') => '/dashboard' 或 '/tangwo-disk/dashboard'
 */
function url(string $path): string {
    return basePath() . '/' . ltrim($path, '/');
}

/**
 * 生成对外公开绝对链（如分享链接），基于 SITE_URL，自动避免子目录双前缀
 * 例：shareUrl('s/aB3kQ') => 'http://localhost/s/aB3kQ' 或 'http://host/tangwo-disk/s/aB3kQ'
 */
function shareUrl(string $path): string {
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * 认证节点入口（登录 / 注册 / 登出）：集群时指向 AUTH_URL 绝对域名，单实例回退同源 url()
 */
function authUrl(string $path = ''): string {
    return defined('AUTH_URL') && AUTH_URL ? rtrim(AUTH_URL, '/') . '/' . ltrim($path, '/') : url($path);
}

/**
 * 控制台节点入口（前端 / 我的文件）：集群时指向 APP_URL 绝对域名，单实例回退同源 url()
 */
function appUrl(string $path = ''): string {
    return defined('APP_URL') && APP_URL ? rtrim(APP_URL, '/') . '/' . ltrim($path, '/') : url($path);
}

/**
 * 上传 / 预检节点入口：集群时指向 IO_URL 绝对域名，单实例回退同源 url()
 */
function ioUrl(string $path = ''): string {
    return defined('IO_URL') && IO_URL ? rtrim(IO_URL, '/') . '/' . ltrim($path, '/') : url($path);
}

/**
 * 数据库连接（单例）
 */
function db() {
    static $pdo = null;
    if (!$pdo) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // 首次连接后自动跑 schema migration（幂等，可重入）
        // 仅在 migrate.php 已 require 过且迁移完成才执行；install/update 阶段也会再次执行 → 安全。
        if (function_exists('runMigrations') && !defined('SKIP_AUTO_MIGRATE')) {
            try { runMigrations($pdo); } catch (Throwable $e) { /* 忽略，避免阻塞读路径 */ }
        }
    }
    return $pdo;
}

/**
 * 读取系统设置
 */
function getSetting($key, $default = null) {
    $stmt = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

/**
 * 写入系统设置
 */
function setSetting($key, $value) {
    db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = ?')
        ->execute([$key, $value, $value]);
}

/**
 * 生成随机 Base62 短码
 */
function generateShortCode($length = 5) {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, 61)];
    }
    return $out;
}

/**
 * 生成全局唯一的短码（查库去重，避免并发撞车）
 */
function generateUniqueShortCode() {
    $pdo = db();
    do {
        $code = generateShortCode(5);
        $stmt = $pdo->prepare('SELECT 1 FROM files WHERE short_code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

/**
 * 同一物理文件被多少条有效记录引用（秒传引用计数，防误删）
 */
function fileRefCount($fileKey, $exceptId = null) {
    $pdo = db();
    $sql = 'SELECT COUNT(*) FROM files WHERE file_key = ? AND status = 1';
    $params = [$fileKey];
    if ($exceptId) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * 当前登录用户 ID（无状态优先：先查 session，再查 HMAC 票据 Cookie）
 * 单实例部署走 session；集群部署走 xc_ticket 跨域无状态票据，节点间无需共享会话。
 */
function currentUid(): ?int {
    if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }
    if (!empty($_COOKIE['xc_ticket'])) {
        $c = Ticket::verify($_COOKIE['xc_ticket']);
        if ($c && isset($c['uid'])) return (int) $c['uid'];
    }
    return null;
}

/**
 * 是否登录
 */
function isLogin(): bool {
    return currentUid() !== null;
}

/**
 * 取当前用户行（含 storage_used / storage_limit，用于配额展示）
 */
function currentUser(): ?array {
    $uid = currentUid();
    if ($uid === null) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    return $stmt->fetch() ?: null;
}

/**
 * 登录成功后签发跨域无状态票据（写入 xc_ticket Cookie）。
 * 集群下各节点（app / io / dl）凭此 Cookie 免共享 session 完成认证。
 * 单实例部署也写 Cookie，但作用域仅当前域，不影响旧逻辑。
 */
function startSessionTicket(array $claims = [], int $ttl = 604800): void {
    $uid = currentUid();
    if ($uid === null) return;
    $claims['role'] = $claims['role'] ?? NODE_ROLE;
    $ticket = Ticket::issue($uid, $claims, $ttl);
    setcookie('xc_ticket', $ticket, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'domain'   => defined('TICKET_COOKIE_DOMAIN') && TICKET_COOKIE_DOMAIN ? TICKET_COOKIE_DOMAIN : '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => (defined('TICKET_COOKIE_DOMAIN') && TICKET_COOKIE_DOMAIN) ? 'None' : 'Lax',
    ]);
}

/**
 * 集群节点角色校验：仅允许目标角色的节点处理该请求（节点归属校验，登录交给业务层）。
 * 用法：requireNodeRole('io'); 单实例或角色匹配则放行，否则 403。
 * 不强制登录——下载/游客上传允许匿名，登录由各自 allow_guest 等开关控制。
 */
function requireNodeRole(string $role): void {
    if (NODE_ROLE !== 'single' && NODE_ROLE !== $role) {
        http_response_code(403);
        exit('本节点不接受该请求');
    }
}

/**
 * 配额占用：返回 [used, limit, pct(百分比)]
 * 登录用户读 users 表真实用量；未登录用系统上限占位。
 */
function getQuota(?int $uid): array {
    $limit = (int) (getSetting('max_file_size') ?: 0);
    if ($uid === null) {
        return ['used' => 0, 'limit' => $limit, 'pct' => 0];
    }
    $stmt = db()->prepare('SELECT storage_used, storage_limit FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    $used = (int) ($row['storage_used'] ?? 0);
    $limit = (int) ($row['storage_limit'] ?? $limit);
    return [
        'used'  => $used,
        'limit' => $limit,
        'pct'   => $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0,
    ];
}

/**
 * 跳转（默认 303 See Other，确保 POST 后重定向走 GET，避免刷新重复提交）
 */
function redirect($url, $code = 303) {
    $code = (int) $code;
    $statuses = [
        301 => 'HTTP/1.1 301 Moved Permanently',
        302 => 'HTTP/1.1 302 Found',
        303 => 'HTTP/1.1 303 See Other',
        307 => 'HTTP/1.1 307 Temporary Redirect',
        308 => 'HTTP/1.1 308 Permanent Redirect',
    ];
    if (isset($statuses[$code])) {
        header($statuses[$code]);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * 安全输出
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 字节格式化
 */
function formatSize($bytes) {
    $bytes = (int) $bytes;
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * 生成新的图形验证码字符串（小写）并存入 session
 * 注意：仅写 session，不输出 SVG。SVG 输出请用 renderCaptchaSvg()
 */
function issueCaptcha(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $charset = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $len = 4;
    $code = '';
    $max = strlen($charset) - 1;
    for ($i = 0; $i < $len; $i++) {
        $code .= $charset[random_int(0, $max)];
    }
    $codeLower = strtolower($code);
    $_SESSION['captcha'] = $codeLower;
    return $code; // 返回原大小写供 SVG 渲染
}

/**
 * 用 session 中的验证码字符串渲染 SVG（不重新生成）
 * 若 session 没有验证码，自动调用 issueCaptcha() 生成
 */
function renderCaptchaSvg(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['captcha'])) {
        issueCaptcha();
    }
    // session 存的是小写，但 SVG 用原大小写更好看——这里使用 strtoupper 显示
    // 校验时 strtolower($_POST['captcha']) 对比 strtolower($_SESSION['captcha']) ✓
    $code = strtoupper($_SESSION['captcha']);

    $width = 140;
    $height = 48;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    $svg .= '<rect width="100%" height="100%" fill="#f8fafc"/>';

    // 干扰线（更少、更淡，避免遮挡字符）
    for ($i = 0; $i < 3; $i++) {
        $x1 = rand(0, $width); $y1 = rand(0, $height);
        $x2 = rand(0, $width); $y2 = rand(0, $height);
        $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#cbd5e1" stroke-width="1"/>';
    }
    // 噪点（大幅减少）
    for ($i = 0; $i < 18; $i++) {
        $cx = rand(0, $width); $cy = rand(0, $height); $r = rand(1, 2);
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="#cbd5e1"/>';
    }
    // 字符
    $colors = ['#1e40af', '#b91c1c', '#047857', '#b45309', '#6d28d9'];
    $len = strlen($code);
    $gap = $width / ($len + 1);
    for ($i = 0; $i < $len; $i++) {
        $ch = $code[$i];
        $x = $gap * ($i + 1);
        $y = $height / 2 + 12 + rand(-2, 2);
        $rot = rand(-14, 14);
        $col = $colors[$i % count($colors)];
        $svg .= '<text x="' . $x . '" y="' . $y . '" font-family="Georgia, \'Times New Roman\', serif" font-size="30" font-weight="bold" fill="' . $col . '" text-anchor="middle" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')">' . htmlspecialchars($ch) . '</text>';
    }
    $svg .= '</svg>';
    return $svg;
}
