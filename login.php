<?php
/**
 * 兼容入口：旧版独立登录页（无验证码保护）已废弃
 * 统一收敛到 dashboard.php 的访客态（含图形验证码、手机/邮箱验证）
 * 已登录用户直接去 dashboard，未登录用户去 dashboard 看到登录/注册表单
 */
require_once 'functions.php';
if (isLogin()) {
    redirect(appUrl('dashboard'));
}
redirect(appUrl('dashboard') . (str_contains(appUrl('dashboard'), '?') ? '&' : '?') . 'tab=login');