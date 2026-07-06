<?php
/**
 * guard.php â€” CopiÃ© depuis erp.meyrinfc.ch/guard.php
 * La clÃ© JWT_SECRET DOIT correspondre Ã  celle de config.php dans l'ERP.
 */
const GUARD_JWT_SECRET  = 'w`ct^\'3:O[Y1Su?Xp+V,P{Mo54B.ab]9HRE~>JDn;_=}g!"L';
const GUARD_COOKIE_NAME = 'mfc_session';
const GUARD_ERP_URL     = 'https://erp.meyrinfc.ch';

function _guard_b64u_decode(string $d): string {
    return base64_decode(strtr($d, '-_', '+/'));
}
function _guard_jwt_decode(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(_guard_b64u_decode($p), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}

$_guard_token   = $_COOKIE[GUARD_COOKIE_NAME] ?? '';
$_guard_session = $_guard_token ? _guard_jwt_decode($_guard_token, GUARD_JWT_SECRET) : null;

if (!$_guard_session) {
    header('Location: ' . GUARD_ERP_URL . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}
if (!in_array($required_app ?? '', $_guard_session['apps'] ?? [], true)) {
    http_response_code(403);
    ?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>AccÃ¨s refusÃ©</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Sora:wght@800&display=swap" rel="stylesheet">
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Inter",sans-serif;background:#F6F4ED;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{background:#fff;border:1px solid #E9E6DC;border-radius:16px;padding:48px 40px;text-align:center;max-width:400px;width:100%}.icon{font-size:40px;margin-bottom:16px}h1{font-family:"Sora";font-size:20px;color:#15140F;margin-bottom:8px}p{font-size:14px;color:#6E6C61;line-height:1.6;margin-bottom:24px}a{display:inline-block;padding:10px 20px;background:#15140F;color:#FFD000;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;font-family:"Sora"}</style>
</head><body><div class="card"><div class="icon">ðŸ”’</div><h1>AccÃ¨s refusÃ©</h1><p>Vous n'avez pas accÃ¨s Ã  cette application.<br>Contactez l'administrateur.</p><a href="<?= htmlspecialchars(GUARD_ERP_URL) ?>">Retour Ã  l'ERP</a></div></body></html><?php
    exit;
}

