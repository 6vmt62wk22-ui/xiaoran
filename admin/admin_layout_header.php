<?php
/**
 * 管理后台 · 共用布局头（侧栏 + 顶栏 + 全局样式）
 * 调用前需在调用方已 require 'functions.php'，并通过鉴权：
 *   if (!isLogin() || currentUid() != 1) { exit('权限不足'); }
 * 页面需定义：$pageTitle（string）、$activeNav（string：overview|users|files|quotas|system|settings|pricing）
 */
if (!isset($pageTitle)) $pageTitle = '管理后台';
if (!isset($activeNav)) $activeNav = '';
$siteName = getSetting('site_name') ?: 'TangWo 网盘';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> · <?= h($siteName) ?></title>
  <link rel="stylesheet" href="assets/vendor/font-awesome/all.min.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
      background: #f6f9fc;
      min-height: 100vh;
      display: flex;
      color: #1e293b;
    }
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; }

    /* ============ 侧栏 ============ */
    .sidebar {
      width: 240px; min-width: 240px;
      background: #fafcff;
      border-right: 1px solid rgba(0,0,0,0.04);
      padding: 28px 16px 24px;
      display: flex; flex-direction: column;
      position: sticky; top: 0; height: 100vh;
    }
    .sidebar-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; padding: 0 4px; }
    .sidebar-brand .icon { width: 40px; height: 40px; border-radius: 10px; background: #3b82f6; display: grid; place-items: center; color: #fff; font-size: 18px; }
    .sidebar-brand .name { font-size: 16px; font-weight: 700; color: #0b1a33; line-height: 1.2; }
    .sidebar-brand .role { font-size: 11px; color: #94a3b8; margin-top: 2px; }

    .nav-section { font-size: 11px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; margin: 20px 0 8px 12px; }
    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border-radius: 10px;
      color: #4a5a72; font-size: 14px; font-weight: 500;
      transition: all .15s ease; cursor: pointer; border: none; background: none;
      width: 100%; text-align: left; margin-bottom: 2px;
    }
    .nav-item i { width: 20px; font-size: 16px; color: #6b7b93; transition: color .15s ease; }
    .nav-item:hover { background: #eef4ff; color: #1a2c4a; }
    .nav-item:hover i { color: #3b82f6; }
    .nav-item.active { background: #eef4ff; color: #1a2c4a; font-weight: 600; }
    .nav-item.active i { color: #3b82f6; }

    .sidebar-spacer { flex: 1; }
    .sidebar-footer { border-top: 1px solid rgba(0,0,0,0.06); padding-top: 16px; }
    .sidebar-footer a { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; color: #94a3b8; font-size: 13px; }
    .sidebar-footer a:hover { background: #fee9e9; color: #dc2626; }
    .sidebar-footer a:hover i { color: #dc2626; }

    /* ============ 主区域 ============ */
    .main { flex: 1; overflow-x: hidden; min-width: 0; }
    .topbar {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 32px; background: #fff; border-bottom: 1px solid rgba(0,0,0,0.04);
      position: sticky; top: 0; z-index: 10;
    }
    .topbar h1 { font-size: 20px; font-weight: 700; color: #0b1a33; }
    .topbar .actions { display: flex; gap: 10px; }
    .btn {
      padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 600;
      display: inline-flex; align-items: center; gap: 6px;
      transition: all .15s; border: none; cursor: pointer; line-height: 1.2;
    }
    .btn-ghost { background: #f1f5f9; color: #334155; }
    .btn-ghost:hover { background: #e2e8f0; }
    .btn-primary { background: #3b82f6; color: #fff; }
    .btn-primary:hover { background: #2563eb; }
    .btn-danger { background: #fee2e2; color: #dc2626; }
    .btn-danger:hover { background: #fecaca; }
    .btn-success { background: #dcfce7; color: #16a34a; }
    .btn-success:hover { background: #bbf7d0; }
    .btn-warn { background: #fef3c7; color: #d97706; }
    .btn-warn:hover { background: #fde68a; }
    .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }

    .content { padding: 28px 32px; }

    /* ============ 统计卡片 ============ */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .stat-card {
      background: #fff; border-radius: 16px; padding: 22px;
      border: 1px solid rgba(0,0,0,0.04);
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      transition: box-shadow .2s;
    }
    .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
    .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; font-size: 18px; margin-bottom: 16px; }
    .stat-icon.blue   { background: #eff6ff; color: #3b82f6; }
    .stat-icon.green  { background: #dcfce7; color: #22c55e; }
    .stat-icon.amber  { background: #fef3c7; color: #f59e0b; }
    .stat-icon.red    { background: #fee2e2; color: #ef4444; }
    .stat-icon.purple { background: #f3e8ff; color: #a855f7; }
    .stat-icon.cyan   { background: #cffafe; color: #06b6d4; }
    .stat-num { font-size: 28px; font-weight: 700; color: #0b1a33; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .stat-lbl { font-size: 13px; color: #64748b; margin-top: 4px; }

    /* ============ 表格 ============ */
    .table-wrap { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,0.04); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .table-toolbar { display: flex; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid rgba(0,0,0,0.04); flex-wrap: wrap; }
    .toolbar-tip { font-size: 12.5px; color: #94a3b8; margin-left: auto; }
    .search-box { flex: 1; min-width: 200px; max-width: 360px; position: relative; }
    .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }
    .search-box input { width: 100%; padding: 8px 12px 8px 36px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .15s; }
    .search-box input:focus { border-color: #3b82f6; }
    .filter-chip { font-size: 12px; padding: 5px 12px; border-radius: 999px; background: #f1f5f9; color: #475569; cursor: pointer; border: 1px solid transparent; transition: all .15s; }
    .filter-chip:hover { background: #e2e8f0; }
    .filter-chip.active { background: #dbeafe; color: #2563eb; border-color: #93c5fd; }

    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; padding: 12px 20px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid rgba(0,0,0,0.06); white-space: nowrap; background: #f8fafc; }
    tbody td { padding: 14px 20px; font-size: 13px; color: #1e293b; border-bottom: 1px solid rgba(0,0,0,0.04); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover { background: #f8fafc; }
    .mono { font-variant-numeric: tabular-nums; }
    .ellipsis { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: middle; }
    .small { font-size: 12px; color: #64748b; }
    .empty-row td { text-align: center; padding: 40px; color: #94a3b8; font-size: 14px; }

    .badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
    .badge-admin { background: #dbeafe; color: #2563eb; }
    .badge-active { background: #dcfce7; color: #16a34a; }
    .badge-trash { background: #fee2e2; color: #dc2626; }
    .badge-folder { background: #fef3c7; color: #d97706; }

    .row-actions { display: flex; gap: 6px; }
    .row-actions form { display: inline; }

    /* ============ 额度管理：操作分组 ============ */
    .quota-actions { justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
    .quota-ops { display: inline-flex; gap: 6px; }

    .quota-bar { width: 100px; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 4px; }
    .quota-fill { height: 100%; background: #3b82f6; border-radius: 999px; }
    .quota-fill.warn { background: #f59e0b; }
    .quota-fill.full { background: #ef4444; }

    /* ============ 系统 / 信息卡片 ============ */
    .sys-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
    .sys-card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,0.04); padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .sys-card h3 { font-size: 14px; font-weight: 700; color: #0b1a33; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .sys-card h3 i { color: #3b82f6; }
    .sys-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,0.04); }
    .sys-row:last-child { border-bottom: none; }
    .sys-row .k { font-size: 13px; color: #64748b; }
    .sys-row .v { font-size: 13px; font-weight: 600; color: #0b1a33; font-variant-numeric: tabular-nums; }

    .disk-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 8px; }
    .disk-fill { height: 100%; border-radius: 999px; transition: width .6s ease; }

    .note { font-size: 12px; color: #94a3b8; margin-top: 16px; padding: 12px 16px; background: #f8fafc; border-radius: 10px; border: 1px solid rgba(0,0,0,0.04); }
    .note i { color: #3b82f6; margin-right: 6px; }

    /* ============ 表单 / Modal ============ */
    .form-row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .form-row label { font-size: 12px; color: #475569; font-weight: 600; }
    .form-row input, .form-row select, .form-row textarea {
      padding: 9px 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .15s; font-family: inherit; background: #fff; color: #1e293b;
    }
    .form-row input:focus, .form-row select:focus, .form-row textarea:focus { border-color: #3b82f6; }
    .form-row .hint { font-size: 11px; color: #94a3b8; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    .modal-mask {
      position: fixed; inset: 0; background: rgba(15,23,42,.45);
      display: none; align-items: center; justify-content: center; z-index: 100;
      backdrop-filter: blur(2px);
    }
    .modal-mask.open { display: flex; }
    .modal {
      background: #fff; border-radius: 16px; width: min(520px, calc(100vw - 32px));
      box-shadow: 0 20px 60px rgba(0,0,0,.18);
      max-height: calc(100vh - 64px); overflow: hidden; display: flex; flex-direction: column;
    }
    .modal-head { padding: 18px 22px; border-bottom: 1px solid rgba(0,0,0,.06); display: flex; align-items: center; justify-content: space-between; }
    .modal-head h2 { font-size: 16px; font-weight: 700; color: #0b1a33; }
    .modal-close { background: none; border: none; font-size: 18px; color: #94a3b8; cursor: pointer; padding: 4px 8px; border-radius: 8px; }
    .modal-close:hover { background: #f1f5f9; color: #1e293b; }
    .modal-body { padding: 20px 22px; overflow-y: auto; }
    .modal-foot { padding: 14px 22px; border-top: 1px solid rgba(0,0,0,.06); display: flex; justify-content: flex-end; gap: 10px; }

    .alert { padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: #dcfce7; color: #166534; }
    .alert-error   { background: #fee2e2; color: #991b1b; }
    .alert-info    { background: #dbeafe; color: #1e40af; }

    /* ============ 响应式 ============ */
    @media (max-width: 900px) {
      .sidebar { width: 200px; min-width: 200px; }
    }
    @media (max-width: 768px) {
      .sidebar { display: none; }
      .content { padding: 16px; }
      .topbar { padding: 14px 16px; }
      .table-wrap { overflow-x: auto; }
      table { min-width: 700px; }
      .stats { grid-template-columns: repeat(2, 1fr); }
      .form-grid-2 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ============ 侧栏 ============ -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="icon"><i class="fas fa-shield-alt"></i></div>
    <div>
      <div class="name"><?= h($siteName) ?></div>
      <div class="role">管理后台</div>
    </div>
  </div>

  <div class="nav-section">管理</div>
  <a class="nav-item <?= $activeNav==='overview'?'active':'' ?>" href="<?= appUrl('dashboardadmin') ?>"><i class="fas fa-chart-pie"></i> 概览</a>
  <a class="nav-item <?= $activeNav==='users'?'active':'' ?>" href="<?= appUrl('dashboardadminusers') ?>"><i class="fas fa-users"></i> 用户管理</a>
  <a class="nav-item <?= $activeNav==='files'?'active':'' ?>" href="<?= appUrl('dashboardadminfiles') ?>"><i class="fas fa-folder-tree"></i> 文件管理</a>
  <a class="nav-item <?= $activeNav==='system'?'active':'' ?>" href="<?= appUrl('dashboardadminsystem') ?>"><i class="fas fa-server"></i> 系统信息</a>
  <a class="nav-item <?= $activeNav==='settings'?'active':'' ?>" href="<?= appUrl('dashboardadminsettings') ?>"><i class="fas fa-plug"></i> 接口配置</a>
  <a class="nav-item <?= $activeNav==='pricing'?'active':'' ?>" href="<?= appUrl('dashboardadminpricing') ?>"><i class="fas fa-yen-sign"></i> 套餐定价</a>

  <div class="nav-section">快捷</div>
  <a class="nav-item" href="<?= appUrl('dashboardadminquotas') ?>"><i class="fas fa-gear"></i> 配额管理</a>

  <div class="sidebar-spacer"></div>
  <div class="sidebar-footer">
    <a href="<?= authUrl('logout') ?>"><i class="fas fa-right-from-bracket"></i> 退出登录</a>
  </div>
</aside>

<!-- ============ 主区域 ============ -->
<div class="main">
  <div class="topbar">
    <h1><?= h($pageTitle) ?></h1>
    <div class="actions">
      <a class="btn btn-ghost" href="<?= appUrl('dashboard') ?>"><i class="fas fa-arrow-left"></i> 返回网盘</a>
      <a class="btn btn-primary" href="<?= appUrl('settings') ?>"><i class="fas fa-gear"></i> 系统设置</a>
    </div>
  </div>
  <div class="content">
