<?php
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json; charset=utf-8');

requireNodeRole('io');   // 仅上传节点处理；集群下其余节点 403

// 游客上传开关
if (!getSetting('allow_guest') && !isLogin()) {
    exit(json_encode(['success' => false, 'message' => '请先登录后再上传']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    exit(json_encode(['success' => false, 'message' => '无效请求']));
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['success' => false, 'message' => '上传失败，错误码: ' . $file['error']]));
}

// 大小限制：优先用后台「最大上传文件」设置（升级套餐后随之生效），兜底用常量 MAX_FILE_SIZE
$maxUpload = (int) (getSetting('max_file_size') ?: MAX_FILE_SIZE);
if ($file['size'] > $maxUpload) {
    exit(json_encode(['success' => false, 'message' => '文件超过限制（' . formatSize($maxUpload) . '）']));
}

// 优先用前端算好的 md5（SparkMD5 分片计算，免服务端整盘读取），格式非法则回退到服务端 md5_file
$clientMd5 = strtolower(trim($_POST['md5'] ?? ''));
$md5 = preg_match('/^[a-f0-9]{32}$/', $clientMd5) ? $clientMd5 : md5_file($file['tmp_name']);
$uid       = currentUid();                 // 集群下从 HMAC 票据取，单实例回退 session
$pdo       = db();
$shortCode = generateUniqueShortCode();
$parentId  = (isset($_POST['folder']) && is_numeric($_POST['folder'])) ? (int)$_POST['folder'] : null;

// ===== 秒传检测 =====
$stmt = $pdo->prepare('SELECT id, file_key FROM files WHERE md5_hash = ? AND status = 1 LIMIT 1');
$stmt->execute([$md5]);
$exist = $stmt->fetch();

if ($exist) {
    // 复用物理文件，只新增一条记录（不重复落盘，不计配额）
    $stmt = $pdo->prepare('INSERT INTO files (user_id, file_name, file_key, file_size, mime_type, md5_hash, short_code, parent_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $uid,
        $file['name'],
        $exist['file_key'],   // 复用已存在文件的存储 key
        $file['size'],
        $file['type'],
        $md5,
        $shortCode,
        $parentId
    ]);
    @unlink($file['tmp_name']);
    exit(json_encode(['success' => true, 'short_code' => $shortCode, 'instant' => true, 'message' => '秒传成功']));
}

// ===== 首次上传：交给存储内核（本机 / COS 由 config 决定） =====
$ym      = date('Y-m');
$ext     = pathinfo($file['name'], PATHINFO_EXTENSION);
$newName = bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
$key     = "$ym/$newName";   // 逻辑存储 key（不含 uploads/ 前缀），驱动负责落到本地或 COS

$storage = Storage::instance();
if (!$storage->put($file['tmp_name'], $key)) {
    exit(json_encode(['success' => false, 'message' => '保存失败']));
}

// 可选：受保护 / 限时分享（仅首次真实上传生效，秒传/复用不覆盖原文件属性）
$sharePassword = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : '';
$expiresAt = null;
if (!empty($_POST['expire_hours']) && is_numeric($_POST['expire_hours'])) {
    $expiresAt = time() + (int)$_POST['expire_hours'] * 3600;
}

$stmt = $pdo->prepare('INSERT INTO files (user_id, file_name, file_key, file_size, mime_type, md5_hash, short_code, share_password, expires_at, parent_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $uid,
    $file['name'],
    $key,
    $file['size'],
    $file['type'],
    $md5,
    $shortCode,
    $sharePassword,
    $expiresAt,
    $parentId
]);

// 真实落盘才计入配额（秒传复用物理文件不计）
if ($uid) {
    $pdo->prepare('UPDATE users SET storage_used = storage_used + ? WHERE id = ?')
        ->execute([$file['size'], $uid]);
}

exit(json_encode(['success' => true, 'short_code' => $shortCode, 'instant' => false]));
