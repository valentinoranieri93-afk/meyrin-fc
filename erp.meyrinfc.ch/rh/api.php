<?php
/**
 * Module RH — API backend
 * PHP 8.x + SQLite (PDO). Session partagée avec erp.meyrinfc.ch (cookie mfc_session).
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
  http_response_code(500);
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Serveur : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
});
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Serveur : ' . $e['message'] . ' (ligne ' . $e['line'] . ')'], JSON_UNESCAPED_UNICODE);
  }
});

require_once __DIR__ . '/mfc_boot.php';
require_once __DIR__ . '/config.php';

/* Session ERP + accès au module. Répond 401/403 en JSON si besoin.
   $rh_session est conservé : le reste du fichier s'en sert déjà. */
$rh_session = mfc_require_api('rh');

if (ob_get_level() > 0) ob_clean();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* ---------------------------------------------------------------- DB */

const DB_DIR = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/rh.sqlite';

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  if (!is_dir(DB_DIR)) mkdir(DB_DIR, 0775, true);
  $ht = DB_DIR . '/.htaccess';
  if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
  $pdo = new PDO('sqlite:' . DB_FILE);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $pdo->exec('PRAGMA journal_mode = DELETE');
  $pdo->exec('PRAGMA foreign_keys = ON');
  init_schema($pdo);
  return $pdo;
}

function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
  $st->execute([$table]);
  return (bool) $st->fetchColumn();
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  if (!table_exists($pdo, $table)) return false;
  $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
  return in_array($column, $cols, true);
}
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
  if (!table_exists($pdo, $table)) return;
  if (!column_exists($pdo, $table, $column)) {
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
  }
}

/**
 * Reconstruit une table pour abandonner d'anciennes contraintes NOT NULL (ex: colonne "role"/"statut" devenue
 * obsolète) que SQLite ne permet pas de relâcher via ALTER TABLE. Ne s'exécute que si la colonne héritée
 * $legacyColumn est encore présente (marqueur d'ancien schéma) ; sans effet sur une base déjà à jour.
 */
function rebuild_table_dropping_legacy(PDO $pdo, string $table, string $legacyColumn, string $newCreateSql, string $keepColumns): void {
  if (!column_exists($pdo, $table, $legacyColumn)) return;
  $pdo->exec("ALTER TABLE $table RENAME TO {$table}__legacy");
  $pdo->exec($newCreateSql);
  $pdo->exec("INSERT INTO $table ($keepColumns) SELECT $keepColumns FROM {$table}__legacy");
  $pdo->exec("DROP TABLE {$table}__legacy");
}

function init_schema(PDO $pdo): void {
  // Migration : anciennes tables "coaches" / "coach_assignments" -> "employees" / "employee_assignments"
  if (table_exists($pdo, 'coaches') && !table_exists($pdo, 'employees')) {
    $pdo->exec('ALTER TABLE coaches RENAME TO employees');
  }
  if (table_exists($pdo, 'coach_assignments') && !table_exists($pdo, 'employee_assignments')) {
    $pdo->exec('ALTER TABLE coach_assignments RENAME TO employee_assignments');
  }
  if (column_exists($pdo, 'employee_assignments', 'coach_id') && !column_exists($pdo, 'employee_assignments', 'employee_id')) {
    $pdo->exec('ALTER TABLE employee_assignments RENAME COLUMN coach_id TO employee_id');
  }
  if (column_exists($pdo, 'indemnites_custom', 'coach_id') && !column_exists($pdo, 'indemnites_custom', 'employee_id')) {
    $pdo->exec('ALTER TABLE indemnites_custom RENAME COLUMN coach_id TO employee_id');
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS postes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS team_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  ensure_column($pdo, 'team_categories', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');

  $pdo->exec("CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  ensure_column($pdo, 'teams', 'category_id', 'INTEGER REFERENCES team_categories(id)');
  ensure_column($pdo, 'teams', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'teams', 'cotisation_montant', 'REAL NOT NULL DEFAULT 0');
  ensure_column($pdo, 'teams', 'effectif_max', 'INTEGER NOT NULL DEFAULT 0');

  // Saisons : seules les affectations (poste + montant d'un employé sur une équipe) sont rattachées à une
  // saison (1er juillet - 30 juin). Équipes, catégories, rôles et employés restent permanents d'une saison à l'autre.
  $pdo->exec("CREATE TABLE IF NOT EXISTS seasons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL UNIQUE,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    iban TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  // Échéance de versement (mensuel/semestriel) : propriété de l'employé (comment on le paie), pas du poste
  // (le montant d'un poste est toujours annuel, voir plus bas).
  ensure_column($pdo, 'employees', 'paiement', "TEXT NOT NULL DEFAULT 'mensuel'");

  // employee_assignments = un "poste" au sein d'une équipe (rôle + employé optionnel + sa propre indemnité).
  // L'ancien barème séparé (indemnite_bareme, poste x équipe) est abandonné : le montant/périodicité vit
  // directement sur chaque poste d'équipe. Reconstruction complète dès qu'un ancien schéma est détecté
  // (absence de la colonne "montant"), quelle que soit sa forme précédente (avec "role" texte ou déjà poste_id).
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER REFERENCES employees(id) ON DELETE SET NULL,
    team_id INTEGER REFERENCES teams(id) ON DELETE CASCADE,
    poste_id INTEGER NOT NULL REFERENCES postes(id) ON DELETE CASCADE,
    montant REAL NOT NULL DEFAULT 0,
    periodicite TEXT NOT NULL DEFAULT 'mensuel',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  if (table_exists($pdo, 'employee_assignments') && !column_exists($pdo, 'employee_assignments', 'montant')) {
    ensure_column($pdo, 'employee_assignments', 'poste_id', 'INTEGER REFERENCES postes(id)');
    if (column_exists($pdo, 'employee_assignments', 'role')) {
      foreach ($pdo->query("SELECT id, role FROM employee_assignments WHERE poste_id IS NULL AND role IS NOT NULL AND role != ''")->fetchAll() as $r) {
        $pdo->prepare('UPDATE employee_assignments SET poste_id=? WHERE id=?')->execute([find_or_create_poste($pdo, $r['role']), $r['id']]);
      }
    }
    ensure_column($pdo, 'employee_assignments', 'montant', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'employee_assignments', 'periodicite', "TEXT NOT NULL DEFAULT 'mensuel'");
    if (table_exists($pdo, 'indemnite_bareme')) {
      foreach ($pdo->query("SELECT ea.id, ib.montant, ib.periodicite FROM employee_assignments ea
        JOIN indemnite_bareme ib ON ib.poste_id = ea.poste_id AND (ib.team_id = ea.team_id OR (ib.team_id IS NULL AND ea.team_id IS NULL))")->fetchAll() as $r) {
        $pdo->prepare('UPDATE employee_assignments SET montant=?, periodicite=? WHERE id=?')->execute([$r['montant'], $r['periodicite'], $r['id']]);
      }
    }
    $pdo->exec('ALTER TABLE employee_assignments RENAME TO employee_assignments__legacy');
    $pdo->exec("CREATE TABLE employee_assignments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      employee_id INTEGER REFERENCES employees(id) ON DELETE SET NULL,
      team_id INTEGER REFERENCES teams(id) ON DELETE CASCADE,
      poste_id INTEGER NOT NULL REFERENCES postes(id) ON DELETE CASCADE,
      montant REAL NOT NULL DEFAULT 0,
      periodicite TEXT NOT NULL DEFAULT 'mensuel',
      active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec('INSERT INTO employee_assignments (id, employee_id, team_id, poste_id, montant, periodicite, active, created_at)
      SELECT id, employee_id, team_id, poste_id, montant, periodicite, active, created_at FROM employee_assignments__legacy');
    $pdo->exec('DROP TABLE employee_assignments__legacy');
  }

  if (table_exists($pdo, 'indemnite_bareme')) $pdo->exec('DROP TABLE indemnite_bareme');

  ensure_column($pdo, 'employee_assignments', 'season_id', 'INTEGER REFERENCES seasons(id)');
  // Poste rattaché directement à une catégorie plutôt qu'à une équipe (ex: Directeur Technique d'une catégorie).
  // Mutuellement exclusif avec team_id : validé côté application, pas de contrainte SQL.
  ensure_column($pdo, 'employee_assignments', 'category_id', 'INTEGER REFERENCES team_categories(id)');

  // Le montant d'un poste est désormais toujours un montant ANNUEL ; "periodicite" (mensuel/annuel, qui
  // servait à calculer l'équivalent mensuel) devient "paiement" (mensuel/semestriel), une simple échéance
  // de versement pour le suivi de liquidité, sans effet sur le calcul du total.
  if (column_exists($pdo, 'employee_assignments', 'periodicite') && !column_exists($pdo, 'employee_assignments', 'paiement')) {
    $pdo->exec('ALTER TABLE employee_assignments RENAME COLUMN periodicite TO paiement');
  }
  ensure_column($pdo, 'employee_assignments', 'paiement', "TEXT NOT NULL DEFAULT 'mensuel'");
  $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')))");
  $alreadyMigrated = (bool) $pdo->query("SELECT 1 FROM schema_migrations WHERE name = 'annualize_montant_2026_07'")->fetchColumn();
  if (!$alreadyMigrated) {
    // Une seule fois : les lignes historiques "mensuel" (montant mensuel) sont annualisées (×12) ; les
    // lignes "annuel" gardent leur montant (déjà annuel). Toutes basculent sur paiement="mensuel" par défaut
    // (modifiable ensuite ligne par ligne vers "semestriel").
    $pdo->exec("UPDATE employee_assignments SET montant = montant * 12 WHERE paiement = 'mensuel'");
    $pdo->exec("UPDATE employee_assignments SET paiement = 'mensuel' WHERE paiement NOT IN ('mensuel','semestriel')");
    $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (name) VALUES (?)')->execute(['annualize_montant_2026_07']);
  }

  // Chaque poste d'équipe doit être rattaché à une équipe : les anciens postes "sans équipe" (ex: staff
  // administratif) sont regroupés dans une équipe de repli, créée avant le passage qui exige une catégorie
  // pour chaque équipe ci-dessous (pour que cette équipe de repli en hérite aussi, sans jamais rester orpheline).
  if ((int)$pdo->query('SELECT COUNT(*) FROM employee_assignments WHERE team_id IS NULL')->fetchColumn() > 0) {
    $fallbackTeamId = find_or_create_team($pdo, 'Staff / Administration');
    $pdo->prepare('UPDATE employee_assignments SET team_id = ? WHERE team_id IS NULL')->execute([$fallbackTeamId]);
  }
  // Chaque équipe doit obligatoirement appartenir à une catégorie : les équipes orphelines (anciennes données,
  // ou l'équipe de repli créée juste au-dessus) sont rattachées à une catégorie "Non classé" créée au besoin.
  if ((int)$pdo->query('SELECT COUNT(*) FROM teams WHERE category_id IS NULL')->fetchColumn() > 0) {
    $fallbackCategoryId = find_or_create_category($pdo, 'Non classé');
    $pdo->prepare('UPDATE teams SET category_id = ? WHERE category_id IS NULL')->execute([$fallbackCategoryId]);
  }

  // Saison courante (règle du 1er juillet) : garantit son existence, en la dupliquant depuis la saison la
  // plus récente si elle vient d'être créée (bascule automatique de saison sans action manuelle). Les
  // affectations existantes qui n'ont pas encore de saison (première migration d'une base existante) y sont rattachées.
  $currentSeasonId = ensure_current_season($pdo);
  if ((int)$pdo->query('SELECT COUNT(*) FROM employee_assignments WHERE season_id IS NULL')->fetchColumn() > 0) {
    $pdo->prepare('UPDATE employee_assignments SET season_id = ? WHERE season_id IS NULL')->execute([$currentSeasonId]);
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS indemnites_custom (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    montant REAL NOT NULL DEFAULT 0,
    month TEXT NOT NULL,
    recurring INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  // Règles de paie configurables (impôt source, charges sociales, assurance accident, LPP...) : le club
  // saisit lui-même les libellés et taux/montants applicables, activables ou non par employé.
  $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL DEFAULT 'charge_sociale',
    label TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'percent',
    valeur REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_payroll_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    rule_id INTEGER NOT NULL REFERENCES payroll_rules(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(employee_id, rule_id)
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    iban TEXT NOT NULL DEFAULT '',
    salaire_mensuel REAL NOT NULL DEFAULT 0,
    is_guest INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  ensure_column($pdo, 'players', 'is_guest', 'INTEGER NOT NULL DEFAULT 0');

  $pdo->exec("CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    adversaire TEXT NOT NULL DEFAULT '',
    competition TEXT NOT NULL DEFAULT '',
    resultat TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS match_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
    player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    montant REAL NOT NULL DEFAULT 0,
    source TEXT NOT NULL DEFAULT 'manuel',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(match_id, player_id)
  )");
  ensure_column($pdo, 'match_players', 'montant', 'REAL NOT NULL DEFAULT 0');
  // Migration : ancienne colonne prime_montant -> montant
  if (column_exists($pdo, 'match_players', 'prime_montant') && column_exists($pdo, 'match_players', 'montant')) {
    $pdo->exec('UPDATE match_players SET montant = prime_montant WHERE montant = 0');
  }
  // Ancien schéma : "statut" NOT NULL, plus utilisé (montant libre) -> reconstruction pour l'abandonner.
  rebuild_table_dropping_legacy($pdo, 'match_players', 'statut', "CREATE TABLE match_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
    player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    montant REAL NOT NULL DEFAULT 0,
    source TEXT NOT NULL DEFAULT 'manuel',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(match_id, player_id)
  )", 'id, match_id, player_id, montant, source, created_at');

  $pdo->exec("CREATE TABLE IF NOT EXISTS imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL DEFAULT '',
    month TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending_review',
    raw_json TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  // Postes par défaut si table vide
  if ((int)$pdo->query('SELECT COUNT(*) FROM postes')->fetchColumn() === 0) {
    $st = $pdo->prepare('INSERT INTO postes (label) VALUES (?)');
    foreach (['Entraîneur principal','Entraîneur adjoint','Entraîneur gardiens','Préparateur physique','Team manager','Kiné','Staff administratif','Directeur technique'] as $label) {
      $st->execute([$label]);
    }
  }
}

/* ---------------------------------------------------------------- Helpers */

function body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return $_POST ?: [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function out($data, int $code = 200): never {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $code = 400): never {
  out(['error' => $msg], $code);
}

function s(array $b, string $k, string $def = ''): string { return trim((string)($b[$k] ?? $def)); }
function i(array $b, string $k, int $def = 0): int { return (int)($b[$k] ?? $def); }
function f(array $b, string $k, float $def = 0): float { return (float)($b[$k] ?? $def); }
function bo(array $b, string $k, bool $def = false): bool { return isset($b[$k]) ? (bool)$b[$k] : $def; }
function ni(array $b, string $k): ?int { $v = $b[$k] ?? null; return ($v === null || $v === '') ? null : (int)$v; }

/** Équivalent mensuel d'un montant : le montant d'un poste est toujours annuel. */
function monthly_equiv(float $montant): float {
  return $montant / 12;
}

/** Normalise un nom pour comparaison floue (minuscule, sans accents, sans espaces superflus). */
function normalize_name(string $n): string {
  $n = mb_strtolower(trim($n));
  $n = strtr($n, [
    'à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
    'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
  ]);
  return preg_replace('/\s+/', ' ', $n);
}

/** Retrouve le joueur le plus proche d'un nom donné (score 0-100). */
function match_player_name(string $name, array $players): array {
  $target = normalize_name($name);
  $best = null; $bestScore = 0;
  foreach ($players as $p) {
    $full = normalize_name($p['first_name'] . ' ' . $p['last_name']);
    $full2 = normalize_name($p['last_name'] . ' ' . $p['first_name']);
    similar_text($target, $full, $pct1);
    similar_text($target, $full2, $pct2);
    $pct = max($pct1, $pct2);
    if ($target === $full || $target === $full2) $pct = 100.0;
    if ($pct > $bestScore) { $bestScore = $pct; $best = $p; }
  }
  return ['player' => $best, 'score' => round($bestScore, 1)];
}

/** Calcule le total mensuel des indemnités par employé pour une saison donnée (état courant, non historisé). */
function compute_employee_indemnites(PDO $pdo, string $month, int $season_id): array {
  $st = $pdo->prepare("
    SELECT e.id AS employee_id, e.first_name, e.last_name,
           ea.team_id, t.name AS team_name, ea.category_id, tc.name AS category_name, ea.poste_id, po.label AS poste_label,
           ea.montant
    FROM employee_assignments ea
    JOIN employees e ON e.id = ea.employee_id AND e.active = 1
    JOIN postes po ON po.id = ea.poste_id
    LEFT JOIN teams t ON t.id = ea.team_id
    LEFT JOIN team_categories tc ON tc.id = ea.category_id
    WHERE ea.active = 1 AND ea.season_id = ?
  ");
  $st->execute([$season_id]);
  $rows = $st->fetchAll();

  $byEmployee = [];
  $alerts = [];
  foreach ($rows as $r) {
    $eid = (int)$r['employee_id'];
    if (!isset($byEmployee[$eid])) {
      $byEmployee[$eid] = ['employee_id' => $eid, 'name' => $r['first_name'] . ' ' . $r['last_name'], 'total' => 0.0, 'lignes' => []];
    }
    $label = $r['poste_label'] . ($r['team_name'] ? ' — ' . $r['team_name'] : ($r['category_name'] ? ' — ' . $r['category_name'] . ' (catégorie)' : ''));
    $montant = round(monthly_equiv((float)$r['montant']), 2);
    $byEmployee[$eid]['total'] += $montant;
    $byEmployee[$eid]['lignes'][] = ['type' => 'poste', 'label' => $label, 'montant' => $montant];
  }

  $st = $pdo->prepare("SELECT ic.*, e.first_name, e.last_name FROM indemnites_custom ic
    JOIN employees e ON e.id = ic.employee_id
    WHERE ic.month = ? OR (ic.recurring = 1 AND ic.month <= ?)");
  $st->execute([$month, $month]);
  foreach ($st->fetchAll() as $r) {
    $eid = (int)$r['employee_id'];
    if (!isset($byEmployee[$eid])) {
      $byEmployee[$eid] = ['employee_id' => $eid, 'name' => $r['first_name'] . ' ' . $r['last_name'], 'total' => 0.0, 'lignes' => []];
    }
    $byEmployee[$eid]['total'] += (float)$r['montant'];
    $byEmployee[$eid]['lignes'][] = ['type' => 'custom', 'label' => $r['label'], 'montant' => (float)$r['montant']];
  }

  return ['par_employee' => array_values($byEmployee), 'alerts' => array_values(array_unique($alerts))];
}

/** Total des indemnités récurrentes "en régime de croisière" par employé pour une saison donnée
 * (hors indemnités ponctuelles), pour la liste Employés. */
function compute_employee_running_totals(PDO $pdo, int $season_id): array {
  $st = $pdo->prepare("
    SELECT ea.employee_id, ea.montant
    FROM employee_assignments ea
    WHERE ea.active = 1 AND ea.employee_id IS NOT NULL AND ea.season_id = ?
  ");
  $st->execute([$season_id]);
  $rows = $st->fetchAll();
  $totals = [];
  foreach ($rows as $r) {
    $eid = (int)$r['employee_id'];
    $totals[$eid] = ($totals[$eid] ?? 0) + monthly_equiv((float)$r['montant']);
  }
  $st = $pdo->query("SELECT employee_id, montant, recurring FROM indemnites_custom WHERE recurring = 1");
  foreach ($st->fetchAll() as $r) {
    $eid = (int)$r['employee_id'];
    $totals[$eid] = ($totals[$eid] ?? 0) + (float)$r['montant'];
  }
  return $totals;
}

function compute_player_pay(PDO $pdo, string $month): array {
  $players = $pdo->query("SELECT * FROM players WHERE active = 1 AND is_guest = 0")->fetchAll();
  $st = $pdo->prepare("SELECT mp.player_id, SUM(mp.montant) AS total_primes
    FROM match_players mp JOIN matches m ON m.id = mp.match_id
    WHERE strftime('%Y-%m', m.date) = ?
    GROUP BY mp.player_id");
  $st->execute([$month]);
  $primes = [];
  foreach ($st->fetchAll() as $r) $primes[(int)$r['player_id']] = (float)$r['total_primes'];

  $out = [];
  foreach ($players as $p) {
    $pid = (int)$p['id'];
    $salaire = (float)$p['salaire_mensuel'];
    $prime = $primes[$pid] ?? 0.0;
    $out[] = [
      'player_id' => $pid,
      'name' => $p['first_name'] . ' ' . $p['last_name'],
      'salaire' => $salaire,
      'primes' => $prime,
      'total' => $salaire + $prime,
    ];
  }
  // Primes versées à des joueurs ponctuels (guests) ce mois-ci : comptées dans le total général mais pas dans la liste nominative des salariés.
  $stg = $pdo->prepare("SELECT SUM(mp.montant) AS total FROM match_players mp
    JOIN matches m ON m.id = mp.match_id JOIN players p ON p.id = mp.player_id
    WHERE p.is_guest = 1 AND strftime('%Y-%m', m.date) = ?");
  $stg->execute([$month]);
  $guestTotal = (float)($stg->fetchColumn() ?: 0);

  return ['joueurs' => $out, 'primes_ponctuels' => $guestTotal];
}

/* ---------------------------------------------------------------- Import Excel/CSV */

/** Lit un fichier CSV ou XLSX simple (une seule feuille, pas de formules) et retourne un tableau de lignes associatives, clé = en-tête normalisé. */
function parse_uploaded_table(array $file): array {
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $rows = $ext === 'xlsx' ? parse_xlsx($file['tmp_name']) : parse_csv($file['tmp_name']);
  if (count($rows) < 2) fail('Fichier vide ou sans ligne de données.');
  $headers = array_map(fn($h) => normalize_header((string)$h), $rows[0]);
  $out = [];
  for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue; // ligne vide
    $assoc = [];
    foreach ($headers as $ci => $h) { if ($h) $assoc[$h] = trim((string)($row[$ci] ?? '')); }
    $out[] = $assoc;
  }
  return $out;
}

/** Reconnaît les en-têtes par sous-chaîne pour tolérer les libellés Odoo ("Nom de l'employé", "Téléphone professionnel"...). */
function normalize_header(string $h): string {
  $h = normalize_name($h);
  if (str_contains($h, 'prenom')) return 'prenom';
  if (str_contains($h, "nom de l") || str_contains($h, 'nom complet') || str_contains($h, 'nom et prenom')) return 'nom_complet';
  if ($h === 'nom' || str_contains($h, 'nom de famille')) return 'nom';
  if (str_contains($h, 'email') || str_contains($h, 'mail')) return 'email';
  if (str_contains($h, 'telephone') || str_contains($h, 'tel')) return 'telephone';
  if (str_contains($h, 'iban')) return 'iban';
  if (str_contains($h, 'departement') || str_contains($h, 'department')) return 'departement';
  if (str_contains($h, 'equipe') || str_contains($h, 'team')) return 'equipe';
  if (str_contains($h, 'poste') || str_contains($h, 'role') || str_contains($h, 'fonction')) return 'poste';
  if (str_contains($h, 'categorie') || str_contains($h, 'category')) return 'categorie';
  return '';
}

/** Sépare un nom complet façon Odoo ("Prénom NOM", nom de famille en MAJUSCULES) en [prénom, nom]. */
function split_full_name(string $full): array {
  $words = preg_split('/\s+/', trim($full));
  if (count($words) < 2) return ['', $full];
  $lastWords = [];
  for ($i = count($words) - 1; $i >= 0; $i--) {
    $w = $words[$i];
    if (mb_strtoupper($w) === $w && preg_match('/\p{L}/u', $w)) { array_unshift($lastWords, $w); }
    else { break; }
  }
  if (!$lastWords) { $lastWords = [array_pop($words)]; return [implode(' ', $words), $lastWords[0]]; }
  $firstWords = array_slice($words, 0, count($words) - count($lastWords));
  return [implode(' ', $firstWords), implode(' ', $lastWords)];
}

function parse_csv(string $path): array {
  $rows = [];
  $fh = fopen($path, 'r');
  if (!$fh) return [];
  $bom = fread($fh, 3);
  if ($bom !== "\xEF\xBB\xBF") rewind($fh);
  while (($r = fgetcsv($fh, 0, ';')) !== false) {
    if (count($r) === 1 && strpos($r[0], ',') !== false) $r = str_getcsv($r[0], ',');
    $rows[] = $r;
  }
  fclose($fh);
  return $rows;
}

/** Lecteur XLSX minimaliste : première feuille, valeurs texte/nombre uniquement (pas de formules). */
function parse_xlsx(string $path): array {
  if (!class_exists('ZipArchive')) fail('Extension ZipArchive indisponible sur ce serveur : exportez le fichier en CSV.');
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) fail('Fichier XLSX illisible.');

  $shared = [];
  $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedXml !== false) {
    $sx = simplexml_load_string($sharedXml);
    foreach ($sx->si as $si) {
      $text = '';
      if (isset($si->t)) { $text = (string)$si->t; }
      else { foreach ($si->r as $r) $text .= (string)$r->t; }
      $shared[] = $text;
    }
  }

  $sheetName = null;
  $wbXml = $zip->getFromName('xl/workbook.xml');
  $sheetPath = 'xl/worksheets/sheet1.xml';
  if ($zip->getFromName($sheetPath) === false) {
    // Cherche la première feuille disponible dans l'archive
    for ($j = 0; $j < $zip->numFiles; $j++) {
      $name = $zip->getNameIndex($j);
      if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) { $sheetPath = $name; break; }
    }
  }
  $sheetXml = $zip->getFromName($sheetPath);
  $zip->close();
  if ($sheetXml === false) fail('Feuille Excel introuvable dans le fichier.');

  $sx = simplexml_load_string($sheetXml);
  $rows = [];
  foreach ($sx->sheetData->row as $row) {
    $cells = [];
    $colIndex = 0;
    foreach ($row->c as $c) {
      $ref = (string)$c['r'];
      preg_match('/([A-Z]+)/', $ref, $mm);
      $colIndex = $mm ? col_letter_to_index($mm[1]) : $colIndex;
      $type = (string)$c['t'];
      $raw = isset($c->v) ? (string)$c->v : '';
      $value = ($type === 's' && $raw !== '') ? ($shared[(int)$raw] ?? '') : $raw;
      $cells[$colIndex] = $value;
      $colIndex++;
    }
    if ($cells) {
      $max = max(array_keys($cells));
      $line = [];
      for ($k = 0; $k <= $max; $k++) $line[] = $cells[$k] ?? '';
      $rows[] = $line;
    } else {
      $rows[] = [];
    }
  }
  return $rows;
}
function col_letter_to_index(string $letters): int {
  $n = 0;
  foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
  return $n - 1;
}

function find_or_create_poste(PDO $pdo, string $label): int {
  $st = $pdo->prepare('SELECT id FROM postes WHERE label = ? COLLATE NOCASE');
  $st->execute([$label]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $st = $pdo->prepare('INSERT INTO postes (label) VALUES (?)');
  $st->execute([$label]);
  return (int)$pdo->lastInsertId();
}
/** Retrouve ou crée une équipe par nom. Une équipe créée implicitement (import) est rattachée à "Non classé"
 * pour respecter l'obligation qu'une équipe appartienne toujours à une catégorie. */
function find_or_create_team(PDO $pdo, string $name): int {
  $st = $pdo->prepare('SELECT id FROM teams WHERE name = ? COLLATE NOCASE');
  $st->execute([$name]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $category_id = find_or_create_category($pdo, 'Non classé');
  $st = $pdo->prepare('INSERT INTO teams (name, category_id) VALUES (?,?)');
  $st->execute([$name, $category_id]);
  return (int)$pdo->lastInsertId();
}
function find_or_create_category(PDO $pdo, string $name): int {
  $st = $pdo->prepare('SELECT id FROM team_categories WHERE name = ? COLLATE NOCASE');
  $st->execute([$name]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM team_categories')->fetchColumn();
  $st = $pdo->prepare('INSERT INTO team_categories (name, sort_order) VALUES (?,?)');
  $st->execute([$name, $next]);
  return (int)$pdo->lastInsertId();
}

/** Label de saison suisse (1er juillet - 30 juin) correspondant à une date donnée, ex: "2025-2026". */
function season_label_for_date(string $ymd): string {
  $y = (int)substr($ymd, 0, 4);
  $m = (int)substr($ymd, 5, 2);
  $startYear = $m >= 7 ? $y : $y - 1;
  return $startYear . '-' . ($startYear + 1);
}

/** Retrouve ou crée une saison par label, en dupliquant les affectations d'une saison de référence si elle est neuve. */
function find_or_create_season(PDO $pdo, string $label, ?int $duplicateFromId = null): int {
  $st = $pdo->prepare('SELECT id FROM seasons WHERE label = ?');
  $st->execute([$label]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $startYear = (int)explode('-', $label)[0];
  $st = $pdo->prepare('INSERT INTO seasons (label, start_date, end_date) VALUES (?,?,?)');
  $st->execute([$label, $startYear . '-07-01', ($startYear + 1) . '-06-30']);
  $newId = (int)$pdo->lastInsertId();
  if ($duplicateFromId) {
    $pdo->prepare('INSERT INTO employee_assignments (employee_id, team_id, category_id, poste_id, montant, active, season_id)
      SELECT employee_id, team_id, category_id, poste_id, montant, active, ? FROM employee_assignments WHERE season_id = ?')
      ->execute([$newId, $duplicateFromId]);
  }
  return $newId;
}

/** Garantit l'existence de la saison en cours (règle du 1er juillet) : bascule automatique dès que le calendrier
 * passe le 1er juillet, la nouvelle saison démarrant avec une copie des affectations de la saison précédente. */
function ensure_current_season(PDO $pdo): int {
  $label = season_label_for_date(date('Y-m-d'));
  $st = $pdo->prepare('SELECT id FROM seasons WHERE label = ?');
  $st->execute([$label]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $latest = $pdo->query('SELECT id FROM seasons ORDER BY start_date DESC LIMIT 1')->fetch();
  return find_or_create_season($pdo, $label, $latest ? (int)$latest['id'] : null);
}

/* ---------------------------------------------------------------- Anthropic (extraction feuille de primes) */

function call_anthropic(array $contentBlocks): array {
  if (!RH_ANTHROPIC_API_KEY) fail('Clé API Claude non configurée (RH_ANTHROPIC_API_KEY).', 500);

  $payload = [
    'model' => RH_ANTHROPIC_MODEL,
    'max_tokens' => 4096,
    'messages' => [[ 'role' => 'user', 'content' => $contentBlocks ]],
  ];

  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'x-api-key: ' . RH_ANTHROPIC_API_KEY,
      'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 90,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res === false) fail('Appel Claude échoué : ' . $err, 502);
  $data = json_decode($res, true);
  if ($code >= 300) fail('Erreur API Claude (' . $code . ') : ' . ($data['error']['message'] ?? $res), 502);

  $text = '';
  foreach (($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $text .= $block['text'];
  }
  if (!preg_match('/\{.*\}/s', $text, $mm)) fail('Réponse Claude inexploitable : ' . $text, 502);
  $parsed = json_decode($mm[0], true);
  if (!is_array($parsed)) fail('JSON invalide renvoyé par Claude.', 502);
  return $parsed;
}

const EXTRACTION_PROMPT = <<<PROMPT
Tu reçois la feuille mensuelle des primes de match de l'équipe première d'un club de football suisse, rédigée par l'entraîneur.
Analyse le document et renvoie UNIQUEMENT un objet JSON (aucun texte autour), au format strict suivant :

{
  "matches": [
    {
      "date": "YYYY-MM-DD ou null si inconnue",
      "adversaire": "nom de l'adversaire ou null",
      "joueurs": [
        { "nom": "nom du joueur tel qu'écrit dans le document", "montant": nombre en CHF (0 si non explicite dans le document) }
      ]
    }
  ]
}

Règles :
- "montant" : le montant en francs suisses explicitement attribué au joueur pour ce match si le document le précise. Si le document ne donne que le statut (titulaire/entré en jeu/etc.) sans montant, mets 0 : le montant sera complété manuellement.
- Ne liste que les joueurs explicitement mentionnés pour un match donné.
- Réponds uniquement avec le JSON, sans commentaire ni balise markdown.
PROMPT;

/* ---------------------------------------------------------------- Router */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

/* ---------------------------------------------------------------- Permissions
 *
 * Table déclarative plutôt qu'une vérification dans chacun des 29 blocs :
 * une seule liste à relire pour savoir qui peut faire quoi, et impossible
 * d'oublier un contrôle au milieu d'un case.
 *
 * Format : action => permission unique, ou ['GET' => perm, 'write' => perm]
 * quand lire et modifier ne demandent pas le même droit. 'write' couvre
 * POST, PUT, PATCH et DELETE.
 *
 * REFUS PAR DÉFAUT : toute action absente de cette table exige le droit le
 * plus élevé du module. Ajouter un point d'entrée sans y penser le rend donc
 * inaccessible, plutôt que public.
 */
const RH_PERMS = [
  'me'                     => null,   // identité de la personne connectée, aucun droit particulier
  'seasons'                => ['GET' => 'teams.view',      'write' => 'teams.edit'],
  'postes'                 => ['GET' => 'teams.view',      'write' => 'indemnites.edit'],
  'team_categories'        => ['GET' => 'teams.view',      'write' => 'teams.edit'],
  'team_categories_reorder'=> 'teams.edit',
  'teams'                  => ['GET' => 'teams.view',      'write' => 'teams.edit'],
  'teams_reorder'          => 'teams.edit',
  'team_totals'            => 'teams.view',
  'category_totals'        => 'teams.view',
  'payment_totals'         => 'teams.view',
  'dashboard'              => 'teams.view',
  'employees'              => ['GET' => 'employees.view',  'write' => 'employees.edit'],
  'employee_assignments'   => ['GET' => 'employees.view',  'write' => 'employees.edit'],
  'indemnites_custom'      => ['GET' => 'employees.view',  'write' => 'indemnites.edit'],
  'payroll_rules'          => ['GET' => 'payroll.view',    'write' => 'payroll.edit'],
  'employee_payroll_rules' => ['GET' => 'payroll.view',    'write' => 'payroll.edit'],
  'players'                => ['GET' => 'employees.view',  'write' => 'primes.edit'],
  'matches'                => ['GET' => 'employees.view',  'write' => 'primes.edit'],
  'primes_matrix'          => ['GET' => 'employees.view',  'write' => 'primes.edit'],
  'match_cell'             => 'primes.edit',
  'match_add_guest'        => 'primes.edit',
  'import_upload'          => 'imports.run',
  'imports'                => 'imports.run',
  'import_get'             => 'imports.run',
  'import_validate'        => 'imports.run',
  'import_discard'         => 'imports.run',
  'import_employees_file'  => 'imports.run',
  'export_compta'          => 'export.compta',
];

$rh_rule = array_key_exists($action, RH_PERMS)
  ? RH_PERMS[$action]
  : 'payroll.edit';   // refus par défaut : le droit le plus élevé du module

if ($rh_rule !== null) {
  if (is_array($rh_rule)) {
    $rh_rule = ($method === 'GET') ? $rh_rule['GET'] : $rh_rule['write'];
  }
  mfc_require_perm('rh.' . $rh_rule);
}

switch ($action) {

  /* ============ SESSION ============ */

  case 'me': {
    out(['user' => [
      'name' => $rh_session['name'] ?? '',
      'role' => $rh_session['role'] ?? '',
      'settings' => $rh_session['settings'] ?? false,
    ]]);
  }

  /* ============ POSTES (paramètres) ============ */

  case 'postes': {
    if ($method === 'GET') out(db()->query('SELECT * FROM postes ORDER BY active DESC, label')->fetchAll());
    if ($method === 'POST') {
      $label = s($b, 'label'); if (!$label) fail('Libellé requis');
      $st = db()->prepare('INSERT INTO postes (label) VALUES (?)');
      try { $st->execute([$label]); } catch (Throwable $e) { fail('Ce poste existe déjà'); }
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $st = db()->prepare('UPDATE postes SET label=?, active=? WHERE id=?');
      $st->execute([s($b, 'label'), bo($b, 'active', true) ? 1 : 0, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM postes WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ CATÉGORIES D'ÉQUIPES (paramètres) ============ */

  case 'team_categories': {
    if ($method === 'GET') out(db()->query('SELECT * FROM team_categories ORDER BY sort_order, name')->fetchAll());
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      $next = (int)db()->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM team_categories')->fetchColumn();
      $st = db()->prepare('INSERT INTO team_categories (name, sort_order) VALUES (?,?)');
      try { $st->execute([$name, $next]); } catch (Throwable $e) { fail('Cette catégorie existe déjà'); }
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $st = db()->prepare('UPDATE team_categories SET name=?, active=? WHERE id=?');
      $st->execute([s($b, 'name'), bo($b, 'active', true) ? 1 : 0, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      $st = db()->prepare('SELECT COUNT(*) FROM teams WHERE category_id=?'); $st->execute([$id]);
      if ((int)$st->fetchColumn() > 0) fail("Cette catégorie contient encore des équipes. Déplace-les ou supprime-les d'abord.");
      db()->prepare('DELETE FROM team_categories WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'team_categories_reorder': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $ids = $b['ids'] ?? []; if (!$ids) fail('ids requis');
    $st = db()->prepare('UPDATE team_categories SET sort_order=? WHERE id=?');
    foreach ($ids as $idx => $id) $st->execute([$idx, (int)$id]);
    out(['ok' => true]);
  }

  /* ============ TEAMS ============ */

  case 'teams': {
    if ($method === 'GET') {
      out(db()->query("SELECT t.*, tc.name AS category_name FROM teams t LEFT JOIN team_categories tc ON tc.id = t.category_id ORDER BY tc.sort_order, t.sort_order")->fetchAll());
    }
    if ($method === 'POST') {
      $name = s($b, 'name'); $category_id = ni($b, 'category_id');
      if (!$name) fail('Nom requis');
      if (!$category_id) fail('Catégorie requise : une équipe doit obligatoirement appartenir à une catégorie.');
      $st0 = db()->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM teams WHERE category_id=?');
      $st0->execute([$category_id]);
      $next = (int)$st0->fetchColumn();
      $st = db()->prepare('INSERT INTO teams (name, category_id, sort_order, cotisation_montant, effectif_max) VALUES (?,?,?,?,?)');
      $st->execute([$name, $category_id, $next, f($b, 'cotisation_montant'), i($b, 'effectif_max')]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); $category_id = ni($b, 'category_id');
      if (!$id) fail('id requis');
      if (!$category_id) fail('Catégorie requise : une équipe doit obligatoirement appartenir à une catégorie.');
      // cotisation_montant/effectif_max ne sont écrasés que si transmis : les appels de renommage/réordonnancement/
      // changement de catégorie n'envoient que name/category_id/active et ne doivent pas remettre ces champs à zéro.
      $existSt = db()->prepare('SELECT cotisation_montant, effectif_max FROM teams WHERE id=?');
      $existSt->execute([$id]);
      $existing = $existSt->fetch();
      if (!$existing) fail('Équipe introuvable', 404);
      $cotisation = array_key_exists('cotisation_montant', $b) ? f($b, 'cotisation_montant') : (float)$existing['cotisation_montant'];
      $effectif = array_key_exists('effectif_max', $b) ? i($b, 'effectif_max') : (int)$existing['effectif_max'];
      $st = db()->prepare('UPDATE teams SET name=?, category_id=?, active=?, cotisation_montant=?, effectif_max=? WHERE id=?');
      $st->execute([s($b, 'name'), $category_id, bo($b, 'active', true) ? 1 : 0, $cotisation, $effectif, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM teams WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'teams_reorder': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $ids = $b['ids'] ?? []; if (!$ids) fail('ids requis');
    $st = db()->prepare('UPDATE teams SET sort_order=? WHERE id=?');
    foreach ($ids as $idx => $id) $st->execute([$idx, (int)$id]);
    out(['ok' => true]);
  }

  /* ============ SAISONS ============ */

  case 'seasons': {
    if ($method === 'GET') {
      $rows = db()->query('SELECT * FROM seasons ORDER BY start_date DESC')->fetchAll();
      $currentLabel = season_label_for_date(date('Y-m-d'));
      foreach ($rows as &$r) $r['is_current'] = ($r['label'] === $currentLabel);
      out($rows);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ EMPLOYÉS ============ */

  case 'employees': {
    if ($method === 'GET') {
      $pdo = db();
      $season_id = i($_GET, 'season_id') ?: ensure_current_season($pdo);
      $employees = $pdo->query('SELECT * FROM employees ORDER BY active DESC, last_name')->fetchAll();
      // Affectations sur toutes les saisons (pas seulement la courante) : la fiche employé doit pouvoir
      // montrer l'historique complet, et la liste Employés doit rester filtrable par n'importe quelle saison.
      $asg = $pdo->query("SELECT ea.*, t.name AS team_name, tc.name AS category_name, po.label AS poste_label, s.label AS season_label
        FROM employee_assignments ea
        JOIN postes po ON po.id = ea.poste_id
        LEFT JOIN teams t ON t.id = ea.team_id
        LEFT JOIN team_categories tc ON tc.id = ea.category_id
        LEFT JOIN seasons s ON s.id = ea.season_id
        WHERE ea.active=1")->fetchAll();
      $byEmployee = [];
      foreach ($asg as $a) $byEmployee[(int)$a['employee_id']][] = $a;
      $custom = $pdo->query('SELECT * FROM indemnites_custom ORDER BY month DESC')->fetchAll();
      $byEmployeeCustom = [];
      foreach ($custom as $c) $byEmployeeCustom[(int)$c['employee_id']][] = $c;
      $totals = compute_employee_running_totals($pdo, $season_id);
      foreach ($employees as &$e) {
        $e['assignments'] = $byEmployee[(int)$e['id']] ?? [];
        $e['indemnites_custom'] = $byEmployeeCustom[(int)$e['id']] ?? [];
        $e['total_indemnites'] = round($totals[(int)$e['id']] ?? 0, 2);
      }
      out($employees);
    }
    if ($method === 'POST') {
      $ln = s($b, 'last_name'); if (!$ln) fail('Nom requis');
      $paiement = s($b, 'paiement', 'mensuel');
      if (!in_array($paiement, ['mensuel', 'semestriel'], true)) fail('paiement invalide');
      $st = db()->prepare('INSERT INTO employees (first_name,last_name,email,phone,iban,paiement) VALUES (?,?,?,?,?,?)');
      $st->execute([s($b, 'first_name'), $ln, s($b, 'email'), s($b, 'phone'), s($b, 'iban'), $paiement]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      // paiement n'est écrasé que si transmis : les sauvegardes du formulaire employé (nom/contact) n'envoient
      // pas ce champ et ne doivent pas remettre l'échéance de versement à sa valeur par défaut.
      $existSt = db()->prepare('SELECT paiement FROM employees WHERE id=?');
      $existSt->execute([$id]);
      $existingEmp = $existSt->fetch();
      if (!$existingEmp) fail('Employé introuvable', 404);
      $paiement = array_key_exists('paiement', $b) ? s($b, 'paiement', 'mensuel') : $existingEmp['paiement'];
      if (!in_array($paiement, ['mensuel', 'semestriel'], true)) fail('paiement invalide');
      $st = db()->prepare('UPDATE employees SET first_name=?,last_name=?,email=?,phone=?,iban=?,active=?,paiement=? WHERE id=?');
      $st->execute([s($b,'first_name'), s($b,'last_name'), s($b,'email'), s($b,'phone'), s($b,'iban'), bo($b,'active',true)?1:0, $paiement, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ AFFECTATIONS EMPLOYÉ ============ */

  case 'employee_assignments': {
    if ($method === 'GET') {
      $team_id = ni($_GET, 'team_id'); $category_id = ni($_GET, 'category_id');
      if (!$team_id && !$category_id) fail('team_id ou category_id requis');
      $season_id = i($_GET, 'season_id') ?: ensure_current_season(db());
      if ($team_id) {
        $st = db()->prepare("SELECT ea.*, po.label AS poste_label, e.first_name, e.last_name
          FROM employee_assignments ea JOIN postes po ON po.id = ea.poste_id
          LEFT JOIN employees e ON e.id = ea.employee_id
          WHERE ea.team_id = ? AND ea.season_id = ? ORDER BY ea.created_at");
        $st->execute([$team_id, $season_id]);
      } else {
        $st = db()->prepare("SELECT ea.*, po.label AS poste_label, e.first_name, e.last_name
          FROM employee_assignments ea JOIN postes po ON po.id = ea.poste_id
          LEFT JOIN employees e ON e.id = ea.employee_id
          WHERE ea.category_id = ? AND ea.season_id = ? ORDER BY ea.created_at");
        $st->execute([$category_id, $season_id]);
      }
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      $team_id = ni($b, 'team_id'); $category_id = ni($b, 'category_id');
      $poste_id = i($b, 'poste_id'); $employee_id = ni($b, 'employee_id');
      $montant = f($b, 'montant');
      $season_id = i($b, 'season_id') ?: ensure_current_season(db());
      if (!$team_id && !$category_id) fail('team_id ou category_id requis');
      if ($team_id && $category_id) fail('Choisir une équipe ou une catégorie, pas les deux');
      if (!$poste_id) fail('poste_id requis');
      $st = db()->prepare('INSERT INTO employee_assignments (employee_id, team_id, category_id, poste_id, montant, season_id) VALUES (?,?,?,?,?,?)');
      $st->execute([$employee_id, $team_id, $category_id, $poste_id, $montant, $season_id]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $employee_id = ni($b, 'employee_id'); $poste_id = i($b, 'poste_id'); $montant = f($b, 'montant');
      if (!$poste_id) fail('poste_id requis');
      $st = db()->prepare('UPDATE employee_assignments SET employee_id=?, poste_id=?, montant=? WHERE id=?');
      $st->execute([$employee_id, $poste_id, $montant, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM employee_assignments WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ TOTAUX PAR ÉQUIPE (équivalent mensuel, pour l'arbre Équipes) ============ */

  case 'team_totals': {
    $season_id = i($_GET, 'season_id') ?: ensure_current_season(db());
    $st = db()->prepare('SELECT team_id, montant FROM employee_assignments WHERE active = 1 AND season_id = ? AND team_id IS NOT NULL');
    $st->execute([$season_id]);
    $rows = $st->fetchAll();
    $totals = [];
    foreach ($rows as $r) {
      $tid = (int)$r['team_id'];
      $totals[$tid] = ($totals[$tid] ?? 0) + monthly_equiv((float)$r['montant']);
    }
    // Total non arrondi : l'arrondi se fait uniquement à l'affichage (côté frontend), pour éviter que le
    // total annuel (mensuel × 12) ne dérive d'un montant mensuel déjà arrondi (ex: 1583.33 × 12 ≠ 19000).
    $out = [];
    foreach ($totals as $tid => $total) $out[] = ['team_id' => $tid, 'total' => $total];
    out($out);
  }

  /* ============ TOTAUX PAR CATÉGORIE (postes rattachés directement à la catégorie, ex: Directeur Technique) ============ */

  case 'category_totals': {
    $season_id = i($_GET, 'season_id') ?: ensure_current_season(db());
    $st = db()->prepare('SELECT category_id, montant FROM employee_assignments WHERE active = 1 AND season_id = ? AND category_id IS NOT NULL');
    $st->execute([$season_id]);
    $rows = $st->fetchAll();
    $totals = [];
    foreach ($rows as $r) {
      $cid = (int)$r['category_id'];
      $totals[$cid] = ($totals[$cid] ?? 0) + monthly_equiv((float)$r['montant']);
    }
    $out = [];
    foreach ($totals as $cid => $total) $out[] = ['category_id' => $cid, 'total' => $total];
    out($out);
  }

  /* ============ TOTAUX PAR ÉCHÉANCE DE PAIEMENT (suivi de liquidité : mensuel vs semestriel) ============ */

  case 'payment_totals': {
    // L'échéance de versement (mensuel/semestriel) est une propriété de l'employé, pas du poste : un poste
    // sans employé assigné est compté par défaut en "mensuel" (comportement neutre, pas de préférence connue).
    $season_id = i($_GET, 'season_id') ?: ensure_current_season(db());
    $st = db()->prepare("SELECT COALESCE(e.paiement, 'mensuel') AS paiement, SUM(ea.montant) AS total
      FROM employee_assignments ea LEFT JOIN employees e ON e.id = ea.employee_id
      WHERE ea.active = 1 AND ea.season_id = ? GROUP BY COALESCE(e.paiement, 'mensuel')");
    $st->execute([$season_id]);
    $out = ['mensuel' => 0.0, 'semestriel' => 0.0];
    foreach ($st->fetchAll() as $r) { if (isset($out[$r['paiement']])) $out[$r['paiement']] = (float)$r['total']; }
    out($out);
  }

  /* ============ INDEMNITÉS PERSONNALISÉES ============ */

  case 'indemnites_custom': {
    if ($method === 'GET') {
      $month = s($_GET, 'month');
      if ($month) {
        $st = db()->prepare("SELECT ic.*, e.first_name, e.last_name FROM indemnites_custom ic JOIN employees e ON e.id=ic.employee_id WHERE ic.month=? OR (ic.recurring=1 AND ic.month<=?) ORDER BY ic.created_at DESC");
        $st->execute([$month, $month]);
      } else {
        $st = db()->query("SELECT ic.*, e.first_name, e.last_name FROM indemnites_custom ic JOIN employees e ON e.id=ic.employee_id ORDER BY ic.created_at DESC");
      }
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      $employee_id = i($b, 'employee_id'); $label = s($b, 'label'); $montant = f($b, 'montant'); $month = s($b, 'month');
      if (!$employee_id || !$label || !$month) fail('employee_id, label et month requis');
      $st = db()->prepare('INSERT INTO indemnites_custom (employee_id, label, montant, month, recurring) VALUES (?,?,?,?,?)');
      $st->execute([$employee_id, $label, $montant, $month, bo($b, 'recurring', false) ? 1 : 0]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM indemnites_custom WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ RÈGLES DE PAIE (paramètres) ============ */

  case 'payroll_rules': {
    if ($method === 'GET') {
      out(db()->query("SELECT * FROM payroll_rules ORDER BY category, sort_order, label")->fetchAll());
    }
    if ($method === 'POST') {
      $category = s($b, 'category'); $label = s($b, 'label'); $type = s($b, 'type', 'percent');
      if (!in_array($category, ['impot_source','charge_sociale','assurance_accident','lpp'], true)) fail('category invalide');
      if (!$label) fail('Libellé requis');
      if (!in_array($type, ['percent','fixe'], true)) fail('type invalide');
      $next = (int)db()->query("SELECT COALESCE(MAX(sort_order),-1)+1 FROM payroll_rules WHERE category=" . db()->quote($category))->fetchColumn();
      $st = db()->prepare('INSERT INTO payroll_rules (category, label, type, valeur, sort_order) VALUES (?,?,?,?,?)');
      $st->execute([$category, $label, $type, f($b, 'valeur'), $next]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $category = s($b, 'category'); $type = s($b, 'type', 'percent');
      if (!in_array($category, ['impot_source','charge_sociale','assurance_accident','lpp'], true)) fail('category invalide');
      if (!in_array($type, ['percent','fixe'], true)) fail('type invalide');
      $st = db()->prepare('UPDATE payroll_rules SET category=?, label=?, type=?, valeur=?, active=? WHERE id=?');
      $st->execute([$category, s($b, 'label'), $type, f($b, 'valeur'), bo($b, 'active', true) ? 1 : 0, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM payroll_rules WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ RÈGLES DE PAIE ACTIVÉES PAR EMPLOYÉ ============ */

  case 'employee_payroll_rules': {
    if ($method === 'GET') {
      $employee_id = i($_GET, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $st = db()->prepare('SELECT rule_id FROM employee_payroll_rules WHERE employee_id=?');
      $st->execute([$employee_id]);
      out(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    if ($method === 'POST') {
      // Remplace l'intégralité du jeu de règles activées pour cet employé (coché/décoché depuis sa fiche).
      $employee_id = i($b, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $ruleIds = array_unique(array_map('intval', $b['rule_ids'] ?? []));
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $pdo->prepare('DELETE FROM employee_payroll_rules WHERE employee_id=?')->execute([$employee_id]);
        $ins = $pdo->prepare('INSERT INTO employee_payroll_rules (employee_id, rule_id) VALUES (?,?)');
        foreach ($ruleIds as $rid) { if ($rid > 0) $ins->execute([$employee_id, $rid]); }
        $pdo->commit();
      } catch (Throwable $e) { $pdo->rollBack(); fail('Échec : ' . $e->getMessage(), 500); }
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ PLAYERS ============ */

  case 'players': {
    if ($method === 'GET') {
      out(db()->query('SELECT * FROM players WHERE is_guest = 0 ORDER BY active DESC, last_name')->fetchAll());
    }
    if ($method === 'POST') {
      $ln = s($b, 'last_name'); if (!$ln) fail('Nom requis');
      $st = db()->prepare('INSERT INTO players (first_name,last_name,email,phone,iban,salaire_mensuel,is_guest) VALUES (?,?,?,?,?,?,?)');
      $st->execute([s($b,'first_name'), $ln, s($b,'email'), s($b,'phone'), s($b,'iban'), f($b,'salaire_mensuel'), bo($b,'is_guest',false)?1:0]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $st = db()->prepare('UPDATE players SET first_name=?,last_name=?,email=?,phone=?,iban=?,salaire_mensuel=?,active=? WHERE id=?');
      $st->execute([s($b,'first_name'), s($b,'last_name'), s($b,'email'), s($b,'phone'), s($b,'iban'), f($b,'salaire_mensuel'), bo($b,'active',true)?1:0, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM players WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ MATCHES ============ */

  case 'matches': {
    if ($method === 'GET') {
      $month = s($_GET, 'month');
      if ($month) {
        $st = db()->prepare("SELECT * FROM matches WHERE strftime('%Y-%m', date) = ? ORDER BY date");
        $st->execute([$month]);
        out($st->fetchAll());
      }
      out(db()->query('SELECT * FROM matches ORDER BY date DESC')->fetchAll());
    }
    if ($method === 'POST') {
      $date = s($b, 'date'); if (!$date) fail('date requise');
      $st = db()->prepare('INSERT INTO matches (date, adversaire, competition, resultat) VALUES (?,?,?,?)');
      $st->execute([$date, s($b,'adversaire'), s($b,'competition'), s($b, 'resultat')]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM matches WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ MATRICE PRIMES DE MATCH ============ */

  case 'primes_matrix': {
    $month = s($_GET, 'month') ?: date('Y-m');
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM matches WHERE strftime('%Y-%m', date) = ? ORDER BY date");
    $st->execute([$month]);
    $matches = $st->fetchAll();
    $matchIds = array_column($matches, 'id');

    $cellsByPlayer = [];
    if ($matchIds) {
      $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
      $st2 = $pdo->prepare("SELECT * FROM match_players WHERE match_id IN ($placeholders)");
      $st2->execute($matchIds);
      foreach ($st2->fetchAll() as $mp) {
        $cellsByPlayer[(int)$mp['player_id']][(int)$mp['match_id']] = (float)$mp['montant'];
      }
    }

    $players = $pdo->query('SELECT * FROM players WHERE active = 1 AND is_guest = 0 ORDER BY last_name')->fetchAll();
    if ($matchIds) {
      $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
      $stg = $pdo->prepare("SELECT DISTINCT p.* FROM players p JOIN match_players mp ON mp.player_id = p.id
        WHERE p.is_guest = 1 AND mp.match_id IN ($placeholders) ORDER BY p.last_name");
      $stg->execute($matchIds);
      $players = array_merge($players, $stg->fetchAll());
    }

    $rows = [];
    foreach ($players as $p) {
      $pid = (int)$p['id'];
      $cells = $cellsByPlayer[$pid] ?? [];
      $rows[] = [
        'player_id' => $pid,
        'name' => $p['first_name'] . ' ' . $p['last_name'],
        'is_guest' => (bool)$p['is_guest'],
        'cells' => $cells,
        'total' => array_sum($cells),
      ];
    }

    out(['month' => $month, 'matches' => $matches, 'players' => $rows]);
  }

  case 'match_cell': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $match_id = i($b, 'match_id'); $player_id = i($b, 'player_id'); $montant = f($b, 'montant');
    if (!$match_id || !$player_id) fail('match_id et player_id requis');
    $st = db()->prepare('INSERT INTO match_players (match_id, player_id, montant, source) VALUES (?,?,?,?)
      ON CONFLICT(match_id, player_id) DO UPDATE SET montant=excluded.montant');
    $st->execute([$match_id, $player_id, $montant, 'manuel']);
    out(['ok' => true]);
  }

  case 'match_add_guest': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $name = s($b, 'name'); if (!$name) fail('Nom requis');
    $matchIds = $b['match_ids'] ?? [];
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO players (first_name, last_name, salaire_mensuel, is_guest) VALUES (?,?,0,1)');
    $st->execute(['', $name]);
    $player_id = (int)$pdo->lastInsertId();
    $st2 = $pdo->prepare('INSERT INTO match_players (match_id, player_id, montant, source) VALUES (?,?,0,?)
      ON CONFLICT(match_id, player_id) DO NOTHING');
    foreach ($matchIds as $mid) $st2->execute([(int)$mid, $player_id, 'manuel']);
    out(['player_id' => $player_id]);
  }

  /* ============ IMPORT FEUILLE DE PRIMES ============ */

  case 'import_upload': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);

    $contentBlocks = [];
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
      $tmp = $_FILES['file']['tmp_name'];
      $name = $_FILES['file']['name'];
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $bytes = file_get_contents($tmp);
      if ($ext === 'pdf') {
        $contentBlocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]];
      } else {
        $contentBlocks[] = ['type' => 'text', 'text' => "Document (" . $name . ") :\n\n" . $bytes];
      }
      $filename = $name;
    } else {
      $text = s($b, 'text');
      if (!$text) fail('Fournissez un fichier ou un texte collé.');
      $contentBlocks[] = ['type' => 'text', 'text' => $text];
      $filename = 'texte-collé';
    }
    $contentBlocks[] = ['type' => 'text', 'text' => EXTRACTION_PROMPT];

    $extraction = call_anthropic($contentBlocks);
    $players = db()->query('SELECT * FROM players WHERE active = 1')->fetchAll();

    foreach (($extraction['matches'] ?? []) as &$m) {
      foreach (($m['joueurs'] ?? []) as &$j) {
        $match = match_player_name($j['nom'] ?? '', $players);
        $j['player_id'] = $match['player'] ? (int)$match['player']['id'] : null;
        $j['match_score'] = $match['score'];
      }
      unset($j);
    }
    unset($m);

    $month = s($b, 'month') ?: date('Y-m');
    $st = db()->prepare('INSERT INTO imports (filename, month, status, raw_json) VALUES (?,?,?,?)');
    $st->execute([$filename, $month, 'pending_review', json_encode($extraction, JSON_UNESCAPED_UNICODE)]);
    out(['import_id' => (int)db()->lastInsertId(), 'extraction' => $extraction]);
  }

  case 'imports': {
    if ($method === 'GET') out(db()->query('SELECT id, filename, month, status, created_at FROM imports ORDER BY created_at DESC')->fetchAll());
    fail('Méthode non supportée', 405);
  }

  case 'import_get': {
    $id = i($_GET, 'id'); if (!$id) fail('id requis');
    $st = db()->prepare('SELECT * FROM imports WHERE id=?'); $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail('Import introuvable', 404);
    $row['extraction'] = json_decode($row['raw_json'], true);
    unset($row['raw_json']);
    out($row);
  }

  case 'import_validate': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $import_id = i($b, 'import_id'); if (!$import_id) fail('import_id requis');
    $matches = $b['matches'] ?? [];
    if (!$matches) fail('Aucun match à valider');

    $pdo = db();
    $pdo->beginTransaction();
    try {
      $countMatches = 0; $countPlayers = 0;
      foreach ($matches as $m) {
        $date = s($m, 'date'); if (!$date) continue;
        $st = $pdo->prepare('INSERT INTO matches (date, adversaire) VALUES (?,?)');
        $st->execute([$date, s($m, 'adversaire')]);
        $match_id = (int)$pdo->lastInsertId();
        $countMatches++;

        foreach (($m['joueurs'] ?? []) as $j) {
          $player_id = i($j, 'player_id');
          if (!$player_id) continue;
          $montant = f($j, 'montant');
          $st2 = $pdo->prepare('INSERT INTO match_players (match_id, player_id, montant, source) VALUES (?,?,?,?)
            ON CONFLICT(match_id, player_id) DO UPDATE SET montant=excluded.montant, source=excluded.source');
          $st2->execute([$match_id, $player_id, $montant, 'import']);
          $countPlayers++;
        }
      }
      $pdo->prepare("UPDATE imports SET status='validated' WHERE id=?")->execute([$import_id]);
      $pdo->commit();
      out(['ok' => true, 'matches_crees' => $countMatches, 'lignes_joueurs' => $countPlayers]);
    } catch (Throwable $e) {
      $pdo->rollBack();
      fail('Échec de la validation : ' . $e->getMessage(), 500);
    }
  }

  case 'import_discard': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $id = i($b, 'id'); if (!$id) fail('id requis');
    db()->prepare("UPDATE imports SET status='discarded' WHERE id=?")->execute([$id]);
    out(['ok' => true]);
  }

  /* ============ IMPORT EXCEL/CSV — ÉQUIPES ET EMPLOYÉS ============ */

  case 'import_employees_file': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Fichier requis.');
    $rows = parse_uploaded_table($_FILES['file']);
    $pdo = db();
    $created = 0; $updated = 0; $skipped = 0; $assignments = 0; $players_created = 0; $players_updated = 0;
    $pdo->beginTransaction();
    try {
      foreach ($rows as $r) {
        $last = trim($r['nom'] ?? '');
        $first = trim($r['prenom'] ?? '');
        if (!$last && !empty($r['nom_complet'])) { [$first, $last] = split_full_name($r['nom_complet']); }
        if (!$last) { $skipped++; continue; }

        $posteRaw = trim($r['poste'] ?? '');
        $team_name = trim($r['equipe'] ?? '') ?: trim($r['departement'] ?? '');

        // Les postes contenant "joueur" (ex: export Odoo "Joueur 1ère") vont dans la table Joueurs, pas Employés.
        if ($posteRaw !== '' && str_contains(normalize_name($posteRaw), 'joueur')) {
          $st = $pdo->prepare('SELECT id FROM players WHERE last_name = ? COLLATE NOCASE AND first_name = ? COLLATE NOCASE');
          $st->execute([$last, $first]);
          $player_id = $st->fetchColumn();
          if ($player_id) {
            $pdo->prepare('UPDATE players SET phone = COALESCE(NULLIF(?, \'\'), phone), email = COALESCE(NULLIF(?, \'\'), email) WHERE id = ?')
              ->execute([$r['telephone'] ?? '', $r['email'] ?? '', $player_id]);
            $players_updated++;
          } else {
            $pdo->prepare('INSERT INTO players (first_name, last_name, email, phone) VALUES (?,?,?,?)')
              ->execute([$first, $last, $r['email'] ?? '', $r['telephone'] ?? '']);
            $players_created++;
          }
          continue;
        }

        $st = $pdo->prepare('SELECT id FROM employees WHERE last_name = ? COLLATE NOCASE AND first_name = ? COLLATE NOCASE');
        $st->execute([$last, $first]);
        $employee_id = $st->fetchColumn();
        if ($employee_id) {
          $pdo->prepare('UPDATE employees SET email = COALESCE(NULLIF(?, \'\'), email), phone = COALESCE(NULLIF(?, \'\'), phone), iban = COALESCE(NULLIF(?, \'\'), iban) WHERE id = ?')
            ->execute([$r['email'] ?? '', $r['telephone'] ?? '', $r['iban'] ?? '', $employee_id]);
          $updated++;
        } else {
          $pdo->prepare('INSERT INTO employees (first_name, last_name, email, phone, iban) VALUES (?,?,?,?,?)')
            ->execute([$first, $last, $r['email'] ?? '', $r['telephone'] ?? '', $r['iban'] ?? '']);
          $employee_id = (int)$pdo->lastInsertId();
          $created++;
        }
        if ($posteRaw !== '') {
          $poste_id = find_or_create_poste($pdo, $posteRaw);
          $team_id = $team_name !== '' ? find_or_create_team($pdo, $team_name) : null;
          $exists = $pdo->prepare('SELECT id FROM employee_assignments WHERE employee_id=? AND poste_id=? AND (team_id=? OR (team_id IS NULL AND ? IS NULL))');
          $exists->execute([$employee_id, $poste_id, $team_id, $team_id]);
          if (!$exists->fetchColumn()) {
            $pdo->prepare('INSERT INTO employee_assignments (employee_id, team_id, poste_id) VALUES (?,?,?)')->execute([$employee_id, $team_id, $poste_id]);
            $assignments++;
          }
        }
      }
      $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); fail('Échec import : ' . $e->getMessage(), 500); }
    out(['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'assignments' => $assignments,
      'players_created' => $players_created, 'players_updated' => $players_updated, 'total_lignes' => count($rows)]);
  }

  /* ============ DASHBOARD ============ */

  case 'dashboard': {
    $month = s($_GET, 'month') ?: date('Y-m');
    $pdo = db();
    $season_id = i($_GET, 'season_id') ?: ensure_current_season($pdo);
    $employeeData = compute_employee_indemnites($pdo, $month, $season_id);
    $playerData = compute_player_pay($pdo, $month);

    $totalEmployees = array_sum(array_column($employeeData['par_employee'], 'total'));
    $totalSalaires = array_sum(array_column($playerData['joueurs'], 'salaire'));
    $totalPrimes = array_sum(array_column($playerData['joueurs'], 'primes')) + $playerData['primes_ponctuels'];

    $teamsCount = (int) $pdo->query('SELECT COUNT(*) FROM teams WHERE active=1')->fetchColumn();
    $employeesCount = (int) $pdo->query('SELECT COUNT(*) FROM employees WHERE active=1')->fetchColumn();
    $playersCount = (int) $pdo->query('SELECT COUNT(*) FROM players WHERE active=1 AND is_guest=0')->fetchColumn();
    $pendingImports = (int) $pdo->query("SELECT COUNT(*) FROM imports WHERE status='pending_review'")->fetchColumn();

    $byTeamSt = $pdo->prepare("
      SELECT t.id, t.name, tc.name AS category_name, COUNT(DISTINCT ea.employee_id) AS employes
      FROM teams t LEFT JOIN team_categories tc ON tc.id = t.category_id
      LEFT JOIN employee_assignments ea ON ea.team_id=t.id AND ea.active=1 AND ea.season_id=?
      WHERE t.active=1 GROUP BY t.id ORDER BY tc.sort_order, t.sort_order
    ");
    $byTeamSt->execute([$season_id]);
    $byTeam = $byTeamSt->fetchAll();

    $alerts = $employeeData['alerts'];
    if ($pendingImports > 0) $alerts[] = "$pendingImports import(s) de feuille de primes en attente de validation";

    out([
      'month' => $month,
      'season_id' => $season_id,
      'totaux' => [
        'indemnites_employes' => round($totalEmployees, 2),
        'salaires_joueurs' => round($totalSalaires, 2),
        'primes_joueurs' => round($totalPrimes, 2),
        'total_general' => round($totalEmployees + $totalSalaires + $totalPrimes, 2),
      ],
      'compteurs' => ['teams' => $teamsCount, 'employees' => $employeesCount, 'players' => $playersCount],
      'par_equipe' => $byTeam,
      'employes' => $employeeData['par_employee'],
      'joueurs' => $playerData['joueurs'],
      'alerts' => array_values($alerts),
    ]);
  }

  /* ============ EXPORT COMPTA (CSV) ============ */

  case 'export_compta': {
    $month = s($_GET, 'month') ?: date('Y-m');
    $pdo = db();
    $season_id = i($_GET, 'season_id') ?: ensure_current_season($pdo);
    $employeeData = compute_employee_indemnites($pdo, $month, $season_id);
    $playerData = compute_player_pay($pdo, $month);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rh-meyrinfc-' . $month . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Type', 'Personne', 'Détail', 'Montant CHF'], ';');

    foreach ($employeeData['par_employee'] as $e) {
      foreach ($e['lignes'] as $l) {
        fputcsv($out, ['Indemnité employé', $e['name'], $l['label'], number_format($l['montant'], 2, '.', '')], ';');
      }
    }
    foreach ($playerData['joueurs'] as $p) {
      if ($p['salaire'] > 0) fputcsv($out, ['Salaire joueur', $p['name'], 'Salaire mensuel', number_format($p['salaire'], 2, '.', '')], ';');
      if ($p['primes'] > 0) fputcsv($out, ['Prime de match', $p['name'], 'Primes du mois', number_format($p['primes'], 2, '.', '')], ';');
    }
    if ($playerData['primes_ponctuels'] > 0) {
      fputcsv($out, ['Prime de match', 'Joueurs ponctuels', 'Primes du mois', number_format($playerData['primes_ponctuels'], 2, '.', '')], ';');
    }
    fclose($out);
    exit;
  }

  default:
    fail('Action inconnue', 404);
}
