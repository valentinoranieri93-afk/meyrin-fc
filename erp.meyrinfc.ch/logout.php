<?php
define('ERP_ROOT', true);
require_once __DIR__ . '/config.php';

setcookie(COOKIE_NAME, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'domain'   => COOKIE_DOMAIN,
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: ' . ERP_URL);
exit;
