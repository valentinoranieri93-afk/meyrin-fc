<?php
define('ERP_ROOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/jwt.php';

// ── Setup check ─────────────────────────────────────────────────────────────
$users = json_decode(file_get_contents(DATA_DIR . 'users.json'), true) ?: [];
if (empty($users) || ($users[0]['password_hash'] ?? '') === '__SETUP_REQUIRED__') {
    header('Location: /setup.php'); exit;
}

// ── Session ──────────────────────────────────────────────────────────────────
$session = null;
$token   = $_COOKIE[COOKIE_NAME] ?? '';
if ($token) $session = jwt_decode($token, JWT_SECRET);

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
        $roles = json_decode(file_get_contents(DATA_DIR . 'roles.json'), true);
        $rd    = $roles[$found['role']] ?? [];
        $jwt   = jwt_encode([
            'sub'      => $found['id'],
            'name'     => $found['name'],
            'login'    => $found['login'],
            'role'     => $found['role'],
            'apps'     => $rd['apps'] ?? [],
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
        erp_log($found['login'], $found['name'], 'login');
        foreach ($users as &$u) {
            if ($u['id'] === $found['id']) { $u['last_login'] = date('c'); break; }
        }
        file_put_contents(DATA_DIR . 'users.json', json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $redirect = $_GET['redirect'] ?? '/';
        header('Location: ' . (str_starts_with($redirect, '/') ? $redirect : '/'));
        exit;
    } else {
        $login_error = 'Identifiant ou mot de passe incorrect.';
        erp_log($login ?: '?', '—', 'login_failed');
    }
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
    <a class="app-card" href="<?= htmlspecialchars($app['url']) ?>" target="_blank"
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
        <span class="card-url"><?= htmlspecialchars(parse_url($app['url'], PHP_URL_HOST)) ?></span>
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
        <button class="btn btn-primary" onclick="openModal('modal-add-user')">
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
    <div class="m-field"><label>Mot de passe</label><input type="password" id="add-pw" placeholder="Min. 6 caractères"></div>
    <div class="m-field">
      <label>Rôle</label>
      <select id="add-role">
        <option value="stagiaire">Stagiaire</option>
        <option value="staff">Staff</option>
        <option value="admin">Administrateur</option>
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
    <div class="m-field">
      <label>Rôle</label>
      <select id="edit-role">
        <option value="stagiaire">Stagiaire</option>
        <option value="staff">Staff</option>
        <option value="admin">Administrateur</option>
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
  const loaders = { users:'loadUsers', roles:'loadRoles', apps:'loadApps', logs:'loadLogs', sessions:'loadSessions', general:'loadGeneral' };
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
  const url = '/api.php?action=' + action;
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
let _rolesCache = {};
function roleBadge(role) {
  const r = _rolesCache[role] || {label:role,color:'#6E6C61',bg:'#F0EDE4'};
  return `<span class="badge" style="background:${r.bg};color:${r.color}"><span class="badge-dot"></span>${esc(r.label)}</span>`;
}

// ── USERS ────────────────────────────────────────────────────────────────────
async function loadUsers() {
  if (!Object.keys(_rolesCache).length) {
    const rr = await api('roles');
    if (rr.ok) Object.entries(rr.roles).forEach(([key, role]) => {
      _rolesCache[key] = {label: role.label, color: role.color, bg: role.color_bg};
    });
  }
  const res = await api('users');
  const tbody = document.getElementById('users-tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="6" style="color:var(--rouge);padding:20px">${res.error}</td></tr>`; return; }
  tbody.innerHTML = res.users.map(u => `
    <tr>
      <td style="font-weight:600">${esc(u.name)}</td>
      <td><code style="font-size:12px;background:var(--bg);padding:2px 7px;border-radius:5px">${esc(u.login)}</code></td>
      <td>${roleBadge(u.role)}</td>
      <td>${u.active ? '<span class="badge" style="background:var(--vert-bg);color:var(--vert)"><span class="badge-dot"></span>Actif</span>' : '<span class="badge" style="background:var(--rouge-bg);color:var(--rouge)"><span class="badge-dot"></span>Inactif</span>'}</td>
      <td style="color:var(--gris-2)">${fmtDate(u.last_login)}</td>
      <td style="text-align:right">
        <button class="btn btn-ghost btn-sm" onclick='editUser(${JSON.stringify(u)})' style="margin-right:6px">Modifier</button>
        ${u.id !== 'usr_1' ? `<button class="btn btn-danger btn-sm" onclick="deleteUser('${esc(u.id)}','${esc(u.name)}')">Supprimer</button>` : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--gris-2);padding:20px">Aucun utilisateur.</td></tr>';
}

function editUser(u) {
  document.getElementById('edit-id').value = u.id;
  document.getElementById('edit-name').value = u.name;
  document.getElementById('edit-role').value = u.role;
  document.getElementById('edit-active').checked = !!u.active;
  document.getElementById('edit-pw').value = '';
  document.getElementById('edit-user-error').style.display = 'none';
  openModal('modal-edit-user');
}

async function createUser() {
  const err = document.getElementById('add-user-error');
  const data = { name: document.getElementById('add-name').value, login: document.getElementById('add-login').value, password: document.getElementById('add-pw').value, role: document.getElementById('add-role').value };
  const res = await api('create_user', data, 'POST');
  if (!res.ok) { err.textContent = res.error; err.style.display='block'; return; }
  closeModal('modal-add-user');
  toast(res.message);
  loadUsers();
}

async function updateUser() {
  const err = document.getElementById('edit-user-error');
  const data = { id: document.getElementById('edit-id').value, name: document.getElementById('edit-name').value, role: document.getElementById('edit-role').value, active: document.getElementById('edit-active').checked, password: document.getElementById('edit-pw').value };
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
let _apps = [];
async function loadRoles() {
  const [rr, ar] = await Promise.all([api('roles'), api('apps')]);
  if (!rr.ok) return;
  _apps = ar.apps || [];
  _rolesCache = {};
  Object.entries(rr.roles).forEach(([key, role]) => {
    _rolesCache[key] = {label: role.label, color: role.color, bg: role.color_bg};
  });
  const thead = document.getElementById('roles-thead');
  const tbody = document.getElementById('roles-tbody');
  thead.innerHTML = '<th>Rôle</th>' + _apps.map(a => `<th>${esc(a.name)}</th>`).join('') + '<th></th>';
  tbody.innerHTML = Object.entries(rr.roles).map(([key, role]) => `
    <tr data-role="${key}">
      <td>${roleBadge(key)}</td>
      ${_apps.map(a => `<td><label class="switch"><input type="checkbox" data-app="${a.slug}" ${role.apps.includes(a.slug)?'checked':''}${key==='admin'?'disabled':''}><span class="switch-slider"></span></label></td>`).join('')}
      <td style="text-align:right;white-space:nowrap">
        ${key !== 'admin'
          ? `<button class="btn btn-ghost btn-sm" onclick='openEditRole(${JSON.stringify({key,label:role.label,color:role.color,color_bg:role.color_bg})})' style="margin-right:6px">Modifier</button><button class="btn btn-danger btn-sm" onclick="deleteRole('${key}','${esc(role.label)}')">Supprimer</button>`
          : '<span style="font-size:11px;color:var(--gris-2)">Rôle système</span>'}
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

// ── Auto-load first page ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  <?php if ($page === 'settings'): ?>
  showPage('users');
  <?php endif ?>
});
</script>

</body>
</html>
