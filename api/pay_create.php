<?php
/**
 * 创建支付订单（登录用户选套餐 + 渠道 + 时长 → 返回二维码内容）
 * POST: plan=v1/v2/v3, channel=wechat/alipay, period=1m/3m/6m/1y
 */
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json; charset=utf-8');
requireNodeRole('app');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false, 'message' => '无效请求']));
}
$uid = currentUid();
if ($uid === null) {
    exit(json_encode(['success' => false, 'message' => '请先登录']));
}

$planKey   = (string)($_POST['plan'] ?? '');
$channelKey= (string)($_POST['channel'] ?? '');
$periodKey = (string)($_POST['period'] ?? '1m');
$plans = PayManager::plans();
if (!isset($plans[$planKey])) {
    exit(json_encode(['success' => false, 'message' => '套餐不存在']));
}
// 套餐 + 时长 → 应付总额（分）：金额只在服务端算，前端展示价仅作参考
$amountFen = PayManager::priceOf($planKey, $periodKey);
if ($amountFen === null) {
    exit(json_encode(['success' => false, 'message' => '购买时长不合法']));
}
$driver = PayManager::channel($channelKey);
if ($driver === null) {
    exit(json_encode(['success' => false, 'message' => '支付渠道未开启']));
}

PayManager::ensureTable();
$plan = $plans[$planKey];
$orderNo = PayManager::newOrderNo($uid);

// 落订单（pending）
db()->prepare('INSERT INTO orders (order_no, user_id, channel, plan, period, amount_fen) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$orderNo, $uid, $channelKey, $planKey, $periodKey, $amountFen]);

// 调渠道下单拿二维码
$r = $driver->createOrder($orderNo, $amountFen, $plan['name'] . ' - ' . (getSetting('site_name') ?: 'TangWo 网盘'));
if (!$r['ok']) {
    // 下单失败关单，避免脏 pending 堆积
    db()->prepare("UPDATE orders SET status='closed' WHERE order_no = ?")->execute([$orderNo]);
    exit(json_encode(['success' => false, 'message' => $r['message']]));
}

exit(json_encode([
    'success'  => true,
    'order_no' => $orderNo,
    'qr'       => $r['qr'],          // 微信 code_url / 支付宝 qr_code，前端转二维码图
    'amount'   => number_format($amountFen / 100, 2, '.', ''),
    'plan'     => $plan['name'],
    'period'   => PayManager::periods()[$periodKey]['name'],
]));
