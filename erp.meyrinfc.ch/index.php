<?php
define('ERP_ROOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/jwt.php';
require_once __DIR__ . '/lib/mfc_auth.php';

// ── Setup check ─────────────────────────────────────────────────────────────
$users = json_decode(file_get_contents(DATA_DIR . 'users.json'), true) ?: [];
if (empty($users) || ($users[0]['password_hash'] ?? '') === '__SETUP_REQUIRED__') {
    header('Location: /setup.php'); exit;
}

// ── Session ──────────────────────────────────────────────────────────────────
/* mfc_session() plutôt que jwt_decode() : vérifie en plus la liste de
   révocation, pour qu'un changement de rôle ou une désactivation prenne effet
   à la requête suivante et non à l'expiration du jeton (8h). */
$session = mfc_session();

// ── Login POST ───────────────────────────────────────────────────────────────
$login_error = '';
if (!$session && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $pw    = $_POST['password'] ?? '';
    $found = null;
    foreach ($users as $u) {
        if ($u['login'] === $login && ($u['active'] ?? true) && password_verify($pw, $u['password_hash'])) {
            $found = $u; break;
        }
    }
    if ($found) {
        erp_issue_token($found);
        erp_log($found['login'], $found['name'], 'login');
        foreach ($users as &$u) {
            if ($u['id'] === $found['id']) { $u['last_login'] = date('c'); break; }
        }
        unset($u);
        file_put_contents(DATA_DIR . 'users.json', json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header('Location: ' . erp_safe_redirect($_GET['redirect'] ?? '/'));
        exit;
    } else {
        $login_error = 'Identifiant ou mot de passe incorrect.';
        erp_log($login ?: '?', '—', 'login_failed');
    }
}

/* ── Réémission silencieuse ───────────────────────────────────────────────────
   Jeton correctement signé et non expiré, mais refusé par la révocation : le
   plus souvent parce que les droits de la personne viennent de changer. Tant
   que son compte est actif, on lui délivre un jeton à jour sans lui redemander
   son mot de passe. Sans ça, la moindre modification de permissions
   déconnecterait tout le monde, ce qui pousserait à ne plus y toucher. */
if (!$session && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw = mfc_raw_session();
    if ($raw) {
        foreach ($users as $u) {
            if ($u['id'] === ($raw['sub'] ?? '') && ($u['active'] ?? true)) {
                erp_issue_token($u);
                /* La révocation a joué son rôle : on la retire, sinon un
                   décalage d'horloge d'une seconde suffirait à faire boucler
                   la redirection. Ceci borne aussi la taille du fichier. */
                $rf  = DATA_DIR . 'revocations.json';
                $rev = file_exists($rf) ? (json_decode(file_get_contents($rf), true) ?: []) : [];
                unset($rev[$u['id']]);
                file_put_contents($rf, json_encode($rev, JSON_PRETTY_PRINT));
                erp_log($u['login'], $u['name'], 'token_refresh');
                /* On renvoie la personne là où elle voulait aller. Sans ça,
                   quelqu'un dont les droits viennent de changer et qui était
                   dans une application atterrit sur le portail de l'ERP sans
                   comprendre pourquoi il a été sorti de son écran. */
                $back = (string)($_GET['redirect'] ?? '');
                header('Location: ' . ($back !== ''
                    ? erp_safe_redirect($back)
                    : ($_SERVER['REQUEST_URI'] ?: '/')));
                exit;
            }
        }
    }
}

/** Émet le cookie de session pour un utilisateur donné. */
function erp_issue_token(array $user): void {
    $roles = json_decode(file_get_contents(DATA_DIR . 'roles.json'), true);
    $rd    = $roles[$user['role']] ?? [];
    /* Permissions détaillées par application. Un rôle qui n'a pas encore de
       bloc "perms" reçoit toutes les permissions de ses apps : le jeton reste
       donc équivalent à l'ancien tant que rien n'a été restreint. */
    $perms = mfc_role_permissions($rd, $user['role'] ?? null);
    $jwt   = jwt_encode([
        'sub'      => $user['id'],
        'name'     => $user['name'],
        'login'    => $user['login'],
        'role'     => $user['role'],
        'apps'     => array_values(array_keys($perms)),
        'perms'    => $perms,
        'settings' => (bool)($rd['can_access_settings'] ?? false),
        'iat'      => time(),
        'exp'      => time() + JWT_DURATION,
    ], JWT_SECRET);
    setcookie(COOKIE_NAME, $jwt, [
        'expires'  => time() + JWT_DURATION,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Destination de retour après connexion, validée.
 *
 * N'accepte qu'un chemin local ou une URL du domaine meyrinfc.ch. Sans ce
 * filtre, un lien du type `erp.meyrinfc.ch/?redirect=https://site-pirate/`
 * enverrait la personne ailleurs juste après sa connexion, sur une page qui
 * aurait toute l'apparence de l'ERP.
 */
function erp_safe_redirect(string $to): string {
    if ($to === '') return '/';
    if (str_starts_with($to, '/') && !str_starts_with($to, '//')) return $to;
    $p = parse_url($to);
    $host = strtolower($p['host'] ?? '');
    $okScheme = ($p['scheme'] ?? '') === 'https';
    $okHost   = $host === 'meyrinfc.ch' || str_ends_with($host, '.meyrinfc.ch');
    return ($okScheme && $okHost) ? $to : '/';
}

/**
 * Une application est-elle servie par l'ERP lui-même ?
 *
 * Vrai pour un chemin relatif ("/arbitrage") comme pour une URL absolue qui
 * pointe vers le domaine courant ("https://erp.meyrinfc.ch/arbitrage"). Les
 * deux formes cohabitent dans apps.json au fil de la consolidation des
 * micro-SaaS en modules : ne reconnaître que le chemin relatif ferait ouvrir un
 * module interne dans un nouvel onglet, comme s'il s'agissait d'un site tiers.
 */
function erp_app_is_internal(string $url): bool {
    if ($url === '') return false;
    if (str_starts_with($url, '//')) return false;
    if (str_starts_with($url, '/'))  return true;
    $authority = erp_url_authority($url);
    return $authority !== '' && $authority === erp_current_authority();
}

/**
 * Domaine d'une URL, port compris.
 *
 * parse_url() rend le port séparément alors que HTTP_HOST le contient : les
 * comparer sans recoller le port ferait passer une adresse du domaine courant
 * pour un site tiers dès qu'un port non standard est en jeu.
 */
function erp_url_authority(string $url): string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $port = parse_url($url, PHP_URL_PORT);
    return ($host !== '' && $port) ? $host . ':' . $port : $host;
}

/** Domaine courant, port compris, dans la même forme que erp_url_authority(). */
function erp_current_authority(): string {
    return strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
}

/**
 * Adresse lisible affichée sur la tuile : domaine + chemin, sans le protocole.
 * Le chemin compte ici, c'est lui qui distingue un module de l'ERP racine.
 */
function erp_app_display_url(string $url): string {
    $host = erp_url_authority($url) ?: erp_current_authority();
    $path = rtrim((string)parse_url($url, PHP_URL_PATH), '/');
    return $host . $path;
}

function erp_log(string $user, string $name, string $action): void {
    $f    = DATA_DIR . 'logs.json';
    $logs = json_decode(file_get_contents($f), true) ?: [];
    array_unshift($logs, ['user' => $user, 'name' => $name, 'action' => $action, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ts' => date('c')]);
    file_put_contents($f, json_encode(array_slice($logs, 0, 1000), JSON_PRETTY_PRINT));
}

// ── State ────────────────────────────────────────────────────────────────────
$all_apps = json_decode(file_get_contents(DATA_DIR . 'apps.json'), true) ?: [];
$tab      = ($session && ($session['settings'] ?? false) && ($_GET['tab'] ?? '') === 'settings') ? 'settings' : 'dashboard';
$page     = $session ? $tab : 'login';

// ── Icons SVG map ────────────────────────────────────────────────────────────
function app_icon(string $icon, string $color): string {
    $icons = [
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="3"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'users'       => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="3"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'whistle'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/>',
        'package'     => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'briefcase'   => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    ];
    $path = $icons[$icon] ?? $icons['whistle'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="' . htmlspecialchars($color) . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Meyrin FC · ERP<?= $page === 'settings' ? ' · Paramètres' : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --jaune:#ffdd00;--jaune-fonce:#c5a900;--jaune-soft:#FFF3C4;
  --noir:#15140F;--noir-2:#211F18;--noir-3:#2C2A21;--encre:#1A1915;
  --gris:#6E6C61;--gris-2:#9A988C;--ligne:#E9E6DC;--ligne-2:#F0EDE4;
  --bg:#F6F4ED;--carte:#FFFFFF;
  --vert:#1F9D5B;--vert-bg:#E4F4EA;
  --rouge:#D8463A;--rouge-bg:#FBE7E4;
  --orange:#E8902A;--orange-bg:#FCEEDB;
  --bleu:#3B6FE0;--bleu-bg:#E6EDFB;
  --violet:#7B59D8;--violet-bg:#EEE8FB;
  --ombre-s:0 1px 2px rgba(20,18,10,.05);
  --ombre:0 1px 2px rgba(20,18,10,.04),0 6px 20px rgba(20,18,10,.06);
  --ombre-l:0 10px 40px rgba(20,18,10,.14);
  --r:14px;--r-s:10px;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%}
body{font-family:"Inter",-apple-system,sans-serif;background:var(--bg);color:var(--encre);-webkit-font-smoothing:antialiased;font-size:14px;line-height:1.5}
h1,h2,h3,.sora{font-family:"Sora","Inter",sans-serif}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}
input,select{font-family:inherit;font-size:14px;outline:none}

/* ── LOGIN ── */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.login-card{background:var(--carte);border:1px solid var(--ligne);border-radius:20px;padding:44px 40px;width:100%;max-width:400px;box-shadow:var(--ombre-l)}
.login-logo{width:52px;height:52px;object-fit:contain;display:block;margin:0 auto 22px}
.login-card h1{font-size:22px;font-weight:800;text-align:center;color:var(--noir);margin-bottom:4px;letter-spacing:-.3px}
.login-sub{text-align:center;font-size:13px;color:var(--gris);margin-bottom:30px}
.field{margin-bottom:14px}
.field label{display:block;font-size:12.5px;font-weight:600;color:var(--noir-3);margin-bottom:6px;letter-spacing:.1px}
.field input{width:100%;padding:10px 14px;border:1px solid var(--ligne);border-radius:10px;background:var(--bg);color:var(--encre);transition:border .15s,background .15s}
.field input:focus{border-color:var(--noir);background:#fff}
.btn-login{width:100%;padding:12px;background:var(--noir);color:var(--jaune);border-radius:10px;font-family:"Sora";font-weight:700;font-size:14px;letter-spacing:.2px;margin-top:6px;transition:background .15s}
.btn-login:hover{background:var(--noir-3)}
.login-error{background:var(--rouge-bg);color:var(--rouge);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px}

/* ── HEADER ── */
.header{background:var(--noir);padding:0 40px;display:flex;align-items:center;justify-content:space-between;height:62px;position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:12px}
.brand-logo{width:36px;height:36px;object-fit:contain;flex-shrink:0}
.brand-name{font-family:"Sora";font-weight:700;font-size:14.5px;color:#E8E6DD;letter-spacing:-.2px;line-height:1.1}
.brand-sub{font-size:10.5px;color:#75736A;margin-top:1px;font-weight:500;letter-spacing:.3px;text-transform:uppercase}
.header-nav{display:flex;align-items:center;gap:4px}
.h-nav-btn{padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;color:#9A988C;transition:background .15s,color .15s;display:flex;align-items:center;gap:7px}
.h-nav-btn:hover,.h-nav-btn.active{background:rgba(255,255,255,.07);color:#E8E6DD}
.h-nav-btn.active{color:#E8E6DD}
.h-nav-btn svg{width:15px;height:15px;opacity:.7}
.h-user{display:flex;align-items:center;gap:10px}
.h-user-name{font-size:13px;color:#C9C7BD;font-weight:500}
.h-role{font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600}
.h-logout{padding:7px 14px;background:rgba(255,255,255,.06);border-radius:8px;font-size:12.5px;color:#9A988C;font-weight:600;transition:background .15s,color .15s}
.h-logout:hover{background:rgba(255,255,255,.12);color:#E8E6DD}

/* ── DASHBOARD ── */
.main{max-width:960px;margin:0 auto;padding:44px 24px 80px}
.hero{margin-bottom:44px}
.hero-eyebrow{display:inline-flex;align-items:center;gap:6px;background:var(--jaune-soft);color:#7A6000;font-size:11.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;padding:5px 12px;border-radius:20px;margin-bottom:14px}
.hero h1{font-size:30px;font-weight:800;letter-spacing:-.5px;line-height:1.15;color:var(--noir);margin-bottom:8px}
.hero h1 span{color:var(--jaune-fonce)}
.hero p{font-size:14.5px;color:var(--gris);max-width:500px;line-height:1.6}
.section-label{font-size:10.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--gris-2);margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
.app-card{background:var(--carte);border:1px solid var(--ligne);border-radius:var(--r);padding:22px;cursor:pointer;display:flex;flex-direction:column;gap:14px;box-shadow:var(--ombre-s);transition:box-shadow .15s,transform .15s,border-color .15s;position:relative;overflow:hidden}
.app-card:hover{box-shadow:var(--ombre-l);transform:translateY(-2px);border-color:var(--ligne-2)}
.app-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--card-accent,var(--jaune));opacity:0;transition:opacity .15s}
.app-card:hover::after{opacity:1}
.card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.card-icon{width:44px;height:44px;border-radius:11px;background:var(--card-icon-bg,var(--jaune-soft));display:flex;align-items:center;justify-content:center;flex-shrink:0}
.card-icon svg{width:21px;height:21px}
.card-arrow{width:26px;height:26px;border-radius:7px;background:var(--bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;transition:background .15s}
.app-card:hover .card-arrow{background:var(--ligne)}
.card-arrow svg{width:13px;height:13px;color:var(--gris-2)}
.card-name{font-family:"Sora";font-weight:700;font-size:15px;letter-spacing:-.2px;color:var(--noir);margin-bottom:4px}
.card-desc{font-size:12.5px;color:var(--gris);line-height:1.5}
.card-footer{display:flex;align-items:center;justify-content:space-between;padding-top:12px;border-top:1px solid var(--ligne)}
.card-url{font-size:11px;color:var(--gris-2);font-weight:500}
.card-status{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:20px;background:var(--card-status-bg,var(--vert-bg));color:var(--card-status-color,var(--vert))}
.card-status-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.no-apps{text-align:center;padding:60px 24px;color:var(--gris);font-size:14px}

/* ── SETTINGS ── */
.settings-wrap{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 62px)}
.settings-sidebar{background:var(--carte);border-right:1px solid var(--ligne);padding:24px 12px}
.settings-back{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gris);font-weight:500;padding:8px 12px;border-radius:8px;margin-bottom:16px;transition:background .15s,color .15s;cursor:pointer}
.settings-back:hover{background:var(--bg);color:var(--encre)}
.settings-back svg{width:15px;height:15px}
.s-label{font-size:10px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--gris-2);padding:6px 12px 4px}
.s-nav-item{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:9px 12px;border-radius:8px;color:var(--gris);font-size:13px;font-weight:500;transition:background .15s,color .15s;cursor:pointer;margin-bottom:2px}
.s-nav-item:hover{background:var(--bg);color:var(--encre)}
.s-nav-item.active{background:var(--jaune-soft);color:#7A6000;font-weight:600}
.s-nav-item svg{width:15px;height:15px;flex-shrink:0}
.settings-content{padding:36px 40px;overflow-y:auto}
.s-page{display:none}.s-page.active{display:block}
.s-title{font-family:"Sora";font-size:20px;font-weight:800;color:var(--noir);margin-bottom:4px;letter-spacing:-.3px}
.s-desc{font-size:13px;color:var(--gris);margin-bottom:28px;line-height:1.5}

/* ── Tables ── */
.s-table-wrap{background:var(--carte);border:1px solid var(--ligne);border-radius:var(--r);overflow:hidden;margin-bottom:20px}
.s-table{width:100%;border-collapse:collapse}
.s-table th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--gris-2);border-bottom:1px solid var(--ligne);background:#FAFAF7}
.s-table td{padding:13px 16px;font-size:13.5px;border-bottom:1px solid var(--ligne-2);vertical-align:middle}
.s-table tr:last-child td{border-bottom:none}
.s-table tr:hover td{background:#FAFAF7}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:20px}
.badge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s,opacity .15s;border:none}
.btn-primary{background:var(--noir);color:var(--jaune)}
.btn-primary:hover{background:var(--noir-3)}
.btn-sm{padding:5px 11px;font-size:12px}
.btn-ghost{background:var(--bg);color:var(--encre);border:1px solid var(--ligne)}
.btn-ghost:hover{background:var(--ligne)}
.btn-danger{background:var(--rouge-bg);color:var(--rouge)}
.btn-danger:hover{background:#f5c6c1}
.s-header-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}

/* ── Switch ── */
.switch{display:inline-block;position:relative;width:38px;height:22px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.switch-slider{position:absolute;inset:0;background:#D9D6CB;border-radius:22px;cursor:pointer;transition:background .2s}
.switch-slider::before{content:'';position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.switch input:checked+.switch-slider{background:var(--vert)}
.roles-matrix .switch-slider{background:var(--rouge)}
.roles-matrix .switch input:checked+.switch-slider{background:var(--vert)}
.roles-matrix .switch input:disabled+.switch-slider{background:var(--vert);opacity:.5;cursor:not-allowed}
.switch input:checked+.switch-slider::before{transform:translateX(16px)}

/* ── Roles matrix ── */
.perm-block{border:1px solid var(--ligne);border-radius:10px;margin-bottom:10px;overflow:hidden}
.perm-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;background:#FAFAF7;border-bottom:1px solid var(--ligne-2);font-size:13px;font-weight:700}
.perm-row{display:flex;align-items:flex-start;gap:10px;padding:8px 12px;border-bottom:1px solid var(--ligne-2);cursor:pointer;font-size:13px}
.perm-row:last-child{border-bottom:none}
.perm-row:hover{background:#FAFAF7}
.perm-row input{margin-top:3px;flex:none}
.perm-row span{display:flex;flex-direction:column;gap:1px}
.perm-row strong{font-weight:500}
.perm-row code{font-size:11px;color:var(--gris-2)}
.roles-matrix{background:var(--carte);border:1px solid var(--ligne);border-radius:var(--r);overflow:hidden}
.roles-matrix table{width:100%;border-collapse:collapse}
.roles-matrix th{padding:12px 16px;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--gris-2);background:#FAFAF7;border-bottom:1px solid var(--ligne);text-align:center}
.roles-matrix th:first-child{text-align:left}
.roles-matrix td{padding:12px 16px;border-bottom:1px solid var(--ligne-2);text-align:center;vertical-align:middle}
.roles-matrix td:first-child{text-align:left;font-weight:600;font-size:13.5px}
.roles-matrix tr:last-child td{border-bottom:none}
.roles-matrix tr:hover td{background:#FAFAF7}
.matrix-save{margin-top:14px;display:flex;justify-content:flex-end}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(20,18,10,.4);z-index:200;align-items:center;justify-content:center;padding:24px}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:18px;padding:36px;max-width:440px;width:100%;box-shadow:0 20px 60px rgba(20,18,10,.2)}
.modal h3{font-family:"Sora";font-size:18px;font-weight:800;color:var(--noir);margin-bottom:20px}
.m-field{margin-bottom:14px}
.m-field label{display:block;font-size:12.5px;font-weight:600;color:var(--noir-3);margin-bottom:5px}
.m-field input,.m-field select{width:100%;padding:9px 13px;border:1px solid var(--ligne);border-radius:9px;background:var(--bg);color:var(--encre);font-size:13.5px;transition:border .15s}
.m-field input:focus,.m-field select:focus{border-color:var(--noir);background:#fff;outline:none}
.m-field small{font-size:11.5px;color:var(--gris-2);margin-top:4px;display:block}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:22px}
.modal-error{background:var(--rouge-bg);color:var(--rouge);border-radius:8px;padding:9px 13px;font-size:12.5px;margin-bottom:14px;display:none}

/* ── Référentiel club (Catégories & Équipes) ── */
.btn-sm{padding:5px 11px;font-size:12px}
.club-season-select{padding:8px 12px;border:1px solid var(--ligne);border-radius:9px;background:#fff;color:var(--encre);font-size:13px;font-weight:600;min-width:170px}
.club-empty{background:var(--carte);border:1px dashed var(--ligne);border-radius:var(--r);padding:28px;font-size:13.5px;color:var(--noir-3);line-height:1.65}
.club-empty strong{display:block;font-size:15px;color:var(--noir);margin-bottom:6px}
.club-empty code{background:var(--bg);border:1px solid var(--ligne);border-radius:5px;padding:1px 5px;font-size:12.5px}
.club-grid{display:grid;grid-template-columns:300px 1fr;gap:18px;align-items:start}
@media(max-width:1100px){.club-grid{grid-template-columns:1fr}}
.club-panel{background:var(--carte);border:1px solid var(--ligne);border-radius:var(--r);overflow:hidden}
.club-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid var(--ligne-2)}
.club-panel-title{font-family:"Sora";font-size:14.5px;font-weight:700;color:var(--noir)}
.club-panel-sub{font-size:12px;color:var(--gris-2);margin-top:3px;line-height:1.5}
.club-panel-foot{padding:14px 20px;border-top:1px solid var(--ligne-2);display:flex;justify-content:flex-end}
.club-tag{font-family:"Inter",sans-serif;font-size:11px;font-weight:600;color:var(--gris-2);background:var(--bg);border:1px solid var(--ligne);border-radius:20px;padding:2px 9px;margin-left:6px;vertical-align:middle}
.club-cat-row{display:flex;align-items:center;gap:7px;padding:8px 20px;border-bottom:1px solid var(--ligne-2)}
.club-cat-row:last-of-type{border-bottom:none}
.club-inp{flex:1;min-width:0;padding:7px 10px;border:1px solid transparent;border-radius:7px;background:transparent;color:var(--encre);font-size:13.5px}
.club-inp:hover{border-color:var(--ligne)}
.club-inp:focus{border-color:var(--noir);background:#fff;outline:none}
.club-teams td{padding:6px 10px}
.club-teams td:first-child{padding-left:16px;color:var(--gris-2)}
.club-teams select.club-inp{background:transparent;-webkit-appearance:none;appearance:none}
.club-x{background:none;border:none;color:var(--gris-2);cursor:pointer;font-size:16px;line-height:1;padding:4px 6px;border-radius:6px}
.club-x:hover{background:var(--rouge-bg);color:var(--rouge)}
.club-move{display:flex;flex-direction:column;gap:1px}
.club-move button{background:none;border:none;color:#BFBBAC;cursor:pointer;font-size:9px;line-height:1;padding:1px}
.club-move button:hover{color:var(--noir)}
.club-none{padding:22px 20px;font-size:13px;color:var(--gris-2);text-align:center}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;background:var(--noir);color:#E8E6DD;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:var(--ombre-l);z-index:300;transform:translateY(80px);opacity:0;transition:transform .25s ease,opacity .25s ease;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success::before{content:'✓  '}
.toast.error{background:var(--rouge)}.toast.error::before{content:'✕  '}

/* ── Color palette ── */
.color-swatch{width:26px;height:26px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:transform .15s,border-color .15s;flex-shrink:0}
.color-swatch:hover{transform:scale(1.15)}
.color-swatch.selected{border-color:var(--encre);transform:scale(1.15);box-shadow:0 0 0 2px #fff,0 0 0 4px var(--encre)}

/* ── Logs table ── */
.log-action{font-size:11.5px;font-family:monospace;background:var(--bg);padding:2px 8px;border-radius:6px;color:var(--gris)}
.log-failed{color:var(--rouge);background:var(--rouge-bg)}

/* ── Responsive ── */
@media(max-width:680px){
  .header{padding:0 16px}
  .main{padding:28px 16px 64px}
  .hero h1{font-size:22px}
  .settings-wrap{grid-template-columns:1fr}
  .settings-sidebar{border-right:none;border-bottom:1px solid var(--ligne);padding:16px 8px}
  .settings-content{padding:24px 16px}
  .h-user-name,.brand-sub{display:none}
}
</style>
</head>
<body>

<?php if ($page === 'login'): ?>
<!-- ════════════════ LOGIN ════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <img class="login-logo" src="https://meyrinfc.ch/wp-content/uploads/2025/06/MeyrinFC_logo_RGB.svg" alt="Meyrin FC">
    <h1>ERP Meyrin FC</h1>
    <p class="login-sub">Connectez-vous pour accéder au back-office</p>
    <?php if ($login_error): ?>
      <div class="login-error"><?= htmlspecialchars($login_error) ?></div>
    <?php endif ?>
    <form method="POST">
      <div class="field">
        <label>Identifiant</label>
        <input type="text" name="login" placeholder="votre.login" autocomplete="username" autofocus
               value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Mot de passe</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password">
      </div>
      <button type="submit" class="btn-login">Se connecter</button>
    </form>
  </div>
</div>

<?php elseif ($page === 'dashboard'): ?>
<!-- ════════════════ DASHBOARD ════════════════ -->
<?php
$roles_data = json_decode(file_get_contents(DATA_DIR . 'roles.json'), true);
$role_info  = $roles_data[$session['role']] ?? ['label' => $session['role'], 'color' => '#6E6C61', 'color_bg' => '#F0EDE4'];
$user_apps  = $session['apps'] ?? [];
?>
<header class="header">
  <div class="brand">
    <img class="brand-logo" src="https://meyrinfc.ch/wp-content/uploads/2025/06/MeyrinFC_logo_RGB.svg" alt="Meyrin FC">
    <div>
      <div class="brand-name">Meyrin FC</div>
      <div class="brand-sub">ERP · Back-office</div>
    </div>
  </div>
  <div class="header-nav">
    <?php if ($session['settings']): ?>
    <a href="/?tab=settings" class="h-nav-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
      Paramètres
    </a>
    <?php endif ?>
    <div class="h-user" style="margin-left:8px;padding-left:12px;border-left:1px solid rgba(255,255,255,.08)">
      <span class="h-user-name"><?= htmlspecialchars($session['name']) ?></span>
      <span class="badge h-role" style="background:<?= htmlspecialchars($role_info['color_bg']) ?>;color:<?= htmlspecialchars($role_info['color']) ?>"><?= htmlspecialchars($role_info['label']) ?></span>
      <a href="/logout.php" class="h-logout">Déconnexion</a>
    </div>
  </div>
</header>

<main class="main">
  <div class="hero">
    <div class="hero-eyebrow">
      <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
      Portail de gestion
    </div>
    <h1>Bienvenue, <span><?= htmlspecialchars(explode(' ', $session['name'])[0]) ?></span></h1>
    <p>Accédez aux applications auxquelles vous avez accès.</p>
  </div>

  <div class="section-label">Vos applications</div>
  <?php
  $visible = array_filter($all_apps, fn($a) => $a['active'] && in_array($a['slug'], $user_apps, true));
  if (empty($visible)):
  ?>
  <div class="no-apps">Aucune application disponible pour votre rôle.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($visible as $app):
      $c = htmlspecialchars($app['color']);
      $cb = htmlspecialchars($app['color_bg']);
    ?>
    <a class="app-card" href="<?= htmlspecialchars($app['url']) ?>" <?= erp_app_is_internal($app['url']) ? '' : 'target="_blank" rel="noopener"' ?>
       style="--card-accent:<?= $c ?>;--card-icon-bg:<?= $cb ?>">
      <div class="card-top">
        <div class="card-icon"><?= app_icon($app['icon'], $app['color']) ?></div>
        <div class="card-arrow">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 13L13 7M13 7H8M13 7v5"/></svg>
        </div>
      </div>
      <div>
        <div class="card-name"><?= htmlspecialchars($app['name']) ?></div>
        <div class="card-desc"><?= htmlspecialchars($app['description']) ?></div>
      </div>
      <div class="card-footer">
        <span class="card-url"><?= htmlspecialchars(erp_app_display_url($app['url'])) ?></span>
        <span class="card-status" style="--card-status-bg:<?= $cb ?>;--card-status-color:<?= $c ?>">
          <span class="card-status-dot"></span>En ligne
        </span>
      </div>
    </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</main>

<?php else: /* SETTINGS */ ?>
<!-- ════════════════ SETTINGS ════════════════ -->
<?php
$roles_data = json_decode(file_get_contents(DATA_DIR . 'roles.json'), true);
$role_info  = $roles_data[$session['role']] ?? ['label' => $session['role'], 'color' => '#6E6C61', 'color_bg' => '#F0EDE4'];
?>
<header class="header">
  <div class="brand">
    <img class="brand-logo" src="https://meyrinfc.ch/wp-content/uploads/2025/06/MeyrinFC_logo_RGB.svg" alt="Meyrin FC">
    <div>
      <div class="brand-name">Meyrin FC</div>
      <div class="brand-sub">ERP · Paramètres</div>
    </div>
  </div>
  <div class="h-user">
    <span class="h-user-name"><?= htmlspecialchars($session['name']) ?></span>
    <span class="badge h-role" style="background:<?= htmlspecialchars($role_info['color_bg']) ?>;color:<?= htmlspecialchars($role_info['color']) ?>"><?= htmlspecialchars($role_info['label']) ?></span>
    <a href="/logout.php" class="h-logout">Déconnexion</a>
  </div>
</header>

<div class="settings-wrap">
  <!-- Sidebar -->
  <aside class="settings-sidebar">
    <a href="/" class="settings-back">
      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5l-7 5 7 5"/></svg>
      Tableau de bord
    </a>
    <div class="s-label">Administration</div>
    <?php
    $nav = [
      ['id'=>'users',    'label'=>'Utilisateurs',    'icon'=>'<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>'],
      ['id'=>'roles',    'label'=>'Rôles & Accès',   'icon'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
      ['id'=>'apps',     'label'=>'Applications',    'icon'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
      ['id'=>'club',     'label'=>'Catégories & Équipes', 'icon'=>'<path d="M12 2l7 4v6c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/>'],
      ['id'=>'logs',     'label'=>"Journal d'activité", 'icon'=>'<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>'],
      ['id'=>'sessions', 'label'=>'Sessions actives', 'icon'=>'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
      ['id'=>'general',  'label'=>'Général',         'icon'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>'],
    ];
    foreach ($nav as $n):
    ?>
    <button class="s-nav-item" data-page="<?= $n['id'] ?>" onclick="showPage('<?= $n['id'] ?>')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $n['icon'] ?></svg>
      <?= $n['label'] ?>
    </button>
    <?php endforeach ?>
  </aside>

  <!-- Content -->
  <div class="settings-content">

    <!-- UTILISATEURS -->
    <div class="s-page active" id="page-users">
      <div class="s-header-row">
        <div>
          <div class="s-title">Utilisateurs</div>
          <div class="s-desc">Gérez les comptes d'accès à l'ERP.</div>
        </div>
        <button class="btn btn-primary" onclick="openAddUser()">
          <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"/></svg>
          Ajouter
        </button>
      </div>
      <div class="s-table-wrap">
        <table class="s-table" id="users-table">
          <thead><tr><th>Nom</th><th>Login</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th></th></tr></thead>
          <tbody id="users-tbody"><tr><td colspan="6" style="text-align:center;color:var(--gris-2);padding:24px">Chargement...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- RÔLES & ACCÈS -->
    <div class="s-page" id="page-roles">
      <div class="s-header-row">
        <div>
          <div class="s-title">Rôles & Accès</div>
          <div class="s-desc">Définissez quelles applications chaque rôle peut utiliser. Les modifications prennent effet à la prochaine connexion de l'utilisateur.</div>
        </div>
        <button class="btn btn-primary" onclick="openModal('modal-add-role')">
          <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"/></svg>
          Ajouter un rôle
        </button>
      </div>
      <div class="roles-matrix">
        <table id="roles-table">
          <thead><tr id="roles-thead"><th>Rôle</th></tr></thead>
          <tbody id="roles-tbody"></tbody>
        </table>
      </div>
      <div class="matrix-save">
        <button class="btn btn-primary" onclick="saveRoles()">Enregistrer les accès</button>
      </div>
    </div>

    <!-- APPLICATIONS -->
    <div class="s-page" id="page-apps">
      <div class="s-title">Applications</div>
      <div class="s-desc">Activez ou désactivez les applications visibles dans l'ERP.</div>
      <div class="s-table-wrap">
        <table class="s-table">
          <thead><tr><th>Application</th><th>URL</th><th>Statut</th><th>Actif</th></tr></thead>
          <tbody id="apps-tbody"><tr><td colspan="4" style="text-align:center;color:var(--gris-2);padding:24px">Chargement...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- CATÉGORIES & ÉQUIPES -->
    <!--
      Référentiel club : source de vérité unique des catégories, des équipes et
      de leur entraîneur, consommée par le module RH et par Arbitrage. Une équipe
      garde son identité d'une saison à l'autre ; son nom, sa catégorie, son
      entraîneur et sa présence sont propres à la saison affichée ici.
    -->
    <div class="s-page" id="page-club">
      <div class="s-header-row">
        <div>
          <div class="s-title">Catégories &amp; Équipes</div>
          <div class="s-desc">Structure sportive du club. Les modules RH et Arbitrage affichent cette liste ; chacun y ajoute ensuite ses propres données (indemnités, cotisations, matchs).</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <select id="club-season" class="club-season-select" onchange="loadClub(this.value)"></select>
          <button class="btn btn-ghost" onclick="openClubSeason(false)">Modifier</button>
          <button class="btn btn-primary" onclick="openClubSeason(true)">Nouvelle saison</button>
        </div>
      </div>

      <div id="club-empty" class="club-empty" style="display:none">
        <strong>Aucune saison définie.</strong>
        Créez une première saison, puis ajoutez vos catégories et vos équipes. Si vos équipes existent déjà dans le module RH, lancez d'abord l'outil de reprise <code>tools/mfc_seed_club.php</code> pour les importer sans les ressaisir.
      </div>

      <div id="club-body" style="display:none">
        <div class="club-grid">
          <!-- Catégories -->
          <div class="club-panel">
            <div class="club-panel-head">
              <div>
                <div class="club-panel-title">Catégories</div>
                <div class="club-panel-sub">Liste commune à toutes les saisons.</div>
              </div>
              <button class="btn btn-ghost btn-sm" onclick="addClubCat()">+ Ajouter</button>
            </div>
            <div id="club-cats"></div>
            <div class="club-panel-foot">
              <button class="btn btn-primary" onclick="saveClubCats()">Enregistrer les catégories</button>
            </div>
          </div>

          <!-- Équipes -->
          <div class="club-panel">
            <div class="club-panel-head">
              <div>
                <div class="club-panel-title">Équipes <span id="club-season-tag" class="club-tag"></span></div>
                <div class="club-panel-sub">Nom, catégorie et entraîneur de la saison affichée. Une équipe désactivée disparaît des modules sans perdre son historique.</div>
              </div>
              <button class="btn btn-ghost btn-sm" onclick="addClubTeam()">+ Ajouter</button>
            </div>
            <div class="s-table-wrap">
              <table class="s-table club-teams">
                <thead><tr><th style="width:34px"></th><th>Équipe</th><th style="width:150px">Catégorie</th><th style="width:180px">Entraîneur</th><th style="width:70px">Active</th><th style="width:40px"></th></tr></thead>
                <tbody id="club-teams-tbody"></tbody>
              </table>
            </div>
            <div class="club-panel-foot">
              <button class="btn btn-primary" onclick="saveClubTeams()">Enregistrer les équipes</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- JOURNAL -->
    <div class="s-page" id="page-logs">
      <div class="s-title">Journal d'activité</div>
      <div class="s-desc">Historique des connexions et actions effectuées dans l'ERP (100 derniers événements).</div>
      <div class="s-table-wrap">
        <table class="s-table">
          <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>IP</th></tr></thead>
          <tbody id="logs-tbody"><tr><td colspan="4" style="text-align:center;color:var(--gris-2);padding:24px">Chargement...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- SESSIONS -->
    <div class="s-page" id="page-sessions">
      <div class="s-title">Sessions actives</div>
      <div class="s-desc">Utilisateurs connectés dans les 8 dernières heures (durée max d'une session).</div>
      <div class="s-table-wrap">
        <table class="s-table">
          <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Dernière connexion</th></tr></thead>
          <tbody id="sessions-tbody"><tr><td colspan="3" style="text-align:center;color:var(--gris-2);padding:24px">Chargement...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- GÉNÉRAL -->
    <div class="s-page" id="page-general">
      <div class="s-title">Paramètres généraux</div>
      <div class="s-desc">Configuration globale de l'ERP.</div>
      <div style="background:var(--carte);border:1px solid var(--ligne);border-radius:var(--r);padding:28px;max-width:480px">
        <div class="m-field">
          <label>Nom du club</label>
          <input type="text" id="g-club-name" placeholder="Meyrin FC">
        </div>
        <div class="m-field">
          <label>Durée de session (heures)</label>
          <input type="number" id="g-session" min="1" max="24" value="8">
          <small>Entre 1h et 24h. Par défaut : 8h.</small>
        </div>
        <div style="margin-top:20px">
          <button class="btn btn-primary" onclick="saveGeneral()">Enregistrer</button>
        </div>
      </div>
    </div>

  </div><!-- /settings-content -->
</div><!-- /settings-wrap -->

<!-- ── Modal : Ajouter utilisateur ── -->
<div class="modal-overlay" id="modal-add-user">
  <div class="modal">
    <h3>Ajouter un utilisateur</h3>
    <div class="modal-error" id="add-user-error"></div>
    <div class="m-field"><label>Prénom Nom</label><input type="text" id="add-name" placeholder="Jean Dupont"></div>
    <div class="m-field"><label>Login</label><input type="text" id="add-login" placeholder="jean.dupont"><small>Identifiant de connexion, sans espaces.</small></div>
    <div class="m-field"><label>E-mail</label><input type="email" id="add-email" placeholder="jean.dupont@meyrinfc.ch"><small>Sert à relier ce compte à ses données dans les applications.</small></div>
    <div class="m-field"><label>Mot de passe</label><input type="password" id="add-pw" placeholder="Min. 6 caractères"></div>
    <div class="m-field">
      <label>Rôle</label>
      <select id="add-role">
        <!-- Rempli dynamiquement depuis roles.json par fillRoleSelects(). -->
        <option value="">Chargement des rôles...</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-add-user')">Annuler</button>
      <button class="btn btn-primary" onclick="createUser()">Créer</button>
    </div>
  </div>
</div>

<!-- ── Modal : Modifier utilisateur ── -->
<div class="modal-overlay" id="modal-edit-user">
  <div class="modal">
    <h3>Modifier l'utilisateur</h3>
    <div class="modal-error" id="edit-user-error"></div>
    <input type="hidden" id="edit-id">
    <div class="m-field"><label>Prénom Nom</label><input type="text" id="edit-name"></div>
    <div class="m-field"><label>E-mail</label><input type="email" id="edit-email" placeholder="jean.dupont@meyrinfc.ch"><small>Sert à relier ce compte à ses données dans les applications.</small></div>
    <div class="m-field">
      <label>Rôle</label>
      <select id="edit-role">
        <!-- Rempli dynamiquement depuis roles.json par fillRoleSelects(). -->
        <option value="">Chargement des rôles...</option>
      </select>
    </div>
    <div class="m-field"><label>Nouveau mot de passe <small style="font-weight:400">(laisser vide pour ne pas changer)</small></label><input type="password" id="edit-pw" placeholder="Laisser vide pour ne pas modifier"></div>
    <div class="m-field" style="display:flex;align-items:center;gap:10px">
      <label style="margin:0">Compte actif</label>
      <label class="switch"><input type="checkbox" id="edit-active" checked><span class="switch-slider"></span></label>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-edit-user')">Annuler</button>
      <button class="btn btn-primary" onclick="updateUser()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- ── Modal : Permissions détaillées d'un rôle ── -->
<div class="modal-overlay" id="modal-perms">
  <div class="modal" style="max-width:620px">
    <h3>Permissions · <span id="perms-role-name"></span></h3>
    <div class="s-desc" style="margin:-6px 0 14px">Décochez ce que ce rôle ne doit pas pouvoir faire. Une application dont toutes les permissions sont décochées devient inaccessible.</div>
    <div class="modal-error" id="perms-error"></div>
    <input type="hidden" id="perms-role-key">
    <div id="perms-body" style="max-height:52vh;overflow-y:auto;margin:0 -4px;padding:0 4px"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-perms')">Annuler</button>
      <button class="btn btn-primary" onclick="savePerms()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- ── Modal : Ajouter rôle ── -->
<div class="modal-overlay" id="modal-add-role">
  <div class="modal">
    <h3>Ajouter un rôle</h3>
    <div class="modal-error" id="add-role-error"></div>
    <div class="m-field">
      <label>Nom du rôle</label>
      <input type="text" id="add-role-label" placeholder="Ex : Bénévole">
    </div>
    <div class="m-field">
      <label>Couleur</label>
      <div class="color-palette" id="add-role-colors" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
        <!-- Généré par JS via renderColorPalette() -->
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-add-role')">Annuler</button>
      <button class="btn btn-primary" onclick="createRole()">Créer</button>
    </div>
  </div>
</div>

<!-- ── Modal : Modifier rôle ── -->
<div class="modal-overlay" id="modal-edit-role">
  <div class="modal">
    <h3>Modifier le rôle</h3>
    <div class="modal-error" id="edit-role-error"></div>
    <input type="hidden" id="edit-role-key">
    <div class="m-field">
      <label>Nom du rôle</label>
      <input type="text" id="edit-role-label">
    </div>
    <div class="m-field">
      <label>Couleur</label>
      <div class="color-palette" id="edit-role-colors" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
        <!-- Généré par JS -->
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-edit-role')">Annuler</button>
      <button class="btn btn-primary" onclick="updateRoleMeta()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- ── Modale saison (référentiel club) ── -->
<div class="modal-overlay" id="modal-club-season">
  <div class="modal">
    <h3 id="club-season-title">Nouvelle saison</h3>
    <div class="modal-error" id="club-season-error"></div>
    <div class="m-field">
      <label>Nom de la saison</label>
      <input type="text" id="club-season-label" placeholder="2026-2027">
    </div>
    <div class="m-field">
      <label>Début</label>
      <input type="date" id="club-season-start">
    </div>
    <div class="m-field">
      <label>Fin</label>
      <input type="date" id="club-season-end">
    </div>
    <div class="m-field" id="club-season-copy-field">
      <label>Reprendre les équipes de</label>
      <select id="club-season-copy"></select>
      <small>Les équipes gardent leur identité : renommer une équipe dans la nouvelle saison ne touche pas aux saisons précédentes ni à l'historique des modules.</small>
    </div>
    <div class="modal-actions">
      <button class="btn btn-danger" id="club-season-delete" onclick="deleteClubSeason()" style="margin-right:auto">Supprimer</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-club-season')">Annuler</button>
      <button class="btn btn-primary" onclick="saveClubSeason()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- ── Toast ── -->
<div class="toast" id="toast"></div>

<?php endif ?>

<script>
// ── Navigation settings ─────────────────────────────────────────────────────
function showPage(id) {
  document.querySelectorAll('.s-page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.s-nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + id)?.classList.add('active');
  document.querySelector(`[data-page="${id}"]`)?.classList.add('active');
  const loaders = { users:'loadUsers', roles:'loadRoles', apps:'loadApps', club:'loadClub', logs:'loadLogs', sessions:'loadSessions', general:'loadGeneral' };
  if (loaders[id]) window[loaders[id]]?.();
}

// ── Toast ────────────────────────────────────────────────────────────────────
function toast(msg, type='success') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'toast ' + type + ' show';
  setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Modals ───────────────────────────────────────────────────────────────────
function openModal(id) {
  document.getElementById(id)?.classList.add('open');
  if (id === 'modal-add-role') renderColorPalette('add-role-colors', null);
}
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

// ── API helper ───────────────────────────────────────────────────────────────
async function api(action, data={}, method='GET') {
  let url = '/api.php?action=' + action;
  /* En GET, les données passent en paramètres d'URL. Sans ça un appel du type
     api('club_structure', {season_id}) perdait silencieusement son filtre et
     renvoyait toujours la saison courante. */
  if (method === 'GET') {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(data).filter(([, v]) => v !== undefined && v !== null && v !== ''))
    ).toString();
    if (qs) url += '&' + qs;
  }
  const opts = method === 'GET'
    ? { method: 'GET' }
    : { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...data}) };
  const r = await fetch(url, opts);
  return r.json();
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmtDate(ts) {
  if (!ts) return '—';
  return new Date(ts).toLocaleString('fr-CH', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
/* Identifiant du compte connecté : on ne propose pas de supprimer son propre
   compte. Le serveur refuse en plus de retirer le dernier administrateur,
   quel qu'il soit (l'ancienne protection ciblait `usr_1` en dur, un
   identifiant qui n'existe sur aucune installation réelle). */
const _me     = <?= json_encode($session['sub'] ?? '') ?>;
const _myRole = <?= json_encode($session['role'] ?? '') ?>;
let _rolesCache = {};
function roleBadge(role) {
  const r = _rolesCache[role] || {label:role,color:'#6E6C61',bg:'#F0EDE4'};
  return `<span class="badge" style="background:${r.bg};color:${r.color}"><span class="badge-dot"></span>${esc(r.label)}</span>`;
}

// ── USERS ────────────────────────────────────────────────────────────────────
/* Recharge le cache des rôles. `force` sert après création ou suppression d'un
   rôle, pour que les listes déroulantes reflètent la réalité sans rechargement
   de page. */
async function ensureRoles(force) {
  if (!force && Object.keys(_rolesCache).length) return;
  const rr = await api('roles');
  if (!rr.ok) return;
  _rolesCache = {};
  _rolesRaw   = rr.roles || {};
  Object.entries(rr.roles).forEach(([key, role]) => {
    _rolesCache[key] = {label: role.label, color: role.color, bg: role.color_bg};
  });
}

/* Les listes de rôles des modales utilisateur étaient écrites en dur dans le
   HTML (stagiaire/staff/admin) : tout rôle créé ensuite restait invisible et
   donc inattribuable. Elles sont désormais construites depuis roles.json. */
function fillRoleSelects(selected) {
  const opts = Object.entries(_rolesCache)
    .map(([key, r]) => `<option value="${esc(key)}">${esc(r.label)}</option>`).join('');
  ['add-role', 'edit-role'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const keep = el.value;
    el.innerHTML = opts;
    const want = (id === 'edit-role' && selected) ? selected : keep;
    if (want && _rolesCache[want]) el.value = want;
  });
}

async function loadUsers() {
  await ensureRoles();
  fillRoleSelects();
  const res = await api('users');
  const tbody = document.getElementById('users-tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="6" style="color:var(--rouge);padding:20px">${res.error}</td></tr>`; return; }
  tbody.innerHTML = res.users.map(u => `
    <tr>
      <td style="font-weight:600">${esc(u.name)}
        <div style="font-weight:400;font-size:11.5px;color:${u.email ? 'var(--gris-2)' : 'var(--rouge)'}">${u.email ? esc(u.email) : 'e-mail manquant'}</div>
      </td>
      <td><code style="font-size:12px;background:var(--bg);padding:2px 7px;border-radius:5px">${esc(u.login)}</code></td>
      <td>${roleBadge(u.role)}</td>
      <td>${u.active ? '<span class="badge" style="background:var(--vert-bg);color:var(--vert)"><span class="badge-dot"></span>Actif</span>' : '<span class="badge" style="background:var(--rouge-bg);color:var(--rouge)"><span class="badge-dot"></span>Inactif</span>'}</td>
      <td style="color:var(--gris-2)">${fmtDate(u.last_login)}</td>
      <td style="text-align:right">
        <button class="btn btn-ghost btn-sm" onclick='editUser(${JSON.stringify(u)})' style="margin-right:6px">Modifier</button>
        ${u.id !== _me ? `<button class="btn btn-danger btn-sm" onclick="deleteUser('${esc(u.id)}','${esc(u.name)}')">Supprimer</button>` : '<span style="font-size:11px;color:var(--gris-2)">Votre compte</span>'}
      </td>
    </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--gris-2);padding:20px">Aucun utilisateur.</td></tr>';
}

async function openAddUser() {
  await ensureRoles();
  fillRoleSelects();
  /* Présélection du rôle le moins doté plutôt que du premier de la liste :
     créer un compte trop puissant par inattention doit être impossible en
     laissant simplement le champ tel quel. */
  const sel = document.getElementById('add-role');
  const keys = Object.keys(_rolesCache);
  if (sel && keys.length) {
    keys.sort((a, b) => ((_rolesRaw[a] || {}).apps || []).length - ((_rolesRaw[b] || {}).apps || []).length);
    sel.value = keys[0];
  }
  openModal('modal-add-user');
}

async function editUser(u) {
  await ensureRoles();
  /* La liste est remplie avant d'y sélectionner le rôle : sinon un rôle
     absent des options serait silencieusement ignoré par le navigateur, et
     l'écran afficherait un rôle qui n'est pas celui de la personne. */
  fillRoleSelects(u.role);
  document.getElementById('edit-id').value = u.id;
  document.getElementById('edit-name').value = u.name;
  document.getElementById('edit-email').value = u.email || '';
  document.getElementById('edit-role').value = u.role;
  document.getElementById('edit-active').checked = !!u.active;
  document.getElementById('edit-pw').value = '';
  document.getElementById('edit-user-error').style.display = 'none';
  openModal('modal-edit-user');
}

async function createUser() {
  const err = document.getElementById('add-user-error');
  const data = { name: document.getElementById('add-name').value, login: document.getElementById('add-login').value, email: document.getElementById('add-email').value, password: document.getElementById('add-pw').value, role: document.getElementById('add-role').value };
  const res = await api('create_user', data, 'POST');
  if (!res.ok) { err.textContent = res.error; err.style.display='block'; return; }
  closeModal('modal-add-user');
  toast(res.message);
  loadUsers();
}

async function updateUser() {
  const err = document.getElementById('edit-user-error');
  /* Se retirer soi-même l'accès à l'administration est autorisé tant qu'un
     autre administrateur existe (le serveur refuse le dernier), mais ça ne
     doit jamais arriver par surprise : sans cet avertissement, on se retrouve
     enfermé dehors sans comprendre ce qui s'est passé. */
  {
    const id = document.getElementById('edit-id').value;
    const newRole = document.getElementById('edit-role').value;
    const stillActive = document.getElementById('edit-active').checked;
    const wasAdmin = !!(_rolesRaw[_myRole] || {}).can_access_settings;
    const willBeAdmin = stillActive && !!(_rolesRaw[newRole] || {}).can_access_settings;
    if (id === _me && wasAdmin && !willBeAdmin) {
      const ok = confirm(
        "Vous êtes sur le point de retirer VOTRE propre accès à l'administration.\n\n"
      + "Vous perdrez immédiatement l'accès à cet écran et il vous faudra un autre "
      + "administrateur pour vous le rendre.\n\nContinuer ?");
      if (!ok) return;
    }
  }
  const data = { id: document.getElementById('edit-id').value, name: document.getElementById('edit-name').value, email: document.getElementById('edit-email').value, role: document.getElementById('edit-role').value, active: document.getElementById('edit-active').checked, password: document.getElementById('edit-pw').value };
  const res = await api('update_user', data, 'POST');
  if (!res.ok) { err.textContent = res.error; err.style.display='block'; return; }
  closeModal('modal-edit-user');
  toast(res.message);
  loadUsers();
}

async function deleteUser(id, name) {
  if (!confirm(`Supprimer l'utilisateur "${name}" ?`)) return;
  const res = await api('delete_user', {id}, 'POST');
  res.ok ? (toast(res.message), loadUsers()) : toast(res.error, 'error');
}

// ── ROLES ────────────────────────────────────────────────────────────────────
let _apps = [], _catalog = {}, _rolesRaw = {};
async function loadRoles() {
  const [rr, ar, cr] = await Promise.all([api('roles'), api('apps'), api('permissions_catalog')]);
  if (!rr.ok) return;
  _apps = ar.apps || [];
  _catalog = (cr && cr.ok) ? (cr.catalog || {}) : {};
  _rolesRaw = rr.roles || {};
  _rolesCache = {};
  Object.entries(rr.roles).forEach(([key, role]) => {
    _rolesCache[key] = {label: role.label, color: role.color, bg: role.color_bg};
  });
  fillRoleSelects();
  const thead = document.getElementById('roles-thead');
  const tbody = document.getElementById('roles-tbody');
  thead.innerHTML = '<th>Rôle</th>' + _apps.map(a => `<th>${esc(a.name)}</th>`).join('') + '<th></th>';
  tbody.innerHTML = Object.entries(rr.roles).map(([key, role]) => `
    <tr data-role="${key}">
      <td>${roleBadge(key)}</td>
      ${_apps.map(a => {
        /* Le rôle système a toutes les applications, y compris celles ajoutées
           après sa création : ses interrupteurs sont donc affichés cochés, et
           non d'après sa liste enregistrée qui, elle, n'est plus consultée. */
        const on = key === 'admin' ? true : role.apps.includes(a.slug);
        return `<td><label class="switch"><input type="checkbox" data-app="${a.slug}" ${on?'checked':''}${key==='admin'?'disabled':''}><span class="switch-slider"></span></label></td>`;
      }).join('')}
      <td style="text-align:right;white-space:nowrap">
        ${key !== 'admin'
          ? `<button class="btn btn-ghost btn-sm" onclick="openPerms('${key}')" style="margin-right:6px">${permsLabel(role)}</button><button class="btn btn-ghost btn-sm" onclick='openEditRole(${JSON.stringify({key,label:role.label,color:role.color,color_bg:role.color_bg})})' style="margin-right:6px">Modifier</button><button class="btn btn-danger btn-sm" onclick="deleteRole('${key}','${esc(role.label)}')">Supprimer</button>`
          : '<span style="font-size:11px;color:var(--gris-2)">Rôle système · tous droits</span>'}
      </td>
    </tr>`).join('') || '<tr><td colspan="9" style="text-align:center;color:var(--gris-2);padding:20px">Aucun rôle.</td></tr>';
}

async function saveRoles() {
  const rows = document.querySelectorAll('#roles-tbody tr[data-role]');
  for (const row of rows) {
    const role = row.dataset.role;
    if (role === 'admin') continue;
    const apps = [...row.querySelectorAll('input[data-app]:checked')].map(i => i.dataset.app);
    const res = await api('update_role', {role, apps}, 'POST');
    if (!res.ok) { toast(res.error, 'error'); return; }
  }
  toast('Accès mis à jour.');
}

// ── PERMISSIONS DÉTAILLÉES ───────────────────────────────────────────────────
/* Rend visible un comportement autrement invisible : tant qu'on n'a rien
   restreint, cocher une application dans la matrice accorde TOUTES ses
   permissions. Le bouton dit donc l'état réel, au lieu de laisser croire
   qu'un rôle neuf serait limité. */
function permsLabel(role) {
  const apps = role.apps || [];
  let total = 0;
  apps.forEach(s => { total += Object.keys((_catalog[s] || {}).permissions || {}).length; });
  if (!apps.length) return 'Permissions';
  if (!role.perms)  return `Permissions · tous les droits (${total})`;
  let granted = 0;
  apps.forEach(s => { granted += ((role.perms || {})[s] || []).length; });
  return granted >= total
    ? `Permissions · tous les droits (${total})`
    : `Permissions · ${granted}/${total}`;
}

function openPerms(key) {
  const role = _rolesRaw[key] || {};
  const granted = role.perms || null;   // null = pas encore réglé => tout accordé
  const allowedApps = role.apps || [];
  const body = document.getElementById('perms-body');

  document.getElementById('perms-role-key').value = key;
  document.getElementById('perms-role-name').textContent = role.label || key;

  const blocks = allowedApps.filter(slug => _catalog[slug]).map(slug => {
    const app  = _catalog[slug];
    const has  = granted ? (granted[slug] || []) : Object.keys(app.permissions);
    const list = Object.entries(app.permissions).map(([pk, label]) => `
      <label class="perm-row">
        <input type="checkbox" data-app="${slug}" value="${pk}" ${has.includes(pk) ? 'checked' : ''}>
        <span><strong>${esc(label)}</strong><code>${slug}.${pk}</code></span>
      </label>`).join('');
    return `<div class="perm-block">
      <div class="perm-head">
        <span>${esc(app.label)}</span>
        <button type="button" class="btn btn-ghost btn-sm" onclick="togglePermApp('${slug}')">Tout / rien</button>
      </div>${list}</div>`;
  }).join('');

  body.innerHTML = blocks || `<div style="padding:16px;color:var(--gris-2);font-size:13px">
    Ce rôle n'a accès à aucune application. Activez d'abord un accès dans la matrice, puis revenez ici.</div>`;

  document.getElementById('perms-error').style.display = 'none';
  openModal('modal-perms');
}

function togglePermApp(slug) {
  const boxes = document.querySelectorAll(`#perms-body input[data-app="${slug}"]`);
  const allOn = [...boxes].every(b => b.checked);
  boxes.forEach(b => b.checked = !allOn);
}

async function savePerms() {
  const key = document.getElementById('perms-role-key').value;
  const perms = {};
  document.querySelectorAll('#perms-body input[data-app]:checked').forEach(b => {
    (perms[b.dataset.app] = perms[b.dataset.app] || []).push(b.value);
  });
  const res = await api('update_role', {role: key, apps: Object.keys(perms), perms}, 'POST');
  if (!res.ok) {
    const err = document.getElementById('perms-error');
    err.textContent = res.error; err.style.display = 'block'; return;
  }
  closeModal('modal-perms');
  toast('Permissions mises à jour. Les utilisateurs concernés les voient immédiatement.');
  loadRoles();
}

function openEditRole(r) {
  document.getElementById('edit-role-key').value   = r.key;
  document.getElementById('edit-role-label').value = r.label;
  renderColorPalette('edit-role-colors', r.color);
  document.getElementById('edit-role-error').style.display = 'none';
  openModal('modal-edit-role');
}
async function createRole() {
  const err = document.getElementById('add-role-error');
  const label = document.getElementById('add-role-label').value.trim();
  const sel   = document.querySelector('#add-role-colors .color-swatch.selected');
  if (!label) { err.textContent='Le nom est requis.'; err.style.display='block'; return; }
  if (!sel)   { err.textContent='Choisissez une couleur.'; err.style.display='block'; return; }
  const res = await api('create_role', {label, color: sel.dataset.color, color_bg: sel.dataset.bg}, 'POST');
  if (!res.ok) { err.textContent=res.error; err.style.display='block'; return; }
  closeModal('modal-add-role');
  document.getElementById('add-role-label').value = '';
  document.querySelectorAll('#add-role-colors .color-swatch').forEach(s => s.classList.remove('selected'));
  toast(res.message); loadRoles();
}
async function updateRoleMeta() {
  const err   = document.getElementById('edit-role-error');
  const key   = document.getElementById('edit-role-key').value;
  const label = document.getElementById('edit-role-label').value.trim();
  const sel   = document.querySelector('#edit-role-colors .color-swatch.selected');
  if (!label) { err.textContent='Le nom est requis.'; err.style.display='block'; return; }
  const data  = {role: key, label};
  if (sel) { data.color = sel.dataset.color; data.color_bg = sel.dataset.bg; }
  const res = await api('update_role_meta', data, 'POST');
  if (!res.ok) { err.textContent=res.error; err.style.display='block'; return; }
  closeModal('modal-edit-role'); toast(res.message); loadRoles();
}
async function deleteRole(key, label) {
  if (!confirm(`Supprimer le rôle "${label}" ?`)) return;
  const res = await api('delete_role', {role: key}, 'POST');
  res.ok ? (toast(res.message), loadRoles()) : toast(res.error, 'error');
}

const ROLE_PALETTE = [
  {color:'#7B59D8',bg:'#EEE8FB'},{color:'#3B6FE0',bg:'#E6EDFB'},
  {color:'#E8902A',bg:'#FCEEDB'},{color:'#1F9D5B',bg:'#E4F4EA'},
  {color:'#D8463A',bg:'#FBE7E4'},{color:'#0891B2',bg:'#CFFAFE'},
  {color:'#15140F',bg:'#E9E6DC'},{color:'#6E6C61',bg:'#F0EDE4'},
];
function renderColorPalette(containerId, selectedColor) {
  const c = document.getElementById(containerId);
  if (!c) return;
  c.innerHTML = ROLE_PALETTE.map(p =>
    `<span class="color-swatch${p.color===selectedColor?' selected':''}" data-color="${p.color}" data-bg="${p.bg}" style="background:${p.color}" onclick="this.closest('.color-palette').querySelectorAll('.color-swatch').forEach(s=>s.classList.remove('selected'));this.classList.add('selected')"></span>`
  ).join('');
}

// ── APPS ─────────────────────────────────────────────────────────────────────
async function loadApps() {
  const res = await api('apps');
  const tbody = document.getElementById('apps-tbody');
  if (!res.ok) return;
  tbody.innerHTML = res.apps.map(a => `
    <tr>
      <td><strong>${esc(a.name)}</strong><br><span style="font-size:12px;color:var(--gris)">${esc(a.description)}</span></td>
      <td><a href="${esc(a.url)}" target="_blank" style="color:var(--bleu);font-size:12.5px">${esc(a.url)}</a></td>
      <td>${a.active ? '<span class="badge" style="background:var(--vert-bg);color:var(--vert)"><span class="badge-dot"></span>Actif</span>' : '<span class="badge" style="background:var(--rouge-bg);color:var(--rouge)"><span class="badge-dot"></span>Inactif</span>'}</td>
      <td><label class="switch"><input type="checkbox" ${a.active?'checked':''} onchange="toggleApp('${a.slug}',this.checked)"><span class="switch-slider"></span></label></td>
    </tr>`).join('');
}

async function toggleApp(slug, active) {
  const res = await api('update_app', {slug, active}, 'POST');
  res.ok ? toast(active ? 'Application activée.' : 'Application désactivée.') : toast(res.error, 'error');
}

// ── LOGS ─────────────────────────────────────────────────────────────────────
async function loadLogs() {
  const res = await api('logs&limit=100');
  const tbody = document.getElementById('logs-tbody');
  if (!res.ok) return;
  const actionLabel = a => {
    if (a==='login') return '<span class="log-action" style="background:var(--vert-bg);color:var(--vert)">login</span>';
    if (a==='login_failed') return '<span class="log-action log-failed">login échoué</span>';
    return `<span class="log-action">${esc(a)}</span>`;
  };
  tbody.innerHTML = res.logs.map(l => `
    <tr>
      <td style="color:var(--gris-2);white-space:nowrap">${fmtDate(l.ts)}</td>
      <td><strong>${esc(l.name)}</strong> <span style="color:var(--gris-2);font-size:12px">${esc(l.user)}</span></td>
      <td>${actionLabel(l.action)}</td>
      <td style="color:var(--gris-2);font-family:monospace;font-size:12px">${esc(l.ip)}</td>
    </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--gris-2);padding:20px">Aucune activité.</td></tr>';
}

// ── SESSIONS ─────────────────────────────────────────────────────────────────
async function loadSessions() {
  const res = await api('sessions');
  const tbody = document.getElementById('sessions-tbody');
  if (!res.ok) return;
  tbody.innerHTML = res.sessions.map(s => `
    <tr>
      <td><strong>${esc(s.name)}</strong> <span style="color:var(--gris-2);font-size:12px">${esc(s.login)}</span></td>
      <td>${roleBadge(s.role)}</td>
      <td style="color:var(--gris-2)">${fmtDate(s.last_login)}</td>
    </tr>`).join('') || '<tr><td colspan="3" style="text-align:center;color:var(--gris-2);padding:20px">Aucune session active.</td></tr>';
}

// ── GENERAL ──────────────────────────────────────────────────────────────────
async function loadGeneral() {
  const res = await api('general');
  if (!res.ok) return;
  document.getElementById('g-club-name').value = res.general.club_name || '';
  document.getElementById('g-session').value   = res.general.session_duration || 8;
}

async function saveGeneral() {
  const data = { club_name: document.getElementById('g-club-name').value, session_duration: parseInt(document.getElementById('g-session').value) };
  const res = await api('update_general', data, 'POST');
  res.ok ? toast(res.message) : toast(res.error, 'error');
}

// ── Escape ───────────────────────────────────────────────────────────────────
function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── RÉFÉRENTIEL CLUB (Catégories & Équipes) ─────────────────────────────────
/*
 * L'édition se fait entièrement en mémoire (_club.cats / _club.teams) puis part
 * en un seul enregistrement. Raison : le tri, l'ajout et la suppression de
 * lignes se répercutent les uns sur les autres ; enregistrer champ par champ
 * multiplierait les états intermédiaires incohérents côté serveur.
 *
 * `ref_id` est l'identité stable d'une équipe. Une ligne ajoutée ici n'en a pas
 * encore : le serveur lui en attribue une, et c'est ce même identifiant que RH
 * et Arbitrage utiliseront pour rattacher leurs propres données.
 */
let _club = { seasonId: null, seasons: [], cats: [], teams: [] };

async function loadClub(seasonId) {
  const r = await api('club_structure', { season_id: seasonId || '' });
  if (!r.ok) { toast(r.error || 'Chargement impossible', 'error'); return; }

  _club.seasons  = r.seasons || [];
  _club.seasonId = r.season_id;
  _club.cats     = (r.categories || []).map(c => ({ ...c }));
  _club.teams    = (r.teams || []).map(t => ({
    ref_id: t.ref_id, name: t.name, category_id: t.category_ref_id,
    coach_name: t.coach_name, active: t.active,
  }));

  const sel = document.getElementById('club-season');
  sel.innerHTML = _club.seasons.map(s =>
    `<option value="${esc(s.id)}"${s.id === _club.seasonId ? ' selected' : ''}>${esc(s.label)}</option>`).join('');

  const hasSeason = _club.seasons.length > 0;
  document.getElementById('club-empty').style.display = hasSeason ? 'none' : 'block';
  document.getElementById('club-body').style.display  = hasSeason ? 'block' : 'none';
  sel.style.display = hasSeason ? '' : 'none';

  const season = _club.seasons.find(s => s.id === _club.seasonId);
  document.getElementById('club-season-tag').textContent = season ? season.label : '';

  renderClubCats();
  renderClubTeams();
}

function renderClubCats() {
  const box = document.getElementById('club-cats');
  if (!_club.cats.length) { box.innerHTML = '<div class="club-none">Aucune catégorie.</div>'; return; }
  box.innerHTML = _club.cats.map((c, i) => `
    <div class="club-cat-row">
      <div class="club-move">
        <button onclick="moveClubCat(${i},-1)" title="Monter">&#9650;</button>
        <button onclick="moveClubCat(${i},1)" title="Descendre">&#9660;</button>
      </div>
      <input class="club-inp" value="${esc(c.name)}" oninput="_club.cats[${i}].name=this.value" placeholder="Nom de la catégorie">
      <button class="club-x" onclick="removeClubCat(${i})" title="Supprimer">&times;</button>
    </div>`).join('');
}

function renderClubTeams() {
  const tb = document.getElementById('club-teams-tbody');
  if (!_club.teams.length) {
    tb.innerHTML = '<tr><td colspan="6" class="club-none">Aucune équipe pour cette saison.</td></tr>';
    return;
  }
  const opts = sel => '<option value="">— sans catégorie —</option>' + _club.cats
    .map(c => `<option value="${esc(c.id)}"${c.id === sel ? ' selected' : ''}>${esc(c.name)}</option>`).join('');

  tb.innerHTML = _club.teams.map((t, i) => `
    <tr>
      <td><div class="club-move">
        <button onclick="moveClubTeam(${i},-1)" title="Monter">&#9650;</button>
        <button onclick="moveClubTeam(${i},1)" title="Descendre">&#9660;</button>
      </div></td>
      <td><input class="club-inp" value="${esc(t.name)}" oninput="_club.teams[${i}].name=this.value" placeholder="Nom de l'équipe"></td>
      <td><select class="club-inp" onchange="_club.teams[${i}].category_id=this.value">${opts(t.category_id)}</select></td>
      <td><input class="club-inp" value="${esc(t.coach_name)}" oninput="_club.teams[${i}].coach_name=this.value" placeholder="—"></td>
      <td><label class="switch"><input type="checkbox"${t.active ? ' checked' : ''} onchange="_club.teams[${i}].active=this.checked"><span class="switch-slider"></span></label></td>
      <td><button class="club-x" onclick="removeClubTeam(${i})" title="Retirer de cette saison">&times;</button></td>
    </tr>`).join('');
}

function addClubCat()  { _club.cats.push({ id: '', name: '', sort_order: _club.cats.length, active: true }); renderClubCats(); }
function addClubTeam() { _club.teams.push({ ref_id: '', name: '', category_id: '', coach_name: '', active: true }); renderClubTeams(); }

function removeClubCat(i)  { _club.cats.splice(i, 1); renderClubCats(); }

/* Retirer une équipe la sort de CETTE saison uniquement : son identité et ses
   saisons passées restent intactes, tout comme les matchs et les affectations
   que les modules lui ont rattachés. */
function removeClubTeam(i) {
  const t = _club.teams[i];
  if (t.ref_id && !confirm(`Retirer « ${t.name} » de cette saison ?\n\nSon historique et les données des modules (matchs, affectations) sont conservés. Pour la masquer sans la retirer, décochez plutôt « Active ».`)) return;
  _club.teams.splice(i, 1);
  renderClubTeams();
}

function moveClubCat(i, d)  { const j = i + d; if (j < 0 || j >= _club.cats.length) return;  [_club.cats[i], _club.cats[j]]   = [_club.cats[j], _club.cats[i]];   renderClubCats(); }
function moveClubTeam(i, d) { const j = i + d; if (j < 0 || j >= _club.teams.length) return; [_club.teams[i], _club.teams[j]] = [_club.teams[j], _club.teams[i]]; renderClubTeams(); }

async function saveClubCats() {
  const rows = _club.cats
    .map((c, i) => ({ id: c.id, name: (c.name || '').trim(), sort_order: i, active: c.active !== false }))
    .filter(c => c.name !== '');
  const r = await api('club_save_categories', { categories: rows }, 'POST');
  if (!r.ok) { toast(r.error, 'error'); return; }
  toast(r.message);
  await loadClub(_club.seasonId);
}

async function saveClubTeams() {
  const rows = _club.teams
    .map((t, i) => ({
      ref_id: t.ref_id, name: (t.name || '').trim(), category_id: t.category_id || '',
      coach_name: (t.coach_name || '').trim(), sort_order: i, active: t.active !== false,
    }))
    .filter(t => t.name !== '');
  const r = await api('club_save_teams', { season_id: _club.seasonId, teams: rows }, 'POST');
  if (!r.ok) { toast(r.error, 'error'); return; }
  toast(r.message);
  await loadClub(_club.seasonId);
}

// ── Saisons du référentiel ───────────────────────────────────────────────────
let _clubSeasonEditing = null;

function openClubSeason(isNew) {
  const season = isNew ? null : _club.seasons.find(s => s.id === _club.seasonId);
  if (!isNew && !season) { toast('Aucune saison à modifier', 'error'); return; }
  _clubSeasonEditing = season ? season.id : null;

  document.getElementById('club-season-title').textContent = season ? 'Modifier la saison' : 'Nouvelle saison';
  document.getElementById('club-season-error').style.display = 'none';
  document.getElementById('club-season-delete').style.display = season ? '' : 'none';
  document.getElementById('club-season-copy-field').style.display = season ? 'none' : '';

  if (season) {
    document.getElementById('club-season-label').value = season.label;
    document.getElementById('club-season-start').value = season.start_date;
    document.getElementById('club-season-end').value   = season.end_date;
  } else {
    /* Saison sportive suisse : 1er juillet au 30 juin. Proposée par défaut,
       modifiable. Avant juillet, la saison qui démarre est celle de l'été à venir. */
    const now = new Date();
    const y = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1;
    document.getElementById('club-season-label').value = `${y + 1}-${y + 2}`;
    document.getElementById('club-season-start').value = `${y + 1}-07-01`;
    document.getElementById('club-season-end').value   = `${y + 2}-06-30`;
    document.getElementById('club-season-copy').innerHTML =
      '<option value="">— partir d\'une liste vide —</option>' +
      _club.seasons.map((s, i) => `<option value="${esc(s.id)}"${i === 0 ? ' selected' : ''}>${esc(s.label)}</option>`).join('');
  }
  openModal('modal-club-season');
}

async function saveClubSeason() {
  const err = document.getElementById('club-season-error');
  const payload = {
    id:         _clubSeasonEditing || '',
    label:      document.getElementById('club-season-label').value.trim(),
    start_date: document.getElementById('club-season-start').value,
    end_date:   document.getElementById('club-season-end').value,
    copy_from:  _clubSeasonEditing ? '' : document.getElementById('club-season-copy').value,
  };
  const r = await api('club_save_season', payload, 'POST');
  if (!r.ok) { err.textContent = r.error; err.style.display = 'block'; return; }
  closeModal('modal-club-season');
  toast(r.message);
  await loadClub(r.season_id);
}

async function deleteClubSeason() {
  if (!_clubSeasonEditing) return;
  let r = await api('club_delete_season', { id: _clubSeasonEditing }, 'POST');
  if (!r.ok && r.needs_force) {
    if (!confirm(r.error + '\n\nSupprimer quand même cette saison ?')) return;
    r = await api('club_delete_season', { id: _clubSeasonEditing, force: 1 }, 'POST');
  }
  if (!r.ok) { toast(r.error, 'error'); return; }
  closeModal('modal-club-season');
  toast(r.message);
  await loadClub(null);
}

// ── Auto-load first page ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  <?php if ($page === 'settings'): ?>
  showPage('users');
  <?php endif ?>
});
</script>

</body>
</html>
