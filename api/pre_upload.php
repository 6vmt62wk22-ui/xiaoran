<?php
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json; charset=utf-8');

requireNodeRole('io');   // 仅上传节点处理；集群下其余节点 403

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false, 'message' => '无效请求']));
}

$md5 = strtolower(trim($_POST['md5'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/', $md5)) {
    exit(json_encode(['success' => false, 'message' => '校验失败']));
}

// 客户端 size 也会传过来，预先拦截超大文件（即使 md5 已存在也拒绝写入新记录）
$clientSize = (int) ($_POST['size'] ?? 0);
if ($clientSize > 0) {
    $maxUpload = (int) (getSetting('max_file_size') ?: MAX_FILE_SIZE);
    if ($clientSize > $maxUpload) {
        exit(json_encode(['success' => false, 'message' => '文件超过单文件上限（' . formatSize($maxUpload) . '），请升级套餐或压缩文件']));
    }
}

// 游客上传开关
if (!getSetting('allow_guest') && !isLogin()) {
    exit(json_encode(['success' => true, 'hit' => false, 'need_login' => true]));
}

$pdo = db();

// 先查库里是否已有相同 md5 的有效文件（秒传命中）
$stmt = $pdo->prepare('SELECT id, file_key, file_name, file_size, mime_type FROM files WHERE md5_hash = ? AND status = 1 LIMIT 1');
$stmt->execute([$md5]);
$exist = $stmt->fetch();
if (!$exist) {
    exit(json_encode(['success' => true, 'hit' => false]));
}

// 命中：确认物理文件仍在（引用计数删除或迁移可能导致记录在、文件不在）
if (!Storage::instance()->exists($exist['file_key'])) {
    exit(json_encode(['success' => true, 'hit' => false]));
}

// 复用物理文件，仅新增一条记录（不重复落盘、不重复上传）——这就是「瞬时上传」
$fileName = trim($_POST['name'] ?? '') ?: $exist['file_name'];
$shortCode = generateUniqueShortCode();
$pdo->prepare('INSERT INTO files (user_id, file_name, file_key, file_size, mime_type, md5_hash, short_code)
               VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([
        currentUid(),
        $fileName,
        $exist['file_key'],
        $exist['file_size'],
        $exist['mime_type'],
        $md5,
        $shortCode,
    ]);

exit(json_encode([
    'success'  => true,
    'hit'      => true,
    'instant'  => true,
    'short_code' => $shortCode,
    'url'      => shareUrl('s/' . $shortCode),
    'message'  => '秒传成功',
]));
