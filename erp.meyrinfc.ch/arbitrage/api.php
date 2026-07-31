<?php
/**
 * Arbitrage Meyrin FC — API backend
 * PHP 8.x + SQLite (PDO). Aucune dépendance externe.
 * Compatible hébergement mutualisé Infomaniak.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Plus de session PHP propre a l'application : l'identite vient de la session
   unique de l'ERP (cookie JWT). */
require_once __DIR__ . '/mfc_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------------------------------------------------------------- DB */

const DB_DIR  = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/arbitrage.sqlite';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DB_DIR)) mkdir(DB_DIR, 0775, true);
    $ht = DB_DIR . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT DEFAULT 'admin',
        active INTEGER DEFAULT 1,
        last_login TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        season_id INTEGER REFERENCES seasons(id),
        name TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT '',
        coach_name TEXT DEFAULT '',
        fee_amount REAL DEFAULT 0,
        active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS seasons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        imported_at TEXT DEFAULT (datetime('now')),
        match_count INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS matches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        season_id INTEGER REFERENCES seasons(id),
        match_number TEXT DEFAULT '',
        match_date TEXT NOT NULL,
        match_time TEXT DEFAULT '',
        team_id INTEGER REFERENCES teams(id),
        opponent TEXT NOT NULL DEFAULT '',
        is_home INTEGER DEFAULT 1,
        competition TEXT DEFAULT '',
        venue TEXT DEFAULT '',
        status TEXT DEFAULT 'pending',
        paid_at TEXT,
        notes TEXT DEFAULT '',
        payment_method TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        sort_order INTEGER DEFAULT 100,
        created_at TEXT DEFAULT (datetime('now'))
    );
    ");
    // Migrate: add payment_method if missing (existing databases)
    $cols = array_column($pdo->query('PRAGMA table_info(matches)')->fetchAll(), 'name');
    if (!in_array('payment_method', $cols)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN payment_method TEXT DEFAULT ''");
    }
    /* Indemnité négociée pour ce match précis (NULL = tarif standard de l'équipe,
       inchangé). Un match reste donc au tarif de son équipe par défaut ; seul un
       montant explicitement saisi ici l'en écarte. */
    if (!in_array('fee_override', $cols)) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN fee_override REAL DEFAULT NULL');
    }
    // Migrate: add season_id to teams if missing (existing databases).
    // Teams used to be shared across all seasons; each season now owns its own team records
    // (coach/fee can differ from one season to the next).
    $teamCols = array_column($pdo->query('PRAGMA table_info(teams)')->fetchAll(), 'name');
    if (!in_array('season_id', $teamCols)) {
        $pdo->exec('ALTER TABLE teams ADD COLUMN season_id INTEGER REFERENCES seasons(id)');
        $latestSeason = $pdo->query('SELECT id FROM seasons ORDER BY id DESC LIMIT 1')->fetch();
        if (!$latestSeason) {
            $hasTeams = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
            if ($hasTeams > 0) {
                $pdo->prepare('INSERT INTO seasons (label, match_count) VALUES (?, 0)')->execute(['Saison précédente']);
                $latestSeason = ['id' => (int) $pdo->lastInsertId()];
            }
        }
        if ($latestSeason) {
            $pdo->prepare('UPDATE teams SET season_id = ? WHERE season_id IS NULL')->execute([(int) $latestSeason['id']]);
        }
    }
    /* Lien vers le referentiel club de l'ERP (lib/mfc_club.php). Le nom, la
       categorie et l'entraineur d'une equipe y sont definis, par saison. Cette
       base garde ce qui lui est propre : l'indemnite d'arbitrage (fee_amount),
       les matchs et leur statut de paiement. */
    $seasonCols = array_column($pdo->query('PRAGMA table_info(seasons)')->fetchAll(), 'name');
    if (!in_array('ref_id', $seasonCols)) {
        $pdo->exec("ALTER TABLE seasons ADD COLUMN ref_id TEXT NOT NULL DEFAULT ''");
    }
    /* Colonnes relues : $teamCols date d'avant l'ajout eventuel de season_id. */
    if (!in_array('ref_id', array_column($pdo->query('PRAGMA table_info(teams)')->fetchAll(), 'name'))) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN ref_id TEXT NOT NULL DEFAULT ''");
    }
    $catCols = array_column($pdo->query('PRAGMA table_info(categories)')->fetchAll(), 'name');
    if (!in_array('ref_id', $catCols)) {
        $pdo->exec("ALTER TABLE categories ADD COLUMN ref_id TEXT NOT NULL DEFAULT ''");
    }

    /* Reparation puis verrou : les doublons nes de la synchro concurrente sont
       fusionnes, et l'index unique rend leur reapparition impossible, quel que
       soit l'ordre d'arrivee des requetes. L'index doit etre cree APRES la
       fusion, sinon sa creation echoue sur les doublons existants. */
    mfc_club_dedup($pdo, 'seasons', ['ref_id'], [
        ['table' => 'matches', 'col' => 'season_id'],
        ['table' => 'teams',   'col' => 'season_id'],
    ]);
    mfc_club_dedup($pdo, 'categories', ['ref_id'], []);
    mfc_club_dedup($pdo, 'teams', ['season_id', 'ref_id'], [
        ['table' => 'matches', 'col' => 'team_id'],
    ], ['fee_amount']);

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_seasons_ref    ON seasons(ref_id)               WHERE ref_id != ''");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_categories_ref ON categories(ref_id)            WHERE ref_id != ''");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_teams_ref      ON teams(season_id, ref_id)      WHERE ref_id != ''");

    // Seed default categories on first run
    $count = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $defaults = [['U6',0],['U8',10],['U10',20],['U12',30],['U14',40],['U16',50],['U18',60],['M21',70],['Seniors',80],['Dames',90],['Vétérans',100]];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO categories (name, sort_order) VALUES (?,?)');
        foreach ($defaults as [$name, $ord]) $ins->execute([$name, $ord]);
    }
}

/* ------------------------------------------------- Referentiel club (ERP)
 *
 * Les equipes ne sont plus creees par l'import : elles viennent de l'ERP
 * (Parametres > Categories & Equipes) et peuvent differer d'une saison a
 * l'autre. C'est ce qui corrige la cause d'origine du probleme : le calendrier
 * de la faitiere ecrit les noms d'equipes a sa facon, et l'ancien import creait
 * une equipe pour chaque orthographe rencontree.
 */

/* mfc_club.php est charge par le socle (mfc_boot.php -> mfc_auth.php), qui sait
   ou le trouver quel que soit l'emplacement de l'application. */

function club_active(): bool {
    return !mfc_club_is_empty();
}

/**
 * Aligne saisons, categories et equipes locales sur le referentiel.
 *
 * Sans suppression : une equipe retiree d'une saison est desactivee, jamais
 * effacee, parce que matches.team_id la reference — y compris pour des matchs
 * deja payes, dont l'historique comptable doit rester lisible.
 */
function club_sync(PDO $pdo): void {
    if (!club_active()) return;

    static $done = false;
    if ($done) return;
    $done = true;

    /* --- Saisons --- */
    $localByRef   = [];
    $localByLabel = [];
    foreach ($pdo->query('SELECT * FROM seasons')->fetchAll() as $r) {
        if (($r['ref_id'] ?? '') !== '') $localByRef[$r['ref_id']] = $r;
        else                             $localByLabel[mb_strtolower($r['label'])] = $r;
    }
    foreach (mfc_club_seasons() as $s) {
        if (isset($localByRef[$s['id']])) {
            if ($localByRef[$s['id']]['label'] !== $s['label']) {
                $pdo->prepare('UPDATE seasons SET label=? WHERE id=?')
                    ->execute([$s['label'], (int)$localByRef[$s['id']]['id']]);
            }
            continue;
        }
        $key = mb_strtolower($s['label']);
        if (isset($localByLabel[$key])) {
            /* Saison importee avant la mise en place du referentiel : on la
               relie plutot que d'en creer une seconde du meme nom, ce qui
               scinderait les matchs deja saisis en deux saisons jumelles. */
            $pdo->prepare('UPDATE OR IGNORE seasons SET ref_id=? WHERE id=?')
                ->execute([$s['id'], (int)$localByLabel[$key]['id']]);
        } else {
            $pdo->prepare('INSERT OR IGNORE INTO seasons (label, match_count, ref_id) VALUES (?,0,?)')
                ->execute([$s['label'], $s['id']]);
        }
    }

    /* --- Categories --- */
    $catByRef = [];
    foreach ($pdo->query('SELECT * FROM categories')->fetchAll() as $r) {
        if (($r['ref_id'] ?? '') !== '') $catByRef[$r['ref_id']] = $r;
    }
    foreach (mfc_club_categories() as $c) {
        if (isset($catByRef[$c['id']])) {
            if ($catByRef[$c['id']]['name'] !== $c['name'] || (int)$catByRef[$c['id']]['sort_order'] !== $c['sort_order']) {
                $pdo->prepare('UPDATE categories SET name=?, sort_order=? WHERE id=?')
                    ->execute([$c['name'], $c['sort_order'], (int)$catByRef[$c['id']]['id']]);
            }
            continue;
        }
        $st = $pdo->prepare('SELECT id FROM categories WHERE name=? AND ref_id=""');
        $st->execute([$c['name']]);
        $exist = $st->fetchColumn();
        if ($exist) $pdo->prepare('UPDATE OR IGNORE categories SET ref_id=?, sort_order=? WHERE id=?')
                        ->execute([$c['id'], $c['sort_order'], (int)$exist]);
        else        $pdo->prepare('INSERT OR IGNORE INTO categories (name, sort_order, ref_id) VALUES (?,?,?)')
                        ->execute([$c['name'], $c['sort_order'], $c['id']]);
    }

    /* --- Equipes, saison par saison ---
       Cette base garde une ligne equipe par saison (l'indemnite d'arbitrage
       peut changer d'une annee a l'autre) : le referentiel y est donc projete
       une fois par saison. */
    $seasons = $pdo->query('SELECT id, ref_id FROM seasons WHERE ref_id != ""')->fetchAll();
    foreach ($seasons as $s) {
        $localSeasonId = (int)$s['id'];
        $refTeams = mfc_club_teams($s['ref_id'], false);

        $existing = [];
        $stmt = $pdo->prepare('SELECT * FROM teams WHERE season_id = ?');
        $stmt->execute([$localSeasonId]);
        foreach ($stmt->fetchAll() as $r) {
            if (($r['ref_id'] ?? '') !== '') $existing[$r['ref_id']] = $r;
        }

        $ins = $pdo->prepare('INSERT OR IGNORE INTO teams (season_id, name, category, coach_name, fee_amount, active, ref_id) VALUES (?,?,?,?,0,?,?)');
        $upd = $pdo->prepare('UPDATE teams SET name=?, category=?, coach_name=?, active=? WHERE id=?');
        foreach ($refTeams as $t) {
            $active = $t['active'] ? 1 : 0;
            if (isset($existing[$t['ref_id']])) {
                $l = $existing[$t['ref_id']];
                if ($l['name'] !== $t['name'] || $l['category'] !== $t['category_name']
                    || $l['coach_name'] !== $t['coach_name'] || (int)$l['active'] !== $active) {
                    $upd->execute([$t['name'], $t['category_name'], $t['coach_name'], $active, (int)$l['id']]);
                }
                continue;
            }
            /* Equipe importee autrefois sous le meme nom : on la relie pour ne
               pas dupliquer la ligne et pour que ses matchs suivent. */
            $byName = $pdo->prepare('SELECT id FROM teams WHERE season_id=? AND ref_id="" AND LOWER(name)=LOWER(?)');
            $byName->execute([$localSeasonId, $t['name']]);
            $exist = $byName->fetchColumn();
            if ($exist) $pdo->prepare('UPDATE OR IGNORE teams SET ref_id=?, category=?, coach_name=?, active=? WHERE id=?')
                            ->execute([$t['ref_id'], $t['category_name'], $t['coach_name'], $active, (int)$exist]);
            else        $ins->execute([$localSeasonId, $t['name'], $t['category_name'], $t['coach_name'], $active, $t['ref_id']]);
        }

        /* Reliee mais absente de cette saison : desactivee, jamais supprimee. */
        $refIds  = array_column($refTeams, 'ref_id');
        $orphans = array_diff(array_keys($existing), $refIds);
        if ($orphans) {
            $ph = implode(',', array_fill(0, count($orphans), '?'));
            $pdo->prepare("UPDATE teams SET active=0 WHERE season_id=? AND ref_id IN ($ph)")
                ->execute(array_merge([$localSeasonId], array_values($orphans)));
        }
    }
}

/** Ordre d'affichage du referentiel pour une saison locale donnee. */
function club_team_rank(PDO $pdo, int $localSeasonId): array {
    if (!club_active()) return [];
    $st = $pdo->prepare('SELECT ref_id FROM seasons WHERE id=?');
    $st->execute([$localSeasonId]);
    $ref = (string)($st->fetchColumn() ?: '');
    if ($ref === '') return [];
    $rank = [];
    foreach (mfc_club_teams($ref, false) as $i => $t) $rank[$t['ref_id']] = $i;
    return $rank;
}

/** Trie des lignes d'equipes selon l'ordre du referentiel. */
function club_sort_teams(array $rows, array $rank): array {
    if (!$rank) return $rows;
    usort($rows, function ($a, $b) use ($rank) {
        $ra = $rank[$a['ref_id'] ?? ''] ?? PHP_INT_MAX;
        $rb = $rank[$b['ref_id'] ?? ''] ?? PHP_INT_MAX;
        return $ra <=> $rb ?: strcmp((string)$a['name'], (string)$b['name']);
    });
    return $rows;
}

/** Refus commun : ces champs ne se modifient plus ici. */
function club_readonly(string $what): never {
    fail("$what : cela se gère maintenant dans l'ERP (Paramètres > Catégories & Équipes), "
       . "pour que RH et Arbitrage affichent la même liste. L'indemnité d'arbitrage et les "
       . 'matchs restent gérés ici.', 409);
}

/** Vrai si l'équipe est reliée au référentiel ERP (ref_id non vide). Une équipe non reliée
 *  n'existe que localement (ancien import) et n'apparaît dans aucun écran de l'ERP. */
function team_has_ref(PDO $pdo, int $id): bool {
    $st = $pdo->prepare('SELECT ref_id FROM teams WHERE id = ?');
    $st->execute([$id]);
    $ref = $st->fetchColumn();
    return $ref !== false && (string)$ref !== '';
}

/* ---------------------------------------------------------------- Helpers */

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $_POST ?: [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never {
    out(['error' => $msg], $code);
}

function s(array $b, string $k, string $def = ''): string {
    return trim((string)($b[$k] ?? $def));
}

function n(array $b, string $k, float $def = 0.0): float {
    return (float)($b[$k] ?? $def);
}

function i(array $b, string $k, int $def = 0): int {
    return (int)($b[$k] ?? $def);
}

function resolve_season_id(PDO $pdo, int $season_id): int {
    if ($season_id > 0) return $season_id;

    /* Saison en cours d'apres le referentiel (celle dont la periode couvre
       aujourd'hui). Le repli historique prenait la derniere saison creee : dès
       qu'une saison future est preparee a l'avance dans l'ERP, elle devient la
       plus recente et l'application s'ouvrait dessus, sur des ecrans vides. */
    if (club_active()) {
        $ref = mfc_club_current_season_id();
        if ($ref !== null) {
            $st = $pdo->prepare('SELECT id FROM seasons WHERE ref_id = ?');
            $st->execute([$ref]);
            $id = $st->fetchColumn();
            if ($id) return (int) $id;
        }
    }

    $row = $pdo->query('SELECT id FROM seasons ORDER BY id DESC LIMIT 1')->fetch();
    return $row ? (int) $row['id'] : 0;
}

/**
 * Identite de la personne connectee, lue directement dans le jeton.
 *
 * Contrairement a Sponsors, Events ou Commandes, AUCUNE table de cette base ne
 * reference users(id) : rien n'a besoin d'un identifiant local. On ne cree donc
 * volontairement aucune ligne ici, pour ne pas polluer l'annuaire existant avec
 * des comptes techniques sans utilite.
 */
function current_user(): ?array {
    $s = mfc_session();
    if (!$s) return null;
    return [
        'id'     => 0,
        'name'   => $s['name']  ?? '',
        'email'  => $s['login'] ?? '',
        'role'   => $s['role']  ?? '',
        'active' => 1,
    ];
}

function require_auth(): array {
    $u = current_user();
    if (!$u) fail('Non authentifié', 401);
    return $u;
}

/* ---------------------------------------------------------------- CSV Parsing */

function detect_separator(string $line): string {
    $counts = [
        ';'  => substr_count($line, ';'),
        ','  => substr_count($line, ','),
        "\t" => substr_count($line, "\t"),
    ];
    arsort($counts);
    return (string) array_key_first($counts);
}

function parse_csv_content(string $content): array {
    // Remove BOM
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    $content = str_replace("\r\n", "\n", $content);
    $content = str_replace("\r", "\n", $content);

    $lines = explode("\n", $content);
    $lines = array_filter($lines, fn($l) => trim($l) !== '');
    $lines = array_values($lines);

    if (empty($lines)) return ['headers' => [], 'rows' => [], 'sep' => ','];

    $sep = detect_separator($lines[0]);

    $rows = [];
    foreach ($lines as $line) {
        $rows[] = array_map('trim', str_getcsv($line, $sep));
    }

    $headers = $rows[0] ?? [];
    $dataRows = array_slice($rows, 1);

    return ['headers' => $headers, 'rows' => $dataRows, 'sep' => $sep];
}

function normalize_date(string $raw): ?string {
    $raw = trim($raw);
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/', $raw, $m)) {
        $y = (int)$m[3] >= 50 ? '19' . $m[3] : '20' . $m[3];
        return sprintf('%s-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
    }
    return null;
}

/**
 * Normalise une valeur servant de cle de comparaison a l'import.
 *
 * Casse, espaces multiples et espaces de bord sont neutralises : le meme match
 * exporte deux fois par la faitiere peut differer sur ces details sans etre un
 * match different.
 */
function import_norm(string $v): string {
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    return mb_strtolower($v, 'UTF-8');
}

function detect_category(string $teamName, array $knownCategories = []): string {
    // Match against known DB categories first (longest match wins to avoid "U1" matching before "U10")
    usort($knownCategories, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($knownCategories as $cat) {
        if ($cat !== '' && stripos($teamName, $cat) !== false) return $cat;
    }
    // Fallback regex
    if (preg_match('/\b(U\d{1,2}|M\d{2}|Dames?|Dame|Seniors?|Senior|Vétérans?|Juniors?|Junior)\b/i', $teamName, $m)) {
        return $m[0];
    }
    return '';
}

/* ---------------------------------------------------------------- Router */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b      = body();

/* ---------------------------------------------------------------- Permissions
 *
 * Table declarative, verifiee en un seul point.
 * Format : action => permission, ou ['GET' => perm, 'write' => perm].
 *
 * REFUS PAR DEFAUT : une action absente exige teams.manage, le droit le plus
 * large du module. Ajouter un point d'entree sans y penser le rend
 * inaccessible, pas public.
 *
 * Choix a connaitre : consulter les equipes, categories et saisons releve de
 * matches.view, parce que ces listes sont indispensables pour simplement lire
 * un calendrier de matchs. Les modifier demande le droit dedie.
 */
const ARB_PERMS = [
    'me'              => null,
    'users'           => ['GET' => 'matches.view', 'write' => null], // ecriture traitee dans le bloc
    'teams'           => ['GET' => 'matches.view', 'write' => 'teams.manage'],
    'teams_bulk_save' => 'teams.manage',
    'categories'      => ['GET' => 'matches.view', 'write' => 'teams.manage'],
    'seasons'         => ['GET' => 'matches.view', 'write' => 'seasons.manage'],
    'matches'         => ['GET' => 'matches.view', 'write' => 'matches.edit'],
    'matches_bulk_pay'=> 'matches.pay',
    'import_csv'      => 'import.run',
    'import_team_csv' => 'import.run',
    'stats'           => 'stats.view',
];

/* Points d'entree supprimes : l'authentification est centralisee dans l'ERP. */
if (in_array($action, ['login', 'logout', 'setup', 'status', 'change_password'], true)) {
    fail("Cette application n'a plus de connexion propre. Utilisez l'ERP : " . ERP_URL, 410);
}

/* Session ERP + acces a l'application. Repond 401/403 en JSON sinon. */
mfc_require_api('arbitrage');

$arb_rule = array_key_exists($action, ARB_PERMS) ? ARB_PERMS[$action] : 'teams.manage';
if (is_array($arb_rule)) {
    $arb_rule = ($method === 'GET') ? $arb_rule['GET'] : $arb_rule['write'];
}
if ($arb_rule !== null) {
    mfc_require_perm('arbitrage.' . $arb_rule);
}

switch ($action) {

    /* ============ AUTH & SETUP ============ */

    /* status, setup, login et logout ont ete supprimes : l'authentification est
       centralisee dans l'ERP. Les appels a ces actions sont interceptes plus
       haut et renvoient un 410 explicite. */

    case 'me': {
        out(['user' => require_auth()]);
    }

    /* ============ ÉQUIPES ============ */

    case 'teams': {
        require_auth();
        if ($method === 'GET') {
            club_sync(db());
            $season_id = resolve_season_id(db(), (int)($_GET['season_id'] ?? 0));
            $st = db()->prepare('SELECT * FROM teams WHERE season_id = ? ORDER BY category, name');
            $st->execute([$season_id]);
            out(club_sort_teams($st->fetchAll(), club_team_rank(db(), $season_id)));
        }
        /* Nom, categorie, entraineur et presence viennent de l'ERP. Reste
           modifiable ici : fee_amount, l'indemnite d'arbitrage par match.
           Exception : une équipe JAMAIS reliée au référentiel (ref_id vide) n'a rien
           à voir avec l'ERP, elle vient d'un ancien import (bug corrigé le 31.07.2026,
           voir club_sync) et n'apparaît nulle part dans Paramètres > Catégories &
           Équipes. La renvoyer vers l'ERP serait un cul-de-sac : elle y est
           introuvable. On la laisse donc passer jusqu'au DELETE générique plus bas. */
        $deletingOrphanTeam = $method === 'DELETE' && !empty($_GET['id']) && !team_has_ref(db(), i($_GET, 'id'));
        if (club_active() && !$deletingOrphanTeam) {
            if ($method !== 'PUT') club_readonly('Les équipes');
            $id = i($b, 'id');
            if (!$id) fail('ID requis');
            foreach (['name', 'category', 'coach_name', 'active'] as $f) {
                if (array_key_exists($f, $b)) club_readonly("Le nom, la catégorie, l'entraîneur et l'activation d'une équipe");
            }
            if (!array_key_exists('fee_amount', $b)) fail('Rien à modifier');
            db()->prepare('UPDATE teams SET fee_amount = ? WHERE id = ?')->execute([n($b, 'fee_amount'), $id]);
            out(['ok' => true]);
        }
        if ($method === 'POST') {
            $name = s($b, 'name');
            if (!$name) fail('Nom de l\'équipe requis');
            $season_id = resolve_season_id(db(), i($b, 'season_id'));
            if (!$season_id) fail('Aucune saison disponible : créez d\'abord une saison');
            db()->prepare('INSERT INTO teams (season_id, name, category, coach_name, fee_amount) VALUES (?,?,?,?,?)')
                ->execute([$season_id, $name, s($b, 'category'), s($b, 'coach_name'), n($b, 'fee_amount')]);
            out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
        }
        if ($method === 'PUT') {
            $id = i($b, 'id');
            if (!$id) fail('ID requis');
            $fields = [];
            $vals   = [];
            foreach (['name', 'category', 'coach_name'] as $f) {
                if (array_key_exists($f, $b)) { $fields[] = "$f = ?"; $vals[] = s($b, $f); }
            }
            if (array_key_exists('fee_amount', $b)) { $fields[] = 'fee_amount = ?'; $vals[] = n($b, 'fee_amount'); }
            if (array_key_exists('active', $b))     { $fields[] = 'active = ?';     $vals[] = i($b, 'active'); }
            if (!$fields) fail('Rien à modifier');
            $vals[] = $id;
            db()->prepare('UPDATE teams SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
            out(['ok' => true]);
        }
        if ($method === 'DELETE') {
            $id = i($_GET, 'id');
            if (!$id) fail('ID requis');
            $st = db()->prepare('SELECT COUNT(*) FROM matches WHERE team_id = ?');
            $st->execute([$id]);
            $matchCount = (int) $st->fetchColumn();
            if ($matchCount > 0) {
                /* purge=1 : suppression définitive demandée explicitement (équipe orpheline,
                   par ex. issue d'un ancien import et introuvable dans le référentiel ERP).
                   Sans ce drapeau, le comportement par défaut reste l'archivage, pour ne
                   jamais perdre un historique de matchs par un clic malheureux. */
                if (!empty($_GET['purge'])) {
                    $pdo = db();
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM matches WHERE team_id = ?')->execute([$id]);
                        $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
                        $pdo->commit();
                    } catch (\Exception $e) {
                        $pdo->rollBack();
                        fail('Erreur lors de la suppression : ' . $e->getMessage(), 500);
                    }
                    out(['ok' => true, 'archived' => false, 'purged' => true, 'matches_deleted' => $matchCount]);
                }
                db()->prepare('UPDATE teams SET active = 0 WHERE id = ?')->execute([$id]);
                out(['ok' => true, 'archived' => true, 'match_count' => $matchCount]);
            } else {
                db()->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
                out(['ok' => true, 'archived' => false]);
            }
        }
        fail('Méthode non supportée', 405);
    }

    /* Enregistrement groupé de l'écran Équipes. Sous référentiel, seul le
       montant de l'indemnité y est encore modifiable. */
    case 'teams_bulk_save': {
        require_auth();
        if (club_active()) {
            $rows = $b['teams'] ?? [];
            if (!is_array($rows)) fail('Format invalide');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('UPDATE teams SET fee_amount = ? WHERE id = ?');
                foreach ($rows as $t) {
                    $id = i($t, 'id');
                    if ($id > 0 && array_key_exists('fee_amount', $t)) $st->execute([n($t, 'fee_amount'), $id]);
                }
                $pdo->commit();
            } catch (\Exception $e) {
                $pdo->rollBack();
                fail('Erreur lors de la sauvegarde : ' . $e->getMessage(), 500);
            }
            $season_id = resolve_season_id($pdo, i($b, 'season_id'));
            $q = $pdo->prepare('SELECT * FROM teams WHERE season_id = ?');
            $q->execute([$season_id]);
            out(['ok' => true, 'teams' => club_sort_teams($q->fetchAll(), club_team_rank($pdo, $season_id))]);
        }
        $teams = $b['teams'] ?? [];
        if (!is_array($teams)) fail('Format invalide');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($teams as $t) {
                $id = i($t, 'id');
                if ($id > 0) {
                    $pdo->prepare('UPDATE teams SET name=?, category=?, coach_name=?, fee_amount=?, active=? WHERE id=?')
                        ->execute([s($t, 'name'), s($t, 'category'), s($t, 'coach_name'), n($t, 'fee_amount'), i($t, 'active', 1), $id]);
                } else {
                    $pdo->prepare('INSERT INTO teams (name, category, coach_name, fee_amount) VALUES (?,?,?,?)')
                        ->execute([s($t, 'name'), s($t, 'category'), s($t, 'coach_name'), n($t, 'fee_amount')]);
                }
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail('Erreur lors de la sauvegarde : ' . $e->getMessage(), 500);
        }
        $rows = $pdo->query('SELECT * FROM teams ORDER BY category, name')->fetchAll();
        out(['ok' => true, 'teams' => $rows]);
    }

    /* ============ SAISONS ============ */

    case 'seasons': {
        require_auth();
        if ($method === 'GET') {
            club_sync(db());
            $rows = db()->query('SELECT * FROM seasons ORDER BY id DESC')->fetchAll();
            $current = club_active() ? mfc_club_current_season_id() : null;
            foreach ($rows as &$r) $r['is_current'] = ($current !== null && ($r['ref_id'] ?? '') === $current);
            out($rows);
        }
        /* Les saisons se créent et se renomment dans l'ERP : elles y définissent
           aussi quelles équipes en font partie, ce que cette base ne peut pas
           deviner seule. */
        if (club_active()) club_readonly('Les saisons');
        if ($method === 'PUT') {
            $id    = i($b, 'id');
            $label = s($b, 'label');
            if (!$id)    fail('ID requis');
            if (!$label) fail('Nom de saison requis');
            db()->prepare('UPDATE seasons SET label = ? WHERE id = ?')->execute([$label, $id]);
            out(['ok' => true]);
        }
        if ($method === 'DELETE') {
            $id = i($_GET, 'id');
            if (!$id) fail('ID requis');
            $pdo = db();
            // Count matches that will be deleted
            $matchSt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE season_id = ?');
            $matchSt->execute([$id]);
            $matchesDeleted = (int) $matchSt->fetchColumn();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM matches WHERE season_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM seasons WHERE id = ?')->execute([$id]);
                $pdo->commit();
            } catch (\Exception $e) {
                $pdo->rollBack();
                fail('Erreur suppression : ' . $e->getMessage(), 500);
            }
            out(['ok' => true, 'matches_deleted' => $matchesDeleted]);
        }
        fail('Méthode non supportée', 405);
    }

    /* ============ IMPORT DU CALENDRIER D'UNE EQUIPE ============
     *
     * Remplace l'ancien import global, qui lisait le nom d'equipe dans le
     * fichier et creait une equipe des qu'il ne le reconnaissait pas. Le
     * calendrier de la faitiere ecrit les noms a sa facon ("FC Meyrin Ib"...),
     * ce qui produisait des equipes parasites a chaque import.
     *
     * Ici, l'equipe est CHOISIE avant l'import : toutes les lignes du fichier
     * lui sont attribuees, sans aucune reconnaissance de nom. Le fichier ne
     * sert plus qu'a dire quand, contre qui, et ou.
     *
     * Le mot-cle du club ("Meyrin") reste utilise, mais pour une seule chose :
     * savoir de quel cote de la ligne on se trouve, donc si le match est a
     * domicile ou a l'exterieur, et quel nom est celui de l'adversaire.
     */

    case 'import_team_csv': {
        require_auth();

        $confirm = (int)($_GET['confirm'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        $club_keyword = s($_POST, 'club_keyword', 'Meyrin');

        if (!$team_id) fail('Choisissez l\'équipe dont vous importez le calendrier');

        $pdo = db();
        club_sync($pdo);

        $tSt = $pdo->prepare('SELECT t.*, s.label AS season_label FROM teams t
                              LEFT JOIN seasons s ON s.id = t.season_id WHERE t.id = ?');
        $tSt->execute([$team_id]);
        $team = $tSt->fetch();
        if (!$team) fail('Équipe introuvable', 404);
        $season_id = (int)$team['season_id'];
        if (!$season_id) fail('Cette équipe n\'est rattachée à aucune saison');

        if (empty($_FILES['csv'])) fail('Fichier CSV requis');
        $f = $_FILES['csv'];
        if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
        if ($f['size'] > 10 * 1024 * 1024) fail('Fichier trop volumineux (10 Mo max)');

        $content = file_get_contents($f['tmp_name']);
        if ($content === false) fail('Impossible de lire le fichier');
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') $content = mb_convert_encoding($content, 'UTF-8', $encoding);

        $parsed  = parse_csv_content($content);
        $headers = $parsed['headers'];
        $rows    = $parsed['rows'];
        if (empty($headers)) fail('CSV vide ou illisible');

        $mapping = [];
        if (!empty($_POST['mapping'])) $mapping = json_decode($_POST['mapping'], true) ?? [];

        /* Etape 1 : pas de correspondance de colonnes fournie, on renvoie un apercu. */
        if (empty($mapping)) {
            out([
                'step'       => 'preview',
                'headers'    => $headers,
                'rows'       => array_slice($rows, 0, 10),
                'separator'  => $parsed['sep'],
                'total_rows' => count($rows),
                'team'       => ['id' => $team_id, 'name' => $team['name'], 'season' => $team['season_label']],
            ]);
        }

        $col = function (string $key) use ($mapping, $headers): int {
            $name = $mapping[$key] ?? '';
            if ($name === '') return -1;
            $idx = array_search($name, $headers, true);
            return $idx !== false ? (int)$idx : -1;
        };
        $dateCol  = $col('date');
        $timeCol  = $col('time');
        $numCol   = $col('match_number');
        $homeCol  = $col('home_team');
        $awayCol  = $col('away_team');
        $compCol  = $col('competition');
        $venueCol = $col('venue');

        if ($dateCol < 0)                 fail('Colonne "date" obligatoire dans le mapping');
        if ($homeCol < 0 && $awayCol < 0) fail('Au moins une colonne équipe est requise, pour distinguer domicile et extérieur');

        /* Matchs deja enregistres pour CETTE equipe et CETTE saison.
         *
         * Un re-import RAPPROCHE chaque ligne du fichier d'un match existant :
         *  - reconnu  -> ses informations sportives sont mises a jour (date,
         *    heure, lieu, competition, adversaire), c'est le cas du match
         *    reprogramme ;
         *  - inconnu  -> il est ajoute.
         *
         * Le statut de paiement, la date de paiement, le moyen de paiement et
         * les notes ne sont JAMAIS touches : ce sont des saisies humaines, que
         * le calendrier de la faitiere ne connait pas et n'a pas a ecraser.
         */
        $existing = [];   // id => ligne complete
        $byNum    = [];   // numero de match  => id
        $byDay    = [];   // date + adversaire => id
        $exSt = $pdo->prepare('SELECT id, match_number, match_date, match_time, opponent, is_home,
                                      competition, venue, status
                               FROM matches WHERE season_id = ? AND team_id = ?');
        $exSt->execute([$season_id, $team_id]);
        foreach ($exSt->fetchAll() as $m) {
            $id = (int)$m['id'];
            $existing[$id] = $m;
            $n = import_norm((string)$m['match_number']);
            if ($n !== '' && !isset($byNum[$n])) $byNum[$n] = $id;
            $dk = $m['match_date'] . '|' . import_norm((string)$m['opponent']);
            if (!isset($byDay[$dk])) $byDay[$dk] = $id;
        }
        /* Un match deja rapproche ne peut pas l'etre une seconde fois : sans ce
           garde-fou, deux lignes identiques dans le fichier se disputeraient le
           meme enregistrement. */
        $consumed = [];

        /* Numeros de match deja utilises par une AUTRE equipe de la saison :
           signe classique d'un fichier depose sur la mauvaise equipe. Signale,
           jamais bloquant : un club peut legitimement renumeroter. */
        $otherNum = [];
        $oSt = $pdo->prepare('SELECT m.match_number, t.name FROM matches m JOIN teams t ON t.id = m.team_id
                              WHERE m.season_id = ? AND m.team_id != ? AND m.match_number != ""');
        $oSt->execute([$season_id, $team_id]);
        foreach ($oSt->fetchAll() as $m) $otherNum[import_norm((string)$m['match_number'])] = $m['name'];

        $errors    = [];
        $warnings  = [];
        $newOnes   = [];
        $updates   = [];   // matchs reconnus dont une information a change
        $unchanged = 0;

        foreach ($rows as $idx => $row) {
            $lineNum = $idx + 2;
            if (count(array_filter($row, fn($c) => $c !== '')) === 0) continue;

            $dateRaw = $row[$dateCol] ?? '';
            $date    = normalize_date($dateRaw);
            if ($date === null) {
                $errors[] = "Ligne $lineNum : date non reconnue ($dateRaw)";
                continue;
            }

            $home = $homeCol >= 0 ? trim((string)($row[$homeCol] ?? '')) : '';
            $away = $awayCol >= 0 ? trim((string)($row[$awayCol] ?? '')) : '';

            /* Le mot-cle ne sert qu'a situer le club, jamais a identifier
               l'equipe : c'est le choix fait a l'ecran qui fait foi. */
            $isHome = $home !== '' && stripos($home, $club_keyword) !== false;
            $isAway = $away !== '' && stripos($away, $club_keyword) !== false;

            if (!$isHome && !$isAway) {
                $errors[] = "Ligne $lineNum : aucune des deux équipes ne contient « $club_keyword » "
                          . '(' . ($home ?: '?') . ' / ' . ($away ?: '?') . ')';
                continue;
            }
            if ($isHome && $isAway) {
                $warnings[] = "Ligne $lineNum : les deux équipes contiennent « $club_keyword », match compté à domicile";
                $isAway = false;
            }

            $opponent = $isHome ? $away : $home;
            $num      = $numCol >= 0 ? trim((string)($row[$numCol] ?? '')) : '';
            $time     = $timeCol >= 0 ? trim((string)($row[$timeCol] ?? '')) : '';

            $incoming = [
                'match_date'   => $date,
                'match_time'   => $time,
                'match_number' => $num,
                'opponent'     => $opponent,
                'is_home'      => $isHome ? 1 : 0,
                'competition'  => $compCol >= 0  ? trim((string)($row[$compCol] ?? ''))  : '',
                'venue'        => $venueCol >= 0 ? trim((string)($row[$venueCol] ?? '')) : '',
            ];

            /* Rapprochement avec un match existant. Le numero de match fait foi
               quand il existe : c'est la seule cle qui survit a un report de
               date. Sans numero, on retombe sur date + adversaire, et un match
               deplace a un autre jour ne peut alors pas etre reconnu — il
               arrivera comme un nouveau match, limite inherente a un fichier
               sans numero. */
            $nk = import_norm($num);
            $dk = $date . '|' . import_norm($opponent);
            $matchId = null;
            if ($nk !== '' && isset($byNum[$nk]))      $matchId = $byNum[$nk];
            elseif (isset($byDay[$dk]))                $matchId = $byDay[$dk];

            if ($matchId !== null && isset($consumed[$matchId])) {
                /* Deux lignes du fichier pointent le meme match : la seconde est
                   un doublon interne, on ne la traite pas. */
                continue;
            }

            if ($matchId !== null) {
                $consumed[$matchId] = true;
                $before  = $existing[$matchId];
                $changed = [];
                foreach ($incoming as $f => $v) {
                    /* Une colonne absente du mapping renvoie une chaine vide :
                       elle ne doit pas effacer une valeur deja en base. */
                    if ($v === '' && (string)$before[$f] !== '') continue;
                    if ((string)$before[$f] !== (string)$v) $changed[$f] = $v;
                }
                if (!$changed) { $unchanged++; continue; }

                if (($before['status'] ?? '') === 'paid') {
                    $warnings[] = 'Le match ' . ($num !== '' ? "n° $num" : 'du ' . $before['match_date'])
                                . ' est déjà payé : ses informations sont mises à jour, le paiement est conservé.';
                }
                $updates[] = [
                    'id'      => $matchId,
                    'before'  => $before,
                    'changed' => $changed,
                    'label'   => ($num !== '' ? "n° $num · " : '') . $before['match_date'] . ' vs ' . $before['opponent'],
                ];
                continue;
            }

            /* Nouveau match : on l'indexe tout de suite pour que deux lignes
               identiques du fichier ne l'inserent pas deux fois. */
            if ($nk !== '') {
                if (isset($otherNum[$nk])) {
                    $warnings[] = "Le match n° $num est déjà enregistré pour l'équipe « "
                                . $otherNum[$nk] . " ». Vérifiez l'équipe choisie.";
                }
                $byNum[$nk] = -count($newOnes) - 1;
            }
            $byDay[$dk] = -count($newOnes) - 1;
            $consumed[-count($newOnes) - 1] = true;
            $newOnes[] = $incoming;
        }

        $warnings = array_values(array_unique($warnings));

        /* Libelles lisibles des champs, pour montrer a l'ecran ce qui change. */
        $fieldLabels = [
            'match_date' => 'Date', 'match_time' => 'Heure', 'match_number' => 'N° de match',
            'opponent' => 'Adversaire', 'is_home' => 'Domicile/extérieur',
            'competition' => 'Compétition', 'venue' => 'Lieu',
        ];
        $describe = function (array $u) use ($fieldLabels): array {
            $diff = [];
            foreach ($u['changed'] as $f => $v) {
                $old = (string)$u['before'][$f];
                $new = (string)$v;
                if ($f === 'is_home') { $old = $old ? 'domicile' : 'extérieur'; $new = $new ? 'domicile' : 'extérieur'; }
                $diff[] = ($fieldLabels[$f] ?? $f) . ' : ' . ($old === '' ? '—' : $old) . ' → ' . ($new === '' ? '—' : $new);
            }
            return ['label' => $u['label'], 'paid' => ($u['before']['status'] ?? '') === 'paid', 'changes' => $diff];
        };

        /* Etape 2 : recapitulatif avant ecriture. */
        if ($confirm === 0) {
            out([
                'step'           => 'confirm',
                'team'           => ['id' => $team_id, 'name' => $team['name'], 'season' => $team['season_label']],
                'new_count'      => count($newOnes),
                'updated_count'  => count($updates),
                'unchanged'      => $unchanged,
                'error_count'    => count($errors),
                'errors'         => array_slice($errors, 0, 30),
                'warnings'       => $warnings,
                'sample'         => array_slice($newOnes, 0, 10),
                'update_sample'  => array_map($describe, array_slice($updates, 0, 15)),
            ]);
        }

        /* Etape 3 : ecriture. Aucune equipe creee, aucune saison creee, et
           surtout aucun champ de paiement touche. */
        $imported = 0;
        $updated  = 0;
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO matches (season_id, match_number, match_date, match_time, team_id, opponent, is_home, competition, venue) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($newOnes as $m) {
                $ins->execute([$season_id, $m['match_number'], $m['match_date'], $m['match_time'],
                               $team_id, $m['opponent'], $m['is_home'], $m['competition'], $m['venue']]);
                $imported++;
            }

            /* Seules les colonnes reellement differentes sont ecrites, et la
               liste blanche interdit d'atteindre status, paid_at, notes ou
               payment_method depuis le contenu d'un fichier. */
            $allowed = ['match_date', 'match_time', 'match_number', 'opponent', 'is_home', 'competition', 'venue'];
            foreach ($updates as $u) {
                $set = [];
                $val = [];
                foreach ($u['changed'] as $f => $v) {
                    if (!in_array($f, $allowed, true)) continue;
                    $set[] = "$f = ?";
                    $val[] = $v;
                }
                if (!$set) continue;
                $val[] = $u['id'];
                $val[] = $season_id;
                $val[] = $team_id;
                $pdo->prepare('UPDATE matches SET ' . implode(', ', $set)
                            . ' WHERE id = ? AND season_id = ? AND team_id = ?')->execute($val);
                $updated++;
            }

            $cnt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE season_id = ?');
            $cnt->execute([$season_id]);
            $pdo->prepare('UPDATE seasons SET match_count = ? WHERE id = ?')
                ->execute([(int)$cnt->fetchColumn(), $season_id]);
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail('Erreur lors de l\'import : ' . $e->getMessage(), 500);
        }

        out([
            'ok'          => true,
            'team'        => ['id' => $team_id, 'name' => $team['name'], 'season' => $team['season_label']],
            'season_id'   => $season_id,
            'imported'    => $imported,
            'updated'     => $updated,
            'unchanged'   => $unchanged,
            'error_count' => count($errors),
            'errors'      => array_slice($errors, 0, 30),
            'warnings'    => $warnings,
        ]);
    }

    /* ============ IMPORT CSV (ancien, global) ============ */

    case 'import_csv': {
        require_auth();

        /* Cet import identifiait les equipes par leur nom dans le fichier et en
           creait une des qu'il ne reconnaissait pas l'orthographe : c'est
           precisement ce que le referentiel corrige. On le ferme des que le
           referentiel existe, plutot que de laisser deux chemins d'import dont
           l'un repeuple la base d'equipes parasites. */
        if (club_active()) {
            fail("L'import global est remplacé par l'import du calendrier équipe par équipe, "
               . "qui n'a plus besoin de reconnaître les noms d'équipes du fichier.", 410);
        }

        $confirm      = (int)($_GET['confirm'] ?? 0);
        $season_label = s($_POST, 'season_label', 'Saison ' . date('Y') . '-' . (date('Y') + 1));
        $club_keyword = s($_POST, 'club_keyword', 'Meyrin');
        $mapping      = [];
        if (!empty($_POST['mapping'])) {
            $mapping = json_decode($_POST['mapping'], true) ?? [];
        }

        if (empty($_FILES['csv'])) fail('Fichier CSV requis');
        $f = $_FILES['csv'];
        if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
        if ($f['size'] > 10 * 1024 * 1024) fail('Fichier trop volumineux (10 Mo max)');

        $content = file_get_contents($f['tmp_name']);
        if ($content === false) fail('Impossible de lire le fichier');

        // Detect and convert encoding
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $parsed_csv = parse_csv_content($content);
        $headers    = $parsed_csv['headers'];
        $rows       = $parsed_csv['rows'];

        if (empty($headers)) fail('CSV vide ou illisible');

        // Step 1 : no mapping → return preview only
        if (empty($mapping)) {
            out([
                'step'       => 'preview',
                'headers'    => $headers,
                'rows'       => array_slice($rows, 0, 10),
                'separator'  => $parsed_csv['sep'],
                'total_rows' => count($rows),
            ]);
        }

        // Step 2 : mapping provided → parse matches
        $col = function(string $key) use ($mapping, $headers): int {
            $colName = $mapping[$key] ?? '';
            if ($colName === '') return -1;
            $idx = array_search($colName, $headers, true);
            return $idx !== false ? (int) $idx : -1;
        };

        $dateCol  = $col('date');
        $timeCol  = $col('time');
        $numCol   = $col('match_number');
        $homeCol  = $col('home_team');
        $awayCol  = $col('away_team');
        $catCol   = $col('category');
        $compCol  = $col('competition');
        $venueCol = $col('venue');

        if ($dateCol < 0)                     fail('Colonne "date" obligatoire dans le mapping');
        if ($homeCol < 0 && $awayCol < 0)     fail('Au moins une colonne équipe est requise dans le mapping');

        // Load DB categories for better auto-detection
        $knownCategories = array_column(db()->query('SELECT name FROM categories ORDER BY sort_order')->fetchAll(), 'name');

        $errors  = [];
        $matches = [];

        foreach ($rows as $idx => $row) {
            $lineNum = $idx + 2;
            if (count(array_filter($row, fn($c) => $c !== '')) === 0) continue;

            $dateRaw = $row[$dateCol] ?? '';
            $date    = normalize_date($dateRaw);
            if ($date === null) {
                $errors[] = "Ligne $lineNum : date non reconnue ({$dateRaw})";
                continue;
            }

            $home = $homeCol >= 0 ? ($row[$homeCol] ?? '') : '';
            $away = $awayCol >= 0 ? ($row[$awayCol] ?? '') : '';

            $isHome = stripos($home, $club_keyword) !== false;
            $isAway = stripos($away, $club_keyword) !== false;

            if (!$isHome && !$isAway) continue; // pas une équipe du club

            $teamName = $isHome ? $home : $away;
            $opponent = $isHome ? $away : $home;

            if (!$teamName) {
                $errors[] = "Ligne $lineNum : nom d'équipe vide";
                continue;
            }

            $cat = $catCol >= 0 ? ($row[$catCol] ?? '') : '';
            if ($cat === '') $cat = detect_category($teamName, $knownCategories);

            $matches[] = [
                'match_date'   => $date,
                'match_time'   => $timeCol >= 0 ? ($row[$timeCol] ?? '') : '',
                'match_number' => $numCol >= 0  ? ($row[$numCol] ?? '')  : '',
                'team_name'    => $teamName,
                'opponent'     => $opponent,
                'is_home'      => $isHome ? 1 : 0,
                'competition'  => $compCol >= 0  ? ($row[$compCol] ?? '')  : '',
                'venue'        => $venueCol >= 0 ? ($row[$venueCol] ?? '') : '',
                'category'     => $cat,
            ];
        }

        // Preview only (confirm=0)
        if ($confirm === 0) {
            out([
                'step'         => 'confirm',
                'parsed_count' => count($matches),
                'error_count'  => count($errors),
                'errors'       => $errors,
                'sample'       => array_slice($matches, 0, 10),
            ]);
        }

        // Actual import (confirm=1)
        $pdo = db();

        $pdo->prepare('INSERT INTO seasons (label, match_count) VALUES (?, 0)')->execute([$season_label]);
        $season_id = (int) $pdo->lastInsertId();

        // Build team lookup map (name|||category) → id, scoped to the season being imported.
        // A team is uniquely identified by the combination of name + category *within a season*:
        // each season gets its own team records (coach/fee can change from one season to the next),
        // so teams from other seasons are never reused here.
        $teamSt = $pdo->prepare('SELECT id, name, category FROM teams WHERE season_id = ?');
        $teamSt->execute([$season_id]);
        $teamRows = $teamSt->fetchAll();
        $teamMap  = [];
        foreach ($teamRows as $t) {
            $key = mb_strtolower($t['name']) . '|||' . mb_strtolower($t['category']);
            $teamMap[$key] = (int) $t['id'];
        }

        $newTeamCount = 0;
        $imported     = 0;

        $pdo->beginTransaction();
        try {
            $insMatch = $pdo->prepare('INSERT INTO matches (season_id, match_number, match_date, match_time, team_id, opponent, is_home, competition, venue) VALUES (?,?,?,?,?,?,?,?,?)');
            $insTeam  = $pdo->prepare('INSERT INTO teams (season_id, name, category, coach_name, fee_amount) VALUES (?,?,?,?,0)');

            foreach ($matches as $p) {
                $key = mb_strtolower($p['team_name']) . '|||' . mb_strtolower($p['category']);
                if (!isset($teamMap[$key])) {
                    $insTeam->execute([$season_id, $p['team_name'], $p['category'], '']);
                    $teamId        = (int) $pdo->lastInsertId();
                    $teamMap[$key] = $teamId;
                    $newTeamCount++;
                }

                $insMatch->execute([
                    $season_id,
                    $p['match_number'],
                    $p['match_date'],
                    $p['match_time'],
                    $teamMap[$key],
                    $p['opponent'],
                    $p['is_home'],
                    $p['competition'],
                    $p['venue'],
                ]);
                $imported++;
            }

            $pdo->prepare('UPDATE seasons SET match_count = ? WHERE id = ?')->execute([$imported, $season_id]);
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail('Erreur lors de l\'import : ' . $e->getMessage(), 500);
        }

        out([
            'ok'           => true,
            'season_id'    => $season_id,
            'season_label' => $season_label,
            'imported'     => $imported,
            'new_teams'    => $newTeamCount,
            'error_count'  => count($errors),
            'errors'       => $errors,
            'needs_config' => $newTeamCount > 0,
        ]);
    }

    /* ============ MATCHS ============ */

    case 'matches': {
        require_auth();

        if ($method === 'GET') {
            $where  = ['1=1'];
            $params = [];

            if (!empty($_GET['team_id']))    { $where[] = 'm.team_id = ?';    $params[] = (int)$_GET['team_id']; }
            if (!empty($_GET['status']))     { $where[] = 'm.status = ?';     $params[] = $_GET['status']; }
            if (!empty($_GET['season_id']))  { $where[] = 'm.season_id = ?';  $params[] = (int)$_GET['season_id']; }
            if (!empty($_GET['competition'])) { $where[] = 'm.competition = ?';          $params[] = $_GET['competition']; }
            if (!empty($_GET['match_number'])){ $where[] = 'm.match_number LIKE ?';      $params[] = '%' . $_GET['match_number'] . '%'; }
            if (!empty($_GET['date_from']))   { $where[] = 'm.match_date >= ?';           $params[] = $_GET['date_from']; }
            if (!empty($_GET['date_to']))    { $where[] = 'm.match_date <= ?';$params[] = $_GET['date_to']; }

            if (!empty($_GET['coach_name'])) { $where[] = 't.coach_name = ?'; $params[] = $_GET['coach_name']; }
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $per_page = min(500, max(1, (int)($_GET['per_page'] ?? 50)));
            $offset   = ($page - 1) * $per_page;
            $whereSQL = implode(' AND ', $where);

            $cntSt = db()->prepare("SELECT COUNT(*) FROM matches m LEFT JOIN teams t ON m.team_id = t.id WHERE $whereSQL");
            $cntSt->execute($params);
            $total = (int) $cntSt->fetchColumn();

            $st = db()->prepare("
                SELECT m.*, t.name as team_name, t.coach_name, t.category,
                       t.fee_amount as team_fee_amount,
                       COALESCE(m.fee_override, t.fee_amount) as fee_amount
                FROM matches m
                LEFT JOIN teams t ON m.team_id = t.id
                WHERE $whereSQL
                ORDER BY m.match_date ASC, m.match_time ASC
                LIMIT $per_page OFFSET $offset
            ");
            $st->execute($params);

            out(['matches' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
        }

        if ($method === 'PUT') {
            $id = i($b, 'id');
            if (!$id) fail('ID requis');
            $fields = [];
            $vals   = [];
            if (array_key_exists('status', $b)) {
                $status = s($b, 'status');
                if (!in_array($status, ['pending', 'paid', 'cancelled'], true)) fail('Statut invalide');
                $fields[] = 'status = ?';  $vals[] = $status;
                $paid_at  = $status === 'paid' ? (s($b, 'paid_at') ?: date('Y-m-d')) : null;
                $fields[] = 'paid_at = ?'; $vals[] = $paid_at;
            }
            if (array_key_exists('notes', $b))          { $fields[] = 'notes = ?';          $vals[] = s($b, 'notes'); }
            if (array_key_exists('payment_method', $b)) { $fields[] = 'payment_method = ?'; $vals[] = s($b, 'payment_method'); }
            if (array_key_exists('match_date', $b))  { $fields[] = 'match_date = ?';  $vals[] = s($b, 'match_date'); }
            if (array_key_exists('match_time', $b))  { $fields[] = 'match_time = ?';  $vals[] = s($b, 'match_time'); }
            if (array_key_exists('team_id', $b))     { $fields[] = 'team_id = ?';     $vals[] = i($b, 'team_id'); }
            if (array_key_exists('opponent', $b))    { $fields[] = 'opponent = ?';    $vals[] = s($b, 'opponent'); }
            if (array_key_exists('is_home', $b))     { $fields[] = 'is_home = ?';     $vals[] = i($b, 'is_home'); }
            if (array_key_exists('competition', $b)) { $fields[] = 'competition = ?'; $vals[] = s($b, 'competition'); }
            if (array_key_exists('venue', $b))       { $fields[] = 'venue = ?';       $vals[] = s($b, 'venue'); }
            /* Indemnité négociée pour ce match : une valeur vide/nulle rétablit le tarif
               standard de l'équipe, un nombre (y compris 0) l'écrase pour ce match seul. */
            if (array_key_exists('fee_override', $b)) {
                $raw = $b['fee_override'];
                $fields[] = 'fee_override = ?';
                $vals[]   = ($raw === null || $raw === '') ? null : (float) $raw;
            }
            if (!$fields) fail('Rien à modifier');
            $vals[] = $id;
            db()->prepare('UPDATE matches SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
            out(['ok' => true]);
        }

        if ($method === 'DELETE') {
            $id = i($_GET, 'id');
            if (!$id) fail('ID requis');
            db()->prepare('DELETE FROM matches WHERE id = ?')->execute([$id]);
            out(['ok' => true]);
        }

        fail('Méthode non supportée', 405);
    }

    case 'matches_bulk_pay': {
        require_auth();
        $ids            = $b['ids'] ?? [];
        $paid_at        = s($b, 'paid_at') ?: date('Y-m-d');
        $notes          = s($b, 'notes');
        $payment_method = s($b, 'payment_method');
        if (!is_array($ids) || empty($ids)) fail('Aucun match sélectionné');
        $ids          = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = array_merge([$paid_at, $notes, $payment_method], $ids);
        db()->prepare("UPDATE matches SET status='paid', paid_at=?, notes=?, payment_method=? WHERE id IN ($placeholders)")
            ->execute($params);
        out(['ok' => true, 'updated' => count($ids)]);
    }

    /* ============ STATISTIQUES DASHBOARD ============ */

    case 'stats': {
        require_auth();
        $pdo = db();
        club_sync($pdo);

        $season_id = resolve_season_id($pdo, (int)($_GET['season_id'] ?? 0));

        // Totals
        $totSt = $pdo->prepare('
            SELECT
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN COALESCE(m.fee_override, t.fee_amount) ELSE 0 END), 0) as total_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN COALESCE(m.fee_override, t.fee_amount) ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(CASE WHEN m.status = "cancelled" THEN 1 ELSE 0 END), 0) as count_cancelled,
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN 1 ELSE 0 END), 0) as count_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN 1 ELSE 0 END), 0) as count_paid,
                COALESCE(SUM(CASE WHEN m.status IN ("pending","paid") THEN 1 ELSE 0 END), 0) as count_total
            FROM matches m
            LEFT JOIN teams t ON m.team_id = t.id
            WHERE m.season_id = ?
        ');
        $totSt->execute([$season_id]);
        $totals = $totSt->fetch();

        // Upcoming matches (next 7 days)
        $from    = date('Y-m-d');
        $to      = date('Y-m-d', strtotime('+7 days'));
        $upSt    = $pdo->prepare('
            SELECT m.*, t.name as team_name, t.coach_name, t.category,
                   t.fee_amount as team_fee_amount,
                   COALESCE(m.fee_override, t.fee_amount) as fee_amount
            FROM matches m
            LEFT JOIN teams t ON m.team_id = t.id
            WHERE m.match_date BETWEEN ? AND ? AND m.season_id = ? AND m.status != \'cancelled\'
            ORDER BY m.match_date, m.match_time
        ');
        $upSt->execute([$from, $to, $season_id]);
        $upcoming = $upSt->fetchAll();

        // Per-team stats
        $tSt = $pdo->prepare('
            SELECT
                t.id, t.name, t.category, t.coach_name, t.fee_amount, t.active,
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN COALESCE(m.fee_override, t.fee_amount) ELSE 0 END), 0) as amount_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN COALESCE(m.fee_override, t.fee_amount) ELSE 0 END), 0) as amount_paid,
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN 1 ELSE 0 END), 0) as count_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN 1 ELSE 0 END), 0) as count_paid,
                COALESCE(SUM(CASE WHEN m.status = "cancelled" THEN 1 ELSE 0 END), 0) as count_cancelled,
                COALESCE(SUM(CASE WHEN m.status IN ("pending","paid") THEN 1 ELSE 0 END), 0) as count_total
            FROM teams t
            LEFT JOIN matches m ON m.team_id = t.id AND m.season_id = ?
            WHERE t.active = 1 AND t.season_id = ?
            GROUP BY t.id
            ORDER BY t.category, t.name
        ');
        $tSt->execute([$season_id, $season_id]);
        $teams = $tSt->fetchAll();

        // Seasons list
        $seasons = $pdo->query('SELECT * FROM seasons ORDER BY id DESC')->fetchAll();

        out([
            'season_id' => $season_id,
            'seasons'   => $seasons,
            'totals'    => $totals,
            'upcoming'  => $upcoming,
            'teams'     => $teams,
        ]);
    }

    /* ============ UTILISATEURS ============ */

    /* Annuaire local conserve en lecture seule pour l'historique. Aucune table
       de cette base ne le reference, et les comptes se gerent dans l'ERP. */
    case 'users': {
        require_auth();
        if ($method === 'GET') {
            out(db()->query('SELECT id, name, email, role, active FROM users ORDER BY id')->fetchAll());
        }
        fail("Les comptes se gèrent dans l'ERP : " . ERP_URL, 403);
    }

    case 'categories': {
        require_auth();
        if ($method === 'GET') {
            club_sync(db());
            $rows = db()->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
            out($rows);
        }
        if (club_active()) club_readonly('Les catégories');
        if ($method === 'POST') {
            $name  = s($b, 'name');
            $order = i($b, 'sort_order', 100);
            if (!$name) fail('Nom requis');
            try {
                db()->prepare('INSERT INTO categories (name, sort_order) VALUES (?,?)')->execute([$name, $order]);
                out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
            } catch (\Exception $e) { fail('Cette catégorie existe déjà'); }
        }
        if ($method === 'PUT') {
            $id      = i($b, 'id');
            $name    = s($b, 'name');
            $oldName = s($b, 'old_name');
            $order   = i($b, 'sort_order', 100);
            if (!$id || !$name) fail('ID et nom requis');
            try {
                db()->prepare('UPDATE categories SET name=?, sort_order=? WHERE id=?')->execute([$name, $order, $id]);
                if ($oldName && $oldName !== $name) {
                    db()->prepare('UPDATE teams SET category=? WHERE category=?')->execute([$name, $oldName]);
                }
                out(['ok' => true]);
            } catch (\Exception $e) { fail('Cette catégorie existe déjà'); }
        }
        if ($method === 'DELETE') {
            $id = i($_GET, 'id');
            if (!$id) fail('ID requis');
            $st = db()->prepare('SELECT name FROM categories WHERE id=?');
            $st->execute([$id]);
            $cat = $st->fetch();
            if (!$cat) fail('Catégorie introuvable');
            $cnt = db()->prepare('SELECT COUNT(*) FROM teams WHERE category=?');
            $cnt->execute([$cat['name']]);
            $teamsAffected = (int) $cnt->fetchColumn();
            db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
            out(['ok' => true, 'teams_affected' => $teamsAffected]);
        }
        fail('Méthode non supportée', 405);
    }

    default:
        fail('Action inconnue : ' . htmlspecialchars($action, ENT_QUOTES), 404);
}
