<?php
/**
 * 发送邮箱/短信验证码（注册流程）
 * POST: target=邮箱或手机号
 * 限流：60 秒/目标（VerifyCode 内控）+ 单 IP 10 分钟内最多 5 次
 */
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json; charset=utf-8');
requireNodeRole('auth');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false, 'message' => '无效请求']));
}

$target = trim((string)($_POST['target'] ?? ''));
if ($target === '') {
    exit(json_encode(['success' => false, 'message' => '请输入邮箱或手机号']));
}

// IP 级限流（防刷短信：恶意刷别人的手机号会产生真实费用）
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$stmt = db()->prepare("SELECT COUNT(*) FROM verify_codes WHERE ip = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
VerifyCode::ensureTable();
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() >= 5) {
    exit(json_encode(['success' => false, 'message' => '操作过于频繁，请 10 分钟后再试']));
}

// 按目标类型判断服务可用性（邮箱要 SMTP 开，手机要短信开）
$isEmail = filter_var($target, FILTER_VALIDATE_EMAIL) !== false;
if ($isEmail && !Mailer::enabled()) {
    exit(json_encode(['success' => false, 'message' => '邮箱验证服务未开启，请联系管理员']));
}
if (!$isEmail && !SmsManager::enabled()) {
    exit(json_encode(['success' => false, 'message' => '短信验证服务未开启，请联系管理员']));
}

$r = VerifyCode::send($target, 'register');
exit(json_encode(['success' => $r['ok'], 'message' => $r['message']]));
