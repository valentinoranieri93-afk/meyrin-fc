<?php
/**
 * api.php — API JSON pour le panneau Paramètres (admin uniquement).
 */
define('ERP_ROOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/jwt.php';
require_once __DIR__ . '/lib/mfc_auth.php';
require_once __DIR__ . '/lib/mfc_club.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ────────────────────────────────────────────────────────────────────
/* mfc_session() applique aussi la liste de révocation : un compte désactivé
   ou dont le rôle vient de changer perd l'accès à l'API immédiatement. */
$session = mfc_session();

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

/**
 * Invalide immédiatement les jetons déjà émis pour un utilisateur.
 *
 * Appelée dès qu'un changement doit prendre effet sans attendre l'expiration
 * naturelle du jeton (8h) : changement de rôle, désactivation, suppression,
 * modification des permissions d'un rôle. Le guard de chaque application
 * refuse alors tout jeton émis avant cet horodatage.
 *
 * Écrit uniquement dans data/revocations.json, jamais dans users.json.
 */
function erp_revoke(array $erp_ids): void {
    if (!$erp_ids) return;
    $f   = DATA_DIR . 'revocations.json';
    $rev = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    /* Horodatage exact, sans marge : la comparaison du guard est un
       « strictement inférieur », donc un jeton réémis dans la même seconde
       reste accepté. Une marge de +1s ferait boucler la réémission
       silencieuse de index.php (jeton neuf immédiatement re-refusé). */
    $ts = time();
    foreach ($erp_ids as $id) { $rev[(string)$id] = $ts; }
    file_put_contents($f, json_encode($rev, JSON_PRETTY_PRINT));
}

/**
 * Identifiants des comptes actifs pouvant administrer l'ERP.
 *
 * Sert à empêcher la suppression ou la désactivation du dernier d'entre eux.
 * L'ancienne protection visait l'identifiant `usr_1` en dur, qui ne correspond
 * à aucun compte réel : elle ne protégeait donc rien. Depuis que toutes les
 * applications dépendent de cette session unique, perdre le dernier compte
 * administrateur revient à perdre l'accès à tout l'ERP, sans recours par
 * l'interface.
 */
function erp_admin_ids(?array $users = null): array {
    $users = $users ?? read_json('users.json');
    $roles = read_json('roles.json');
    $ids   = [];
    foreach ($users as $u) {
        if (!($u['active'] ?? true)) continue;
        if (!empty($roles[$u['role'] ?? '']['can_access_settings'])) $ids[] = $u['id'];
    }
    return $ids;
}

/** Identifiants des utilisateurs portant un rôle donné. */
function erp_users_with_role(string $role): array {
    $ids = [];
    foreach (read_json('users.json') as $u) {
        if (($u['role'] ?? '') === $role) $ids[] = $u['id'];
    }
    return $ids;
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
        $email = strtolower(trim($input['email'] ?? ''));
        if (!$login || !$name || strlen($pw) < 6) {
            json_die(400, 'Login, nom et mot de passe (6 car. min) requis.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_die(400, 'Adresse e-mail invalide.');
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
            'email'         => $email,
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
        $revoke = false;   // le changement doit-il couper les sessions en cours ?
        if (isset($input['name']))   $users[$idx]['name']   = trim($input['name']);
        if (isset($input['email'])) {
            $email = strtolower(trim($input['email']));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_die(400, 'Adresse e-mail invalide.');
            }
            $users[$idx]['email'] = $email;
        }
        if (isset($input['role']))   {
            $roles = read_json('roles.json');
            if (!isset($roles[$input['role']])) json_die(400, 'Rôle invalide.');
            if ($users[$idx]['role'] !== $input['role']) $revoke = true;
            $users[$idx]['role'] = $input['role'];
        }
        if (isset($input['active'])) {
            if ((bool)$users[$idx]['active'] !== (bool)$input['active']) $revoke = true;
            $users[$idx]['active'] = (bool)$input['active'];
        }
        if (!empty($input['password']) && strlen($input['password']) >= 6) {
            $users[$idx]['password_hash'] = password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }
        /* Vérifié sur l'état APRÈS modification : c'est le seul moment où l'on
           sait si le changement demandé laisse l'ERP sans administrateur. */
        if (erp_admin_ids($users) === []) {
            json_die(403, "Ce changement retirerait le dernier compte administrateur. Nommez un autre administrateur avant.");
        }
        write_json('users.json', $users);
        if ($revoke) erp_revoke([$users[$idx]['id']]);
        erp_log($session['login'], $session['name'], "update_user:{$users[$idx]['login']}");
        json_ok(['message' => 'Utilisateur mis à jour.']);

    case 'delete_user':
        require_admin();
        $id = $input['id'] ?? '';
        $admins = erp_admin_ids();
        if (in_array($id, $admins, true) && count($admins) <= 1) {
            json_die(403, "Impossible de supprimer le dernier compte administrateur : plus personne ne pourrait administrer cet ERP.");
        }
        $users = read_json('users.json');
        $login_del = '';
        $users = array_filter($users, function($u) use ($id, &$login_del) {
            if ($u['id'] === $id) { $login_del = $u['login']; return false; }
            return true;
        });
        if (!$login_del) json_die(404, 'Utilisateur introuvable.');
        write_json('users.json', array_values($users));
        erp_revoke([$id]);
        erp_log($session['login'], $session['name'], "delete_user:$login_del");
        json_ok(['message' => "Utilisateur $login_del supprimé."]);

    // ── RÔLES ──────────────────────────────────────────────────────────────
    case 'roles':
        require_auth();
        json_ok(['roles' => read_json('roles.json')]);

    /* Catalogue des permissions déclarées par les applications (lib/permissions.json).
       Sert à construire la grille de l'écran Rôles. */
    case 'permissions_catalog':
        require_auth();
        $cat = mfc_permission_catalog();
        unset($cat['_comment']);
        json_ok(['catalog' => $cat]);

    case 'update_role':
        require_admin();
        $role_key = $input['role'] ?? '';
        $apps     = $input['apps'] ?? [];
        $roles    = read_json('roles.json');
        if (!isset($roles[$role_key])) json_die(404, 'Rôle introuvable.');
        $apps = array_values(array_unique($apps));
        $roles[$role_key]['apps'] = $apps;

        /* La matrice d'accès fait autorité sur le périmètre : décocher une app
           retire aussi ses permissions détaillées, sinon un accès resterait
           ouvert par le bloc perms alors que l'interrupteur est éteint. */
        if (!isset($input['perms']) && !empty($roles[$role_key]['perms'])) {
            $p = array_intersect_key($roles[$role_key]['perms'], array_flip($apps));
            /* Une app qu'on vient de cocher n'a encore aucune permission réglée :
               on lui accorde tout le catalogue par défaut. Sans ça, l'accès
               serait activé dans la matrice mais vide en pratique, ce qui se
               lirait comme un bug côté utilisateur. */
            foreach ($apps as $slug) {
                if (!isset($p[$slug])) {
                    $keys = mfc_app_permission_keys($slug);
                    $p[$slug] = $keys ?: ['*'];
                }
            }
            $roles[$role_key]['perms'] = $p;
        }

        /* Permissions détaillées, envoyées seulement par la modale dédiée.
           Absentes d'un simple enregistrement de la matrice d'accès : on ne
           veut pas effacer silencieusement des permissions déjà réglées. */
        if (isset($input['perms']) && is_array($input['perms'])) {
            $catalog = mfc_permission_catalog();
            $clean   = [];
            foreach ($input['perms'] as $app => $keys) {
                if (!isset($catalog[$app]) || !is_array($keys)) continue;
                /* Refus par défaut : seule une clé réellement déclarée au
                   catalogue est acceptée. Une clé inventée est ignorée. */
                $valid = array_values(array_intersect(
                    $keys, array_keys($catalog[$app]['permissions'] ?? [])
                ));
                if ($valid) $clean[$app] = $valid;
            }
            $roles[$role_key]['perms'] = $clean;
            /* La liste d'apps reste cohérente avec les permissions accordées :
               une app sans aucune permission n'est plus accessible. */
            $roles[$role_key]['apps'] = array_values(array_keys($clean));
        }

        write_json('roles.json', $roles);
        /* Les porteurs de ce rôle doivent voir le changement tout de suite,
           pas à leur prochaine connexion. */
        erp_revoke(erp_users_with_role($role_key));
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

    // ── RÉFÉRENTIEL CLUB (catégories & équipes) ─────────────────────────────
    /*
     * Source de vérité de la structure sportive, consommée par RH et Arbitrage.
     * Lecture ouverte à toute session (les modules en ont besoin pour afficher
     * leurs écrans), écriture réservée aux administrateurs de l'ERP.
     */

    case 'club_structure': {
        require_auth();
        $season = mfc_club_resolve_season($_GET['season_id'] ?? null);
        json_ok([
            'seasons'    => mfc_club_seasons(),
            'categories' => mfc_club_categories(),
            'season_id'  => $season,
            'teams'      => $season === null ? [] : mfc_club_teams($season, false),
            'is_empty'   => mfc_club_is_empty(),
        ]);
    }

    case 'club_save_categories': {
        require_admin();
        $rows = $input['categories'] ?? null;
        if (!is_array($rows)) json_die(400, 'Format invalide.');

        $data    = mfc_club_read();
        $before  = [];
        foreach ($data['categories'] as $c) $before[$c['id']] = $c;

        $kept = [];
        $next = [];
        foreach ($rows as $i => $r) {
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') continue;
            if (mb_strlen($name) > 60) json_die(400, "Nom de catégorie trop long : $name");
            $id = (string)($r['id'] ?? '');
            if ($id === '' || !isset($before[$id])) $id = mfc_club_uid('cat');
            if (isset($kept[$id])) continue; // doublon d'identifiant dans la requête
            $kept[$id] = true;
            $next[] = [
                'id'         => $id,
                'name'       => $name,
                'sort_order' => (int)($r['sort_order'] ?? $i),
                'active'     => (bool)($r['active'] ?? true),
            ];
        }

        /* Une catégorie retirée de la liste est supprimée. Refuser tant que des
           équipes s'y rattachent, sur n'importe quelle saison : sans ce contrôle
           ces équipes se retrouveraient sans catégorie en silence, y compris sur
           des saisons passées que l'administrateur ne regardait pas. */
        $removed = array_diff(array_keys($before), array_keys($kept));
        if ($removed) {
            $blocking = [];
            foreach ($data['memberships'] as $seasonId => $ms) {
                foreach ($ms as $m) {
                    if (in_array($m['category_id'], $removed, true)) {
                        $label = mfc_club_season($seasonId)['label'] ?? $seasonId;
                        $blocking[$before[$m['category_id']]['name'] . " ($label)"] = true;
                    }
                }
            }
            if ($blocking) {
                json_die(409, 'Ces catégories contiennent encore des équipes : '
                    . implode(', ', array_keys($blocking)) . '. Déplacez ces équipes d\'abord.');
            }
        }

        $data['categories'] = $next;
        if (!mfc_club_write($data)) json_die(500, 'Écriture du référentiel impossible.');
        erp_log($session['login'], $session['name'], 'club_save_categories:' . count($next));
        json_ok(['message' => 'Catégories enregistrées.', 'categories' => mfc_club_categories()]);
    }

    case 'club_save_teams': {
        require_admin();
        $seasonId = mfc_club_resolve_season($input['season_id'] ?? null);
        if ($seasonId === null) json_die(400, 'Créez d\'abord une saison.');
        $rows = $input['teams'] ?? null;
        if (!is_array($rows)) json_die(400, 'Format invalide.');

        $data     = mfc_club_read();
        $knownCat = array_column($data['categories'], 'id');
        $knownTm  = array_column($data['teams'], 'id');

        $members = [];
        $seen    = [];
        $created = 0;
        foreach ($rows as $i => $r) {
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') continue;
            if (mb_strlen($name) > 100) json_die(400, "Nom d'équipe trop long : $name");

            $catId = (string)($r['category_id'] ?? '');
            if ($catId !== '' && !in_array($catId, $knownCat, true)) {
                json_die(400, "Catégorie inconnue pour l'équipe \"$name\".");
            }

            /* ref_id absent ou inconnu = nouvelle identité d'équipe. On ne
               réutilise jamais un identifiant fourni par le client s'il ne
               correspond à rien : ça créerait une équipe fantôme référencée
               nulle part ailleurs. */
            $refId = (string)($r['ref_id'] ?? '');
            if ($refId === '' || !in_array($refId, $knownTm, true)) {
                $refId = mfc_club_uid('tm');
                $data['teams'][] = ['id' => $refId, 'created_at' => date('c')];
                $knownTm[] = $refId;
                $created++;
            }
            if (isset($seen[$refId])) {
                json_die(400, "L'équipe \"$name\" apparaît deux fois dans cette saison.");
            }
            $seen[$refId] = true;

            $members[] = [
                'team_id'     => $refId,
                'name'        => $name,
                'category_id' => $catId,
                'coach_name'  => trim((string)($r['coach_name'] ?? '')),
                'sort_order'  => (int)($r['sort_order'] ?? $i),
                'active'      => (bool)($r['active'] ?? true),
            ];
        }

        $data['memberships'][$seasonId] = $members;
        if (!mfc_club_write($data)) json_die(500, 'Écriture du référentiel impossible.');
        erp_log($session['login'], $session['name'], "club_save_teams:$seasonId:" . count($members));
        json_ok([
            'message'  => count($members) . ' équipe(s) enregistrée(s)' . ($created ? ", dont $created nouvelle(s)" : '') . '.',
            'teams'    => mfc_club_teams($seasonId, false),
        ]);
    }

    case 'club_save_season': {
        require_admin();
        $id    = trim((string)($input['id'] ?? ''));
        $label = trim((string)($input['label'] ?? ''));
        $start = trim((string)($input['start_date'] ?? ''));
        $end   = trim((string)($input['end_date'] ?? ''));

        if ($label === '') json_die(400, 'Nom de saison requis.');
        foreach ([$start, $end] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) json_die(400, 'Dates de saison invalides (AAAA-MM-JJ).');
        }
        if ($start >= $end) json_die(400, 'La date de fin doit suivre la date de début.');

        $data  = mfc_club_read();
        $idx   = -1;
        foreach ($data['seasons'] as $i => $s) { if ($s['id'] === $id) { $idx = $i; break; } }

        if ($idx >= 0) {
            $data['seasons'][$idx] = ['id' => $id, 'label' => $label, 'start_date' => $start, 'end_date' => $end];
            $msg = "Saison \"$label\" mise à jour.";
        } else {
            foreach ($data['seasons'] as $s) {
                if (mb_strtolower($s['label']) === mb_strtolower($label)) json_die(409, 'Une saison porte déjà ce nom.');
            }
            $id = mfc_club_uid('sea');
            $data['seasons'][] = ['id' => $id, 'label' => $label, 'start_date' => $start, 'end_date' => $end];

            /* Duplication de la saison précédente comme point de départ : c'est
               le comportement déjà en place côté RH pour les affectations, et
               ça évite de ressaisir tout l'organigramme chaque été. Les équipes
               gardent leur identité, seuls les rattachements sont recopiés. */
            $copyFrom = trim((string)($input['copy_from'] ?? ''));
            if ($copyFrom !== '' && isset($data['memberships'][$copyFrom])) {
                $data['memberships'][$id] = $data['memberships'][$copyFrom];
                $msg = "Saison \"$label\" créée à partir de la précédente ("
                     . count($data['memberships'][$id]) . ' équipe(s) reprise(s)).';
            } else {
                $data['memberships'][$id] = [];
                $msg = "Saison \"$label\" créée.";
            }
        }

        if (!mfc_club_write($data)) json_die(500, 'Écriture du référentiel impossible.');
        erp_log($session['login'], $session['name'], "club_save_season:$id");
        json_ok(['message' => $msg, 'season_id' => $id, 'seasons' => mfc_club_seasons()]);
    }

    case 'club_delete_season': {
        require_admin();
        $id = trim((string)($input['id'] ?? ''));
        $data = mfc_club_read();
        if (!mfc_club_season($id)) json_die(404, 'Saison introuvable.');

        /* Les modules gardent leurs propres données rattachées à cette saison
           (matchs d'Arbitrage, affectations RH) et l'ERP n'a aucun moyen fiable
           de les compter d'ici. On refuse donc par défaut dès qu'il reste des
           équipes, plutôt que de laisser ces données orphelines en silence. */
        $count = count($data['memberships'][$id] ?? []);
        if ($count > 0 && empty($input['force'])) {
            json_die(409, "Cette saison contient encore $count équipe(s). Les modules qui s'y réfèrent "
                . '(matchs, affectations) conserveront leurs données, qui deviendront orphelines. '
                . 'Confirmez pour continuer.', ['needs_force' => true, 'team_count' => $count]);
        }

        $data['seasons'] = array_values(array_filter($data['seasons'], fn($s) => $s['id'] !== $id));
        unset($data['memberships'][$id]);
        if (!mfc_club_write($data)) json_die(500, 'Écriture du référentiel impossible.');
        erp_log($session['login'], $session['name'], "club_delete_season:$id");
        json_ok(['message' => 'Saison supprimée.', 'seasons' => mfc_club_seasons()]);
    }

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
