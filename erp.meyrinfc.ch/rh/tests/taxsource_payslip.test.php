<?php
/**
 * Test bout en bout : compute_payslip() applique le barème d'impôt à la source.
 * Travaille sur une COPIE de data/rh.sqlite, jamais l'original.
 * Lancer :  php rh/tests/taxsource_payslip.test.php
 */

declare(strict_types=1);

$root = __DIR__ . '/..';
$src  = $root . '/data/rh.sqlite';
if (!is_file($src)) { echo "data/rh.sqlite absent — test ignoré.\n"; exit(0); }
$tar = getenv('TAR_FILE') ?: (__DIR__ . '/../../../../../context/import/tar26ge.txt');
if (!is_file($tar)) { echo "Fichier barème introuvable — passer TAR_FILE=...\n"; exit(1); }

$tmp = tempnam(sys_get_temp_dir(), 'rhps') . '.sqlite';
copy($src, $tmp);

$pdo = new PDO('sqlite:' . $tmp);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// --- Colonnes / tables ajoutées par init_schema (reproduites ici sans bootstrap api.php)
function add_col(PDO $p, string $t, string $c, string $def): void {
  $cols = $p->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array($c, $cols, true)) $p->exec("ALTER TABLE $t ADD COLUMN $c $def");
}
add_col($pdo, 'employees', 'is_rate_determining_income', 'REAL NOT NULL DEFAULT 0');
$pdo->exec("CREATE TABLE IF NOT EXISTS is_bareme_rates (canton TEXT NOT NULL, year INTEGER NOT NULL, code TEXT NOT NULL,
  income_from INTEGER NOT NULL, income_to INTEGER, min_tax INTEGER NOT NULL DEFAULT 0, rate_pct REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (canton, year, code, income_from))");
$pdo->exec("CREATE TABLE IF NOT EXISTS is_bareme_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, canton TEXT, year INTEGER,
  filename TEXT, generated_at TEXT, rows_imported INTEGER, codes_json TEXT, created_at TEXT DEFAULT (datetime('now')))");

// --- Stubs des helpers qui vivent dans api.php (hors périmètre de ce test unitaire)
$RS_ROW = null;
function payroll_rate_settings_for_year(PDO $pdo, int $year): array {
  $r = $pdo->query('SELECT * FROM payroll_rate_settings ORDER BY ABS(year - ' . $year . ') LIMIT 1')->fetch();
  return $r ?: ['year' => $year];
}
function ensure_current_season(PDO $pdo): int {
  return (int) ($pdo->query('SELECT id FROM seasons ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 1);
}

require_once $root . '/lib_payroll_taxsource.php';
require_once $root . '/lib_payroll_engine.php';

$parsed = taxsource_parse_estv(file_get_contents($tar));
taxsource_store($pdo, $parsed, 'tar26ge.txt');
$year = $parsed['year'];
$month = $year . '-03';

// --- Employé de test : on en prend un existant et on force ses champs IS
$emp = $pdo->query('SELECT id FROM employees LIMIT 1')->fetch();
if (!$emp) { echo "aucun employé dans la base — test ignoré.\n"; @unlink($tmp); exit(0); }
$eid = (int) $emp['id'];

$FAILS = 0;
function check(string $l, bool $ok): void { global $FAILS; echo ($ok ? "  ok   " : "  FAIL ") . $l . "\n"; if (!$ok) $FAILS++; }
function is_line(array $c): ?array { foreach ($c['charge_lines'] as $l) if ($l['key'] === 'is') return $l; return null; }

function set_emp(PDO $pdo, int $id, array $f): void {
  $set = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
  $pdo->prepare("UPDATE employees SET $set WHERE id=?")->execute([...array_values($f), $id]);
}

echo "== barème appliqué ==\n";
set_emp($pdo, $eid, ['tax_at_source' => 1, 'tax_canton' => 'GE', 'tax_bareme' => 'C2N',
  'is_rate' => 0, 'is_amount' => 0, 'is_rate_determining_income' => 0]);
$c = compute_payslip($pdo, 'employee', $eid, $month, ['manual_gross_override' => null]);
// on ne connaît pas le brut de cet employé : on vérifie la cohérence interne
$l = is_line($c);
$gross = $c['gross_total'];
$expectedRate = taxsource_rate($pdo, 'GE', $year, 'C2N', (int) round($gross * 100));
check('ligne impôt à la source présente', $l !== null);
check('base = brut total', $l && abs($l['base'] - $gross) < 0.01);
check('taux = taux de la tranche du brut', $l && $expectedRate && abs($l['rate'] - $expectedRate['rate_pct']) < 0.001);
check('montant = brut x taux', $l && abs($l['amount'] - round($gross * $l['rate'] / 100, 2)) < 0.01);

echo "== revenu déterminant (autre employeur) ==\n";
set_emp($pdo, $eid, ['is_rate_determining_income' => $gross + 3000]);
$c2 = compute_payslip($pdo, 'employee', $eid, $month, []);
$l2 = is_line($c2);
$rateHigher = taxsource_rate($pdo, 'GE', $year, 'C2N', (int) round(($gross + 3000) * 100));
check('taux monte (revenu déterminant plus élevé)', $l2 && $rateHigher && abs($l2['rate'] - $rateHigher['rate_pct']) < 0.001 && $l2['rate'] >= $l['rate']);
check('base reste le brut Meyrin, pas le revenu déterminant', $l2 && abs($l2['base'] - $gross) < 0.01);

echo "== override taux manuel ==\n";
set_emp($pdo, $eid, ['is_rate_determining_income' => 0, 'is_rate' => 15]);
$c3 = compute_payslip($pdo, 'employee', $eid, $month, []);
$l3 = is_line($c3);
check('taux manuel 15 % appliqué, barème ignoré', $l3 && abs($l3['rate'] - 15.0) < 0.001);
check('warning « taux saisi manuellement »', (bool) array_filter($c3['warnings'], fn($w) => str_contains($w, 'manuellement')));

echo "== assujetti sans barème importé ==\n";
set_emp($pdo, $eid, ['is_rate' => 0, 'tax_bareme' => 'C2N', 'tax_canton' => 'VD']); // VD non importé
$c4 = compute_payslip($pdo, 'employee', $eid, $month, []);
$l4 = is_line($c4);
check('retenue nulle, pas d\'exception', $l4 && abs($l4['amount']) < 0.001);
check('warning explicite', (bool) array_filter($c4['warnings'], fn($w) => str_contains($w, 'aucun barème')));

@unlink($tmp);
echo "\n" . ($FAILS === 0 ? "TOUS LES TESTS PASSENT\n" : "$FAILS test(s) en échec\n");
exit($FAILS === 0 ? 0 : 1);
