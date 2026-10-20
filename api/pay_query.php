<?php
/**
 * 订单状态轮询（前端扫码后每 3 秒问一次；回调未到时主动向渠道查单兜底）
 * GET/POST: order_no=xxx
 */
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json; charset=utf-8');
requireNodeRole('app');

$orderNo = trim((string)($_REQUEST['order_no'] ?? ''));
if ($orderNo === '') {
    exit(json_encode(['success' => false, 'message' => '缺少订单号']));
}
$uid = currentUid();
if ($uid === null) {
    exit(json_encode(['success' => false, 'message' => '请先登录']));
}

PayManager::ensureTable();
$stmt = db()->prepare('SELECT * FROM orders WHERE order_no = ? AND user_id = ?');
$stmt->execute([$orderNo, $uid]);
$order = $stmt->fetch();
if (!$order) {
    exit(json_encode(['success' => false, 'message' => '订单不存在']));
}

// 本地已 paid → 直接返回
if ($order['status'] === 'paid') {
    exit(json_encode(['success' => true, 'paid' => true, 'quota' => (int)currentUser()['storage_limit']]));
}

// pending：主动向渠道查单（异步通知可能延迟/未达）
$driver = PayManager::channel($order['channel']);
if ($driver !== null) {
    $q = $driver->queryOrder($orderNo);
    if ($q['ok'] && $q['paid']) {
        PayManager::settlePaid($orderNo, $q['trade_no']);
        exit(json_encode(['success' => true, 'paid' => true, 'quota' => (int)currentUser()['storage_limit']]));
    }
}

exit(json_encode(['success' => true, 'paid' => false]));
