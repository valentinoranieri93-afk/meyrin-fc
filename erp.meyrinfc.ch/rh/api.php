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

  /* Lien vers le référentiel club de l'ERP (voir lib/mfc_club.php). Le nom, la
     catégorie et l'entraîneur d'une équipe s'y définissent désormais, par
     saison. Ce module garde ses propres colonnes (cotisation, effectif) et
     surtout ses affectations : employee_assignments.team_id est en ON DELETE
     CASCADE, donc les lignes locales ne sont JAMAIS supprimées par la synchro,
     seulement désactivées. */
  ensure_column($pdo, 'teams', 'ref_id', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'team_categories', 'ref_id', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'seasons', 'ref_id', "TEXT NOT NULL DEFAULT ''");

  /* Réparation puis verrou. La synchro lisait puis écrivait sans verrou, et
     plusieurs requêtes partent en parallèle au chargement d'une page : chacune
     pouvait insérer la même équipe. Les doublures sont fusionnées (leurs
     affectations sont d'abord rattachées à la ligne conservée, ON DELETE CASCADE
     oblige), puis un index unique empêche toute réapparition. L'ordre compte :
     créer l'index avant la fusion échouerait sur les doublons existants. */
  mfc_club_dedup($pdo, 'seasons', ['ref_id'], [
    ['table' => 'employee_assignments', 'col' => 'season_id'],
  ]);
  mfc_club_dedup($pdo, 'team_categories', ['ref_id'], [
    ['table' => 'teams',                'col' => 'category_id'],
    ['table' => 'employee_assignments', 'col' => 'category_id'],
  ]);
  mfc_club_dedup($pdo, 'teams', ['ref_id'], [
    ['table' => 'employee_assignments', 'col' => 'team_id'],
  ], ['cotisation_montant', 'effectif_max']);

  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_rh_seasons_ref ON seasons(ref_id)         WHERE ref_id != ''");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_rh_cats_ref    ON team_categories(ref_id) WHERE ref_id != ''");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_rh_teams_ref   ON teams(ref_id)           WHERE ref_id != ''");

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
  // Token d'accès à l'espace documents personnel (fiches de paie), généré à la demande depuis l'onglet Paiements.
  ensure_column($pdo, 'employees', 'access_token', "TEXT NOT NULL DEFAULT ''");

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

  // Repli pour les VRAIS orphelins d'avant le poste de catégorie (2026-07-15) : ni équipe ni catégorie.
  // Un poste explicitement rattaché à une catégorie (category_id renseigné) n'est PAS orphelin — il ne
  // doit jamais être touché ici. Bug corrigé le 2026-08-01 : cette réparation tournait à CHAQUE appel API
  // (pas de verrou schema_migrations) et sa condition ne distinguait pas un poste de catégorie volontaire
  // d'un vieil orphelin, donc tout poste de catégorie créé depuis le 15.07 se faisait reconvertir en poste
  // d'équipe "Staff / Administration" dès le rechargement suivant.
  $alreadyMigratedOrphans = (bool) $pdo->query("SELECT 1 FROM schema_migrations WHERE name = 'fallback_team_orphans_2026_08'")->fetchColumn();
  if (!$alreadyMigratedOrphans) {
    if ((int)$pdo->query('SELECT COUNT(*) FROM employee_assignments WHERE team_id IS NULL AND category_id IS NULL')->fetchColumn() > 0) {
      $fallbackTeamId = find_or_create_team($pdo, 'Staff / Administration');
      $pdo->prepare('UPDATE employee_assignments SET team_id = ? WHERE team_id IS NULL AND category_id IS NULL')->execute([$fallbackTeamId]);
    }
    $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (name) VALUES (?)')->execute(['fallback_team_orphans_2026_08']);
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
  ensure_column($pdo, 'players', 'poste', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'players', 'date_naissance', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'players', 'adresse', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'players', 'npa', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'players', 'ville', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'players', 'access_token', "TEXT NOT NULL DEFAULT ''");

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

  // Suivi mensuel des paiements (employés + joueurs) : coché "Payé" et note libre, un par personne et par mois.
  $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_type TEXT NOT NULL CHECK(person_type IN ('employee','player')),
    person_id INTEGER NOT NULL,
    period TEXT NOT NULL,
    paid INTEGER NOT NULL DEFAULT 0,
    paid_at TEXT,
    note TEXT NOT NULL DEFAULT '',
    UNIQUE(person_type, person_id, period)
  )");
  // Fiche de paie déposée manuellement (générée en externe) et suivi de son envoi par e-mail.
  ensure_column($pdo, 'payroll_payments', 'payslip_path', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'payroll_payments', 'payslip_filename', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'payroll_payments', 'sent_at', 'TEXT');

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

/** URL publique de l'espace documents personnel, construite à partir de l'hôte/chemin courants (rh/). */
function person_link_url(string $token): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'erp.meyrinfc.ch';
  $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/rh/api.php')), '/');
  return "$scheme://$host$dir/mes-documents.php?token=$token";
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
    SELECT e.id AS employee_id, e.first_name, e.last_name, e.paiement,
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

  // Un employé payé "semestriel" n'est dû qu'en juin et décembre, pour le montant du semestre (annuel / 2) ;
  // les autres mois, ses postes ne comptent pas dans le total dû.
  $monthNum = (int)substr($month, 5, 2);
  $byEmployee = [];
  $alerts = [];
  foreach ($rows as $r) {
    $isSemestriel = $r['paiement'] === 'semestriel';
    if ($isSemestriel && !in_array($monthNum, [6, 12], true)) continue;
    $eid = (int)$r['employee_id'];
    if (!isset($byEmployee[$eid])) {
      $byEmployee[$eid] = ['employee_id' => $eid, 'name' => $r['first_name'] . ' ' . $r['last_name'], 'total' => 0.0, 'lignes' => []];
    }
    $label = $r['poste_label'] . ($r['team_name'] ? ' — ' . $r['team_name'] : ($r['category_name'] ? ' — ' . $r['category_name'] . ' (catégorie)' : ''));
    $montant = round($isSemestriel ? ((float)$r['montant'] / 2) : monthly_equiv((float)$r['montant']), 2);
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

/** Liste unifiée employés + joueurs à payer pour un mois donné (montant > 0 uniquement), pour l'onglet Paiements. */
function compute_month_payments(PDO $pdo, string $month, int $season_id): array {
  $employeeData = compute_employee_indemnites($pdo, $month, $season_id);
  $playerData = compute_player_pay($pdo, $month);
  $out = [];
  foreach ($employeeData['par_employee'] as $e) {
    if ($e['total'] <= 0) continue;
    $detail = implode(', ', array_map(fn($l) => $l['label'], $e['lignes']));
    $out[] = ['person_type' => 'employee', 'person_id' => $e['employee_id'], 'name' => $e['name'], 'detail' => $detail, 'montant' => round($e['total'], 2)];
  }
  foreach ($playerData['joueurs'] as $p) {
    if ($p['total'] <= 0) continue;
    $parts = [];
    if ($p['salaire'] > 0) $parts[] = 'Salaire';
    if ($p['primes'] > 0) $parts[] = 'Primes de match';
    $out[] = ['person_type' => 'player', 'person_id' => $p['player_id'], 'name' => $p['name'], 'detail' => implode(' + ', $parts), 'montant' => round($p['total'], 2)];
  }

  // Ajoute l'e-mail de chaque personne (utilisé côté client pour le bouton "envoyer via ma messagerie").
  $empIds = array_values(array_unique(array_column(array_filter($out, fn($r) => $r['person_type'] === 'employee'), 'person_id')));
  $playerIds = array_values(array_unique(array_column(array_filter($out, fn($r) => $r['person_type'] === 'player'), 'person_id')));
  $emails = [];
  if ($empIds) {
    $ph = implode(',', array_fill(0, count($empIds), '?'));
    $st = $pdo->prepare("SELECT id, email FROM employees WHERE id IN ($ph)");
    $st->execute($empIds);
    foreach ($st->fetchAll() as $r) $emails['employee-' . $r['id']] = $r['email'];
  }
  if ($playerIds) {
    $ph = implode(',', array_fill(0, count($playerIds), '?'));
    $st = $pdo->prepare("SELECT id, email FROM players WHERE id IN ($ph)");
    $st->execute($playerIds);
    foreach ($st->fetchAll() as $r) $emails['player-' . $r['id']] = $r['email'];
  }
  foreach ($out as &$r) $r['email'] = $emails[$r['person_type'] . '-' . $r['person_id']] ?? '';
  unset($r);

  return $out;
}

/** Envoi d'un e-mail avec une pièce jointe via mail() natif, sans dépendance externe (multipart/mixed construit à la main). */
function send_mail_with_attachment(string $to, string $subject, string $bodyText, string $filePath, string $fileName, ?string &$error = null): bool {
  $boundary = md5(uniqid((string)microtime(), true));
  $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
  $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
  $fileContent = chunk_split(base64_encode((string)file_get_contents($filePath)));

  $headers = "From: " . RH_MAIL_FROM_NAME . " <" . RH_MAIL_FROM_ADDRESS . ">\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

  $message = "--$boundary\r\n";
  $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $message .= $bodyText . "\r\n\r\n";
  $message .= "--$boundary\r\n";
  $message .= "Content-Type: $mime; name=\"$fileName\"\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n";
  $message .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n\r\n";
  $message .= $fileContent . "\r\n";
  $message .= "--$boundary--";

  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  return smtp_send($to, $encodedSubject, $headers, $message, $error);
}

/**
 * Envoi via SMTP authentifié (mail() est désactivé sur cet hébergement, comme sur beaucoup de mutualisés
 * Infomaniak). Client minimal par socket, sans dépendance externe. Identifiants dans les constantes
 * RH_SMTP_* (config.php), elles-mêmes lues depuis les variables d'environnement de l'hébergement.
 * $error est renseigné avec la raison précise de l'échec, pour l'afficher directement dans l'app
 * (l'accès aux logs serveur n'étant pas toujours pratique).
 */
function smtp_send(string $to, string $encodedSubject, string $headers, string $message, ?string &$error = null): bool {
  if (!RH_SMTP_USER || !RH_SMTP_PASS) {
    $error = 'Identifiants SMTP manquants (RH_SMTP_USER / RH_SMTP_PASS non renseignés dans config.php).';
    return false;
  }
  $host = RH_SMTP_HOST; $port = RH_SMTP_PORT;
  $scheme = ($port === 465) ? 'ssl://' : 'tcp://';

  $errno = 0; $errstr = '';
  $socket = @stream_socket_client("$scheme$host:$port", $errno, $errstr, 15);
  if (!$socket) { $error = "Connexion au serveur SMTP $host:$port impossible : $errstr"; return false; }
  stream_set_timeout($socket, 15);

  $read = function () use ($socket): string {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
      $data .= $line;
      if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
  };
  $write = function (string $cmd) use ($socket): void { fwrite($socket, $cmd . "\r\n"); };
  $expect = function (string $data, string $code, string $step) use ($socket, &$error): bool {
    if (substr($data, 0, 3) === $code) return true;
    $error = "Étape $step : réponse SMTP inattendue : " . trim($data);
    fclose($socket);
    return false;
  };

  if (!$expect($read(), '220', 'connexion')) return false;

  $write('EHLO meyrinfc.ch');
  if (!$expect($read(), '250', 'EHLO')) return false;

  if ($port !== 465) {
    $write('STARTTLS');
    if (!$expect($read(), '220', 'STARTTLS')) return false;
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $error = 'Échec de la négociation TLS (STARTTLS).'; fclose($socket); return false; }
    $write('EHLO meyrinfc.ch');
    if (!$expect($read(), '250', 'EHLO post-TLS')) return false;
  }

  $write('AUTH LOGIN');
  if (!$expect($read(), '334', 'AUTH LOGIN')) return false;
  $write(base64_encode(RH_SMTP_USER));
  if (!$expect($read(), '334', 'envoi utilisateur')) return false;
  $write(base64_encode(RH_SMTP_PASS));
  if (!$expect($read(), '235', 'authentification (identifiants refusés ?)')) {
    // Diagnostic : révèle la source et la forme des identifiants réellement envoyés (jamais le mot de passe en clair),
    // pour détecter une variable d'environnement qui écraserait silencieusement la valeur de config.php.
    $userSource = getenv('RH_SMTP_USER') !== false && getenv('RH_SMTP_USER') !== '' ? 'variable d\'environnement de l\'hébergement' : 'config.php';
    $passSource = getenv('RH_SMTP_PASS') !== false && getenv('RH_SMTP_PASS') !== '' ? 'variable d\'environnement de l\'hébergement' : 'config.php';
    $error .= " [Diagnostic : identifiant envoyé = \"" . RH_SMTP_USER . "\" (source : $userSource) ; mot de passe envoyé = " . strlen(RH_SMTP_PASS) . " caractère(s) (source : $passSource, premier caractère : \"" . substr(RH_SMTP_PASS, 0, 1) . "\")]";
    return false;
  }

  $write('MAIL FROM: <' . RH_SMTP_USER . '>');
  if (!$expect($read(), '250', 'MAIL FROM')) return false;
  $write('RCPT TO: <' . $to . '>');
  if (!$expect($read(), '250', 'RCPT TO')) return false;
  $write('DATA');
  if (!$expect($read(), '354', 'DATA')) return false;

  $data = "To: $to\r\nSubject: $encodedSubject\r\n$headers\r\n$message\r\n";
  $data = preg_replace('/\r\n\./', "\r\n..", $data); // dot-stuffing RFC 5321
  $write($data . '.');
  $ok = $expect($read(), '250', 'envoi du message');
  $write('QUIT');
  fclose($socket);
  return $ok;
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

/* ------------------------------------------------- Référentiel club (ERP)
 *
 * Le nom, la catégorie et l'entraîneur des équipes viennent désormais de
 * l'ERP (Paramètres > Catégories & Équipes), et peuvent différer d'une saison
 * à l'autre. Ce module garde ses propres données par équipe (cotisation,
 * effectif, affectations, indemnités) et les rattache au `ref_id`, l'identité
 * stable d'une équipe.
 *
 * Tant que le référentiel est vide (avant la reprise via tools/mfc_seed_club.php),
 * tout continue de fonctionner exactement comme avant : les tables locales font
 * foi. Le basculement n'est donc jamais brutal.
 */

/* mfc_club.php est charge par le socle (mfc_boot.php -> mfc_auth.php), qui sait
   ou le trouver quel que soit l'emplacement de l'application. */

function club_active(): bool {
  return !mfc_club_is_empty();
}

/* --------------------------------------------------- Référentiel contacts (ERP)
 *
 * RH reste l'écran de saisie des employés (nom, contact, IBAN), mais chaque
 * création/modification alimente aussi le référentiel partagé (lib/mfc_contacts.php),
 * en continu plutôt que par le batch ponctuel de contacts/sync_modules.php.
 * mfc_contacts_ingest() ne fait que COMPLÉTER les champs vides côté annuaire :
 * une correction faite depuis le module Contacts n'est jamais écrasée par RH.
 */

/** Pousse un employé créé/modifié vers l'annuaire partagé. Jamais bloquant : une panne du
 * référentiel contacts (base absente, verrou) ne doit pas empêcher d'enregistrer un employé. */
function sync_employee_to_contacts(int $employeeId, string $first, string $last, string $email, string $phone, string $iban, bool $active): void {
  try {
    mfc_contacts_ingest(mfc_contacts_db(), [
      'first_name' => $first, 'last_name' => $last, 'email' => $email,
      'phone' => $phone, 'iban' => $iban, 'active' => $active ? 1 : 0,
      'qualities' => ['salarie'],
    ], 'rh', 'employee:' . $employeeId);
  } catch (Throwable $e) { /* l'annuaire n'est pas critique pour la RH elle-même */ }
}

/** Enrichit une liste d'employés avec ce que l'annuaire partagé sait en plus des champs propres à
 * RH : mobile, adresse, et les autres qualités de la personne (bénévole, contact sponsor...), pour
 * que la fiche employé révèle qu'une même personne porte plusieurs casquettes dans le club. */
function attach_contacts_directory(array &$employees): void {
  if (!$employees) return;
  try {
    $cdb = mfc_contacts_db();
    $localIds = array_map(fn($e) => 'employee:' . $e['id'], $employees);
    $ph = implode(',', array_fill(0, count($localIds), '?'));
    $st = $cdb->prepare("SELECT l.local_id, c.id AS contact_id, c.ref_id, c.mobile, c.street, c.zip, c.city
      FROM contact_links l JOIN contacts c ON c.id = l.contact_id
      WHERE l.module = 'rh' AND l.local_id IN ($ph)");
    $st->execute($localIds);
    $byLocal = [];
    foreach ($st->fetchAll() as $r) $byLocal[$r['local_id']] = $r;
    if (!$byLocal) return;

    $cids = array_values(array_unique(array_column($byLocal, 'contact_id')));
    $ph2 = implode(',', array_fill(0, count($cids), '?'));

    $qualities = [];
    $stq = $cdb->prepare("SELECT contact_id, quality FROM contact_qualities WHERE contact_id IN ($ph2) AND quality != 'salarie'");
    $stq->execute($cids);
    foreach ($stq->fetchAll() as $r) $qualities[(int)$r['contact_id']][] = $r['quality'];

    $stp = $cdb->prepare("SELECT contact_id FROM contact_duplicates WHERE status='pending' AND (contact_id IN ($ph2) OR other_id IN ($ph2))");
    $stp->execute([...$cids, ...$cids]);
    $pending = array_flip($stp->fetchAll(PDO::FETCH_COLUMN));

    foreach ($employees as &$e) {
      $c = $byLocal['employee:' . $e['id']] ?? null;
      if (!$c) { $e['annuaire'] = null; continue; }
      $cid = (int)$c['contact_id'];
      $addr = trim($c['street'] . (($c['zip'] || $c['city']) ? ', ' . trim($c['zip'] . ' ' . $c['city']) : ''));
      $e['annuaire'] = [
        'ref_id' => $c['ref_id'],
        'mobile' => $c['mobile'],
        'address' => $addr,
        'other_qualities' => $qualities[$cid] ?? [],
        'a_verifier' => isset($pending[$cid]),
      ];
    }
    unset($e);
  } catch (Throwable $e) { /* annuaire indisponible : la liste RH reste utilisable sans lui */ }
}

/**
 * Aligne les tables locales sur le référentiel.
 *
 * Idempotente et sans suppression : une équipe retirée du référentiel est
 * désactivée, jamais effacée, parce que employee_assignments.team_id est en
 * ON DELETE CASCADE — une suppression emporterait silencieusement les postes,
 * les montants et l'historique de paie rattachés à cette équipe.
 */
function club_sync(PDO $pdo): void {
  if (!club_active()) return;

  static $done = false;
  if ($done) return;
  $done = true;

  /* --- Saisons --- */
  $localSeasons = [];
  foreach ($pdo->query('SELECT * FROM seasons')->fetchAll() as $r) {
    if (($r['ref_id'] ?? '') !== '') $localSeasons[$r['ref_id']] = $r;
  }
  $insS = $pdo->prepare('INSERT OR IGNORE INTO seasons (label, start_date, end_date, ref_id) VALUES (?,?,?,?)');
  $updS = $pdo->prepare('UPDATE seasons SET label=?, start_date=?, end_date=? WHERE id=?');
  foreach (mfc_club_seasons() as $s) {
    if (isset($localSeasons[$s['id']])) {
      $l = $localSeasons[$s['id']];
      if ($l['label'] !== $s['label'] || $l['start_date'] !== $s['start_date'] || $l['end_date'] !== $s['end_date']) {
        $updS->execute([$s['label'], $s['start_date'], $s['end_date'], (int)$l['id']]);
      }
      continue;
    }
    /* Saison déjà présente localement sous le même libellé mais pas encore
       reliée (installation antérieure à la reprise) : on la relie plutôt que
       d'en créer une seconde, ce qui dédoublerait les affectations à l'écran. */
    $byLabel = $pdo->prepare('SELECT id FROM seasons WHERE label = ? AND ref_id = ""');
    $byLabel->execute([$s['label']]);
    $existing = $byLabel->fetchColumn();
    if ($existing) {
      $pdo->prepare('UPDATE OR IGNORE seasons SET ref_id=?, start_date=?, end_date=? WHERE id=?')
          ->execute([$s['id'], $s['start_date'], $s['end_date'], (int)$existing]);
    } else {
      $insS->execute([$s['label'], $s['start_date'], $s['end_date'], $s['id']]);
    }
  }

  /* --- Catégories --- */
  $localCats = [];
  foreach ($pdo->query('SELECT * FROM team_categories')->fetchAll() as $r) {
    if (($r['ref_id'] ?? '') !== '') $localCats[$r['ref_id']] = $r;
  }
  $insC = $pdo->prepare('INSERT OR IGNORE INTO team_categories (name, sort_order, active, ref_id) VALUES (?,?,?,?)');
  $updC = $pdo->prepare('UPDATE team_categories SET name=?, sort_order=?, active=? WHERE id=?');
  foreach (mfc_club_categories() as $c) {
    $active = $c['active'] ? 1 : 0;
    if (isset($localCats[$c['id']])) {
      $l = $localCats[$c['id']];
      if ($l['name'] !== $c['name'] || (int)$l['sort_order'] !== $c['sort_order'] || (int)$l['active'] !== $active) {
        $updC->execute([$c['name'], $c['sort_order'], $active, (int)$l['id']]);
      }
      continue;
    }
    $byName = $pdo->prepare('SELECT id FROM team_categories WHERE name = ? AND ref_id = ""');
    $byName->execute([$c['name']]);
    $existing = $byName->fetchColumn();
    if ($existing) $pdo->prepare('UPDATE OR IGNORE team_categories SET ref_id=?, sort_order=?, active=? WHERE id=?')
                       ->execute([$c['id'], $c['sort_order'], $active, (int)$existing]);
    else           $insC->execute([$c['name'], $c['sort_order'], $active, $c['id']]);
  }

  /* --- Équipes ---
     Une seule ligne locale par identité d'équipe, toutes saisons confondues.
     Le nom mis en cache ici est celui de la saison la plus récente où l'équipe
     figure : il ne sert que de repli, l'affichage réel passe par club_overlay_teams(). */
  $catByRef = [];
  foreach ($pdo->query('SELECT id, ref_id FROM team_categories WHERE ref_id != ""')->fetchAll() as $r) {
    $catByRef[$r['ref_id']] = (int)$r['id'];
  }

  $latest = [];  // ref_id équipe => membership de la saison la plus récente
  foreach (mfc_club_seasons() as $s) {          // déjà triées, plus récente en tête
    foreach (mfc_club_teams($s['id'], false) as $t) {
      if (!isset($latest[$t['ref_id']])) $latest[$t['ref_id']] = $t;
    }
  }

  $localTeams = [];
  foreach ($pdo->query('SELECT * FROM teams')->fetchAll() as $r) {
    if (($r['ref_id'] ?? '') !== '') $localTeams[$r['ref_id']] = $r;
  }
  $insT = $pdo->prepare('INSERT OR IGNORE INTO teams (name, category_id, sort_order, active, ref_id) VALUES (?,?,?,1,?)');
  $updT = $pdo->prepare('UPDATE teams SET name=?, category_id=?, sort_order=?, active=1 WHERE id=?');
  foreach ($latest as $ref => $t) {
    $catId = $catByRef[$t['category_ref_id']] ?? null;
    if (isset($localTeams[$ref])) {
      $l = $localTeams[$ref];
      if ($l['name'] !== $t['name'] || (int)$l['category_id'] !== (int)$catId
          || (int)$l['sort_order'] !== $t['sort_order'] || (int)$l['active'] !== 1) {
        $updT->execute([$t['name'], $catId, $t['sort_order'], (int)$l['id']]);
      }
      continue;
    }
    $byName = $pdo->prepare('SELECT id FROM teams WHERE name = ? AND ref_id = ""');
    $byName->execute([$t['name']]);
    $existing = $byName->fetchColumn();
    if ($existing) $pdo->prepare('UPDATE OR IGNORE teams SET ref_id=?, category_id=?, sort_order=? WHERE id=?')
                       ->execute([$ref, $catId, $t['sort_order'], (int)$existing]);
    else           $insT->execute([$t['name'], $catId, $t['sort_order'], $ref]);
  }

  /* Une équipe reliée au référentiel mais disparue de toutes les saisons est
     désactivée. Ses affectations, ses montants et son historique restent en
     base et réapparaissent si elle est remise dans une saison depuis l'ERP. */
  $orphans = array_diff(array_keys($localTeams), array_keys($latest));
  if ($orphans) {
    $ph = implode(',', array_fill(0, count($orphans), '?'));
    $pdo->prepare("UPDATE teams SET active = 0 WHERE ref_id IN ($ph)")->execute(array_values($orphans));
  }
}

/** Identifiant de saison du référentiel correspondant à une saison locale. */
function club_season_ref(PDO $pdo, int $localSeasonId): ?string {
  if (!club_active()) return null;
  if ($localSeasonId > 0) {
    $st = $pdo->prepare('SELECT ref_id FROM seasons WHERE id = ?');
    $st->execute([$localSeasonId]);
    $ref = (string)($st->fetchColumn() ?: '');
    if ($ref !== '' && mfc_club_season($ref)) return $ref;
  }
  return mfc_club_current_season_id();
}

/**
 * Applique le référentiel sur une liste d'équipes locales, pour une saison.
 *
 * Chaque ligne reçoit le nom, la catégorie et l'entraîneur de la saison
 * demandée, et les équipes absentes de cette saison sont écartées. Sans ce
 * filtre, une équipe créée pour 2027-2028 apparaîtrait dans les écrans de
 * 2026-2027 avec des montants qui ne la concernent pas.
 */
function club_overlay_teams(PDO $pdo, array $rows, ?string $seasonRef): array {
  if (!club_active() || $seasonRef === null) return $rows;

  /* L'ordre d'affichage est celui du référentiel (catégorie puis rang), pas un
     tri recalculé ici : deux modules qui trient chacun à leur façon finiraient
     par présenter la même liste dans deux ordres différents. */
  $rank = 0;
  $ref  = [];
  foreach (mfc_club_teams($seasonRef, false) as $t) {
    $t['_rank'] = $rank++;
    $ref[$t['ref_id']] = $t;
  }

  $out    = [];
  $unref  = [];
  foreach ($rows as $r) {
    $rid = (string)($r['ref_id'] ?? '');
    if ($rid === '') { $unref[] = $r; continue; }  // équipe locale non reliée : conservée telle quelle
    if (!isset($ref[$rid])) continue;              // absente de cette saison
    $t = $ref[$rid];
    $r['name']          = $t['name'];
    $r['category_name'] = $t['category_name'];
    $r['coach_name']    = $t['coach_name'];
    $r['sort_order']    = $t['sort_order'];
    $r['active']        = $t['active'] ? 1 : 0;
    $r['from_club']     = 1;
    $r['_rank']         = $t['_rank'];
    $out[] = $r;
  }

  usort($out, fn($a, $b) => $a['_rank'] <=> $b['_rank']);
  foreach ($out as &$o) unset($o['_rank']);
  unset($o);

  return array_merge($out, $unref);
}

/** Refus commun : ces champs ne se modifient plus ici. */
function club_readonly(string $what = 'Les équipes et catégories'): never {
  fail("$what : cela se gère maintenant dans l'ERP (Paramètres > Catégories & Équipes), "
     . 'pour que RH et Arbitrage affichent la même liste. Les cotisations, effectifs, '
     . 'postes et indemnités restent modifiables ici.', 409);
}

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

  /* --- Paiements et fiches de paie (ajoutes le 30.07.2026) ---------------
     Ces six actions tombaient sur le refus par defaut, donc sur payroll.edit :
     invisible pour un administrateur, mais un comptable en lecture seule ne
     pouvait pas consulter la liste des paiements ni ouvrir une fiche. */
  'payments'               => ['GET' => 'payroll.view', 'write' => 'payroll.edit'],
  'payments_file'          => 'payroll.view',
  'payments_upload'        => 'payroll.edit',
  'payments_send_email'    => 'payroll.edit',
  /* Le lien personnel donne acces aux fiches de paie de la personne sans
     compte : le consulter revient a pouvoir le transmettre, d'ou payroll.view.
     Le regenerer invalide l'ancien lien, c'est une modification. */
  'person_link'            => 'payroll.view',
  'person_link_regenerate' => 'payroll.edit',
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
    if ($method === 'GET') {
      club_sync(db());
      $rows = db()->query('SELECT * FROM team_categories ORDER BY sort_order, name')->fetchAll();
      foreach ($rows as &$r) $r['from_club'] = ($r['ref_id'] ?? '') !== '' ? 1 : 0;
      out($rows);
    }
    if (club_active()) club_readonly('Les catégories');
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
    if (club_active()) club_readonly("L'ordre des catégories");
    $ids = $b['ids'] ?? []; if (!$ids) fail('ids requis');
    $st = db()->prepare('UPDATE team_categories SET sort_order=? WHERE id=?');
    foreach ($ids as $idx => $id) $st->execute([$idx, (int)$id]);
    out(['ok' => true]);
  }

  /* ============ TEAMS ============ */

  case 'teams': {
    if ($method === 'GET') {
      $pdo = db();
      club_sync($pdo);
      $rows = $pdo->query("SELECT t.*, tc.name AS category_name, '' AS coach_name
                           FROM teams t LEFT JOIN team_categories tc ON tc.id = t.category_id
                           ORDER BY tc.sort_order, t.sort_order")->fetchAll();
      /* Le nom, la catégorie et l'entraîneur affichés sont ceux de la saison
         demandée : la même équipe peut changer de nom d'une saison à l'autre. */
      out(club_overlay_teams($pdo, $rows, club_season_ref($pdo, i($_GET, 'season_id'))));
    }
    /* Création, renommage, changement de catégorie et suppression passent par
       l'ERP. Restent modifiables ici : cotisation et effectif, propres à RH. */
    if (club_active()) {
      if ($method !== 'PUT') club_readonly('Les équipes');
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $onlyRhFields = !array_key_exists('name', $b) && !array_key_exists('category_id', $b) && !array_key_exists('active', $b);
      if (!$onlyRhFields) club_readonly('Le nom, la catégorie et l\'activation d\'une équipe');
      $st = db()->prepare('SELECT cotisation_montant, effectif_max FROM teams WHERE id=?');
      $st->execute([$id]);
      $ex = $st->fetch();
      if (!$ex) fail('Équipe introuvable', 404);
      db()->prepare('UPDATE teams SET cotisation_montant=?, effectif_max=? WHERE id=?')->execute([
        array_key_exists('cotisation_montant', $b) ? f($b, 'cotisation_montant') : (float)$ex['cotisation_montant'],
        array_key_exists('effectif_max', $b)       ? i($b, 'effectif_max')       : (int)$ex['effectif_max'],
        $id,
      ]);
      out(['ok' => true]);
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
    if (club_active()) club_readonly("L'ordre des équipes");
    $ids = $b['ids'] ?? []; if (!$ids) fail('ids requis');
    $st = db()->prepare('UPDATE teams SET sort_order=? WHERE id=?');
    foreach ($ids as $idx => $id) $st->execute([$idx, (int)$id]);
    out(['ok' => true]);
  }

  /* ============ SAISONS ============ */

  case 'seasons': {
    if ($method === 'GET') {
      club_sync(db());
      $rows = db()->query('SELECT * FROM seasons ORDER BY start_date DESC')->fetchAll();
      /* Avec le référentiel, la saison courante est celle dont la période
         couvre aujourd'hui, telle que définie dans l'ERP — plutôt qu'un
         libellé recalculé ici, qui divergerait dès qu'une saison ne suit pas
         le découpage 1er juillet - 30 juin. */
      $currentRef   = club_active() ? mfc_club_current_season_id() : null;
      $currentLabel = season_label_for_date(date('Y-m-d'));
      foreach ($rows as &$r) {
        $r['is_current'] = $currentRef !== null
          ? (($r['ref_id'] ?? '') === $currentRef)
          : ($r['label'] === $currentLabel);
      }
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
      unset($e);
      attach_contacts_directory($employees);
      out($employees);
    }
    if ($method === 'POST') {
      $ln = s($b, 'last_name'); if (!$ln) fail('Nom requis');
      $paiement = s($b, 'paiement', 'mensuel');
      if (!in_array($paiement, ['mensuel', 'semestriel'], true)) fail('paiement invalide');
      $first = s($b, 'first_name'); $email = s($b, 'email'); $phone = s($b, 'phone'); $iban = s($b, 'iban');
      $st = db()->prepare('INSERT INTO employees (first_name,last_name,email,phone,iban,paiement) VALUES (?,?,?,?,?,?)');
      $st->execute([$first, $ln, $email, $phone, $iban, $paiement]);
      $newId = (int)db()->lastInsertId();
      sync_employee_to_contacts($newId, $first, $ln, $email, $phone, $iban, true);
      out(['id' => $newId]);
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
      $first = s($b,'first_name'); $last = s($b,'last_name'); $email = s($b,'email'); $phone = s($b,'phone'); $iban = s($b,'iban');
      $active = bo($b,'active',true);
      $st = db()->prepare('UPDATE employees SET first_name=?,last_name=?,email=?,phone=?,iban=?,active=?,paiement=? WHERE id=?');
      $st->execute([$first, $last, $email, $phone, $iban, $active?1:0, $paiement, $id]);
      sync_employee_to_contacts($id, $first, $last, $email, $phone, $iban, $active);
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
          WHERE ea.category_id = ? AND ea.season_id = ? ORDER BY ea.created_at DESC");
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
      out(db()->query('SELECT * FROM players WHERE is_guest = 0 ORDER BY active DESC, first_name')->fetchAll());
    }
    if ($method === 'POST') {
      $ln = s($b, 'last_name'); if (!$ln) fail('Nom requis');
      $poste = s($b, 'poste');
      if ($poste !== '' && !in_array($poste, ['gardien', 'defenseur', 'milieu', 'attaquant'], true)) fail('poste invalide');
      $st = db()->prepare('INSERT INTO players (first_name,last_name,email,phone,iban,salaire_mensuel,is_guest,poste,date_naissance,adresse,npa,ville) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([s($b,'first_name'), $ln, s($b,'email'), s($b,'phone'), s($b,'iban'), f($b,'salaire_mensuel'), bo($b,'is_guest',false)?1:0, $poste, s($b,'date_naissance'), s($b,'adresse'), s($b,'npa'), s($b,'ville')]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $poste = s($b, 'poste');
      if ($poste !== '' && !in_array($poste, ['gardien', 'defenseur', 'milieu', 'attaquant'], true)) fail('poste invalide');
      $st = db()->prepare('UPDATE players SET first_name=?,last_name=?,email=?,phone=?,iban=?,salaire_mensuel=?,active=?,poste=?,date_naissance=?,adresse=?,npa=?,ville=? WHERE id=?');
      $st->execute([s($b,'first_name'), s($b,'last_name'), s($b,'email'), s($b,'phone'), s($b,'iban'), f($b,'salaire_mensuel'), bo($b,'active',true)?1:0, $poste, s($b,'date_naissance'), s($b,'adresse'), s($b,'npa'), s($b,'ville'), $id]);
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

  /* ============ SUIVI DES PAIEMENTS (employés + joueurs, coché "Payé" par mois) ============ */

  case 'payments': {
    $pdo = db();
    if ($method === 'GET') {
      $month = s($_GET, 'month') ?: date('Y-m');
      $season_id = i($_GET, 'season_id') ?: ensure_current_season($pdo);
      $year = substr($month, 0, 4);

      // Calcule les 12 mois de l'année une seule fois (réutilisé pour le total annuel dû et payé).
      $allMonthRows = [];
      $anneeDu = 0.0;
      for ($m = 1; $m <= 12; $m++) {
        $mm = $year . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        $allMonthRows[$mm] = compute_month_payments($pdo, $mm, $season_id);
        foreach ($allMonthRows[$mm] as $r) $anneeDu += $r['montant'];
      }

      $st = $pdo->prepare('SELECT person_type, person_id, paid, note, payslip_filename, sent_at FROM payroll_payments WHERE period = ?');
      $st->execute([$month]);
      $status = [];
      foreach ($st->fetchAll() as $r) $status[$r['person_type'] . '-' . $r['person_id']] = $r;

      $rows = $allMonthRows[$month] ?? [];
      $totalDu = 0.0; $totalPaye = 0.0;
      foreach ($rows as &$r) {
        $key = $r['person_type'] . '-' . $r['person_id'];
        $st2 = $status[$key] ?? null;
        $r['paid'] = $st2 ? (bool)$st2['paid'] : false;
        $r['note'] = $st2 ? $st2['note'] : '';
        $r['payslip_filename'] = $st2 ? $st2['payslip_filename'] : '';
        $r['sent_at'] = $st2 ? $st2['sent_at'] : null;
        $totalDu += $r['montant'];
        if ($r['paid']) $totalPaye += $r['montant'];
      }
      unset($r);

      $anneePaye = 0.0;
      $stYear = $pdo->prepare("SELECT person_type, person_id, period FROM payroll_payments WHERE period LIKE ? AND paid = 1");
      $stYear->execute([$year . '-%']);
      foreach ($stYear->fetchAll() as $pm) {
        foreach ($allMonthRows[$pm['period']] ?? [] as $r) {
          if ($r['person_type'] === $pm['person_type'] && (int)$r['person_id'] === (int)$pm['person_id']) { $anneePaye += $r['montant']; break; }
        }
      }

      out([
        'month' => $month, 'rows' => $rows,
        'total_du' => round($totalDu, 2), 'total_paye' => round($totalPaye, 2),
        'annee_du' => round($anneeDu, 2), 'annee_paye' => round($anneePaye, 2),
      ]);
    }
    if ($method === 'POST') {
      $person_type = s($b, 'person_type'); $person_id = i($b, 'person_id'); $period = s($b, 'period');
      if (!in_array($person_type, ['employee', 'player'], true)) fail('person_type invalide');
      if (!$person_id || !$period) fail('person_id et period requis');
      $paid = bo($b, 'paid', false);
      $note = s($b, 'note');
      $exists = $pdo->prepare('SELECT id FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
      $exists->execute([$person_type, $person_id, $period]);
      $id = $exists->fetchColumn();
      if ($id) {
        $pdo->prepare('UPDATE payroll_payments SET paid=?, paid_at=?, note=? WHERE id=?')
          ->execute([$paid ? 1 : 0, $paid ? date('Y-m-d H:i:s') : null, $note, $id]);
      } else {
        $pdo->prepare('INSERT INTO payroll_payments (person_type, person_id, period, paid, paid_at, note) VALUES (?,?,?,?,?,?)')
          ->execute([$person_type, $person_id, $period, $paid ? 1 : 0, $paid ? date('Y-m-d H:i:s') : null, $note]);
      }
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Dépôt manuel d'une fiche de paie (générée en externe) pour un mois donné. */
  case 'payments_upload': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $pdo = db();
    $person_type = s($b, 'person_type'); $person_id = i($b, 'person_id'); $period = s($b, 'period');
    if (!in_array($person_type, ['employee', 'player'], true)) fail('person_type invalide');
    if (!$person_id || !$period) fail('person_id et period requis');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Fichier manquant ou invalide.');
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) fail('Format non supporté (PDF, JPG ou PNG uniquement).');

    $dir = DB_DIR . '/payslips';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");

    $filename = "{$person_type}_{$person_id}_{$period}.{$ext}";
    $path = $dir . '/' . $filename;

    $existing = $pdo->prepare('SELECT id, payslip_path FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
    $existing->execute([$person_type, $person_id, $period]);
    $row = $existing->fetch();
    // Retire l'ancien fichier s'il portait une autre extension, pour ne pas laisser d'orphelin.
    if ($row && $row['payslip_path'] && $row['payslip_path'] !== $path && is_file($row['payslip_path'])) unlink($row['payslip_path']);

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) fail("Échec de l'enregistrement du fichier.");

    if ($row) {
      $pdo->prepare('UPDATE payroll_payments SET payslip_path=?, payslip_filename=?, sent_at=NULL WHERE id=?')->execute([$path, $origName, $row['id']]);
    } else {
      $pdo->prepare('INSERT INTO payroll_payments (person_type, person_id, period, payslip_path, payslip_filename) VALUES (?,?,?,?,?)')
        ->execute([$person_type, $person_id, $period, $path, $origName]);
    }
    out(['ok' => true, 'filename' => $origName]);
  }

  /* Téléchargement/consultation d'une fiche de paie déposée. */
  case 'payments_file': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $person_type = s($_GET, 'person_type'); $person_id = i($_GET, 'person_id'); $period = s($_GET, 'period');
    $st = db()->prepare('SELECT payslip_path, payslip_filename FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
    $st->execute([$person_type, $person_id, $period]);
    $row = $st->fetch();
    if (!$row || !$row['payslip_path'] || !is_file($row['payslip_path'])) fail('Fichier introuvable.', 404);
    $ext = strtolower(pathinfo($row['payslip_path'], PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
    if (ob_get_level() > 0) ob_clean();
    $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($row['payslip_filename']) . '"');
    header('Content-Length: ' . filesize($row['payslip_path']));
    readfile($row['payslip_path']);
    exit;
  }

  /* Envoi par e-mail de la fiche de paie déposée pour la personne et la période données. */
  case 'payments_send_email': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $pdo = db();
    $person_type = s($b, 'person_type'); $person_id = i($b, 'person_id'); $period = s($b, 'period');
    if (!in_array($person_type, ['employee', 'player'], true)) fail('person_type invalide');
    if (!$person_id || !$period) fail('person_id et period requis');

    $st = $pdo->prepare('SELECT payslip_path, payslip_filename FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
    $st->execute([$person_type, $person_id, $period]);
    $row = $st->fetch();
    if (!$row || !$row['payslip_path'] || !is_file($row['payslip_path'])) fail("Aucune fiche de paie n'a été déposée pour cette période.");

    $table = $person_type === 'employee' ? 'employees' : 'players';
    $p = $pdo->prepare("SELECT first_name, last_name, email FROM $table WHERE id = ?");
    $p->execute([$person_id]);
    $person = $p->fetch();
    if (!$person || !$person['email']) fail("Cette personne n'a pas d'adresse e-mail enregistrée.");

    $monthLabels = ['01'=>'janvier','02'=>'février','03'=>'mars','04'=>'avril','05'=>'mai','06'=>'juin','07'=>'juillet','08'=>'août','09'=>'septembre','10'=>'octobre','11'=>'novembre','12'=>'décembre'];
    [$y, $m] = explode('-', $period);
    $periodLabel = ($monthLabels[$m] ?? $m) . ' ' . $y;

    $subject = 'Meyrin FC — Fiche de paie ' . $periodLabel;
    $body = "Bonjour {$person['first_name']},\n\nVeuillez trouver ci-joint votre fiche de paie pour $periodLabel.\n\nCordialement,\nMeyrin FC";

    $mailError = null;
    if (!send_mail_with_attachment($person['email'], $subject, $body, $row['payslip_path'], $row['payslip_filename'], $mailError)) {
      fail("Échec de l'envoi SMTP : " . ($mailError ?: 'raison inconnue') . '.');
    }

    $pdo->prepare('UPDATE payroll_payments SET sent_at=? WHERE person_type=? AND person_id=? AND period=?')
      ->execute([date('Y-m-d H:i:s'), $person_type, $person_id, $period]);
    out(['ok' => true, 'sent_to' => $person['email']]);
  }

  /* Lien permanent vers l'espace documents personnel (fiches de paie) d'un employé ou joueur.
     Génère le token à la première demande, le réutilise ensuite (lien stable, à donner une seule fois). */
  case 'person_link': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $pdo = db();
    $person_type = s($_GET, 'person_type'); $person_id = i($_GET, 'person_id');
    if (!in_array($person_type, ['employee', 'player'], true)) fail('person_type invalide');
    if (!$person_id) fail('person_id requis');
    $table = $person_type === 'employee' ? 'employees' : 'players';

    $st = $pdo->prepare("SELECT access_token FROM $table WHERE id = ?");
    $st->execute([$person_id]);
    $token = $st->fetchColumn();
    if ($token === false) fail('Personne introuvable.', 404);
    if (!$token) {
      $token = bin2hex(random_bytes(32));
      $pdo->prepare("UPDATE $table SET access_token = ? WHERE id = ?")->execute([$token, $person_id]);
    }
    out(['url' => person_link_url($token)]);
  }

  /* Révoque l'ancien lien et en génère un nouveau (ex: lien transmis par erreur). */
  case 'person_link_regenerate': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $pdo = db();
    $person_type = s($b, 'person_type'); $person_id = i($b, 'person_id');
    if (!in_array($person_type, ['employee', 'player'], true)) fail('person_type invalide');
    if (!$person_id) fail('person_id requis');
    $table = $person_type === 'employee' ? 'employees' : 'players';

    $token = bin2hex(random_bytes(32));
    $st = $pdo->prepare("UPDATE $table SET access_token = ? WHERE id = ?");
    $st->execute([$token, $person_id]);
    if ($st->rowCount() === 0) fail('Personne introuvable.', 404);
    out(['url' => person_link_url($token)]);
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
