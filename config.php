<?php
/**
 * ============================================================
 *  本地测试 / 宝塔部署 · 必改清单
 *  - 数据库凭据 / 站点地址：推荐通过 /install 向导写入到 config_db.php
 *  - 手动改也行：把下面这块替换为你的实际值
 *  - TICKET_SECRET → 上线前务必改成一串随机长串
 *  流程：宝塔装 PHP-8.3 + MySQL → 建站(根目录指到本文件夹) → 访问 /
 *        → 自动跳 /install → 在表单填 DB 账号密码 / 站点信息 → 完成
 * ============================================================
 */

// 优先加载安装器写入的运行时配置（config_db.php 由 install.php 自动生成，请勿手动编辑）
if (file_exists(__DIR__ . '/config_db.php')) {
    require_once __DIR__ . '/config_db.php';
}

// 统一守卫：未安装 → 除 install.php 外全部跳 /install
// install.php 自己不 require config.php，所以不会触发这条
if (!file_exists(__DIR__ . '/config_db.php')) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'install.php') {
        $bp = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/\\');
        if ($bp === '/' || $bp === '\\' || $bp === '.') $bp = '';
        header('Location: ' . $bp . '/install');
        exit;
    }
}

// ===== 数据库配置（占位值：未安装时 db() 会失败，引导到 /install） =====
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', '');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// ===== 站点配置 =====
if (!defined('SITE_URL')) define('SITE_URL', '');   // 未安装时为空，安装器写入后为完整站点地址（结尾不带斜杠）

// ===== 项目信息（开源署名）=====
if (!defined('APP_NAME'))   define('APP_NAME', 'TangWo 网盘');     // 项目名称（页脚 / 标题可用）
if (!defined('APP_AUTHOR')) define('APP_AUTHOR', 'TangWo');     // 作者署名（MIT 协议 Copyright 持有人）

// ===== 文件存储 / 上传上限 =====
define('UPLOAD_DIR', __DIR__ . '/uploads/');      // 文件落盘目录
define('MAX_FILE_SIZE', 100 * 1024 * 1024);       // 单文件上限：100MB

// ===== 本机下载加速：Nginx X-Accel-Redirect（大文件不经 PHP，省内存） =====
// 开启后，本机存储的下载由 Nginx 直接发文件，PHP 只下发指令头。
// 必须在 nginx 中配置对应 internal 内部路径（见 nginx-rewrite.conf 的 /_protected_files 段）。
define('X_ACCEL_REDIRECT', false);                // 部署到 nginx 且配好 internal 后改 true
define('X_ACCEL_LOCATION', '/_protected_files');   // 对应 nginx internal location 前缀，指向 uploads 目录

// ===== 集群票据密钥（app/auth/io/dl 多节点跨域认证用，生产务必改成随机长串） =====
// 生产务必改成固定的随机长串（仅生成一次后写死，例如用 php -r "echo bin2hex(random_bytes(32));" 生成）。
// 严禁使用运行时随机值（如 random_bytes），否则每次请求密钥都变，跨请求/跨节点 HMAC 票据校验必然失败。
define('TICKET_SECRET', 'CHANGE_ME_xc_ticket_secret_务必替换为固定长串');

// ===== 集群节点角色（单实例部署留 'single'；多子域集群填 app/auth/io/dl） =====
// single : 单实例，所有功能在同一份代码（默认，兼容旧部署，session 即可认证）
// auth   : 仅处理登录 / 注册 / 登出，登录后签发跨域无状态票据（xc_ticket Cookie）
// app    : 控制台 / 前端页面（消费票据，不处理写操作）
// io     : 上传 / 预检 API（消费票据，校验 role=io）
// dl     : 下载节点（消费票据或匿名游客，校验 role=dl）
// 多份代码指向同一 repo，靠 NODE_ROLE 区分职责；无状态票据让节点间无需共享 session。
// 集群部署可单份代码 + 软链，用环境变量 XC_NODE_ROLE 覆盖（见 nginx-cluster.conf）；未设则取下方常量。
$_nodeRoleEnv = getenv('XC_NODE_ROLE');
define('NODE_ROLE', ($_nodeRoleEnv !== false && $_nodeRoleEnv !== '') ? $_nodeRoleEnv : 'single');
// 跨子域共享 Cookie 域名（集群时必填，需带前缀点，如 '.xcsc1.cn'），单实例留空用当前域
define('TICKET_COOKIE_DOMAIN', '');
// 集群子域入口（单实例留空，走同源 url()；集群时填绝对域名，前端链接自动跨子域）
define('AUTH_URL', '');   // 登录 / 注册 / 登出节点，如 https://auth.xcsc1.cn
define('APP_URL',  '');   // 控制台 / 前端节点，如 https://app.xcsc1.cn
define('IO_URL',   '');   // 上传 / 预检节点，如 https://io.xcsc1.cn

// 开启会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
