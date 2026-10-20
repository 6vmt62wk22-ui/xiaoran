<?php
/**
 * 兼容入口：旧版独立注册页（无验证码保护）已废弃
 * 统一收敛到 dashboard.php 的访客态（含图形验证码、手机号/邮箱验证）
 */
require_once 'functions.php';
if (isLogin()) {
    redirect(appUrl('dashboard'));
}
redirect(appUrl('dashboard') . (str_contains(appUrl('dashboard'), '?') ? '&' : '?') . 'tab=register');
