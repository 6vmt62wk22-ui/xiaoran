<?php
/**
 * 管理后台 · 套餐定价（独立页）
 * 与「接口配置 → 支付网关 → 套餐定价」段一致：plan_v1/v2/v3_price（月价，分）
 * 改后立即生效：/dashboardupgrade 页 PayManager::plans() 会重读。
 */
require_once __DIR__ . '/../functions.php';
if (!isLogin() || currentUid() != 1) { exit('权限不足'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'savePricing') {
    foreach (['plan_v1_price', 'plan_v2_price', 'plan_v3_price'] as $k) {
        if (isset($_POST[$k])) {
            setSetting($k, trim((string)$_POST[$k]));
        }
    }
    $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => '套餐定价已保存'];
    redirect(appUrl('dashboardadminpricing'));
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$pageTitle = '套餐定价';
$activeNav = 'pricing';
require __DIR__ . '/admin_layout_header.php';

$v = fn(string $k, string $d = '') => h((string)(getSetting($k) ?: $d));
$plans = [
    'v1' => ['name' => '10GB 套餐',  'key' => 'plan_v1_price', 'default' => '600',  'quota' => '10 GB'],
    'v2' => ['name' => '30GB 套餐',  'key' => 'plan_v2_price', 'default' => '1200', 'quota' => '30 GB'],
    'v3' => ['name' => '100GB 套餐', 'key' => 'plan_v3_price', 'default' => '1800', 'quota' => '100 GB'],
];
?>

<style>
.pricing-card { background:#fff; border:1px solid rgba(0,0,0,.06); border-radius:14px; padding:22px 24px; margin-bottom:18px; }
.pricing-card h3 { font-size:14px; font-weight:700; color:#0b1a33; margin:0 0 4px; display:flex; align-items:center; gap:8px; }
.pricing-card h3 i { color:#3b82f6; }
.pricing-card .desc { font-size:12px; color:#94a3b8; margin:0 0 16px; line-height:1.6; }
.pricing-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
.pricing-item { background:linear-gradient(180deg,#f8fbff,#f1f5f9); border:1px solid rgba(59,130,246,.15); border-radius:12px; padding:18px 18px 16px; text-align:center; transition:all .15s; }
.pricing-item:hover { border-color:#3b82f6; box-shadow:0 4px 14px rgba(59,130,246,.1); }
.pricing-item .badge { display:inline-block; font-size:11px; font-weight:700; color:#3b82f6; background:#eff6ff; border-radius:999px; padding:3px 10px; margin-bottom:10px; }
.pricing-item .quota { font-size:14px; font-weight:700; color:#0b1a33; margin-bottom:14px; }
.pricing-item label { display:block; font-size:11px; font-weight:600; color:#64748b; text-align:left; margin-bottom:6px; }
.pricing-item .ipw { position:relative; }
.pricing-item .ipw input { width:100%; padding:10px 12px 10px 28px; border:1px solid #e2e8f0; border-radius:10px; font-size:15px; font-weight:600; color:#1e293b; background:#fff; box-sizing:border-box; font-family:inherit; text-align:center; }
.pricing-item .ipw input:focus { outline:none; border-color:#3b82f6; }
.pricing-item .ipw .unit { position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:12px; color:#94a3b8; font-weight:600; }
.pricing-item .ipw .yuan { position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:12px; color:#94a3b8; font-weight:600; }
.pricing-item .preview { font-size:11px; color:#94a3b8; margin-top:8px; line-height:1.5; }
.pricing-tips { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; font-size:12px; color:#92400e; line-height:1.65; margin-bottom:18px; }
.pricing-tips b { color:#78350f; }
.save-bar { position:sticky; bottom:0; background:linear-gradient(180deg,transparent,#f4f6fa 30%); padding:16px 0 4px; display:flex; gap:10px; }
.btn-save { background:#3b82f6; color:#fff; border:none; border-radius:10px; padding:10px 26px; font-size:13px; font-weight:600; cursor:pointer; }
.btn-save:hover { background:#2563eb; }
.btn-ghost-s { background:#fff; color:#475569; border:1px solid #e2e8f0; border-radius:10px; padding:10px 18px; font-size:13px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
@media (max-width:780px) { .pricing-grid { grid-template-columns:1fr; } }
</style>

<?php if ($flash): ?>
<div style="background:<?= $flash['type']==='ok'?'#f0fdf4':'#fef2f2' ?>;border:1px solid <?= $flash['type']==='ok'?'#bbf7d0':'#fecaca' ?>;color:<?= $flash['type']==='ok'?'#166534':'#991b1b' ?>;padding:12px 18px;border-radius:12px;margin-bottom:18px;font-size:13px">
    <?= h($flash['text']) ?>
</div>
<?php endif; ?>

<!-- 顶部说明 -->
<div class="pricing-card">
    <h3><i class="fas fa-tags"></i> 套餐定价（元/月）</h3>
    <p class="desc">
        升级空间页三个套餐的<b style="color:#0b1a33">月价</b>，单位为<b style="color:#0b1a33">分</b>（如 600 = ¥6/月）。
        实际支付额 = 月价 × 月数 × 时长折扣（6 个月 9 折、1 年 8 折）。配额固定 <b style="color:#0b1a33">10GB / 30GB / 100GB</b>。
        保存后立即在「<a href="<?= appUrl('dashboard') ?>?view=upgrade ?>" style="color:#3b82f6">升级空间</a>」页生效。
    </p>

    <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="savePricing">

        <div class="pricing-grid">
            <?php foreach ($plans as $p): ?>
            <div class="pricing-item">
                <span class="badge"><?= h($p['quota']) ?></span>
                <div class="quota"><?= h($p['name']) ?></div>
                <label>套餐月价（分）</label>
                <div class="ipw">
                    <span class="unit">¥</span>
                    <input name="<?= h($p['key']) ?>" value="<?= $v($p['key'], $p['default']) ?>" placeholder="<?= h($p['default']) ?>" inputmode="numeric" pattern="[0-9]*" />
                    <span class="yuan">分</span>
                </div>
                <div class="preview">
                    <?php
                        $now = (int)(getSetting($p['key']) ?: $p['default']);
                        $yuan = number_format($now / 100, 2);
                    ?>
                    现行 = <b style="color:#0b1a33">¥<?= $yuan ?></b> / 月
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="pricing-tips" style="margin-top:18px">
            <b>提示：</b>
            输入整数（分），最低 1（= ¥0.01/月），建议 100 以上。
            月价 = 0 表示免费试用；建议三档价格<b>递增</b>（如 v3 ≥ v2 ≥ v1）以引导用户升级大套餐。
            修改后请到「<a href="<?= appUrl('dashboardadminsettings') ?>?tab=pay" style="color:#b45309">接口配置 → 支付网关</a>」确认至少启用了一个支付渠道，否则用户付款页面会空白。
        </div>

        <div class="save-bar">
            <button type="submit" class="btn-save"><i class="fas fa-check"></i> 保存定价</button>
            <a class="btn-ghost-s" href="<?= appUrl('dashboardadminsettings') ?>"><i class="fas fa-plug"></i> 接口配置</a>
            <a class="btn-ghost-s" href="<?= appUrl('dashboard') ?>?view=upgrade ?>"><i class="fas fa-eye"></i> 预览升级页</a>
        </div>
    </form>
</div>

<!-- 历史价/对比（只读） -->
<div class="pricing-card">
    <h3><i class="fas fa-clock-rotate-left"></i> 生效预览</h3>
    <p class="desc">按当前保存的月价计算三档套餐的支付总额（含时长折扣）。</p>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#f8fafc;color:#475569;font-weight:600">
                <th style="padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0">套餐</th>
                <th style="padding:10px 12px;text-align:right;border-bottom:1px solid #e2e8f0">1 个月</th>
                <th style="padding:10px 12px;text-align:right;border-bottom:1px solid #e2e8f0">3 个月</th>
                <th style="padding:10px 12px;text-align:right;border-bottom:1px solid #e2e8f0">6 个月（9 折）</th>
                <th style="padding:10px 12px;text-align:right;border-bottom:1px solid #e2e8f0">1 年（8 折）</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($plans as $pk => $p):
                $price = (int)(getSetting($p['key']) ?: $p['default']);
                $c1 = $price * 1;
                $c3 = $price * 3;
                $c6 = round($price * 6 * 0.9);
                $c12 = round($price * 12 * 0.8);
            ?>
            <tr>
                <td style="padding:12px;border-bottom:1px solid #f1f5f9"><b><?= h($p['name']) ?></b></td>
                <td style="padding:12px;text-align:right;border-bottom:1px solid #f1f5f9;font-variant-numeric:tabular-nums">¥<?= number_format($c1/100, 2) ?></td>
                <td style="padding:12px;text-align:right;border-bottom:1px solid #f1f5f9;font-variant-numeric:tabular-nums">¥<?= number_format($c3/100, 2) ?></td>
                <td style="padding:12px;text-align:right;border-bottom:1px solid #f1f5f9;font-variant-numeric:tabular-nums;color:#16a34a">¥<?= number_format($c6/100, 2) ?></td>
                <td style="padding:12px;text-align:right;border-bottom:1px solid #f1f5f9;font-variant-numeric:tabular-nums;color:#16a34a">¥<?= number_format($c12/100, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/admin_layout_footer.php'; ?>
