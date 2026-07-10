<?php
/**
 * Guard du module RH — même domaine que l'ERP, réutilise directement sa config/session.
 * ob_start() : ../config.php contient un BOM UTF-8 avant <?php ; sans tampon de sortie,
 * ces 3 octets partiraient avant tout header()/setcookie() et les ferait échouer silencieusement.
 */
ob_start();
define('ERP_ROOT', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/jwt.php';

$rh_token   = $_COOKIE[COOKIE_NAME] ?? '';
$rh_session = $rh_token ? jwt_decode($rh_token, JWT_SECRET) : null;

if (!$rh_session) {
    header('Location: ' . ERP_URL . '/?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/rh'));
    exit;
}
if (!in_array('rh', $rh_session['apps'] ?? [], true)) {
    http_response_code(403);
    ?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Accès refusé</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Sora:wght@800&display=swap" rel="stylesheet">
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Inter",sans-serif;background:#F6F4ED;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{background:#fff;border:1px solid #E9E6DC;border-radius:16px;padding:48px 40px;text-align:center;max-width:400px;width:100%}.icon{font-size:40px;margin-bottom:16px}h1{font-family:"Sora";font-size:20px;color:#15140F;margin-bottom:8px}p{font-size:14px;color:#6E6C61;line-height:1.6;margin-bottom:24px}a{display:inline-block;padding:10px 20px;background:#15140F;color:#FFD000;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;font-family:"Sora"}</style>
</head><body><div class="card"><div class="icon">🔒</div><h1>Accès refusé</h1><p>Vous n'avez pas accès au module RH.<br>Contactez l'administrateur.</p><a href="<?= htmlspecialchars(ERP_URL) ?>">Retour à l'ERP</a></div></body></html><?php
    exit;
}
