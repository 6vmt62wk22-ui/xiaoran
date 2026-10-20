<?php
/**
 * 管理后台 · 用户管理（列表 + 新建 + 编辑 + 删除 + 重置密码）
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

$pdo      = db();
$siteName = getSetting('site_name') ?: 'TangWo 网盘';
$flash    = ['type' => '', 'msg' => ''];

// ============ POST 处理 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'createUser') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $contact  = trim($_POST['contact'] ?? '');
        $limitGB  = (float) ($_POST['storage_gb'] ?? 2);

        if ($username === '' || strlen($password) < 6) {
            $flash = ['type' => 'error', 'msg' => '用户名不能为空，密码至少 6 位'];
        } else {
            $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $exists->execute([$username]);
            if ($exists->fetch()) {
                $flash = ['type' => 'error', 'msg' => '用户名已存在'];
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $bytes = (int) round($limitGB * 1073741824);
                $pdo->prepare('INSERT INTO users (username, password, contact, storage_limit, storage_used) VALUES (?,?,?,?,0)')
                    ->execute([$username, $hash, $contact ?: null, $bytes]);
                $flash = ['type' => 'success', 'msg' => '已创建用户「' . h($username) . '」'];
            }
        }
    }

    elseif ($act === 'updateUser') {
        $uid      = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $contact  = trim($_POST['contact'] ?? '');
        $limitGB  = (float) ($_POST['storage_gb'] ?? 2);
        $newpw    = (string) ($_POST['new_password'] ?? '');

        if ($uid < 2 || $username === '') {
            $flash = ['type' => 'error', 'msg' => '参数无效'];
        } else {
            // 用户名查重
            $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $dup->execute([$username, $uid]);
            if ($dup->fetch()) {
                $flash = ['type' => 'error', 'msg' => '用户名已被其他账号占用'];
            } else {
                $bytes = (int) round($limitGB * 1073741824);
                if ($newpw !== '') {
                    if (strlen($newpw) < 6) {
                        $flash = ['type' => 'error', 'msg' => '新密码至少 6 位'];
                    } else {
                        $hash = password_hash($newpw, PASSWORD_BCRYPT);
                        $pdo->prepare('UPDATE users SET username=?, contact=?, storage_limit=?, password=? WHERE id=?')
                            ->execute([$username, $contact ?: null, $bytes, $hash, $uid]);
                        $flash = ['type' => 'success', 'msg' => '已更新用户资料与密码'];
                    }
                } else {
                    $pdo->prepare('UPDATE users SET username=?, contact=?, storage_limit=? WHERE id=?')
                        ->execute([$username, $contact ?: null, $bytes, $uid]);
                    $flash = ['type' => 'success', 'msg' => '已更新用户资料'];
                }
            }
        }
    }

    elseif ($act === 'delUser') {
        $uid = (int) ($_POST['id'] ?? 0);
        if ($uid > 0 && $uid != 1) {
            $owned = $pdo->prepare("SELECT file_key FROM files WHERE user_id = ? AND status = 1 AND type = 'file'");
            $owned->execute([$uid]);
            $keys = $owned->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            foreach ($keys as $key) {
                if (fileRefCount($key) === 0) {
                    Storage::instance()->delete($key);
                }
            }
            $flash = ['type' => 'success', 'msg' => '已删除用户（其文件已清理）'];
        }
    }

    // PRG：写入 flash 到 session，重定向到 GET
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['admin_flash'] = $flash;
    header('Location: ' . appUrl('admin_users'));
    exit;
}

// 读取 flash
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

// ============ 用户列表 ============
$users = $pdo->query('SELECT * FROM users ORDER BY id ASC LIMIT 500')->fetchAll();
$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$pageTitle = '用户管理';
$activeNav = 'users';
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
      <input type="text" id="userSearch" placeholder="搜索用户名、联系方式...">
    </div>
    <span class="small">共 <?= $totalUsers ?> 人</span>
    <div style="margin-left:auto;display:flex;gap:8px">
      <a class="btn btn-primary" href="<?= appUrl('admin_quotas') ?>"><i class="fas fa-hard-drive"></i> 批量调额度</a>
      <button class="btn btn-success" onclick="openModal('modal-create')"><i class="fas fa-plus"></i> 新建用户</button>
    </div>
  </div>
  <table id="userTable">
    <thead>
      <tr>
        <th>ID</th><th>用户名</th><th>联系方式</th><th>配额使用</th><th>注册时间</th><th style="text-align:right">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
        <tr class="empty-row"><td colspan="6">暂无用户</td></tr>
      <?php else: foreach ($users as $u):
        $used  = (int) $u['storage_used'];
        $limit = (int) $u['storage_limit'];
        $pct   = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
        $bar   = $pct >= 90 ? 'full' : ($pct >= 70 ? 'warn' : '');
        $searchAttr = h($u['username'] . ' ' . ($u['contact'] ?? ''));
      ?>
      <tr data-search="<?= $searchAttr ?>">
        <td class="mono"><?= $u['id'] ?></td>
        <td>
          <?= h($u['username']) ?>
          <?php if ($u['id'] == 1): ?><span class="badge badge-admin"><i class="fas fa-crown"></i> 管理员</span><?php endif; ?>
        </td>
        <td class="small"><?= h($u['contact'] ?? '') ?: '—' ?></td>
        <td>
          <div class="mono small"><?= formatSize($used) ?></div>
          <?php if ($limit > 0): ?>
          <div class="quota-bar"><div class="quota-fill <?= $bar ?>" style="width:<?= $pct ?>%"></div></div>
          <?php endif; ?>
        </td>
        <td class="small mono"><?= h(date('Y-m-d H:i', strtotime($u['created_at']))) ?></td>
        <td>
          <div class="row-actions" style="justify-content:flex-end">
            <?php if ($u['id'] == 1): ?>
              <span class="small">系统账号不可编辑</span>
            <?php else: ?>
              <button class="btn btn-ghost btn-sm" onclick='openEdit(<?= json_encode([
                  "id"           => (int) $u["id"],
                  "username"     => $u["username"],
                  "contact"      => $u["contact"] ?? "",
                  "storage_gb"   => $u["storage_limit"] > 0 ? round($u["storage_limit"] / 1073741824, 2) : 0,
              ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-pen"></i> 编辑</button>
              <form method="POST" id="form-deluser-<?= $u['id'] ?>">
                <input type="hidden" name="action" value="delUser">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="button" class="btn btn-danger btn-sm" onclick="confirmSubmit('form-deluser-<?= $u['id'] ?>', '确定删除用户「<?= h(addslashes($u['username'])) ?>」？其所有文件将一并删除，且不可恢复。', { title:'删除用户', icon:'trash', danger:true, okText:'确认删除' })"><i class="fas fa-trash"></i> 删除</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- ============ 新建用户 Modal ============ -->
<div class="modal-mask" id="modal-create">
  <div class="modal">
    <div class="modal-head">
      <h2><i class="fas fa-user-plus" style="color:#3b82f6;margin-right:6px"></i>新建用户</h2>
      <button class="modal-close" onclick="closeModal('modal-create')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="createUser">
      <div class="modal-body">
        <div class="form-row">
          <label>用户名 *</label>
          <input type="text" name="username" required maxlength="50" placeholder="字母数字下划线">
        </div>
        <div class="form-row">
          <label>密码 * <span class="hint">至少 6 位</span></label>
          <input type="text" name="password" required minlength="6" placeholder="初始登录密码">
        </div>
        <div class="form-row">
          <label>联系方式</label>
          <input type="text" name="contact" maxlength="191" placeholder="手机号或邮箱（可选）">
        </div>
        <div class="form-row">
          <label>初始额度（GB） <span class="hint">填 0 表示无限制</span></label>
          <input type="number" name="storage_gb" value="2" min="0" step="0.1">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modal-create')">取消</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> 创建</button>
      </div>
    </form>
  </div>
</div>

<!-- ============ 编辑用户 Modal ============ -->
<div class="modal-mask" id="modal-edit">
  <div class="modal">
    <div class="modal-head">
      <h2><i class="fas fa-user-pen" style="color:#3b82f6;margin-right:6px"></i>编辑用户</h2>
      <button class="modal-close" onclick="closeModal('modal-edit')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="updateUser">
      <input type="hidden" name="id" id="edit-id">
      <div class="modal-body">
        <div class="form-row">
          <label>用户名 *</label>
          <input type="text" name="username" id="edit-username" required maxlength="50">
        </div>
        <div class="form-row">
          <label>联系方式</label>
          <input type="text" name="contact" id="edit-contact" maxlength="191">
        </div>
        <div class="form-row">
          <label>存储额度（GB） <span class="hint">填 0 表示无限制</span></label>
          <input type="number" name="storage_gb" id="edit-storage" min="0" step="0.1">
        </div>
        <div class="form-row">
          <label>重置密码 <span class="hint">留空则不修改</span></label>
          <input type="text" name="new_password" minlength="6" placeholder="新密码（至少 6 位）">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modal-edit')">取消</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> 保存</button>
      </div>
    </form>
  </div>
</div>

<script>
// 通用：弹出确认后提交指定 form（替代原生 confirm）
function confirmSubmit(formId, msg, opts) {
  askConfirm(msg, function() { document.getElementById(formId).submit(); }, opts);
}

function openEdit(u) {
  document.getElementById('edit-id').value = u.id;
  document.getElementById('edit-username').value = u.username;
  document.getElementById('edit-contact').value = u.contact || '';
  document.getElementById('edit-storage').value = u.storage_gb;
  openModal('modal-edit');
}
bindTableSearch('userSearch', 'userTable');
</script>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
