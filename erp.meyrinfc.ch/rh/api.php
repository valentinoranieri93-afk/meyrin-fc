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
  /* Compte du plan comptable (module Comptabilité) auquel rattacher ce rôle, pour regrouper
     plusieurs rôles sous un même intitulé sur le décompte de paie (ex: "Entraîneur assistant"
     et "Entraîneur gardiens" -> "Indemnité entraîneur"). Instantané (numéro + nom), pas une
     clé étrangère : les deux modules ont chacun leur propre base SQLite, comme pour ref_id
     ailleurs dans ce module. Paramétré à la main par un responsable (écran Paramètres > Rôles),
     jamais déduit automatiquement. */
  ensure_column($pdo, 'postes', 'account_number', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'postes', 'account_label',  "TEXT NOT NULL DEFAULT ''");

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

  /* ---- Fiche employé complète (2026-08-14) ----------------------------------
     Tout ce qu'un dossier RH suisse doit porter pour un engagement : identité au
     sens AVS, statut de séjour, imposition à la source, contrat, et affiliations
     aux assurances sociales.

     Ces colonnes vivent dans RH et pas dans l'annuaire partagé (lib/mfc_contacts.php)
     parce qu'elles ne décrivent pas la PERSONNE mais son EMPLOI : un bénévole ou un
     contact sponsor présent dans le même annuaire n'a ni numéro AVS d'employeur, ni
     barème d'impôt source, ni groupe LAA. Ce qui relève bien de l'identité (adresse,
     date de naissance, mobile, langue) est en revanche reversé à l'annuaire par
     sync_employee_to_contacts(), pour rester saisi une seule fois.

     Volontairement pas de barème officiel suisse en dur (taux AVS, AC, LPP) :
     décision déjà prise le 2026-07-15 pour les règles de paie, elle vaut ici aussi.
     Les taux usuels sont seulement suggérés en placeholder dans l'interface. */

  // Identité (au sens des assurances sociales)
  ensure_column($pdo, 'employees', 'birth_name',        "TEXT NOT NULL DEFAULT ''"); // nom de naissance, exigé par l'AVS quand il diffère
  ensure_column($pdo, 'employees', 'avs_number',        "TEXT NOT NULL DEFAULT ''"); // 756.XXXX.XXXX.XX
  ensure_column($pdo, 'employees', 'birth_date',        "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'gender',            "TEXT NOT NULL DEFAULT ''"); // f | m | autre
  ensure_column($pdo, 'employees', 'nationality',       "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'lang',              "TEXT NOT NULL DEFAULT 'fr'");
  ensure_column($pdo, 'employees', 'civil_status',      "TEXT NOT NULL DEFAULT ''"); // celibataire | marie | partenariat | separe | divorce | veuf
  ensure_column($pdo, 'employees', 'civil_status_since',"TEXT NOT NULL DEFAULT ''"); // date d'effet : change le barème d'impôt source
  ensure_column($pdo, 'employees', 'mobile',            "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'street',            "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'zip',               "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'city',              "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'country',           "TEXT NOT NULL DEFAULT 'CH'");
  ensure_column($pdo, 'employees', 'canton',            "TEXT NOT NULL DEFAULT ''"); // canton de domicile : détermine le barème d'impôt source
  ensure_column($pdo, 'employees', 'emergency_name',    "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'emergency_phone',   "TEXT NOT NULL DEFAULT ''");

  // Statut de séjour
  ensure_column($pdo, 'employees', 'permit_type',       "TEXT NOT NULL DEFAULT ''"); // B, C, G, L, Ci, F, S… vide = ressortissant suisse
  ensure_column($pdo, 'employees', 'permit_expiry',     "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'is_frontalier',     'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'employees', 'residence_country', "TEXT NOT NULL DEFAULT ''"); // pays de résidence du frontalier
  ensure_column($pdo, 'employees', 'arrival_ch_date',   "TEXT NOT NULL DEFAULT ''");

  // Imposition à la source
  ensure_column($pdo, 'employees', 'tax_at_source',     'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'employees', 'tax_canton',        "TEXT NOT NULL DEFAULT ''"); // canton de travail pour un frontalier, de domicile sinon
  ensure_column($pdo, 'employees', 'tax_bareme',        "TEXT NOT NULL DEFAULT ''"); // code de tarif : A0N, B1Y, C2N…
  ensure_column($pdo, 'employees', 'tax_church',        'INTEGER NOT NULL DEFAULT 0'); // impôt ecclésiastique (Y/N du code de tarif)
  ensure_column($pdo, 'employees', 'spouse_works',      'INTEGER NOT NULL DEFAULT 0'); // départage les barèmes B et C
  ensure_column($pdo, 'employees', 'tax_children',      'INTEGER NOT NULL DEFAULT 0'); // nombre de charges reconnues

  // Contrat de travail
  ensure_column($pdo, 'employees', 'contract_start',    "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'contract_end',      "TEXT NOT NULL DEFAULT ''"); // CDD uniquement
  ensure_column($pdo, 'employees', 'contract_type',     "TEXT NOT NULL DEFAULT ''"); // cdi | cdd | stage | apprentissage | sur_appel | mandat | benevole_indemnise
  ensure_column($pdo, 'employees', 'activity_rate',     'REAL NOT NULL DEFAULT 0');  // taux d'activité en %
  ensure_column($pdo, 'employees', 'workplace',         "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'department',        "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'job_title',         "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'manager_name',      "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'trial_months',      'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'employees', 'notice_period',     "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'vacation_days',     'REAL NOT NULL DEFAULT 0');
  ensure_column($pdo, 'employees', 'cct',               "TEXT NOT NULL DEFAULT ''"); // convention collective applicable
  ensure_column($pdo, 'employees', 'payments_per_year', 'INTEGER NOT NULL DEFAULT 12'); // 12 ou 13 (13e salaire)
  ensure_column($pdo, 'employees', 'bank_name',         "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'account_holder',    "TEXT NOT NULL DEFAULT ''"); // si le titulaire du compte diffère de l'employé
  ensure_column($pdo, 'employees', 'notes',             "TEXT NOT NULL DEFAULT ''");

  /* Assurances sociales et prévoyance (2026-08-14, revu) : deux régimes distincts.
     AVS/AC/AMat/AANP/LAAC/IJM ont le même taux pour tout le monde un mois donné, et ce
     taux ne change que d'une année civile à l'autre (barème AVS, contrat AANP du club) :
     leur valeur vit donc dans payroll_rate_settings (une ligne par année), pas sur
     l'employé. L'employé garde seulement l'assujettissement (est-il concerné, oui/non).
     LPP et impôt à la source restent personnels (âge, plan, situation familiale) :
     taux/montant continuent de vivre sur la fiche. */
  foreach (['avs', 'ac', 'amat', 'aanp', 'laac', 'ijm'] as $key) {
    ensure_column($pdo, 'employees', $key . '_subject', 'INTEGER NOT NULL DEFAULT 0');
  }
  foreach (['lpp', 'is'] as $key) {
    ensure_column($pdo, 'employees', $key . '_subject', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'employees', $key . '_rate',    'REAL NOT NULL DEFAULT 0'); // part employé, en % du brut
    ensure_column($pdo, 'employees', $key . '_amount',  'REAL NOT NULL DEFAULT 0'); // part employé, montant fixe par période
  }
  /* d'anciennes colonnes {avs,ac,amat,aanp,laac,ijm}_rate/_amount ont pu être créées et
     remplies entre le 14 et le 14 août 2026 (une seule fenêtre de déploiement) : elles ne
     sont plus lues nulle part, mais ne sont pas supprimées pour ne rien perdre. Reprises
     ci-dessous vers payroll_rate_settings, voir plus bas. */

  /* Taux d'assurances sociales, un jeu par année civile, saisi une fois dans les
     Paramètres. Volontairement pas de barème officiel suisse en dur (décision du
     2026-07-15) : les taux usuels ne sont que des indications côté interface. */
  $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_rate_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL UNIQUE,
    avs_rate REAL NOT NULL DEFAULT 0,
    ac_rate REAL NOT NULL DEFAULT 0,
    amat_rate REAL NOT NULL DEFAULT 0,
    aanp_rate REAL NOT NULL DEFAULT 0,
    laac_rate REAL NOT NULL DEFAULT 0,
    ijm_rate REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  /* Retenue lavage (joueurs uniquement) : seuil de salaire mensuel brut au-delà duquel elle
     s'applique automatiquement, montant prélevé, et compte du plan comptable (Comptabilité)
     affiché comme référence sur le décompte — pas d'écriture comptable créée. Réglée une fois
     par année civile, comme les taux d'assurances sociales. */
  ensure_column($pdo, 'payroll_rate_settings', 'lavage_threshold', 'REAL NOT NULL DEFAULT 300');
  ensure_column($pdo, 'payroll_rate_settings', 'lavage_amount', 'REAL NOT NULL DEFAULT 30');
  ensure_column($pdo, 'payroll_rate_settings', 'lavage_account_number', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'payroll_rate_settings', 'lavage_account_label', "TEXT NOT NULL DEFAULT ''");
  /* Comptes du plan comptable pour les deux revenus d'un joueur, réglés une fois pour tout le
     club : le salaire fixe de la fiche joueur et les primes de match restent deux lignes
     distinctes sur le décompte (sources différentes), chacune sous l'intitulé de son compte. */
  ensure_column($pdo, 'payroll_rate_settings', 'player_salary_account_number', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'payroll_rate_settings', 'player_salary_account_label', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'payroll_rate_settings', 'player_bonus_account_number', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'payroll_rate_settings', 'player_bonus_account_label', "TEXT NOT NULL DEFAULT ''");

  /* Références administratives des assureurs et de la prévoyance (assureur LAA, n° de
     police, groupe LAA, institution et plan LPP, caisse d'allocations familiales) :
     plusieurs profils possibles (ex. "Staff" / "Joueurs"), un employé en choisit un.
     Ce sont des infos générales du club, pas de l'employé : les saisir une fois évite
     de les ressaisir sur chaque fiche et garantit qu'elles restent cohérentes. */
  $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_insurance_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL,
    laa_insurer TEXT NOT NULL DEFAULT '',
    laa_policy TEXT NOT NULL DEFAULT '',
    laa_group TEXT NOT NULL DEFAULT '',
    lpp_institution TEXT NOT NULL DEFAULT '',
    lpp_plan TEXT NOT NULL DEFAULT '',
    caf_fund TEXT NOT NULL DEFAULT '',
    caf_number TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  ensure_column($pdo, 'employees', 'insurance_profile_id', 'INTEGER REFERENCES payroll_insurance_profiles(id) ON DELETE SET NULL');
  // Ce qui reste personnel malgré le profil : le numéro d'affilié LPP de l'employé, et
  // la part patronale (coût employeur, jamais retenue, dépend du salaire de la personne).
  ensure_column($pdo, 'employees', 'lpp_member_number',   "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employees', 'lpp_employer_amount', 'REAL NOT NULL DEFAULT 0');

  /* Reprise unique : si d'anciennes colonnes laa_insurer/laa_policy/laa_group/lpp_institution/
     lpp_plan/caf_fund/caf_number existent encore sur employees (fenêtre du 2026-08-14) et
     portent des valeurs, un profil est créé par combinaison distincte trouvée, et les
     employés concernés y sont rattachés. Sans quoi une saisie faite dans l'intervalle
     disparaîtrait sans avertissement. */
  $legacyRefCols = ['laa_insurer', 'laa_policy', 'laa_group', 'lpp_institution', 'lpp_plan', 'caf_fund', 'caf_number'];
  $hasLegacyRefCols = true;
  foreach ($legacyRefCols as $c) { if (!column_exists($pdo, 'employees', $c)) { $hasLegacyRefCols = false; break; } }
  $migratedProfiles = (bool) $pdo->query("SELECT 1 FROM schema_migrations WHERE name = 'insurance_profiles_2026_08'")->fetchColumn();
  if (!$migratedProfiles && $hasLegacyRefCols) {
    $rows = $pdo->query("SELECT id, laa_insurer, laa_policy, laa_group, lpp_institution, lpp_plan, caf_fund, caf_number
                         FROM employees
                         WHERE laa_insurer!='' OR laa_policy!='' OR laa_group!='' OR lpp_institution!=''
                            OR lpp_plan!='' OR caf_fund!='' OR caf_number!=''")->fetchAll();
    $profileByCombo = [];
    foreach ($rows as $r) {
      $combo = implode('|', array_map(fn($c) => $r[$c], $legacyRefCols));
      if (!isset($profileByCombo[$combo])) {
        $ins = $pdo->prepare('INSERT INTO payroll_insurance_profiles (label, laa_insurer, laa_policy, laa_group, lpp_institution, lpp_plan, caf_fund, caf_number) VALUES (?,?,?,?,?,?,?,?)');
        $label = 'Profil repris ' . (count($profileByCombo) + 1);
        $ins->execute([$label, $r['laa_insurer'], $r['laa_policy'], $r['laa_group'], $r['lpp_institution'], $r['lpp_plan'], $r['caf_fund'], $r['caf_number']]);
        $profileByCombo[$combo] = (int)$pdo->lastInsertId();
      }
      $pdo->prepare('UPDATE employees SET insurance_profile_id = ? WHERE id = ?')->execute([$profileByCombo[$combo], $r['id']]);
    }
    $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (name) VALUES (?)')->execute(['insurance_profiles_2026_08']);
  }

  /* Si le passage à la fiche employé (2026-08-14, première mouture) a déjà été déployé et
     utilisé avant cette révision, d'anciennes colonnes {avs,ac,amat,aanp,laac,ijm}_rate/_amount
     ont pu être créées et remplies sur employees. Elles ne sont plus lues nulle part depuis
     ce fichier, mais on en reprend la première valeur non nulle trouvée par ligne vers le
     réglage global de l'année en cours, pour ne rien perdre d'un taux déjà saisi. */
  $migratedRates = (bool) $pdo->query("SELECT 1 FROM schema_migrations WHERE name = 'shared_rates_2026_08'")->fetchColumn();
  if (!$migratedRates) {
    $sharedKeys = ['avs', 'ac', 'amat', 'aanp', 'laac', 'ijm'];
    $found = [];
    foreach ($sharedKeys as $k) {
      if (!column_exists($pdo, 'employees', $k . '_rate')) continue;
      $v = (float) $pdo->query("SELECT {$k}_rate FROM employees WHERE {$k}_rate != 0 LIMIT 1")->fetchColumn();
      if ($v !== 0.0) $found[$k . '_rate'] = $v;
    }
    if ($found) {
      $year = (int) date('Y');
      $existsSt = $pdo->prepare('SELECT 1 FROM payroll_rate_settings WHERE year = ?');
      $existsSt->execute([$year]);
      if (!$existsSt->fetchColumn()) {
        $cols = implode(',', array_keys($found));
        $ph = implode(',', array_fill(0, count($found), '?'));
        $pdo->prepare("INSERT INTO payroll_rate_settings (year, $cols) VALUES (?, $ph)")
            ->execute([$year, ...array_values($found)]);
      }
    }
    $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (name) VALUES (?)')->execute(['shared_rates_2026_08']);
  }

  /* Enfants : table à part et non un compteur, parce que les allocations familiales
     se justifient enfant par enfant (âge, formation en cours jusqu'à 25 ans) et que
     l'attestation demandée par la caisse porte sur chaque enfant. */
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_children (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    first_name TEXT NOT NULL DEFAULT '',
    last_name TEXT NOT NULL DEFAULT '',
    birth_date TEXT NOT NULL DEFAULT '',
    in_education INTEGER NOT NULL DEFAULT 0,
    allocation REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  /* Suivi des pièces d'engagement. Une ligne par type de document et par employé
     (UNIQUE), remplie au fur et à mesure de l'onboarding. Les dates d'échéance
     comptent autant que la réception : un permis de séjour et un extrait spécial
     du casier judiciaire se périment, et l'extrait spécial est la pièce qui
     conditionne l'encadrement de mineurs. */
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    doc_type TEXT NOT NULL,
    received INTEGER NOT NULL DEFAULT 0,
    received_date TEXT NOT NULL DEFAULT '',
    expiry_date TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(employee_id, doc_type)
  )");
  // Le fichier numérique attaché à une pièce d'engagement (une par doc_type et par employé).
  ensure_column($pdo, 'employee_documents', 'file_path', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employee_documents', 'file_name', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employee_documents', 'file_mime', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'employee_documents', 'file_size', 'INTEGER NOT NULL DEFAULT 0');

  /* Dossier libre : documents qui arrivent au fil du temps et ne correspondent à aucune
     case de la checklist fixe (justificatif ponctuel, échange avec une assurance...).
     Une ligne par fichier, pas de type imposé — juste un libellé donné à l'upload. */
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    label TEXT NOT NULL DEFAULT '',
    file_path TEXT NOT NULL,
    file_name TEXT NOT NULL,
    file_mime TEXT NOT NULL DEFAULT '',
    file_size INTEGER NOT NULL DEFAULT 0,
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  /* Reprise unique des anciennes règles de paie vers la fiche employé. Les règles
     étaient globales et cochées par employé ; elles deviennent des colonnes de
     l'employé. Sans cette reprise, les taux déjà saisis (AANP, AVS, LPP, impôt
     source) disparaîtraient des décomptes sans avertissement.

     Les tables payroll_rules / employee_payroll_rules ne sont volontairement PAS
     supprimées : la reprise est un aplatissement de données, et garder la source
     permet de vérifier après coup ce qui a été repris. Elles ne sont plus lues
     nulle part ailleurs. */
  $migratedRules = (bool) $pdo->query("SELECT 1 FROM schema_migrations WHERE name = 'payroll_rules_to_fiche_2026_08'")->fetchColumn();
  if (!$migratedRules && table_exists($pdo, 'payroll_rules') && table_exists($pdo, 'employee_payroll_rules')) {
    // Une catégorie de règle par ligne de la fiche. charge_sociale/assurance_accident visent
    // des lignes désormais À TAUX PARTAGÉ (avs/aanp) : seul l'assujettissement est repris sur
    // l'employé, un taux en % trouvé alimente le réglage global de l'année en cours (la
    // première valeur non nulle rencontrée ; un montant fixe pour ces catégories n'a pas
    // d'équivalent dans le nouveau modèle et est donc ignoré). lpp/impot_source restent
    // personnels : accumulés comme avant, plusieurs règles actives d'une même catégorie sur
    // un employé s'additionnent, ce qui reproduit le total qu'il voyait.
    $map = ['charge_sociale' => 'avs', 'assurance_accident' => 'aanp', 'lpp' => 'lpp', 'impot_source' => 'is'];
    $sharedKeys = ['avs', 'ac', 'amat', 'aanp', 'laac', 'ijm'];
    $rows = $pdo->query("SELECT epr.employee_id, pr.category, pr.type, pr.valeur
                         FROM employee_payroll_rules epr
                         JOIN payroll_rules pr ON pr.id = epr.rule_id
                         WHERE pr.active = 1")->fetchAll();
    $acc = [];
    $sharedRateFound = [];
    foreach ($rows as $r) {
      $key = $map[$r['category']] ?? null;
      if ($key === null) continue;
      $eid = (int)$r['employee_id'];
      if (in_array($key, $sharedKeys, true)) {
        $pdo->prepare("UPDATE employees SET {$key}_subject = 1 WHERE id = ?")->execute([$eid]);
        if ($r['type'] === 'percent' && (float)$r['valeur'] > 0 && !isset($sharedRateFound[$key])) {
          $sharedRateFound[$key] = (float)$r['valeur'];
        }
        continue;
      }
      $acc[$eid][$key] ??= ['rate' => 0.0, 'amount' => 0.0];
      if ($r['type'] === 'percent') $acc[$eid][$key]['rate']   += (float)$r['valeur'];
      else                          $acc[$eid][$key]['amount'] += (float)$r['valeur'];
    }
    foreach ($acc as $eid => $lines) {
      foreach ($lines as $key => $v) {
        $pdo->prepare("UPDATE employees SET {$key}_subject = 1, {$key}_rate = ?, {$key}_amount = ? WHERE id = ?")
            ->execute([$v['rate'], $v['amount'], $eid]);
      }
    }
    if ($sharedRateFound) {
      $year = (int) date('Y');
      $existsSt = $pdo->prepare('SELECT 1 FROM payroll_rate_settings WHERE year = ?');
      $existsSt->execute([$year]);
      if (!$existsSt->fetchColumn()) {
        $cols = implode(',', array_map(fn($k) => "{$k}_rate", array_keys($sharedRateFound)));
        $ph = implode(',', array_fill(0, count($sharedRateFound), '?'));
        $pdo->prepare("INSERT INTO payroll_rate_settings (year, $cols) VALUES (?, $ph)")
            ->execute([$year, ...array_values($sharedRateFound)]);
      }
    }
    $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (name) VALUES (?)')->execute(['payroll_rules_to_fiche_2026_08']);
  }

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
  /* Charges sociales des joueurs payés : mêmes six lignes à taux partagé que les employés
     (assujettissement à cocher, taux lu dans payroll_rate_settings), pas de LPP ni d'impôt à
     la source (pas de contrat employé). Abattement AVS propre aux joueurs et entraîneurs :
     2500.-/semestre (416.67 CHF/mois), réduit la base de calcul des charges, jamais le salaire
     brut affiché. */
  ensure_column($pdo, 'players', 'avs_subject',  'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'players', 'ac_subject',   'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'players', 'amat_subject', 'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'players', 'aanp_subject', 'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'players', 'laac_subject', 'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'players', 'ijm_subject',  'INTEGER NOT NULL DEFAULT 0');
  ensure_column($pdo, 'players', 'avs_abatement', 'INTEGER NOT NULL DEFAULT 0');

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
 * référentiel contacts (base absente, verrou) ne doit pas empêcher d'enregistrer un employé.
 * $identity porte les champs d'identité de la fiche (adresse, naissance, mobile, langue) qui
 * appartiennent à la personne et non à son emploi : ils sont donc reversés à l'annuaire, où
 * mfc_contacts_ingest() ne remplit que ce qui y est encore vide. */
function sync_employee_to_contacts(int $employeeId, string $first, string $last, string $email, string $phone, string $iban, bool $active, array $identity = []): void {
  try {
    $payload = [
      'first_name' => $first, 'last_name' => $last, 'email' => $email,
      'phone' => $phone, 'iban' => $iban, 'active' => $active ? 1 : 0,
      'qualities' => ['salarie'],
    ];
    foreach (['mobile', 'street', 'zip', 'city', 'country', 'birth_date', 'lang', 'job_title'] as $k) {
      if (($identity[$k] ?? '') !== '') $payload[$k] = $identity[$k];
    }
    mfc_contacts_ingest(mfc_contacts_db(), $payload, 'rh', 'employee:' . $employeeId);
  } catch (Throwable $e) { /* l'annuaire n'est pas critique pour la RH elle-même */ }
}

/* --------------------------------------------------- Fiche employé complète
 *
 * Une fonction plutôt qu'une constante de premier niveau : sous le switch du
 * routeur, une const déclarée après coup serait introuvable à l'exécution.
 */

/** Champs de la fiche employé acceptés en écriture, avec leur type de conversion.
 * first_name/last_name/email/phone/iban/active/paiement restent traités à part
 * (validation propre, synchro annuaire), ils ne figurent donc pas ici. */
function employee_fiche_fields(): array {
  return [
    // Identité
    'birth_name' => 'str', 'avs_number' => 'str', 'birth_date' => 'str', 'gender' => 'str',
    'nationality' => 'str', 'lang' => 'str', 'civil_status' => 'str', 'civil_status_since' => 'str',
    'mobile' => 'str', 'street' => 'str', 'zip' => 'str', 'city' => 'str', 'country' => 'str',
    'canton' => 'str', 'emergency_name' => 'str', 'emergency_phone' => 'str',
    // Séjour
    'permit_type' => 'str', 'permit_expiry' => 'str', 'is_frontalier' => 'bool',
    'residence_country' => 'str', 'arrival_ch_date' => 'str',
    // Impôt à la source
    'tax_at_source' => 'bool', 'tax_canton' => 'str', 'tax_bareme' => 'str',
    'tax_church' => 'bool', 'spouse_works' => 'bool', 'tax_children' => 'int',
    // Contrat
    'contract_start' => 'str', 'contract_end' => 'str', 'contract_type' => 'str',
    'activity_rate' => 'float', 'workplace' => 'str', 'department' => 'str', 'job_title' => 'str',
    'manager_name' => 'str', 'trial_months' => 'int', 'notice_period' => 'str',
    'vacation_days' => 'float', 'cct' => 'str', 'payments_per_year' => 'int',
    'bank_name' => 'str', 'account_holder' => 'str', 'notes' => 'str',
    // Assurances sociales à taux partagé (voir Paramètres > Paie) : seul l'assujettissement
    // est propre à l'employé, le taux vient de payroll_rate_settings.
    'avs_subject' => 'bool', 'ac_subject' => 'bool', 'amat_subject' => 'bool',
    'aanp_subject' => 'bool', 'laac_subject' => 'bool', 'ijm_subject' => 'bool',
    // LPP et impôt à la source : personnels (âge/plan, situation familiale)
    'lpp_subject' => 'bool',  'lpp_rate' => 'float',  'lpp_amount' => 'float',
    'is_subject' => 'bool',   'is_rate' => 'float',   'is_amount' => 'float',
    // Profil d'assurance (assureur LAA, institution LPP...) choisi dans une liste
    // paramétrée une fois pour tout le club ; seuls le n° d'affilié et la part
    // employeur restent propres à la personne. 'nullint' et non 'int' : la colonne est
    // une clé étrangère avec ON DELETE SET NULL, y écrire 0 la ferait échouer (pragma
    // foreign_keys=ON, et aucun profil n'a l'id 0).
    'insurance_profile_id' => 'nullint',
    'lpp_member_number' => 'str', 'lpp_employer_amount' => 'float',
  ];
}

/** Construit le fragment SET d'un UPDATE à partir des seuls champs réellement transmis.
 * Un formulaire partiel (le bandeau "Paiement" de la fiche détaillée, par exemple) ne doit
 * jamais remettre à zéro les champs qu'il n'affiche pas. */
function employee_fiche_assignments(array $b): array {
  $set = []; $vals = [];
  // Symétrique de la redaction en lecture : sans droit sur les salaires, un champ de
  // rémunération transmis à la main est ignoré, pas seulement masqué à l'affichage.
  $blocked = mfc_can('rh.payroll.edit') ? [] : array_flip(employee_payroll_only_fields());
  foreach (employee_fiche_fields() as $col => $type) {
    if (!array_key_exists($col, $b)) continue;
    if (isset($blocked[$col])) continue;
    $set[] = "$col = ?";
    $vals[] = match ($type) {
      'int'     => i($b, $col),
      'nullint' => ni($b, $col), // NULL si vide/0, jamais 0 : la colonne est une clé étrangère
      'float'   => f($b, $col),
      'bool'    => bo($b, $col) ? 1 : 0,
      default   => s($b, $col),
    };
  }
  return [$set, $vals];
}

/** Catalogue des pièces d'engagement. L'extrait spécial du casier judiciaire figure
 * en tête des pièces obligatoires : c'est celle qui conditionne l'encadrement de mineurs. */
function employee_document_types(): array {
  return ['contrat_signe', 'piece_identite', 'permis_sejour', 'attestation_avs',
          'casier_special', 'coordonnees_bancaires'];
}

/** Champs de la fiche réservés à qui détient le droit sur les salaires : ce qui décrit
 * la rémunération et les retenues, plus le numéro AVS, qui est un identifiant d'État. */
function employee_payroll_only_fields(): array {
  return ['avs_number', 'tax_at_source', 'tax_canton', 'tax_bareme', 'tax_church',
          'spouse_works', 'tax_children', 'payments_per_year',
          'insurance_profile_id', 'lpp_member_number', 'lpp_employer_amount',
          'avs_subject', 'ac_subject', 'amat_subject', 'aanp_subject', 'laac_subject', 'ijm_subject',
          'lpp_subject', 'lpp_rate', 'lpp_amount',
          'is_subject', 'is_rate', 'is_amount'];
}

/** Taux d'assurances sociales partagés d'une année civile. Retombe sur des zéros si
 * l'année n'a pas encore été paramétrée, plutôt que d'échouer : mieux vaut un décompte
 * à retenues nulles et visibles qu'une page qui casse. */
function payroll_rate_settings_for_year(PDO $pdo, int $year): array {
  $st = $pdo->prepare('SELECT * FROM payroll_rate_settings WHERE year = ?');
  $st->execute([$year]);
  $row = $st->fetch();
  return $row ?: ['year' => $year, 'avs_rate' => 0.0, 'ac_rate' => 0.0, 'amat_rate' => 0.0,
                   'aanp_rate' => 0.0, 'laac_rate' => 0.0, 'ijm_rate' => 0.0,
                   'lavage_threshold' => 300.0, 'lavage_amount' => 30.0,
                   'lavage_account_number' => '', 'lavage_account_label' => '',
                   'player_salary_account_number' => '', 'player_salary_account_label' => '',
                   'player_bonus_account_number' => '', 'player_bonus_account_label' => ''];
}

/** Document PDF du décompte de paie (généré via Dompdf, voir case 'payslip_pdf').
 * Le calcul et le formatage (regroupement par compte, taux, montants en fr-CH) restent faits
 * côté écran (rh/index.html, buildPayslipPayload()) : cette fonction pose le résultat déjà
 * mis en forme dans un document, elle ne recalcule rien. Ça garantit que le PDF affiche
 * exactement ce que le responsable a validé à l'écran avant de cliquer sur "Générer".
 * HTML volontairement en tableaux (pas de flex/grid) : c'est ce que Dompdf sait le mieux
 * mettre en page de façon fiable, comme dans un vrai logiciel de facturation. */
function render_payslip_pdf_html(array $header, array $revenue, string $grossTotal, array $charges, string $chargesTotal, array $divers, string $diversTotal, string $net, string $chargesNote = ''): string {
  $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $row = function(string $label, string $base, string $taux, string $montant, bool $bold = false, bool $topBorder = false) use ($e) {
    $tb = $topBorder ? 'border-top:1.5px solid #15140F;' : '';
    $fw = $bold ? 'font-weight:700;' : '';
    return '<tr>'
      . "<td style=\"padding:6px 8px 6px 0;{$tb}{$fw}\">{$e($label)}</td>"
      . "<td style=\"padding:6px 8px 6px 0;{$tb}text-align:right;color:#6E6C61\">{$e($base)}</td>"
      . "<td style=\"padding:6px 8px 6px 0;{$tb}text-align:right;color:#6E6C61\">{$e($taux)}</td>"
      . "<td style=\"padding:6px 0 6px;{$tb}text-align:right;{$fw}\">{$e($montant)}</td>"
      . '</tr>';
  };

  $revenueRows = '';
  foreach ($revenue as $l) $revenueRows .= $row((string)($l['label'] ?? '—'), '', '', (string)($l['montant'] ?? ''));
  $chargeRows = '';
  foreach ($charges as $l) $chargeRows .= $row((string)($l['label'] ?? '—'), (string)($l['base'] ?? ''), (string)($l['taux'] ?? ''), (string)($l['montant'] ?? ''));
  // "Divers" : retenues ajoutées à la main sur le décompte, catégorie distincte après Charges
  // sociales plutôt que mélangées aux charges calculées par taux. Absente du document si vide.
  $diversRows = '';
  foreach ($divers as $l) $diversRows .= $row((string)($l['label'] ?? '—'), '', '', (string)($l['montant'] ?? ''));
  $diversBlock = $divers ? ($row('Divers', '', '', '', true) . $diversRows . $row('', '', '', $diversTotal, true, true)) : '';

  $periodeLabel = !empty($header['is_semestriel']) ? 'Période semestrielle' : 'Période mensuelle';
  $addressLines = array_filter([$header['street'] ?? '', trim(($header['zip'] ?? '') . ' ' . ($header['city'] ?? '')), $header['country'] ?? '']);

  // Logo officiel du club (SVG, meyrinfc.ch), en data URI plutôt qu'un <img src="chemin"> :
  // Dompdf lit le fichier une seule fois ici, sans avoir à résoudre un chemin relatif depuis
  // son propre contexte de rendu. Vectoriel : net à cette taille comme en plus grand, et pas
  // besoin de l'extension GD côté serveur (contrairement à un PNG intégré de la même façon).
  $logoPath = __DIR__ . '/assets/logo-meyrinfc.svg';
  $logoImg = is_file($logoPath)
    ? '<img src="data:image/svg+xml;base64,' . base64_encode(file_get_contents($logoPath)) . '" style="width:78px;height:78px;display:block;margin:0 auto">'
    : '';

  return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
    @page { margin: 16mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color:#15140F; }
    table { width:100%; border-collapse:collapse; }
  </style></head><body>
    ' . ($logoImg ? '<div style="text-align:center;margin-bottom:14px">' . $logoImg . '</div>' : '') . '
    <table style="border-bottom:1.5px solid #15140F;margin-bottom:26px"><tr>
      <td style="padding:0 0 12px">
        <div style="font-size:15px;font-weight:700;color:#15140F">MEYRIN FC</div>
        <div style="font-size:10px;color:#6E6C61;margin-top:2px">Décompte de salaire</div>
      </td>
      <td style="padding:0 0 12px;text-align:right">
        <div style="font-size:13px;font-weight:700;color:#15140F">' . $e($header['period_label'] ?? '') . '</div>
        <div style="font-size:9.5px;color:#6E6C61;margin-top:2px">' . $e($periodeLabel) . '</div>
      </td>
    </tr></table>

    <table style="margin-bottom:30px"><tr>
      <td style="width:50%;vertical-align:top">
        <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C;margin-bottom:2px">N° collaborateur</div>
        <div style="font-weight:600;margin-bottom:10px">' . $e($header['collab_id'] ?? '') . '</div>
        <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C;margin-bottom:2px">N° AVS</div>
        <div style="font-weight:600">' . $e($header['avs'] ?: '—') . '</div>
      </td>
      <td style="width:50%;vertical-align:top;text-align:right">
        <div style="font-weight:700;font-size:11.5px">' . $e(trim(($header['first_name'] ?? '') . ' ' . ($header['last_name'] ?? ''))) . '</div>
        ' . implode('', array_map(fn($l) => '<div style="color:#6E6C61;margin-top:2px">' . $e($l) . '</div>', $addressLines)) . '
      </td>
    </tr></table>

    <table>
      <tr style="border-bottom:1.5px solid #15140F">
        <td style="padding-bottom:6px;font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C">Rubrique et genres de salaires</td>
        <td style="width:75px;padding-bottom:6px;font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C;text-align:right">Base</td>
        <td style="width:65px;padding-bottom:6px;font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C;text-align:right">Taux</td>
        <td style="width:85px;padding-bottom:6px;font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C;text-align:right">Montant</td>
      </tr>
      ' . $row('Salaire brut', '', '', '', true) . $revenueRows . $row('', '', '', $grossTotal, true, true) . '
      ' . $row('Charges sociales', '', '', '', true) . '
      ' . ($chargesNote ? '<tr><td colspan="4" style="padding:0 0 6px;font-size:9.5px;color:#6E6C61">' . $e($chargesNote) . '</td></tr>' : '') . '
      ' . $chargeRows . $row('', '', '', $chargesTotal, true, true) . '
      ' . $diversBlock . '
    </table>

    <table style="margin-top:22px;border:1px solid #E9E6DC"><tr>
      <td style="width:60%;padding:12px 16px;background:#F6F4ED">
        <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#9A988C;margin-bottom:2px">Paiement</div>
        <div style="font-weight:600">' . $e($header['iban'] ?: '—') . '</div>
      </td>
      <td style="width:40%;padding:12px 16px;background:#F6F4ED;border-left:1px solid #E9E6DC;text-align:right">
        <span style="font-size:10px;color:#6E6C61">Salaire net&#160;&#160;</span>
        <span style="font-size:15px;font-weight:700;color:#15140F">' . $e($net) . '</span>
      </td>
    </tr></table>
  </body></html>';
}

/** Attache enfants, pièces d'engagement, dossier libre et profil d'assurance résolu à une
 * liste d'employés, en requêtes groupées (pas de requête dans la boucle).
 *
 * Sans le droit sur les salaires, les champs de rémunération sont retirés de la réponse :
 * la liste des employés est accessible bien plus largement (un coach y consulte les
 * effectifs), et masquer un onglet côté navigateur n'est pas une protection. */
function attach_employee_fiche(PDO $pdo, array &$employees): void {
  if (!$employees) return;
  $canPayroll = mfc_can('rh.payroll.view');
  if ($canPayroll) {
    $children = $pdo->query('SELECT * FROM employee_children ORDER BY birth_date')->fetchAll();
    $byChild = []; foreach ($children as $c) $byChild[(int)$c['employee_id']][] = $c;
    $profiles = $pdo->query('SELECT * FROM payroll_insurance_profiles')->fetchAll();
    $byProfile = []; foreach ($profiles as $p) $byProfile[(int)$p['id']] = $p;
    $rateSettings = payroll_rate_settings_for_year($pdo, (int) date('Y'));
  }
  $docs   = $pdo->query('SELECT * FROM employee_documents')->fetchAll();
  $byDoc  = []; foreach ($docs as $d) $byDoc[(int)$d['employee_id']][] = $d;
  $files  = $pdo->query('SELECT * FROM employee_files ORDER BY uploaded_at DESC')->fetchAll();
  $byFile = []; foreach ($files as $f) $byFile[(int)$f['employee_id']][] = $f;
  $hidden = $canPayroll ? [] : array_flip(employee_payroll_only_fields());
  foreach ($employees as &$e) {
    if ($hidden) foreach ($hidden as $col => $_) unset($e[$col]);
    $e['children']  = $canPayroll ? ($byChild[(int)$e['id']] ?? []) : [];
    $e['documents'] = $byDoc[(int)$e['id']] ?? [];
    $e['files']     = $byFile[(int)$e['id']] ?? [];
    if ($canPayroll) {
      $pid = (int)($e['insurance_profile_id'] ?? 0);
      $e['insurance_profile'] = $byProfile[$pid] ?? null;
      $e['rate_settings'] = $rateSettings;
    }
  }
  unset($e);
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
  /* Les enfants servent aux allocations familiales et au barème d'impôt source :
     donnée de paie, et donnée personnelle de mineurs. D'où payroll.view et non
     employees.view, contrairement aux pièces d'engagement, qui relèvent du suivi
     administratif courant de l'employé. */
  'employee_children'      => ['GET' => 'payroll.view',    'write' => 'payroll.edit'],
  'employee_documents'     => ['GET' => 'employees.view',  'write' => 'employees.edit'],
  'employee_document_upload'      => 'employees.edit',
  'employee_document_remove_file' => 'employees.edit',
  'employee_document_file'        => 'employees.view',
  'employee_files'         => ['GET' => 'employees.view',  'write' => 'employees.edit'],
  'employee_files_file'    => 'employees.view',
  'payroll_rate_settings'  => ['GET' => 'payroll.view',    'write' => 'payroll.edit'],
  'insurance_profiles'     => ['GET' => 'payroll.view',    'write' => 'payroll.edit'],
  'payslip_context'        => 'payroll.view',
  'player_payslip_context' => 'payroll.view',
  'player_payslip_pdf'     => 'payroll.edit',
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
  'payslip_pdf'            => 'payroll.edit',
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
      $st = db()->prepare('INSERT INTO postes (label, account_number, account_label) VALUES (?,?,?)');
      try { $st->execute([$label, s($b, 'account_number'), s($b, 'account_label')]); } catch (Throwable $e) { fail('Ce poste existe déjà'); }
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $st = db()->prepare('UPDATE postes SET label=?, active=?, account_number=?, account_label=? WHERE id=?');
      $st->execute([s($b, 'label'), bo($b, 'active', true) ? 1 : 0, s($b, 'account_number'), s($b, 'account_label'), $id]);
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
      $asg = $pdo->query("SELECT ea.*, t.name AS team_name, tc.name AS category_name, po.label AS poste_label,
               po.account_number AS account_number, po.account_label AS account_label, s.label AS season_label
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
      attach_employee_fiche($pdo, $employees);
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
      // Le reste de la fiche est écrit dans un second temps : la création peut n'avoir que le nom.
      [$set, $vals] = employee_fiche_assignments($b);
      if ($set) {
        $vals[] = $newId;
        db()->prepare('UPDATE employees SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
      }
      sync_employee_to_contacts($newId, $first, $ln, $email, $phone, $iban, true, $b);
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
      // Les champs de la fiche complète ne sont écrits que s'ils sont transmis : le bandeau
      // "Paiement" de la fiche détaillée n'envoie que l'identité et ne doit rien effacer d'autre.
      [$set, $vals] = employee_fiche_assignments($b);
      array_unshift($set, 'first_name=?', 'last_name=?', 'email=?', 'phone=?', 'iban=?', 'active=?', 'paiement=?');
      array_unshift($vals, $first, $last, $email, $phone, $iban, $active?1:0, $paiement);
      $vals[] = $id;
      db()->prepare('UPDATE employees SET ' . implode(', ', $set) . ' WHERE id=?')->execute($vals);
      sync_employee_to_contacts($id, $first, $last, $email, $phone, $iban, $active, $b);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ENFANTS DE L'EMPLOYÉ ============ */

  case 'employee_children': {
    if ($method === 'GET') {
      $employee_id = i($_GET, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $st = db()->prepare('SELECT * FROM employee_children WHERE employee_id=? ORDER BY birth_date');
      $st->execute([$employee_id]);
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      $employee_id = i($b, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $st = db()->prepare('INSERT INTO employee_children (employee_id, first_name, last_name, birth_date, in_education, allocation) VALUES (?,?,?,?,?,?)');
      $st->execute([$employee_id, s($b,'first_name'), s($b,'last_name'), s($b,'birth_date'), bo($b,'in_education')?1:0, f($b,'allocation')]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $st = db()->prepare('UPDATE employee_children SET first_name=?, last_name=?, birth_date=?, in_education=?, allocation=? WHERE id=?');
      $st->execute([s($b,'first_name'), s($b,'last_name'), s($b,'birth_date'), bo($b,'in_education')?1:0, f($b,'allocation'), $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      db()->prepare('DELETE FROM employee_children WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ PIÈCES D'ENGAGEMENT ============ */

  case 'employee_documents': {
    if ($method === 'GET') {
      $employee_id = i($_GET, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $st = db()->prepare('SELECT * FROM employee_documents WHERE employee_id=?');
      $st->execute([$employee_id]);
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      // Upsert par (employé, type) : la case à cocher de la checklist envoie toujours le même
      // couple, qu'il s'agisse d'une première réception ou d'une correction de date.
      $employee_id = i($b, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $doc_type = s($b, 'doc_type');
      if (!in_array($doc_type, employee_document_types(), true)) fail('Type de document inconnu');
      $st = db()->prepare("INSERT INTO employee_documents (employee_id, doc_type, received, received_date, expiry_date, notes, updated_at)
        VALUES (?,?,?,?,?,?,datetime('now'))
        ON CONFLICT(employee_id, doc_type) DO UPDATE SET
          received=excluded.received, received_date=excluded.received_date,
          expiry_date=excluded.expiry_date, notes=excluded.notes, updated_at=datetime('now')");
      $st->execute([$employee_id, $doc_type, bo($b,'received')?1:0, s($b,'received_date'), s($b,'expiry_date'), s($b,'notes')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Dépôt du fichier numérique d'une pièce d'engagement. Marque automatiquement la pièce
     "reçue" : un fichier attaché sans case cochée serait un état incohérent. */
  case 'employee_document_upload': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $pdo = db();
    $employee_id = i($b, 'employee_id'); if (!$employee_id) fail('employee_id requis');
    $doc_type = s($b, 'doc_type');
    if (!in_array($doc_type, employee_document_types(), true)) fail('Type de document inconnu');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Fichier manquant ou invalide.');
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) fail('Format non supporté (PDF, JPG ou PNG uniquement).');

    $dir = DB_DIR . '/employee_docs';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");

    $st = $pdo->prepare('SELECT id, file_path FROM employee_documents WHERE employee_id=? AND doc_type=?');
    $st->execute([$employee_id, $doc_type]);
    $existing = $st->fetch();
    $path = $dir . "/{$employee_id}_{$doc_type}.{$ext}";
    if ($existing && $existing['file_path'] && $existing['file_path'] !== $path && is_file($existing['file_path'])) unlink($existing['file_path']);
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) fail("Échec de l'enregistrement du fichier.");
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext];
    $size = filesize($path);

    $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type, received, received_date, file_path, file_name, file_mime, file_size, updated_at)
      VALUES (?,?,1,?,?,?,?,?,datetime('now'))
      ON CONFLICT(employee_id, doc_type) DO UPDATE SET
        received=1, received_date=CASE WHEN received_date='' THEN excluded.received_date ELSE received_date END,
        file_path=excluded.file_path, file_name=excluded.file_name, file_mime=excluded.file_mime,
        file_size=excluded.file_size, updated_at=datetime('now')")
      ->execute([$employee_id, $doc_type, date('Y-m-d'), $path, $origName, $mime, $size]);
    out(['ok' => true, 'filename' => $origName]);
  }

  /* Retrait du fichier attaché à une pièce, sans toucher au statut "reçu" ni aux dates :
     utile quand un mauvais fichier a été déposé et que la pièce reste physiquement reçue. */
  case 'employee_document_remove_file': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $pdo = db();
    $employee_id = i($b, 'employee_id'); $doc_type = s($b, 'doc_type');
    if (!$employee_id || !$doc_type) fail('employee_id et doc_type requis');
    $st = $pdo->prepare('SELECT file_path FROM employee_documents WHERE employee_id=? AND doc_type=?');
    $st->execute([$employee_id, $doc_type]);
    $path = $st->fetchColumn();
    if ($path && is_file($path)) unlink($path);
    $pdo->prepare("UPDATE employee_documents SET file_path='', file_name='', file_mime='', file_size=0, updated_at=datetime('now') WHERE employee_id=? AND doc_type=?")
      ->execute([$employee_id, $doc_type]);
    out(['ok' => true]);
  }

  /* Téléchargement/consultation du fichier d'une pièce d'engagement, depuis l'ERP (session,
     pas de token). Le lien personnel de l'employé passe par mon-piece.php, gardé par token. */
  case 'employee_document_file': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $employee_id = i($_GET, 'employee_id'); $doc_type = s($_GET, 'doc_type');
    $st = db()->prepare('SELECT file_path, file_name, file_mime FROM employee_documents WHERE employee_id=? AND doc_type=?');
    $st->execute([$employee_id, $doc_type]);
    $row = $st->fetch();
    if (!$row || !$row['file_path'] || !is_file($row['file_path'])) fail('Fichier introuvable.', 404);
    if (ob_get_level() > 0) ob_clean();
    $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $row['file_mime']);
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($row['file_name']) . '"');
    header('Content-Length: ' . filesize($row['file_path']));
    readfile($row['file_path']);
    exit;
  }

  /* ============ DOSSIER LIBRE (documents qui arrivent au fil du temps) ============ */

  case 'employee_files': {
    $pdo = db();
    if ($method === 'GET') {
      $employee_id = i($_GET, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      $st = $pdo->prepare('SELECT id, employee_id, label, file_name, file_mime, file_size, uploaded_at FROM employee_files WHERE employee_id=? ORDER BY uploaded_at DESC');
      $st->execute([$employee_id]);
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      $employee_id = i($b, 'employee_id'); if (!$employee_id) fail('employee_id requis');
      if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Fichier manquant ou invalide.');
      $origName = $_FILES['file']['name'];
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'], true)) fail('Format non supporté.');

      $dir = DB_DIR . '/employee_docs';
      if (!is_dir($dir)) mkdir($dir, 0775, true);
      $ht = $dir . '/.htaccess';
      if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");

      $filename = "{$employee_id}_" . bin2hex(random_bytes(6)) . ".{$ext}";
      $path = $dir . '/' . $filename;
      if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) fail("Échec de l'enregistrement du fichier.");
      $mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
               'doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
               'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$ext];
      $label = s($b, 'label') ?: $origName;
      $pdo->prepare('INSERT INTO employee_files (employee_id, label, file_path, file_name, file_mime, file_size) VALUES (?,?,?,?,?,?)')
          ->execute([$employee_id, $label, $path, $origName, $mime, filesize($path)]);
      out(['id' => (int)$pdo->lastInsertId()]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      $st = $pdo->prepare('SELECT file_path FROM employee_files WHERE id=?');
      $st->execute([$id]);
      $path = $st->fetchColumn();
      if ($path && is_file($path)) unlink($path);
      $pdo->prepare('DELETE FROM employee_files WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'employee_files_file': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $id = i($_GET, 'id'); if (!$id) fail('id requis');
    $st = db()->prepare('SELECT file_path, file_name, file_mime FROM employee_files WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !is_file($row['file_path'])) fail('Fichier introuvable.', 404);
    if (ob_get_level() > 0) ob_clean();
    $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $row['file_mime']);
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($row['file_name']) . '"');
    header('Content-Length: ' . filesize($row['file_path']));
    readfile($row['file_path']);
    exit;
  }

  /* ============ PARAMÈTRES > PAIE : taux partagés et profils d'assurance ============ */

  case 'payroll_rate_settings': {
    $pdo = db();
    if ($method === 'GET') {
      out($pdo->query('SELECT * FROM payroll_rate_settings ORDER BY year DESC')->fetchAll());
    }
    if ($method === 'POST' || $method === 'PUT') {
      $year = i($b, 'year'); if (!$year) fail('year requis');
      $rateCols = ['avs_rate','ac_rate','amat_rate','aanp_rate','laac_rate','ijm_rate','lavage_threshold','lavage_amount'];
      $strCols = ['lavage_account_number','lavage_account_label','player_salary_account_number','player_salary_account_label','player_bonus_account_number','player_bonus_account_label'];
      $cols = array_merge($rateCols, $strCols);
      $vals = array_merge(array_map(fn($c) => f($b, $c), $rateCols), array_map(fn($c) => s($b, $c), $strCols));
      $pdo->prepare('INSERT INTO payroll_rate_settings (year, ' . implode(',', $cols) . ') VALUES (?,' . implode(',', array_fill(0, count($cols), '?')) . ')
        ON CONFLICT(year) DO UPDATE SET ' . implode(', ', array_map(fn($c) => "$c=excluded.$c", $cols)))
        ->execute([$year, ...$vals]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'insurance_profiles': {
    $pdo = db();
    if ($method === 'GET') {
      out($pdo->query('SELECT * FROM payroll_insurance_profiles ORDER BY active DESC, sort_order, label')->fetchAll());
    }
    if ($method === 'POST') {
      $label = s($b, 'label'); if (!$label) fail('Libellé requis');
      $st = $pdo->prepare('INSERT INTO payroll_insurance_profiles (label, laa_insurer, laa_policy, laa_group, lpp_institution, lpp_plan, caf_fund, caf_number) VALUES (?,?,?,?,?,?,?,?)');
      $st->execute([$label, s($b,'laa_insurer'), s($b,'laa_policy'), s($b,'laa_group'), s($b,'lpp_institution'), s($b,'lpp_plan'), s($b,'caf_fund'), s($b,'caf_number')]);
      out(['id' => (int)$pdo->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $label = s($b, 'label'); if (!$label) fail('Libellé requis');
      $st = $pdo->prepare('UPDATE payroll_insurance_profiles SET label=?, laa_insurer=?, laa_policy=?, laa_group=?, lpp_institution=?, lpp_plan=?, caf_fund=?, caf_number=?, active=? WHERE id=?');
      $st->execute([$label, s($b,'laa_insurer'), s($b,'laa_policy'), s($b,'laa_group'), s($b,'lpp_institution'), s($b,'lpp_plan'), s($b,'caf_fund'), s($b,'caf_number'), bo($b,'active',true)?1:0, $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id'); if (!$id) fail('id requis');
      // ON DELETE SET NULL sur employees.insurance_profile_id : les fiches qui pointaient
      // vers ce profil retombent à "aucun profil" plutôt que de casser.
      $pdo->prepare('DELETE FROM payroll_insurance_profiles WHERE id=?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ CONTEXTE D'UN DÉCOMPTE DE PAIE ============ */

  /* Point d'entrée distinct de 'employees' pour que le droit sur les salaires soit exigé
     explicitement par le serveur avant de composer un décompte. La page de décompte
     s'appuyait jusqu'ici sur le 403 renvoyé par payroll_rules, un garde-fou hérité d'un
     appel qui n'existe plus : le rendre volontaire évite qu'il disparaisse en silence. */
  case 'payslip_context': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $pdo = db();
    $employee_id = i($_GET, 'employee_id'); if (!$employee_id) fail('employee_id requis');
    // Année civile du mois décompté (ex: "2026-01"), pas l'année en cours : un décompte de
    // janvier doit utiliser le taux de janvier même généré après le nouvel an.
    $month = s($_GET, 'month');
    $year = preg_match('/^(\d{4})-\d{2}$/', $month, $mm) ? (int)$mm[1] : (int) date('Y');
    $st = $pdo->prepare('SELECT * FROM employees WHERE id=?');
    $st->execute([$employee_id]);
    $emp = $st->fetch();
    if (!$emp) fail('Employé introuvable', 404);
    $ch = $pdo->prepare('SELECT * FROM employee_children WHERE employee_id=? ORDER BY birth_date');
    $ch->execute([$employee_id]);
    $profile = null;
    if (!empty($emp['insurance_profile_id'])) {
      $ps = $pdo->prepare('SELECT * FROM payroll_insurance_profiles WHERE id = ?');
      $ps->execute([(int)$emp['insurance_profile_id']]);
      $profile = $ps->fetch() ?: null;
    }
    out([
      'employee' => $emp,
      'children' => $ch->fetchAll(),
      'rate_settings' => payroll_rate_settings_for_year($pdo, $year),
      'insurance_profile' => $profile,
    ]);
  }

  /* Génère le PDF natif du décompte (Dompdf), à la place de l'impression navigateur.
     Enregistre aussi directement le fichier dans payroll_payments, comme un dépôt manuel
     (payments_upload) : plus besoin d'imprimer puis de re-déposer le fichier à la main. */
  case 'payslip_pdf': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $employee_id = i($b, 'employee_id'); $month = s($b, 'month');
    if (!$employee_id || !preg_match('/^\d{4}-\d{2}$/', $month)) fail('employee_id et month requis');
    $header = is_array($b['header'] ?? null) ? $b['header'] : [];
    $revenue = is_array($b['revenue'] ?? null) ? $b['revenue'] : [];
    $charges = is_array($b['charges'] ?? null) ? $b['charges'] : [];
    $divers = is_array($b['divers'] ?? null) ? $b['divers'] : [];

    $pdo = db();
    $st = $pdo->prepare('SELECT first_name, last_name FROM employees WHERE id = ?');
    $st->execute([$employee_id]);
    $emp = $st->fetch();
    if (!$emp) fail('Employé introuvable', 404);

    require_once __DIR__ . '/../lib/vendor/autoload.php';
    $html = render_payslip_pdf_html($header, $revenue, s($b, 'gross_total'), $charges, s($b, 'charges_total'), $divers, s($b, 'divers_total'), s($b, 'net'));

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfContent = $dompdf->output();

    $dir = DB_DIR . '/payslips';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
    $filename = "employee_{$employee_id}_{$month}.pdf";
    $path = $dir . '/' . $filename;
    $origName = "{$month}_Décompte_{$emp['last_name']}_{$emp['first_name']}.pdf";
    file_put_contents($path, $pdfContent);

    $existing = $pdo->prepare('SELECT id, payslip_path FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
    $existing->execute(['employee', $employee_id, $month]);
    $exRow = $existing->fetch();
    if ($exRow) {
      $pdo->prepare('UPDATE payroll_payments SET payslip_path=?, payslip_filename=?, sent_at=NULL WHERE id=?')->execute([$path, $origName, $exRow['id']]);
    } else {
      $pdo->prepare('INSERT INTO payroll_payments (person_type, person_id, period, payslip_path, payslip_filename) VALUES (?,?,?,?,?)')
        ->execute(['employee', $employee_id, $month, $path, $origName]);
    }

    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($origName) . '"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
    exit;
  }

  /* Contexte du décompte d'un joueur payé : salaire fixe + primes du mois, assujettissements
     et abattement AVS propres au joueur, taux partagés de l'année civile du mois décompté
     (mêmes réglages que les employés, Paramètres > Paie). */
  case 'player_payslip_context': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $pdo = db();
    $player_id = i($_GET, 'player_id'); if (!$player_id) fail('player_id requis');
    $month = s($_GET, 'month');
    $year = preg_match('/^(\d{4})-\d{2}$/', $month, $mm) ? (int)$mm[1] : (int) date('Y');
    $st = $pdo->prepare('SELECT * FROM players WHERE id=?');
    $st->execute([$player_id]);
    $player = $st->fetch();
    if (!$player) fail('Joueur introuvable', 404);
    $pr = $pdo->prepare("SELECT COALESCE(SUM(mp.montant),0) AS total FROM match_players mp
      JOIN matches m ON m.id = mp.match_id WHERE mp.player_id = ? AND strftime('%Y-%m', m.date) = ?");
    $pr->execute([$player_id, $month]);
    $primes = (float) $pr->fetchColumn();
    out([
      'player' => $player,
      'primes_mois' => $primes,
      'rate_settings' => payroll_rate_settings_for_year($pdo, $year),
    ]);
  }

  /* Même mécanique que payslip_pdf pour les employés : le calcul (charges, abattement,
     retenue lavage) reste fait côté écran, cette route pose le résultat déjà mis en forme
     dans un PDF et l'enregistre dans payroll_payments (person_type='player'). */
  case 'player_payslip_pdf': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $player_id = i($b, 'player_id'); $month = s($b, 'month');
    if (!$player_id || !preg_match('/^\d{4}-\d{2}$/', $month)) fail('player_id et month requis');
    $header = is_array($b['header'] ?? null) ? $b['header'] : [];
    $revenue = is_array($b['revenue'] ?? null) ? $b['revenue'] : [];
    $charges = is_array($b['charges'] ?? null) ? $b['charges'] : [];
    $divers = is_array($b['divers'] ?? null) ? $b['divers'] : [];

    $pdo = db();
    $st = $pdo->prepare('SELECT first_name, last_name FROM players WHERE id = ?');
    $st->execute([$player_id]);
    $player = $st->fetch();
    if (!$player) fail('Joueur introuvable', 404);

    require_once __DIR__ . '/../lib/vendor/autoload.php';
    $html = render_payslip_pdf_html($header, $revenue, s($b, 'gross_total'), $charges, s($b, 'charges_total'), $divers, s($b, 'divers_total'), s($b, 'net'), s($b, 'charges_note'));

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfContent = $dompdf->output();

    $dir = DB_DIR . '/payslips';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
    $filename = "player_{$player_id}_{$month}.pdf";
    $path = $dir . '/' . $filename;
    $origName = "{$month}_Décompte_{$player['last_name']}_{$player['first_name']}.pdf";
    file_put_contents($path, $pdfContent);

    $existing = $pdo->prepare('SELECT id, payslip_path FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
    $existing->execute(['player', $player_id, $month]);
    $exRow = $existing->fetch();
    if ($exRow) {
      $pdo->prepare('UPDATE payroll_payments SET payslip_path=?, payslip_filename=?, sent_at=NULL WHERE id=?')->execute([$path, $origName, $exRow['id']]);
    } else {
      $pdo->prepare('INSERT INTO payroll_payments (person_type, person_id, period, payslip_path, payslip_filename) VALUES (?,?,?,?,?)')
        ->execute(['player', $player_id, $month, $path, $origName]);
    }

    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($origName) . '"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
    exit;
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
      $st = db()->prepare('INSERT INTO players (first_name,last_name,email,phone,iban,salaire_mensuel,is_guest,poste,date_naissance,adresse,npa,ville,
        avs_subject,ac_subject,amat_subject,aanp_subject,laac_subject,ijm_subject,avs_abatement) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([s($b,'first_name'), $ln, s($b,'email'), s($b,'phone'), s($b,'iban'), f($b,'salaire_mensuel'), bo($b,'is_guest',false)?1:0, $poste, s($b,'date_naissance'), s($b,'adresse'), s($b,'npa'), s($b,'ville'),
        bo($b,'avs_subject',false)?1:0, bo($b,'ac_subject',false)?1:0, bo($b,'amat_subject',false)?1:0, bo($b,'aanp_subject',false)?1:0, bo($b,'laac_subject',false)?1:0, bo($b,'ijm_subject',false)?1:0, bo($b,'avs_abatement',false)?1:0]);
      out(['id' => (int)db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); if (!$id) fail('id requis');
      $poste = s($b, 'poste');
      if ($poste !== '' && !in_array($poste, ['gardien', 'defenseur', 'milieu', 'attaquant'], true)) fail('poste invalide');
      $st = db()->prepare('UPDATE players SET first_name=?,last_name=?,email=?,phone=?,iban=?,salaire_mensuel=?,active=?,poste=?,date_naissance=?,adresse=?,npa=?,ville=?,
        avs_subject=?,ac_subject=?,amat_subject=?,aanp_subject=?,laac_subject=?,ijm_subject=?,avs_abatement=? WHERE id=?');
      $st->execute([s($b,'first_name'), s($b,'last_name'), s($b,'email'), s($b,'phone'), s($b,'iban'), f($b,'salaire_mensuel'), bo($b,'active',true)?1:0, $poste, s($b,'date_naissance'), s($b,'adresse'), s($b,'npa'), s($b,'ville'),
        bo($b,'avs_subject',false)?1:0, bo($b,'ac_subject',false)?1:0, bo($b,'amat_subject',false)?1:0, bo($b,'aanp_subject',false)?1:0, bo($b,'laac_subject',false)?1:0, bo($b,'ijm_subject',false)?1:0, bo($b,'avs_abatement',false)?1:0, $id]);
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
