<?php
// 图形验证码端点（点击刷新按钮时调用）
require_once __DIR__ . '/../functions.php';

issueCaptcha(); // 重新生成

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
echo renderCaptchaSvg();