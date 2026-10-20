<?php
/**
 * 支付异步通知统一入口（微信 / 支付宝）
 *   微信：POST JSON body，需按 Wechatpay-Signature 验签（本实现用 APIv3 密钥解密报文自证）
 *   支付宝：POST 表单参数，支付宝公钥 RSA2 验签
 * 通知可能重复发送（微信至少一次），settlePaid 幂等处理
 */
require_once __DIR__ . '/../functions.php';
requireNodeRole('app');   // 集群下由 app 节点承接

$channel = (string)($_GET['channel'] ?? '');
$rawBody = file_get_contents('php://input') ?: '';

$driver = PayManager::channel($channel);
if ($driver === null) {
    http_response_code(404);
    exit('channel not enabled');
}

$r = $driver->verifyNotify($rawBody);

// 验签/解密失败 → 按厂商要求的失败应答返回，让厂商重试
if (!$r['ok']) {
    header('Content-Type: application/json; charset=utf-8');
    exit($r['reply']);
}

// 支付成功 → 履约（升配额）
if ($r['paid'] && $r['order_no'] !== '') {
    PayManager::ensureTable();
    PayManager::settlePaid($r['order_no'], $r['trade_no']);
}

// 按厂商要求应答：微信 JSON {"code":"SUCCESS"}，支付宝纯文本 "success"
header('Content-Type: ' . ($channel === 'alipay' ? 'text/plain' : 'application/json') . '; charset=utf-8');
exit($r['reply']);
