<?php
/**
 * api.php — API JSON pour le panneau Paramètres (admin uniquement).
 */
define('ERP_ROOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/jwt.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ────────────────────────────────────────────────────────────────────
$token   = $_COOKIE[COOKIE_NAME] ?? '';
$session = $token ? jwt_decode($token, JWT_SECRET) : null;

function require_auth(): void {
    global $session;
    if (!$session) { json_die(401, 'Non authentifié.'); }
}
function require_admin(): void {
    global $session;
    require_auth();
    if (!($session['settings'] ?? false)) { json_die(403, 'Accès réservé aux administrateurs.'); }
}
function json_die(int $code, string $msg, array $data = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Helpers fichiers ────────────────────────────────────────────────────────
function read_json(string $file): array {
    return json_decode(file_get_contents(DATA_DIR . $file), true) ?: [];
}
function write_json(string $file, array $data): void {
    file_put_contents(DATA_DIR . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function uid(): string {
    return 'usr_' . substr(md5(uniqid('', true)), 0, 8);
}

// ── Router ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Lire le body JSON si applicable
$body = [];
if ($method === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
}
$input = array_merge($_POST, $body);

switch ($action) {

    // ── UTILISATEURS ───────────────────────────────────────────────────────
    case 'users':
        require_admin();
        $users = read_json('users.json');
        // Ne pas exposer les hash
        $safe = array_map(fn($u) => array_diff_key($u, ['password_hash' => 1]), $users);
        json_ok(['users' => array_values($safe)]);

    case 'create_user':
        require_admin();
        $login = trim($input['login'] ?? '');
        $name  = trim($input['name'] ?? '');
        $pw    = $input['password'] ?? '';
        $role  = $input['role'] ?? 'stagiaire';
        if (!$login || !$name || strlen($pw) < 6) {
            json_die(400, 'Login, nom et mot de passe (6 car. min) requis.');
        }
        $users = read_json('users.json');
        foreach ($users as $u) {
            if ($u['login'] === $login) json_die(409, 'Ce login existe déjà.');
        }
        $roles = read_json('roles.json');
        if (!isset($roles[$role])) json_die(400, 'Rôle invalide.');
        $users[] = [
            'id'            => uid(),
            'login'         => $login,
            'password_hash' => password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]),
            'name'          => $name,
            'role'          => $role,
            'active'        => true,
            'created_at'    => date('Y-m-d'),
            'last_login'    => null,
        ];
        write_json('users.json', $users);
        erp_log($session['login'], $session['name'], "create_user:$login");
        json_ok(['message' => "Utilisateur $login créé."]);

    case 'update_user':
        require_admin();
        $id   = $input['id'] ?? '';
        $users = read_json('users.json');
        $idx  = -1;
        foreach ($users as $i => $u) { if ($u['id'] === $id) { $idx = $i; break; } }
        if ($idx === -1) json_die(404, 'Utilisateur introuvable.');
        if (isset($input['name']))   $users[$idx]['name']   = trim($input['name']);
        if (isset($input['role']))   {
            $roles = read_json('roles.json');
            if (!isset($roles[$input['role']])) json_die(400, 'Rôle invalide.');
            $users[$idx]['role'] = $input['role'];
        }
        if (isset($input['active'])) $users[$idx]['active'] = (bool)$input['active'];
        if (!empty($input['password']) && strlen($input['password']) >= 6) {
            $users[$idx]['password_hash'] = password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }
        write_json('users.json', $users);
        erp_log($session['login'], $session['name'], "update_user:{$users[$idx]['login']}");
        json_ok(['message' => 'Utilisateur mis à jour.']);

    case 'delete_user':
        require_admin();
        $id = $input['id'] ?? '';
        if ($id === 'usr_1') json_die(403, 'Impossible de supprimer le compte admin principal.');
        $users = read_json('users.json');
        $login_del = '';
        $users = array_filter($users, function($u) use ($id, &$login_del) {
            if ($u['id'] === $id) { $login_del = $u['login']; return false; }
            return true;
        });
        if (!$login_del) json_die(404, 'Utilisateur introuvable.');
        write_json('users.json', array_values($users));
        erp_log($session['login'], $session['name'], "delete_user:$login_del");
        json_ok(['message' => "Utilisateur $login_del supprimé."]);

    // ── RÔLES ──────────────────────────────────────────────────────────────
    case 'roles':
        require_auth();
        json_ok(['roles' => read_json('roles.json')]);

    case 'update_role':
        require_admin();
        $role_key = $input['role'] ?? '';
        $apps     = $input['apps'] ?? [];
        $roles    = read_json('roles.json');
        if (!isset($roles[$role_key])) json_die(404, 'Rôle introuvable.');
        $roles[$role_key]['apps'] = array_values(array_unique($apps));
        write_json('roles.json', $roles);
        erp_log($session['login'], $session['name'], "update_role:$role_key");
        json_ok(['message' => "Rôle $role_key mis à jour."]);

    case 'create_role':
        require_admin();
        $label    = trim($input['label'] ?? '');
        $color    = $input['color']    ?? '#6E6C61';
        $color_bg = $input['color_bg'] ?? '#F0EDE4';
        if (!$label) json_die(400, 'Le nom du rôle est requis.');
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_',
            iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label)), '_'));
        if (!$key) json_die(400, 'Nom de rôle invalide.');
        $roles = read_json('roles.json');
        if (isset($roles[$key])) json_die(409, 'Un rôle avec ce nom existe déjà.');
        $roles[$key] = [
            'label'                => $label,
            'color'                => $color,
            'color_bg'             => $color_bg,
            'apps'                 => [],
            'can_access_settings'  => false,
        ];
        write_json('roles.json', $roles);
        erp_log($session['login'], $session['name'], "create_role:$key");
        json_ok(['message' => "Rôle \"$label\" créé.", 'key' => $key]);

    case 'update_role_meta':
        require_admin();
        $role_key = $input['role'] ?? '';
        if ($role_key === 'admin') json_die(403, 'Le rôle Admin ne peut pas être modifié.');
        $roles = read_json('roles.json');
        if (!isset($roles[$role_key])) json_die(404, 'Rôle introuvable.');
        if (!empty($input['label']))    $roles[$role_key]['label']    = trim($input['label']);
        if (!empty($input['color']))    $roles[$role_key]['color']    = $input['color'];
        if (!empty($input['color_bg'])) $roles[$role_key]['color_bg'] = $input['color_bg'];
        write_json('roles.json', $roles);
        erp_log($session['login'], $session['name'], "update_role_meta:$role_key");
        json_ok(['message' => 'Rôle mis à jour.']);

    case 'delete_role':
        require_admin();
        $role_key = $input['role'] ?? '';
        if ($role_key === 'admin') json_die(403, 'Le rôle Admin ne peut pas être supprimé.');
        $roles = read_json('roles.json');
        if (!isset($roles[$role_key])) json_die(404, 'Rôle introuvable.');
        $users = read_json('users.json');
        foreach ($users as $u) {
            if ($u['role'] === $role_key) json_die(409, 'Des utilisateurs ont encore ce rôle. Changez-les d\'abord.');
        }
        unset($roles[$role_key]);
        write_json('roles.json', $roles);
        erp_log($session['login'], $session['name'], "delete_role:$role_key");
        json_ok(['message' => 'Rôle supprimé.']);

    // ── APPLICATIONS ────────────────────────────────────────────────────────
    case 'apps':
        require_auth();
        json_ok(['apps' => read_json('apps.json')]);

    case 'update_app':
        require_admin();
        $slug = $input['slug'] ?? '';
        $apps = read_json('apps.json');
        $idx  = -1;
        foreach ($apps as $i => $a) { if ($a['slug'] === $slug) { $idx = $i; break; } }
        if ($idx === -1) json_die(404, 'Application introuvable.');
        if (isset($input['active']))      $apps[$idx]['active']      = (bool)$input['active'];
        if (isset($input['name']))        $apps[$idx]['name']        = trim($input['name']);
        if (isset($input['description'])) $apps[$idx]['description'] = trim($input['description']);
        if (isset($input['url']))         $apps[$idx]['url']         = trim($input['url']);
        write_json('apps.json', $apps);
        erp_log($session['login'], $session['name'], "update_app:$slug");
        json_ok(['message' => "Application $slug mise à jour."]);

    // ── JOURNAL ─────────────────────────────────────────────────────────────
    case 'logs':
        require_admin();
        $logs  = read_json('logs.json');
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        json_ok(['logs' => array_slice($logs, 0, $limit)]);

    // ── SESSIONS ACTIVES ────────────────────────────────────────────────────
    case 'sessions':
        require_admin();
        $users = read_json('users.json');
        $cutoff = time() - JWT_DURATION;
        $active = [];
        foreach ($users as $u) {
            if (!$u['last_login']) continue;
            if (strtotime($u['last_login']) >= $cutoff) {
                $active[] = [
                    'login'      => $u['login'],
                    'name'       => $u['name'],
                    'role'       => $u['role'],
                    'last_login' => $u['last_login'],
                ];
            }
        }
        json_ok(['sessions' => $active]);

    // ── PARAMÈTRES GÉNÉRAUX ─────────────────────────────────────────────────
    case 'general':
        require_admin();
        // Config générale stockée dans data/general.json
        $f = DATA_DIR . 'general.json';
        if (!file_exists($f)) file_put_contents($f, json_encode(['club_name' => CLUB_NAME, 'session_duration' => JWT_DURATION / 3600]));
        json_ok(['general' => json_decode(file_get_contents($f), true)]);

    case 'update_general':
        require_admin();
        $f    = DATA_DIR . 'general.json';
        $conf = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        if (isset($input['club_name'])) $conf['club_name'] = trim($input['club_name']);
        if (isset($input['session_duration'])) $conf['session_duration'] = max(1, min(24, (int)$input['session_duration']));
        file_put_contents($f, json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        json_ok(['message' => 'Paramètres généraux mis à jour.']);

    default:
        json_die(400, 'Action inconnue.');
}

// ── Log helper ──────────────────────────────────────────────────────────────
function erp_log(string $user, string $name, string $action): void {
    $f    = DATA_DIR . 'logs.json';
    $logs = json_decode(file_get_contents($f), true) ?: [];
    array_unshift($logs, [
        'user'   => $user,
        'name'   => $name,
        'action' => $action,
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        'ts'     => date('c'),
    ]);
    file_put_contents($f, json_encode(array_slice($logs, 0, 1000), JSON_PRETTY_PRINT));
}
