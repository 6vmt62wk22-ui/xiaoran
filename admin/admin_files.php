<?php
/**
 * 管理后台 · 文件管理（搜索 + 类型/回收站过滤 + 恢复/删除 + 批量删除）
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

$pdo      = db();
$siteName = getSetting('site_name') ?: 'TangWo 网盘';
$flash    = ['type' => '', 'msg' => ''];

// ============ POST 处理 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'purgeFile') {
        $fid = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM files WHERE id = ?');
        $stmt->execute([$fid]);
        $file = $stmt->fetch();
        if ($file) {
            if ($file['type'] !== 'folder') {
                $refs = fileRefCount($file['file_key'], $file['id']);
                if ($refs === 0) {
                    Storage::instance()->delete($file['file_key']);
                    if ($file['user_id']) {
                        $pdo->prepare('UPDATE users SET storage_used = GREATEST(0, storage_used - ?) WHERE id = ?')
                            ->execute([$file['file_size'], $file['user_id']]);
                    }
                }
            }
            $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fid]);
            $flash = ['type' => 'success', 'msg' => '已彻底删除'];
        }
    }

    elseif ($act === 'restoreFile') {
        $fid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE files SET status = 1 WHERE id = ?')->execute([$fid]);
        $flash = ['type' => 'success', 'msg' => '已恢复'];
    }

    elseif ($act === 'purgeTrash') {
        // 清空回收站
        $rows = $pdo->query("SELECT * FROM files WHERE status = 0")->fetchAll();
        $count = 0;
        foreach ($rows as $r) {
            if ($r['type'] !== 'folder') {
                $refs = fileRefCount($r['file_key'], $r['id']);
                if ($refs === 0) {
                    Storage::instance()->delete($r['file_key']);
                    if ($r['user_id']) {
                        $pdo->prepare('UPDATE users SET storage_used = GREATEST(0, storage_used - ?) WHERE id = ?')
                            ->execute([$r['file_size'], $r['user_id']]);
                    }
                }
            }
            $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$r['id']]);
            $count++;
        }
        $flash = ['type' => 'success', 'msg' => '回收站已清空，共清理 ' . $count . ' 项'];
    }

    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['admin_flash'] = $flash;
    header('Location: ' . appUrl('admin_files'));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

// ============ 文件列表 ============
$files = $pdo->query(
    "SELECT f.*, u.username AS owner FROM files f
     LEFT JOIN users u ON u.id = f.user_id
     ORDER BY f.id DESC LIMIT 500"
)->fetchAll();

$trashFiles = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE status = 0")->fetchColumn();

$pageTitle = '文件管理';
$activeNav = 'files';
require __DIR__ . '/admin_layout_header.php';
?>

<?php if ($flash['msg']): ?>
  <div class="alert alert-<?= $flash['type']==='success'?'success':'error' ?>">
    <i class="fas fa-<?= $flash['type']==='success'?'check-circle':'exclamation-circle' ?>"></i>
    <?= h($flash['msg']) ?>
  </div>
<?php endif; ?>

<div class="table-wrap">
  <div class="table-toolbar">
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="fileSearch" placeholder="搜索文件名、拥有者...">
    </div>
    <div style="display:flex;gap:6px">
      <button class="filter-chip active" data-filter="all" onclick="setFilter(this,'all')">全部</button>
      <button class="filter-chip" data-filter="file" onclick="setFilter(this,'file')">文件</button>
      <button class="filter-chip" data-filter="folder" onclick="setFilter(this,'folder')">文件夹</button>
      <button class="filter-chip" data-filter="trash" onclick="setFilter(this,'trash')">回收站</button>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <?php if ($trashFiles > 0): ?>
      <form method="POST" id="form-purge-trash">
        <input type="hidden" name="action" value="purgeTrash">
        <button type="button" class="btn btn-warn" onclick="confirmSubmit('form-purge-trash', '确定清空回收站？共 <?= $trashFiles ?> 项文件将被彻底删除（不可恢复）。', { title:'清空回收站', icon:'broom', danger:true, okText:'确认清空' })"><i class="fas fa-broom"></i> 清空回收站 (<?= $trashFiles ?>)</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <table id="fileTable">
    <thead>
      <tr>
        <th>ID</th><th>文件名</th><th>拥有者</th><th>大小</th><th>下载</th><th>状态</th><th>上传时间</th><th style="text-align:right">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($files)): ?>
        <tr class="empty-row"><td colspan="8">暂无文件</td></tr>
      <?php else: foreach ($files as $f):
        $isFolder = ($f['type'] ?? 'file') === 'folder';
        $isTrash  = $f['status'] == 0;
        $filterAttr = $isTrash ? 'trash' : ($isFolder ? 'folder' : 'file');
        $searchAttr = h(($f['file_name'] ?? '') . ' ' . ($f['owner'] ?? ''));
        $nameShort = mb_strlen($f['file_name']) > 28 ? mb_substr($f['file_name'], 0, 28) . '…' : $f['file_name'];
      ?>
      <tr data-filter="<?= $filterAttr ?>" data-search="<?= $searchAttr ?>">
        <td class="mono"><?= $f['id'] ?></td>
        <td>
          <span class="ellipsis" title="<?= h($f['file_name']) ?>">
            <?= ($isFolder ? '<i class="fas fa-folder" style="color:#f59e0b"></i> ' : '<i class="fas fa-file" style="color:#64748b"></i> ')
               . h($nameShort) ?>
          </span>
        </td>
        <td class="small"><?= h($f['owner'] ?: '游客') ?></td>
        <td class="mono small"><?= $isFolder ? '—' : formatSize($f['file_size']) ?></td>
        <td class="mono small"><?= (int) $f['downloads'] ?></td>
        <td>
          <?php if ($isTrash): ?><span class="badge badge-trash"><i class="fas fa-trash"></i> 回收站</span>
          <?php elseif ($isFolder): ?><span class="badge badge-folder"><i class="fas fa-folder"></i> 文件夹</span>
          <?php else: ?><span class="badge badge-active"><i class="fas fa-check"></i> 有效</span><?php endif; ?>
        </td>
        <td class="small mono"><?= h(date('Y-m-d H:i', strtotime($f['created_at']))) ?></td>
        <td>
          <div class="row-actions" style="justify-content:flex-end">
            <?php if ($isTrash): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="restoreFile">
              <input type="hidden" name="id" value="<?= $f['id'] ?>">
              <button class="btn btn-success btn-sm" type="submit"><i class="fas fa-rotate-left"></i> 恢复</button>
            </form>
            <?php endif; ?>
            <form method="POST" id="form-purge-<?= $f['id'] ?>" style="display:inline">
              <input type="hidden" name="action" value="purgeFile">
              <input type="hidden" name="id" value="<?= $f['id'] ?>">
              <button type="button" class="btn btn-danger btn-sm" onclick="confirmSubmit('form-purge-<?= $f['id'] ?>', '确定彻底删除「<?= h(addslashes($f['file_name'])) ?>」？此操作不可恢复。', { title:'彻底删除', icon:'trash', danger:true, okText:'确认删除' })"><i class="fas fa-trash"></i> 删除</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
// 通用：弹出确认后提交指定 form（替代原生 confirm）
function confirmSubmit(formId, msg, opts) {
  askConfirm(msg, function() { document.getElementById(formId).submit(); }, opts);
}

let currentFilter = 'all';
function setFilter(btn, filter) {
  document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentFilter = filter;
  applyFileFilter();
}
function applyFileFilter() {
  const q = (document.getElementById('fileSearch').value || '').trim().toLowerCase();
  document.querySelectorAll('#fileTable tbody tr').forEach(tr => {
    if (tr.classList.contains('empty-row')) return;
    const f = tr.dataset.filter || 'file';
    const text = (tr.dataset.search || tr.textContent).toLowerCase();
    const okFilter = (currentFilter === 'all' || f === currentFilter);
    const okSearch = (!q || text.includes(q));
    tr.style.display = (okFilter && okSearch) ? '' : 'none';
  });
}
document.getElementById('fileSearch').addEventListener('input', applyFileFilter);
</script>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
