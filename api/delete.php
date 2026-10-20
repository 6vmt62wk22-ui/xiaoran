<?php
require_once __DIR__ . '/../functions.php';

if (!isLogin()) {
    redirect('login.php');
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    redirect(appUrl('dashboard'));
}

$me   = currentUid();                 // 集群下从 HMAC 票据取，单实例回退 session
$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) {
    redirect(appUrl('dashboard'));
}

// 权限校验：只能操作自己的文件（游客上传的 user_id 为 NULL，仅管理员 user_id=1 可操作）
if ($file['user_id'] !== null && $file['user_id'] !== $me && $me !== 1) {
    exit('无权操作');
}

$action = $_GET['action'] ?? 'trash';   // trash（移入回收站） | restore（恢复） | purge（彻底删除）

// 返回地址（保留当前 folder 参数）
$backUrl = appUrl('dashboard');
if (!empty($_GET['folder'])) $backUrl .= '?folder=' . (int)$_GET['folder'];

// 收集文件夹树下的所有记录 ID（含自身）
function collectTreeIds(PDO $pdo, int $folderId, $userId): array {
    $ids = [$folderId];
    $stmt = $pdo->prepare('SELECT id, type FROM files WHERE parent_id = ? AND user_id <=> ?');
    $stmt->execute([$folderId, $userId]);
    while ($row = $stmt->fetch()) {
        if ($row['type'] === 'folder') {
            $ids = array_merge($ids, collectTreeIds($pdo, (int)$row['id'], $userId));
        } else {
            $ids[] = (int)$row['id'];
        }
    }
    return $ids;
}

$ids = ($file['type'] === 'folder') ? collectTreeIds($pdo, $id, $file['user_id']) : [$id];
$placeholders = implode(',', array_fill(0, count($ids), '?'));

if ($action === 'restore') {
    // 恢复：状态置回 1（软删除时物理文件未删除，可直接恢复）
    $pdo->prepare("UPDATE files SET status = 1 WHERE id IN ($placeholders)")->execute($ids);
    redirect($backUrl);
}

if ($action === 'purge') {
    // 彻底删除：先取这些记录，再判断物理文件删除与配额回收
    $inStmt = $pdo->prepare("SELECT id, file_key, file_size, type, user_id FROM files WHERE id IN ($placeholders) AND type = 'file'");
    $inStmt->execute($ids);
    $toClean = $inStmt->fetchAll();
    foreach ($toClean as $f) {
        $refs = fileRefCount($f['file_key'], $f['id']);
        if ($refs === 0) {
            Storage::instance()->delete($f['file_key']);
            if ($f['user_id']) {
                $pdo->prepare('UPDATE users SET storage_used = GREATEST(0, storage_used - ?) WHERE id = ?')
                    ->execute([$f['file_size'], $f['user_id']]);
            }
        }
    }
    $pdo->prepare("DELETE FROM files WHERE id IN ($placeholders)")->execute($ids);
    redirect($backUrl);
}

// 默认 trash：软删除（移入回收站）。保留物理文件与配额，待回收站彻底删除时才清理，
// 这样「恢复」可以真正找回文件，而不是只剩一条坏记录。
$pdo->prepare("UPDATE files SET status = 0 WHERE id IN ($placeholders)")->execute($ids);
redirect($backUrl);
