<?php
/**
 * 管理后台 · 额度管理
 * 管理员可一键设置每个用户的最大上传（storage_limit），或自定义 MB / 设为无限制
 * 取代原"升级空间"快捷入口（admin 侧栏）。
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

$pdo      = db();
$siteName = getSetting('site_name') ?: 'TangWo 网盘';
$flash    = ['type' => '', 'msg' => ''];

// 套餐档位（与 dashboardupgrade.php $PLANS 保持一致）
$PLANS = [
    'v0' => ['name' => '免费版',  'storage' => 2  * 1073741824],
    'v1' => ['name' => '基础版',  'storage' => 10 * 1073741824],
    'v2' => ['name' => '进阶版',  'storage' => 30 * 1073741824],
    'v3' => ['name' => '旗舰版',  'storage' => 100 * 1073741824],
];

// ============ POST 处理 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'setQuota') {
        $uid = (int) ($_POST['id'] ?? 0);
        $mode = $_POST['mode'] ?? '';   // 'plan' | 'custom_mb' | 'unlimited'
        $newBytes = 0;

        if ($mode === 'plan') {
            $planKey = $_POST['plan'] ?? '';
            if (!isset($PLANS[$planKey])) {
                $flash = ['type' => 'error', 'msg' => '未知套餐'];
            } else {
                $newBytes = $PLANS[$planKey]['storage'];
            }
        } elseif ($mode === 'custom_mb') {
            $mb = (float) ($_POST['mb'] ?? 0);
            if ($mb < 0) $mb = 0;
            $newBytes = (int) round($mb * 1048576);
        } elseif ($mode === 'unlimited') {
            $newBytes = 0;
        } else {
            $flash = ['type' => 'error', 'msg' => '未知模式'];
        }

        if ($uid > 0 && empty($flash['msg'])) {
            $pdo->prepare('UPDATE users SET storage_limit = ? WHERE id = ?')->execute([$newBytes, $uid]);
            $flash = ['type' => 'success', 'msg' => '已更新用户配额'];
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['admin_flash'] = $flash;
    header('Location: ' . appUrl('admin_quotas'));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

// ============ 用户列表（含用量） ============
$users = $pdo->query(
    'SELECT id, username, contact, storage_used, storage_limit, created_at FROM users ORDER BY id ASC LIMIT 500'
)->fetchAll();

$pageTitle = '额度管理';
$activeNav = 'quotas';
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
      <input type="text" id="quotaSearch" placeholder="搜索用户名...">
    </div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="location.reload()" title="刷新列表"><i class="fas fa-rotate-right"></i></button>
    <span class="toolbar-tip">为每个用户设置最大上传容量（0 = 不限）</span>
  </div>
  <table id="quotaTable">
    <thead>
      <tr>
        <th>ID</th><th>用户名</th><th>已用 / 配额</th><th>使用率</th><th style="text-align:right">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
        <tr class="empty-row"><td colspan="5">暂无用户</td></tr>
      <?php else: foreach ($users as $u):
        $used  = (int) $u['storage_used'];
        $limit = (int) $u['storage_limit'];
        $pct   = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
        $bar   = $pct >= 90 ? 'full' : ($pct >= 70 ? 'warn' : '');
        $uid   = (int) $u['id'];
        $isAdmin = $uid === 1;
      ?>
      <tr data-search="<?= h($u['username']) ?>">
        <td class="mono"><?= $uid ?></td>
        <td>
          <?= h($u['username']) ?>
          <?php if ($isAdmin): ?><span class="badge badge-admin"><i class="fas fa-crown"></i> 管理员</span><?php endif; ?>
        </td>
        <td class="small mono">
          <?= formatSize($used) ?> / <?= $limit > 0 ? formatSize($limit) : '<span style="color:#16a34a">无限制</span>' ?>
        </td>
        <td>
          <?php if ($limit > 0): ?>
            <div class="quota-bar"><div class="quota-fill <?= $bar ?>" style="width:<?= $pct ?>%"></div></div>
            <div class="small mono" style="margin-top:3px"><?= $pct ?>%</div>
          <?php else: ?>
            <span class="small" style="color:#94a3b8">—</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="row-actions quota-actions">
            <div class="quota-ops">
              <button class="btn btn-primary btn-sm" onclick="openCustomQuota(<?= $uid ?>, '<?= h(addslashes($u['username'])) ?>')"><i class="fas fa-sliders"></i> 自定义</button>
              <form method="POST" id="form-unlimited-<?= $uid ?>" style="display:inline">
                <input type="hidden" name="action" value="setQuota">
                <input type="hidden" name="id" value="<?= $uid ?>">
                <input type="hidden" name="mode" value="unlimited">
                <button type="button" class="btn btn-warn btn-sm" onclick="askUnlimited('<?= h(addslashes($u['username'])) ?>', <?= $uid ?>)"><i class="fas fa-infinity"></i> 无限制</button>
              </form>
            </div>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="note">
  <i class="fas fa-lightbulb"></i>
  <strong>说明：</strong>点击「自定义」可填任意 MB 数（1024 MB = 1 GB；填 0 等同无限制）；「无限制」按钮将容量直接归零，撤销限速。管理员（含当前登录的你自己）也支持调整。
  普通用户自己在「个人中心 → 套餐权限」会看到套餐档展示，但最终生效值仍以本页为准。
</div>

<!-- ============ 自定义额度 Modal ============ -->
<div class="modal-mask" id="modal-custom">
  <div class="modal">
    <div class="modal-head">
      <h2><i class="fas fa-sliders" style="color:#3b82f6;margin-right:6px"></i>自定义额度</h2>
      <button class="modal-close" onclick="closeModal('modal-custom')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="setQuota">
      <input type="hidden" name="mode" value="custom_mb">
      <input type="hidden" name="id" id="custom-id">
      <div class="modal-body">
        <div class="form-row">
          <label>用户</label>
          <input type="text" id="custom-username" disabled style="background:#f8fafc">
        </div>
        <div class="form-row">
          <label>容量（MB）<span class="hint">填 0 表示无限制；1024 MB = 1 GB</span></label>
          <input type="number" name="mb" min="0" step="1" value="0" required>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modal-custom')">取消</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> 确定</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCustomQuota(uid, username) {
  document.getElementById('custom-id').value = uid;
  document.getElementById('custom-username').value = username;
  openModal('modal-custom');
}
function askUnlimited(username, uid) {
  askConfirm('确定将「' + username + '」设为无限制？该用户将不再有容量上限。', function() {
    document.getElementById('form-unlimited-' + uid).submit();
  }, { title: '设为无限制', icon: 'infinity', danger: true, okText: '确认无限制' });
}
bindTableSearch('quotaSearch', 'quotaTable');
</script>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
