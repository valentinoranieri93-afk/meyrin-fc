<?php
/**
 * Arbitrage Meyrin FC — API backend
 * PHP 8.x + SQLite (PDO). Aucune dépendance externe.
 * Compatible hébergement mutualisé Infomaniak.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 14,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

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
    // Seed default categories on first run
    $count = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $defaults = [['U6',0],['U8',10],['U10',20],['U12',30],['U14',40],['U16',50],['U18',60],['M21',70],['Seniors',80],['Dames',90],['Vétérans',100]];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO categories (name, sort_order) VALUES (?,?)');
        foreach ($defaults as [$name, $ord]) $ins->execute([$name, $ord]);
    }
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

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? AND active = 1');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
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

switch ($action) {

    /* ============ AUTH & SETUP ============ */

    case 'status': {
        $count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        out(['installed' => $count > 0, 'user' => current_user()]);
    }

    case 'setup': {
        $count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) fail('Application déjà installée', 403);
        $name  = s($b, 'name') ?: 'Administrateur';
        $email = strtolower(s($b, 'email'));
        $pass  = (string)($b['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide');
        if (strlen($pass) < 8) fail('Mot de passe : 8 caractères minimum');
        db()->prepare("INSERT INTO users (name, email, password_hash, role, last_login) VALUES (?,?,?,?,datetime('now'))")
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
        $_SESSION['uid'] = (int) db()->lastInsertId();
        session_regenerate_id(true);
        out(['ok' => true, 'user' => current_user()]);
    }

    case 'login': {
        $email = strtolower(s($b, 'email'));
        $pass  = (string)($b['password'] ?? '');
        $st    = db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            usleep(400000);
            fail('E-mail ou mot de passe incorrect', 401);
        }
        $_SESSION['uid'] = (int) $u['id'];
        session_regenerate_id(true);
        db()->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([(int)$u['id']]);
        out(['ok' => true, 'user' => current_user()]);
    }

    case 'logout': {
        session_destroy();
        out(['ok' => true]);
    }

    case 'me': {
        out(['user' => require_auth()]);
    }

    /* ============ ÉQUIPES ============ */

    case 'teams': {
        require_auth();
        if ($method === 'GET') {
            $rows = db()->query('SELECT * FROM teams ORDER BY category, name')->fetchAll();
            out($rows);
        }
        if ($method === 'POST') {
            $name = s($b, 'name');
            if (!$name) fail('Nom de l\'équipe requis');
            db()->prepare('INSERT INTO teams (name, category, coach_name, fee_amount) VALUES (?,?,?,?)')
                ->execute([$name, s($b, 'category'), s($b, 'coach_name'), n($b, 'fee_amount')]);
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
                db()->prepare('UPDATE teams SET active = 0 WHERE id = ?')->execute([$id]);
                out(['ok' => true, 'archived' => true, 'match_count' => $matchCount]);
            } else {
                db()->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
                out(['ok' => true, 'archived' => false]);
            }
        }
        fail('Méthode non supportée', 405);
    }

    case 'teams_bulk_save': {
        require_auth();
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
            $rows = db()->query('SELECT * FROM seasons ORDER BY id DESC')->fetchAll();
            out($rows);
        }
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

    /* ============ IMPORT CSV ============ */

    case 'import_csv': {
        require_auth();

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

        // Build team lookup map (name|||category) → id
        // A team is uniquely identified by the combination of name + category.
        // "Meyrin FC 1" in U14 and "Meyrin FC 1" in U18 are two distinct teams.
        $teamRows = $pdo->query('SELECT id, name, category FROM teams')->fetchAll();
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
            $insTeam  = $pdo->prepare('INSERT INTO teams (name, category, coach_name, fee_amount) VALUES (?,?,?,0)');

            foreach ($matches as $p) {
                $key = mb_strtolower($p['team_name']) . '|||' . mb_strtolower($p['category']);
                if (!isset($teamMap[$key])) {
                    $insTeam->execute([$p['team_name'], $p['category'], '']);
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
                SELECT m.*, t.name as team_name, t.coach_name, t.fee_amount, t.category
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

        $season_id = (int)($_GET['season_id'] ?? 0);

        // Use most recent season if not specified
        if ($season_id === 0) {
            $row = $pdo->query('SELECT id FROM seasons ORDER BY id DESC LIMIT 1')->fetch();
            $season_id = $row ? (int)$row['id'] : 0;
        }

        // Totals
        $totSt = $pdo->prepare('
            SELECT
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN t.fee_amount ELSE 0 END), 0) as total_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN t.fee_amount ELSE 0 END), 0) as total_paid,
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
            SELECT m.*, t.name as team_name, t.coach_name, t.fee_amount, t.category
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
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN t.fee_amount ELSE 0 END), 0) as amount_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN t.fee_amount ELSE 0 END), 0) as amount_paid,
                COALESCE(SUM(CASE WHEN m.status = "pending"   THEN 1 ELSE 0 END), 0) as count_pending,
                COALESCE(SUM(CASE WHEN m.status = "paid"      THEN 1 ELSE 0 END), 0) as count_paid,
                COALESCE(SUM(CASE WHEN m.status = "cancelled" THEN 1 ELSE 0 END), 0) as count_cancelled,
                COALESCE(SUM(CASE WHEN m.status IN ("pending","paid") THEN 1 ELSE 0 END), 0) as count_total
            FROM teams t
            LEFT JOIN matches m ON m.team_id = t.id AND m.season_id = ?
            WHERE t.active = 1
            GROUP BY t.id
            ORDER BY t.category, t.name
        ');
        $tSt->execute([$season_id]);
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

    case 'users': {
        require_auth();
        $me = current_user();
        if ($me['role'] !== 'admin') fail('Accès réservé aux administrateurs', 403);

        if ($method === 'GET') {
            $rows = db()->query('SELECT id, name, email, role, active, last_login, created_at FROM users ORDER BY created_at')->fetchAll();
            out($rows);
        }

        if ($method === 'POST') {
            $name  = s($b, 'name');
            $email = strtolower(s($b, 'email'));
            $pass  = (string)($b['password'] ?? '');
            $role  = s($b, 'role', 'viewer');
            if (!$name)  fail('Nom requis');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide');
            if (strlen($pass) < 8) fail('Mot de passe : 8 caractères minimum');
            if (!in_array($role, ['admin', 'viewer'], true)) fail('Rôle invalide');
            try {
                db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
                out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
            } catch (\Exception $e) { fail('Cette adresse e-mail est déjà utilisée'); }
        }

        if ($method === 'PUT') {
            $id = i($b, 'id');
            if (!$id) fail('ID requis');
            $fields = [];
            $vals   = [];
            if (array_key_exists('name', $b))   { $fields[] = 'name = ?';  $vals[] = s($b, 'name'); }
            if (array_key_exists('email', $b))  {
                $email = strtolower(s($b, 'email'));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide');
                $fields[] = 'email = ?'; $vals[] = $email;
            }
            if (array_key_exists('role', $b)) {
                if ($id === $me['id']) fail('Vous ne pouvez pas modifier votre propre rôle');
                $role = s($b, 'role');
                if (!in_array($role, ['admin', 'viewer'], true)) fail('Rôle invalide');
                $fields[] = 'role = ?'; $vals[] = $role;
            }
            if (array_key_exists('active', $b)) {
                if ($id === $me['id']) fail('Vous ne pouvez pas désactiver votre propre compte');
                $fields[] = 'active = ?'; $vals[] = i($b, 'active');
            }
            if (!empty($b['password'])) {
                $pass = (string)$b['password'];
                if (strlen($pass) < 8) fail('Mot de passe : 8 caractères minimum');
                $fields[] = 'password_hash = ?'; $vals[] = password_hash($pass, PASSWORD_DEFAULT);
            }
            if (!$fields) fail('Rien à modifier');
            $vals[] = $id;
            try {
                db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
                out(['ok' => true]);
            } catch (\Exception $e) { fail('Cette adresse e-mail est déjà utilisée'); }
        }

        if ($method === 'DELETE') {
            $id = i($_GET, 'id');
            if (!$id) fail('ID requis');
            if ($id === $me['id']) fail('Vous ne pouvez pas supprimer votre propre compte');
            $st = db()->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1");
            $st->execute();
            $adminCount = (int) $st->fetchColumn();
            $tr = db()->prepare('SELECT role FROM users WHERE id=?');
            $tr->execute([$id]);
            $target = $tr->fetch();
            if ($target && $target['role'] === 'admin' && $adminCount <= 1) {
                fail('Impossible de supprimer le dernier administrateur');
            }
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            out(['ok' => true]);
        }

        fail('Méthode non supportée', 405);
    }

    /* ============ CATÉGORIES ============ */

    case 'categories': {
        require_auth();
        if ($method === 'GET') {
            $rows = db()->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
            out($rows);
        }
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
