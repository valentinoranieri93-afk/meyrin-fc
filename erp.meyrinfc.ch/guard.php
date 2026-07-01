<?php
/**
 * guard.php — À copier dans chaque micro-SaaS.
 *
 * Usage dans index.php du micro-SaaS :
 *   $required_app = 'caisse';   // slug de l'app
 *   require_once __DIR__ . '/guard.php';
 *
 * Si l'utilisateur n'est pas authentifié ou n'a pas accès,
 * il est redirigé vers l'ERP.
 */

// ── Config (doit correspondre à l'ERP) ─────────────────────────────────────
const GUARD_JWT_SECRET    = 'YPeBEiu4XgHx6,c/s@<GiwPp6*iNF9z0&/gGuOg|COs|j.St';
const GUARD_COOKIE_NAME   = 'mfc_session';
const GUARD_ERP_URL       = 'https://erp.meyrinfc.ch';

// ── JWT helpers ─────────────────────────────────────────────────────────────
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

// ── Vérification ────────────────────────────────────────────────────────────
$_guard_token   = $_COOKIE[GUARD_COOKIE_NAME] ?? '';
$_guard_session = $_guard_token ? _guard_jwt_decode($_guard_token, GUARD_JWT_SECRET) : null;

if (!$_guard_session) {
    header('Location: ' . GUARD_ERP_URL . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

if (!in_array($required_app ?? '', $_guard_session['apps'] ?? [], true)) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Accès refusé · Meyrin FC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Sora:wght@800&display=swap" rel="stylesheet">
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Inter",sans-serif;background:#F6F4ED;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{background:#fff;border:1px solid #E9E6DC;border-radius:16px;padding:48px 40px;text-align:center;max-width:400px;width:100%}.icon{font-size:40px;margin-bottom:16px}h1{font-family:"Sora";font-size:20px;color:#15140F;margin-bottom:8px}p{font-size:14px;color:#6E6C61;line-height:1.6;margin-bottom:24px}a{display:inline-block;padding:10px 20px;background:#15140F;color:#FFD000;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;font-family:"Sora"}</style>
</head><body>
<div class="card">
  <div class="icon">🔒</div>
  <h1>Accès refusé</h1>
  <p>Vous n'avez pas les droits nécessaires pour accéder à cette application.<br>Contactez l'administrateur si nécessaire.</p>
  <a href="<?= htmlspecialchars(GUARD_ERP_URL) ?>">Retour à l'ERP</a>
</div>
</body></html><?php
    exit;
}

// L'utilisateur est authentifié et autorisé — la variable $_guard_session est disponible.
