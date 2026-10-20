<?php
require_once 'functions.php';

// 清除跨域无状态票据（集群下 Cookie 作用域为共享域名，必须按 domain 清）
if (!empty($_COOKIE['xc_ticket'])) {
    setcookie('xc_ticket', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => defined('TICKET_COOKIE_DOMAIN') && TICKET_COOKIE_DOMAIN ? TICKET_COOKIE_DOMAIN : '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => (defined('TICKET_COOKIE_DOMAIN') && TICKET_COOKIE_DOMAIN) ? 'None' : 'Lax',
    ]);
}
session_destroy();
redirect(appUrl(''));
