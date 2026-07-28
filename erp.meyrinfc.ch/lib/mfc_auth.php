<?php
/**
 * mfc_auth.php — Socle d'authentification unique des applications Meyrin FC.
 *
 * Remplace à terme les guard.php dupliqués dans chaque micro-SaaS. Ce fichier
 * est la SEULE source de vérité pour le secret JWT, la lecture de la session,
 * les permissions et la révocation.
 *
 * Usage dans une page HTML (index.php d'une app) :
 *   require_once '/chemin/vers/erp.meyrinfc.ch/lib/mfc_auth.php';
 *   $user = mfc_require_login('sponsors');   // redirige vers l'ERP si non connecté
 *
 * Usage dans une API JSON (api.php d'une app) :
 *   require_once '/chemin/vers/erp.meyrinfc.ch/lib/mfc_auth.php';
 *   $user = mfc_require_api('sponsors');     // renvoie 401 JSON si non connecté
 *   mfc_require_perm('sponsors.edit');       // renvoie 403 JSON si droit manquant
 *
 * ÉTAPE 1 : ce fichier est déposé mais n'est encore branché sur aucune app.
 */

if (defined('MFC_AUTH_LOADED')) return;
define('MFC_AUTH_LOADED', true);

/* config.php de l'ERP commence par un BOM UTF-8. Sans tampon de sortie, ces
   3 octets partiraient avant tout header()/setcookie() et les feraient échouer
   silencieusement (problème déjà rencontré sur le module RH). */
if (!headers_sent() && ob_get_level() === 0) ob_start();

if (!defined('ERP_ROOT')) define('ERP_ROOT', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/jwt.php';

/* ================================================================= CHEMINS */

/** Racine de l'ERP (dossier contenant config.php). */
function mfc_erp_root(): string {
    return dirname(__DIR__);
}

/** Dossier parent commun à tous les sous-domaines (hypothèse : dossiers frères). */
function mfc_sites_root(): string {
    return dirname(mfc_erp_root());
}

/**
 * Chemin disque d'une app à partir de son slug, ou null si introuvable.
 * Le module RH est un sous-dossier de l'ERP, les autres sont des dossiers frères.
 */
function mfc_app_path(string $slug): ?string {
    if ($slug === 'rh')  return is_dir(mfc_erp_root() . '/rh')  ? mfc_erp_root() . '/rh'  : null;
    if ($slug === 'erp') return mfc_erp_root();
    $candidates = [
        mfc_sites_root() . '/' . $slug . '.meyrinfc.ch',
        mfc_sites_root() . '/' . ucfirst($slug) . '.meyrinfc.ch', // certaines sauvegardes sont capitalisées
        mfc_sites_root() . '/' . $slug,
    ];
    foreach ($candidates as $p) {
        if (is_dir($p)) return $p;
    }
    return null;
}

/* ============================================================= PERMISSIONS */

/**
 * Catalogue complet des permissions déclarées (lib/permissions.json).
 *
 * Volontairement placé dans lib/ et NON dans data/ : data/ contient l'état
 * vivant (users.json, roles.json, logs.json) qui ne doit jamais être écrasé
 * par un déploiement FTP. Le catalogue, lui, est du code : il se déploie.
 */
function mfc_permission_catalog(): array {
    static $cat = null;
    if ($cat !== null) return $cat;
    $f = __DIR__ . '/permissions.json';
    $cat = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    return $cat;
}

/** Liste plate des clés de permission d'une app. Ex : ['sponsors.view', ...]. */
function mfc_app_permission_keys(string $slug): array {
    $cat  = mfc_permission_catalog();
    $keys = [];
    foreach ($cat[$slug]['permissions'] ?? [] as $key => $meta) {
        $keys[] = $key;
    }
    return $keys;
}

/**
 * Permissions effectives d'un rôle, sous la forme ['app' => ['perm', ...]].
 *
 * Rétrocompatibilité : un rôle qui n'a pas encore de bloc "perms" (format
 * actuel de roles.json, une simple liste d'apps) reçoit TOUTES les permissions
 * des apps auxquelles il a accès. Le comportement reste donc strictement
 * identique à aujourd'hui tant que les rôles n'ont pas été enrichis.
 */
function mfc_role_permissions(array $role): array {
    if (!empty($role['perms']) && is_array($role['perms'])) {
        $out = [];
        foreach ($role['perms'] as $app => $perms) {
            if (is_array($perms) && $perms) $out[$app] = array_values($perms);
        }
        return $out;
    }
    $out = [];
    foreach ($role['apps'] ?? [] as $app) {
        $keys = mfc_app_permission_keys($app);
        $out[$app] = $keys ?: ['*'];   // '*' = accès total si l'app n'a pas encore de catalogue
    }
    return $out;
}

/* ================================================================ SESSION */

/** Session décodée depuis le cookie JWT, ou null. Ne vérifie pas la révocation. */
function mfc_raw_session(): ?array {
    static $s = false;
    if ($s !== false) return $s;
    $token = $_COOKIE[COOKIE_NAME] ?? '';
    $s = $token ? jwt_decode($token, JWT_SECRET) : null;
    return $s;
}

/**
 * Horodatage de révocation d'un utilisateur (0 si aucun).
 * Tout jeton émis AVANT cet horodatage est refusé : désactiver un compte ou
 * changer ses droits dans l'ERP prend effet à la requête suivante, sans
 * attendre l'expiration naturelle du jeton (8h).
 */
function mfc_revoked_at(string $erp_id): int {
    static $rev = null;
    if ($rev === null) {
        $f   = DATA_DIR . 'revocations.json';
        $rev = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }
    return (int)($rev[$erp_id] ?? 0);
}

/** Session valide (signature + expiration + révocation), ou null. */
function mfc_session(): ?array {
    $s = mfc_raw_session();
    if (!$s) return null;
    $erp_id = (string)($s['sub'] ?? '');
    if ($erp_id === '') return null;
    if ((int)($s['iat'] ?? 0) < mfc_revoked_at($erp_id)) return null;
    return $s;
}

/** Permissions portées par la session courante, ['app' => ['perm', ...]]. */
function mfc_session_perms(): array {
    $s = mfc_session();
    if (!$s) return [];
    if (!empty($s['perms']) && is_array($s['perms'])) return $s['perms'];
    /* Jeton émis avant la migration : on retombe sur la liste d'apps, avec
       toutes les permissions. Évite de déconnecter tout le monde au déploiement. */
    return mfc_role_permissions(['apps' => $s['apps'] ?? []]);
}

/* ============================================================ VÉRIFICATIONS */

/** L'utilisateur courant a-t-il accès à l'app (au moins une permission) ? */
function mfc_can_access(string $app): bool {
    $perms = mfc_session_perms();
    return !empty($perms[$app]);
}

/**
 * L'utilisateur courant détient-il la permission demandée ?
 * $perm au format 'app.domaine.action', ex : 'rh.payroll.view'.
 */
function mfc_can(string $perm): bool {
    $parts = explode('.', $perm, 2);
    if (count($parts) !== 2) return false;
    [$app, $key] = $parts;
    $perms = mfc_session_perms()[$app] ?? [];
    if (in_array('*', $perms, true)) return true;
    return in_array($key, $perms, true) || in_array($perm, $perms, true);
}

/* =================================================== POINTS D'ENTRÉE PAGES */

/**
 * URL absolue de la page courante, pour que l'ERP sache où renvoyer la
 * personne après connexion. L'ancien guard ne transmettait que le chemin
 * (`/index.php`), que l'ERP interprétait sur son propre domaine : on revenait
 * donc toujours sur le portail, jamais dans l'application d'origine.
 */
function mfc_current_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return $host ? $scheme . '://' . $host . $uri : $uri;
}

/** Page HTML : exige une session valide et l'accès à l'app, sinon redirige/refuse. */
function mfc_require_login(string $app): array {
    $s = mfc_session();
    if (!$s) {
        header('Location: ' . ERP_URL . '/?redirect=' . urlencode(mfc_current_url()));
        exit;
    }
    if (!mfc_can_access($app)) {
        http_response_code(403);
        mfc_render_denied($app);
        exit;
    }
    return $s;
}

/** API JSON : exige une session valide et l'accès à l'app, sinon 401/403 JSON. */
function mfc_require_api(string $app): array {
    $s = mfc_session();
    if (!$s) mfc_json_die(401, 'Session expirée. Reconnectez-vous à l\'ERP.');
    if (!mfc_can_access($app)) mfc_json_die(403, 'Vous n\'avez pas accès à cette application.');
    return $s;
}

/** API JSON : exige une permission précise, sinon 403. */
function mfc_require_perm(string $perm): void {
    if (!mfc_can($perm)) {
        mfc_json_die(403, 'Droit insuffisant pour cette action.', ['required' => $perm]);
    }
}

function mfc_json_die(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function mfc_render_denied(string $app): void {
    $catalog = mfc_permission_catalog();
    $label   = $catalog[$app]['label'] ?? $app;
    ?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accès refusé · Meyrin FC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Sora:wght@800&display=swap" rel="stylesheet">
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Inter",sans-serif;background:#F6F4ED;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{background:#fff;border:1px solid #E9E6DC;border-radius:16px;padding:48px 40px;text-align:center;max-width:400px;width:100%}.icon{font-size:40px;margin-bottom:16px}h1{font-family:"Sora";font-size:20px;color:#15140F;margin-bottom:8px}p{font-size:14px;color:#6E6C61;line-height:1.6;margin-bottom:24px}a{display:inline-block;padding:10px 20px;background:#15140F;color:#FFD000;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;font-family:"Sora"}</style>
</head><body>
<div class="card">
  <div class="icon">🔒</div>
  <h1>Accès refusé</h1>
  <p>Vous n'avez pas les droits nécessaires pour accéder à <strong><?= htmlspecialchars($label) ?></strong>.<br>Contactez l'administrateur si nécessaire.</p>
  <a href="<?= htmlspecialchars(ERP_URL) ?>">Retour à l'ERP</a>
</div>
</body></html><?php
}

/* ======================================= ANNUAIRE LOCAL (non destructif) */

/**
 * Retrouve ou crée la ligne `users` locale correspondant à l'utilisateur ERP.
 *
 * RÈGLE ABSOLUE : cette fonction ne SUPPRIME ni ne MODIFIE jamais une ligne
 * existante autrement qu'en renseignant son erp_id. Tout l'historique métier
 * (owner_id, created_by, user_id des journaux...) reste intact et attribué.
 *
 * Non utilisée à l'étape 1 : fournie ici pour que le socle soit complet.
 */
function mfc_local_user(PDO $pdo, array $session): array {
    $erp_id = (string)$session['sub'];

    /* La colonne erp_id est ajoutée si absente. ALTER TABLE ADD COLUMN est une
       opération additive : aucune donnée existante n'est touchée. */
    $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $has  = false;
    foreach ($cols as $c) { if ($c['name'] === 'erp_id') { $has = true; break; } }
    if (!$has) $pdo->exec('ALTER TABLE users ADD COLUMN erp_id TEXT');

    $st = $pdo->prepare('SELECT * FROM users WHERE erp_id = ? LIMIT 1');
    $st->execute([$erp_id]);
    if ($u = $st->fetch()) return $u;

    /* Aucun compte local rattaché : on en crée un. Le rapprochement avec un
       éventuel compte historique se fait via le script de rapprochement, pas
       automatiquement ici (aucune clé commune fiable entre login ERP et e-mail). */
    $name  = (string)($session['name'] ?? $session['login'] ?? 'Utilisateur');
    $email = strtolower((string)($session['login'] ?? $erp_id)) . '@erp.local';
    $ins   = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, active, erp_id) VALUES (?,?,?,?,1,?)'
    );
    $ins->execute([$name, $email, '__ERP_SSO__', 'admin', $erp_id]);

    $st->execute([$erp_id]);
    return $st->fetch();
}
