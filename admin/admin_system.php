<?php
/**
 * 管理后台 · 系统信息 + 维护操作
 * - 重算用户已用容量（按 SUM(files.file_size) 校准 users.storage_used）
 * - 清空回收站（移至 admin_files.php 实现）
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

$pdo      = db();
$siteName = getSetting('site_name') ?: 'TangWo 网盘';
$flash    = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'recalcStorage') {
        // 校准每个用户的 storage_used
        $rows = $pdo->query(
            "SELECT user_id, COALESCE(SUM(file_size),0) AS total
             FROM files WHERE status = 1 AND type = 'file' AND user_id IS NOT NULL
             GROUP BY user_id"
        )->fetchAll();
        // 先清零
        $pdo->exec('UPDATE users SET storage_used = 0');
        // 再回填
        $upd = $pdo->prepare('UPDATE users SET storage_used = ? WHERE id = ?');
        $touched = 0;
        foreach ($rows as $r) {
            $upd->execute([(int) $r['total'], (int) $r['user_id']]);
            $touched++;
        }
        $flash = ['type' => 'success', 'msg' => '已重算 ' . $touched . ' 个用户的已用容量'];
    }

    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['admin_flash'] = $flash;
    header('Location: ' . appUrl('admin_system'));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

$phpVersion  = PHP_VERSION;
$dbVersion   = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?: '未知';
$schemaVer   = getSetting('schema_version', '1001');
$storageDrv  = getSetting('storage_driver', 'local');
$diskFree    = @disk_free_space(dirname(__DIR__));
$diskTotal   = @disk_total_space(dirname(__DIR__));
$uploadMax   = ini_get('upload_max_filesize');
$postMax     = ini_get('post_max_size');
$phpMaxExec  = ini_get('max_execution_time');
$trashFiles  = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE status = 0")->fetchColumn();

$pageTitle = '系统信息';
$activeNav = 'system';
require __DIR__ . '/admin_layout_header.php';
?>

<?php if ($flash['msg']): ?>
  <div class="alert alert-<?= $flash['type']==='success'?'success':'error' ?>">
    <i class="fas fa-<?= $flash['type']==='success'?'check-circle':'exclamation-circle' ?>"></i>
    <?= h($flash['msg']) ?>
  </div>
<?php endif; ?>

<div class="sys-grid">
  <div class="sys-card">
    <h3><i class="fas fa-code"></i> 运行环境</h3>
    <div class="sys-row"><span class="k">PHP 版本</span><span class="v mono"><?= h($phpVersion) ?></span></div>
    <div class="sys-row"><span class="k">MySQL 版本</span><span class="v mono"><?= h($dbVersion) ?></span></div>
    <div class="sys-row"><span class="k">Schema 版本</span><span class="v mono"><?= h($schemaVer) ?></span></div>
    <div class="sys-row"><span class="k">存储引擎</span><span class="v"><?= h($storageDrv) ?></span></div>
  </div>
  <div class="sys-card">
    <h3><i class="fas fa-sliders"></i> PHP 限制</h3>
    <div class="sys-row"><span class="k">upload_max_filesize</span><span class="v mono"><?= h($uploadMax) ?></span></div>
    <div class="sys-row"><span class="k">post_max_size</span><span class="v mono"><?= h($postMax) ?></span></div>
    <div class="sys-row"><span class="k">max_execution_time</span><span class="v mono"><?= h($phpMaxExec) ?>s</span></div>
  </div>
  <div class="sys-card">
    <h3><i class="fas fa-hard-drive"></i> 磁盘空间</h3>
    <?php if ($diskFree !== false && $diskTotal !== false):
      $usedPct = $diskTotal > 0 ? round(($diskTotal - $diskFree) / $diskTotal * 100) : 0;
      $diskClass = $usedPct >= 90 ? '#ef4444' : ($usedPct >= 70 ? '#f59e0b' : '#3b82f6');
    ?>
    <div class="sys-row"><span class="k">可用空间</span><span class="v mono"><?= formatSize($diskFree) ?></span></div>
    <div class="sys-row"><span class="k">总空间</span><span class="v mono"><?= formatSize($diskTotal) ?></span></div>
    <div class="sys-row"><span class="k">已用</span><span class="v mono"><?= $usedPct ?>%</span></div>
    <div class="disk-bar"><div class="disk-fill" style="width:<?= $usedPct ?>%;background:<?= $diskClass ?>"></div></div>
    <?php else: ?>
    <div class="sys-row"><span class="k">磁盘信息</span><span class="v">无法获取</span></div>
    <?php endif; ?>
  </div>
</div>

<div class="sys-card" style="margin-top:16px">
  <h3><i class="fas fa-screwdriver-wrench"></i> 维护操作</h3>
  <div class="sys-row">
    <div>
      <div style="font-size:13px;font-weight:600;color:#0b1a33">重算用户已用容量</div>
      <div class="small" style="margin-top:2px">按 files 表 SUM(file_size) 校准 users.storage_used。</div>
    </div>
    <form method="POST" id="form-recalc">
      <input type="hidden" name="action" value="recalcStorage">
      <button type="button" class="btn btn-primary btn-sm" onclick="confirmSubmit('form-recalc', '确定重算所有用户的已用容量？这会覆盖当前值。', { title:'重算用量', icon:'calculator', okText:'确认重算' })"><i class="fas fa-calculator"></i> 立即重算</button>
    </form>
  </div>
  <div class="sys-row">
    <div>
      <div style="font-size:13px;font-weight:600;color:#0b1a33">清空回收站</div>
      <div class="small" style="margin-top:2px">当前回收站 <span class="mono" style="color:#dc2626"><?= $trashFiles ?></span> 项，彻底删除不可恢复。</div>
    </div>
    <a class="btn btn-warn btn-sm" href="<?= appUrl('admin_files') ?>"><i class="fas fa-broom"></i> 前往清空</a>
  </div>
  <div class="sys-row">
    <div>
      <div style="font-size:13px;font-weight:600;color:#0b1a33">修改站点名称 / 上传限制</div>
      <div class="small" style="margin-top:2px">全局设置在「系统设置」中调整。</div>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= appUrl('settings') ?>"><i class="fas fa-gear"></i> 前往设置</a>
  </div>
</div>

<div class="note"><i class="fas fa-info-circle"></i> PHP 限制如需修改，请编辑 php.ini 或在宝塔「PHP 8.3 配置」中调整。存储引擎切换请前往「系统设置」。</div>

<script>
// 通用：弹出确认后提交指定 form（替代原生 confirm）
function confirmSubmit(formId, msg, opts) {
  askConfirm(msg, function() { document.getElementById(formId).submit(); }, opts);
}
</script>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
