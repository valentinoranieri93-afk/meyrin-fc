<?php
/**
 * Tests du barème d'impôt à la source (lib_payroll_taxsource.php).
 * Lancer :  php rh/tests/taxsource.test.php
 * N'écrit que dans un sqlite temporaire, jamais dans data/rh.sqlite.
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib_payroll_taxsource.php';

$FAILS = 0;
function check(string $label, bool $ok): void {
  global $FAILS;
  echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
  if (!$ok) $FAILS++;
}
function eqf(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) < $eps; }

/* --- Fichier d'exemple : la vraie grille GE 2026 déposée dans context/import --------- */
$file = __DIR__ . '/../../../../../context/import/tar26ge.txt';
if (!is_file($file)) {
  // repli : chemin relatif au repo si la structure de dossiers diffère
  $alt = getenv('TAR_FILE');
  if ($alt && is_file($alt)) $file = $alt;
}
if (!is_file($file)) { echo "Fichier barème introuvable ($file). Passer TAR_FILE=... en variable d'env.\n"; exit(1); }

$raw = file_get_contents($file);
$parsed = taxsource_parse_estv($raw);

echo "== parse ==\n";
check('aucune erreur de parsing', $parsed['errors'] === []);
check('canton GE', $parsed['canton'] === 'GE');
check('année 2026', $parsed['year'] === 2026);
check('date de génération lue', $parsed['generated_at'] === '20251025');
check('codes A0N et C2N présents', in_array('A0N', $parsed['codes'], true) && in_array('C2N', $parsed['codes'], true));
check('barèmes L/M/N/P frontaliers allemands présents dans le fichier', in_array('L0N', $parsed['codes'], true));

/* --- Stockage dans un sqlite temporaire ------------------------------------------------ */
$tmp = tempnam(sys_get_temp_dir(), 'taxsrc') . '.sqlite';
$pdo = new PDO('sqlite:' . $tmp);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE is_bareme_rates (canton TEXT NOT NULL, year INTEGER NOT NULL, code TEXT NOT NULL,
  income_from INTEGER NOT NULL, income_to INTEGER, min_tax INTEGER NOT NULL DEFAULT 0, rate_pct REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (canton, year, code, income_from))");
$pdo->exec("CREATE TABLE is_bareme_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, canton TEXT, year INTEGER,
  filename TEXT, generated_at TEXT, rows_imported INTEGER, codes_json TEXT, created_at TEXT DEFAULT (datetime('now')))");

$inserted = taxsource_store($pdo, $parsed, 'tar26ge.txt');
echo "== store ($inserted lignes) ==\n";
check('lignes insérées == lignes parsées', $inserted === count($parsed['rates']));

/* --- Tranche ouverte : la ligne de plus haut revenu de chaque code a income_to NULL --- */
$open = $pdo->query("SELECT COUNT(*) c FROM is_bareme_rates WHERE code='A0N' AND income_to IS NULL")->fetch();
check('A0N : exactement une tranche ouverte', (int)$open['c'] === 1);

/* --- taxsource_rate : cas nominaux --------------------------------------------------- */
echo "== taxsource_rate ==\n";
$r = taxsource_rate($pdo, 'GE', 2026, 'A0N', 245000);   // 2 450.00
check('A0N @ 2 450.- => 0.09 %', $r !== null && eqf($r['rate_pct'], 0.09));

$r = taxsource_rate($pdo, 'GE', 2026, 'C2N', 970000);   // 9 700.00
check('C2N @ 9 700.- => 9.42 %', $r !== null && eqf($r['rate_pct'], 9.42));

$r = taxsource_rate($pdo, 'GE', 2026, 'A0N', 100000);   // 1 000.00, sous la 1re tranche imposable
check('A0N @ 1 000.- => 0 % (sous le seuil)', $r !== null && eqf($r['rate_pct'], 0.0));

$r = taxsource_rate($pdo, 'GE', 2026, 'A0N', 5000000000); // 50 000 000.-, bien au-delà du plafond
check('A0N très haut revenu => taux plafond 40.52 %', $r !== null && eqf($r['rate_pct'], 40.52));

$r = taxsource_rate($pdo, 'GE', 2026, 'ZZZ', 500000);
check('code inconnu => null (repli manuel)', $r === null);

$r = taxsource_rate($pdo, 'VD', 2026, 'A0N', 500000);
check('canton non importé => null', $r === null);

/* --- bornes exactes de tranche ----------------------------------------------------- */
// La tranche C2N [9 700.00 ; 9 750.00[ porte 9.42 % ; 9 750.00 pile bascule sur la suivante.
$a = taxsource_rate($pdo, 'GE', 2026, 'C2N', 974999);
$b = taxsource_rate($pdo, 'GE', 2026, 'C2N', 975000);
check('borne haute exclusive : 9 749.99 => 9.42, 9 750.00 => 9.47',
  $a !== null && eqf($a['rate_pct'], 9.42) && $b !== null && eqf($b['rate_pct'], 9.47));

/* --- taxsource_available_codes ---------------------------------------------------- */
$codes = taxsource_available_codes($pdo, 'GE', 2026);
check('available_codes non vide et trié', $codes !== [] && $codes === array_values($codes) && $codes[0] === 'A0N');

/* --- taxsource_suggest_code ----------------------------------------------------- */
echo "== suggest_code ==\n";
check('célibataire sans enfant => A0N', taxsource_suggest_code('celibataire', false, 0) === 'A0N');
check('marié, conjoint sans activité, 0 enfant => B0N', taxsource_suggest_code('marie', false, 0) === 'B0N');
check('marié, deux revenus, 2 enfants => C2N', taxsource_suggest_code('marie', true, 2) === 'C2N');
check('divorcé avec 1 enfant => H1N', taxsource_suggest_code('divorce', false, 1) === 'H1N');

@unlink($tmp);

echo "\n" . ($FAILS === 0 ? "TOUS LES TESTS PASSENT\n" : "$FAILS test(s) en échec\n");
exit($FAILS === 0 ? 0 : 1);
