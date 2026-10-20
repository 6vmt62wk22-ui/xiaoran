<?php
/**
 * 管理后台 · 概览（统计 + 今日动态 + 存储概况 + 站点信息）
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

$pdo = db();
$siteName = getSetting('site_name') ?: 'TangWo 网盘';

$totalUsers     = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeFiles    = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE status = 1 AND type = 'file'")->fetchColumn();
$activeFolders  = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE status = 1 AND type = 'folder'")->fetchColumn();
$trashFiles     = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE status = 0")->fetchColumn();
$totalStorage   = (int) $pdo->query("SELECT COALESCE(SUM(file_size), 0) FROM files WHERE status = 1 AND type = 'file'")->fetchColumn();
$todayUploads   = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE status = 1 AND type = 'file' AND DATE(created_at) = CURDATE()")->fetchColumn();
$totalDownloads = (int) $pdo->query('SELECT COALESCE(SUM(downloads), 0) FROM files WHERE status = 1')->fetchColumn();

$schemaVer  = getSetting('schema_version', '1001');
$storageDrv = getSetting('storage_driver', 'local');
$diskFree   = @disk_free_space(dirname(__DIR__));
$diskTotal  = @disk_total_space(dirname(__DIR__));

$pageTitle = '管理后台';
$activeNav = 'overview';
require __DIR__ . '/admin_layout_header.php';
?>

<div class="stats">
  <div class="stat-card">
    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
    <div class="stat-num mono"><?= $totalUsers ?></div>
    <div class="stat-lbl">注册用户</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-file"></i></div>
    <div class="stat-num mono"><?= $activeFiles ?></div>
    <div class="stat-lbl">有效文件</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon amber"><i class="fas fa-folder"></i></div>
    <div class="stat-num mono"><?= $activeFolders ?></div>
    <div class="stat-lbl">文件夹</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><i class="fas fa-trash-alt"></i></div>
    <div class="stat-num mono"><?= $trashFiles ?></div>
    <div class="stat-lbl">回收站</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="fas fa-database"></i></div>
    <div class="stat-num mono"><?= formatSize($totalStorage) ?></div>
    <div class="stat-lbl">总存储</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon cyan"><i class="fas fa-download"></i></div>
    <div class="stat-num mono"><?= $totalDownloads ?></div>
    <div class="stat-lbl">总下载</div>
  </div>
</div>

<div class="sys-grid">
  <div class="sys-card">
    <h3><i class="fas fa-chart-line"></i> 今日动态</h3>
    <div class="sys-row"><span class="k">今日上传</span><span class="v mono"><?= $todayUploads ?> 个文件</span></div>
    <div class="sys-row"><span class="k">总下载次数</span><span class="v mono"><?= $totalDownloads ?></span></div>
    <div class="sys-row"><span class="k">回收站文件</span><span class="v mono"><?= $trashFiles ?></span></div>
  </div>
  <div class="sys-card">
    <h3><i class="fas fa-hdd"></i> 存储概况</h3>
    <div class="sys-row"><span class="k">已用存储</span><span class="v mono"><?= formatSize($totalStorage) ?></span></div>
    <div class="sys-row"><span class="k">有效文件</span><span class="v mono"><?= $activeFiles ?></span></div>
    <div class="sys-row"><span class="k">文件夹</span><span class="v mono"><?= $activeFolders ?></span></div>
  </div>
  <div class="sys-card">
    <h3><i class="fas fa-info-circle"></i> 站点信息</h3>
    <div class="sys-row"><span class="k">站点名称</span><span class="v"><?= h($siteName) ?></span></div>
    <div class="sys-row"><span class="k">Schema 版本</span><span class="v mono"><?= h($schemaVer) ?></span></div>
    <div class="sys-row"><span class="k">存储引擎</span><span class="v"><?= h($storageDrv) ?></span></div>
    <?php if ($diskFree !== false && $diskTotal !== false): $usedPct = $diskTotal > 0 ? round(($diskTotal - $diskFree) / $diskTotal * 100) : 0; ?>
    <div class="sys-row"><span class="k">磁盘可用</span><span class="v mono"><?= formatSize($diskFree) ?> / <?= formatSize($diskTotal) ?> (<?= $usedPct ?>%)</span></div>
    <?php endif; ?>
  </div>
</div>

<div class="note"><i class="fas fa-lightbulb"></i> 列表仅展示最近 200 条记录。如需修改站点名称、上传限制等，请前往「系统设置」；要批量调整用户额度，请到「额度管理」。</div>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
