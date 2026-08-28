<?php
/**
 * Impôt à la source — lecture du barème officiel ESTV et calcul du taux mensuel.
 *
 * Le fichier importé est le fichier standard de l'Administration fédérale des
 * contributions (« Aufbau und Recordformate der Quellensteuer-Tarife », Recordart 06),
 * celui que consomment tous les logiciels de paie suisses. Une grille par canton et
 * par année civile, importée une fois par an dans Paramètres > Paie.
 *
 * Genève applique le modèle mensuel : le taux dépend du revenu brut du mois
 * (salaire + primes + indemnités), primes et 13e inclus au mois où ils sont versés.
 * compute_payslip() somme déjà tout ça dans $grossTotal, donc rien à changer côté
 * assiette : ce fichier ne fournit que la correspondance revenu -> taux.
 *
 * Pur calcul, aucune dépendance à $_GET/$_POST ni aux helpers de api.php.
 *
 * --- Format d'une ligne Recordart 06 (positions 1-based, largeur fixe) ---------
 *   01-02  2   Recordart                      "06"
 *   03-04  2   Transaktionsart                (ignoré)
 *   05-06  2   Canton                         "GE"
 *   07-16  10  Code de tarif                  "A0N", "C2N"... cadré à gauche
 *   17-24  8   Valable dès                    JJJJMMTT -> année de la grille
 *   25-33  9   Revenu mensuel dès Fr.         entier, 2 décimales implicites
 *   34-42  9   Pas de la tranche Fr.          entier, 2 décimales implicites
 *   43-43  1   Code sexe                      (vide)
 *   44-45  2   Nombre d'enfants               00..09
 *   46-54  9   Impôt minimum Fr.              entier, 2 décimales implicites
 *   55-59  5   Taux d'impôt %                 entier, 2 décimales implicites
 *   60-62  3   Code statut                    (vide)
 *
 * Les autres Recordart : 00 en-tête (canton + date de génération), 99 pied
 * (nombre total de lignes du fichier, sert au contrôle d'intégrité), 11/12/13
 * catégories prédéfinies (hors périmètre RH d'un club, ignorées).
 */

declare(strict_types=1);

/** Découpe le contenu brut d'un fichier ESTV. Ne touche pas la base : renvoie une
 * structure que l'appelant valide (aperçu) puis persiste (voir taxsource_store()).
 *
 * @return array{
 *   ok: bool,
 *   canton: string,
 *   year: int,
 *   generated_at: string,
 *   rates: list<array{canton:string,year:int,code:string,income_from:int,income_to:?int,min_tax:int,rate_pct:float}>,
 *   codes: list<string>,
 *   ignored: array<string,int>,
 *   line_count: int,
 *   trailer_count: ?int,
 *   errors: list<string>
 * }
 * Montants (income_from, income_to, min_tax) en centimes ; rate_pct en pourcent (9.42).
 */
function taxsource_parse_estv(string $raw): array {
  $lines = preg_split('/\r\n|\n|\r/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

  $canton = '';
  $generatedAt = '';
  $year = 0;
  $trailerCount = null;
  $ignored = ['11' => 0, '12' => 0, '13' => 0, 'autre' => 0];
  $errors = [];
  $rows = [];          // code => list of [from, step, min_tax, rate_pct]
  $dataLineCount = 0;

  foreach ($lines as $no => $ln) {
    $rt = substr($ln, 0, 2);

    if ($rt === '00') {
      $canton = trim(substr($ln, 2, 2));
      // La date de génération (JJJJMMTT) figure dans l'en-tête après un remplissage d'espaces
      // de largeur variable selon l'émetteur : on prend la première suite de 8 chiffres.
      if (preg_match('/(\d{8})/', $ln, $m)) $generatedAt = $m[1];
      continue;
    }
    if ($rt === '99') {
      // "GE00032073" : le nombre est en fin de champ, préfixé du canton. On garde les chiffres.
      $digits = preg_replace('/\D+/', '', substr($ln, 2));
      $trailerCount = ($digits === '') ? null : (int) $digits;
      continue;
    }
    if ($rt === '06') {
      if (strlen($ln) < 59) { $errors[] = 'Ligne ' . ($no + 1) . ' tronquée (Recordart 06).'; continue; }
      $lineCanton = trim(substr($ln, 4, 2));
      $code       = rtrim(substr($ln, 6, 10));
      $datum      = substr($ln, 16, 8);
      $from       = (int) substr($ln, 24, 9);   // centimes
      $step       = (int) substr($ln, 33, 9);   // centimes
      $minTax     = (int) substr($ln, 45, 9);   // centimes
      $rateInt    = (int) substr($ln, 54, 5);   // centièmes de pourcent

      if ($code === '' || $datum === '') { $errors[] = 'Ligne ' . ($no + 1) . ' : code ou date manquant.'; continue; }
      $lineYear = (int) substr($datum, 0, 4);
      if ($year === 0) $year = $lineYear;
      if ($canton === '') $canton = $lineCanton;

      $rows[$code][] = [$from, $step, $minTax, round($rateInt / 100, 2)];
      $dataLineCount++;
      continue;
    }

    // Recordart 11 / 12 / 13 : comptés pour l'aperçu, pas stockés.
    if (isset($ignored[$rt])) { $ignored[$rt]++; }
    else { $ignored['autre']++; }
  }

  if ($year === 0)   $errors[] = 'Aucune ligne de tarif (Recordart 06) trouvée dans le fichier.';
  if ($canton === '') $errors[] = 'Canton absent du fichier.';

  // Chaque code : ligne de plus haut revenu = tranche ouverte (income_to NULL, taux plafond
  // au-delà). Les autres : [from, from + pas[.
  $rates = [];
  $codes = array_keys($rows);
  sort($codes);
  foreach ($codes as $code) {
    $list = $rows[$code];
    usort($list, fn($a, $b) => $a[0] <=> $b[0]);
    $lastIdx = count($list) - 1;
    foreach ($list as $idx => [$from, $step, $minTax, $ratePct]) {
      $rates[] = [
        'canton'      => $canton,
        'year'        => $year,
        'code'        => $code,
        'income_from' => $from,
        'income_to'   => $idx === $lastIdx ? null : $from + $step,
        'min_tax'     => $minTax,
        'rate_pct'    => $ratePct,
      ];
    }
  }

  $totalLines = count($lines);
  if ($trailerCount !== null && $trailerCount !== $totalLines) {
    $errors[] = "Contrôle d'intégrité : le pied de fichier annonce $trailerCount lignes, "
              . "le fichier en contient $totalLines.";
  }

  return [
    'ok'           => $errors === [],
    'canton'       => $canton,
    'year'         => $year,
    'generated_at' => $generatedAt,
    'rates'        => $rates,
    'codes'        => $codes,
    'ignored'      => array_filter($ignored),
    'line_count'   => $dataLineCount,
    'trailer_count'=> $trailerCount,
    'errors'       => $errors,
  ];
}

/** Persiste une grille parsée : remplace intégralement (canton, année). L'appelant
 * s'occupe de la sauvegarde du fichier sqlite avant d'appeler ceci.
 * @param array $parsed sortie de taxsource_parse_estv()
 * @return int nombre de lignes de tarif insérées
 */
function taxsource_store(PDO $pdo, array $parsed, string $filename): int {
  $canton = $parsed['canton'];
  $year   = $parsed['year'];
  if ($canton === '' || $year === 0) throw new InvalidArgumentException('Grille invalide : canton ou année manquant.');

  $pdo->beginTransaction();
  try {
    $pdo->prepare('DELETE FROM is_bareme_rates WHERE canton = ? AND year = ?')->execute([$canton, $year]);
    $ins = $pdo->prepare('INSERT INTO is_bareme_rates
      (canton, year, code, income_from, income_to, min_tax, rate_pct) VALUES (?,?,?,?,?,?,?)');
    foreach ($parsed['rates'] as $r) {
      $ins->execute([$r['canton'], $r['year'], $r['code'], $r['income_from'], $r['income_to'], $r['min_tax'], $r['rate_pct']]);
    }
    $pdo->prepare('DELETE FROM is_bareme_imports WHERE canton = ? AND year = ?')->execute([$canton, $year]);
    $pdo->prepare('INSERT INTO is_bareme_imports
      (canton, year, filename, generated_at, rows_imported, codes_json) VALUES (?,?,?,?,?,?)')
      ->execute([$canton, $year, $filename, $parsed['generated_at'], count($parsed['rates']),
                 json_encode($parsed['codes'], JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  return count($parsed['rates']);
}

/** Taux applicable à un revenu mensuel déterminant, pour un code de barème donné.
 * @param int $incomeCents revenu déterminant pour le taux, en centimes
 * @return array{rate_pct: float, min_tax: int}|null null si aucune grille pour ce
 *         (canton, année, code) — l'appelant retombe alors sur la saisie manuelle.
 */
function taxsource_rate(PDO $pdo, string $canton, int $year, string $code, int $incomeCents): ?array {
  $code = trim($code);
  if ($code === '' || $canton === '') return null;
  $st = $pdo->prepare('SELECT rate_pct, min_tax FROM is_bareme_rates
    WHERE canton = ? AND year = ? AND code = ? AND income_from <= ?
      AND (income_to IS NULL OR ? < income_to)
    ORDER BY income_from DESC LIMIT 1');
  $st->execute([$canton, $year, $code, $incomeCents, $incomeCents]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    // Revenu sous la première tranche : taux 0 explicite si la grille existe pour ce code.
    $exists = $pdo->prepare('SELECT 1 FROM is_bareme_rates WHERE canton = ? AND year = ? AND code = ? LIMIT 1');
    $exists->execute([$canton, $year, $code]);
    if ($exists->fetchColumn()) return ['rate_pct' => 0.0, 'min_tax' => 0];
    return null;
  }
  return ['rate_pct' => (float) $row['rate_pct'], 'min_tax' => (int) $row['min_tax']];
}

/** Codes de barème réellement disponibles pour un canton/année, pour peupler le menu
 * déroulant de la fiche. Retour trié.
 * @return list<string>
 */
function taxsource_available_codes(PDO $pdo, string $canton, int $year): array {
  $st = $pdo->prepare('SELECT DISTINCT code FROM is_bareme_rates WHERE canton = ? AND year = ? ORDER BY code');
  $st->execute([$canton, $year]);
  return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** Suggestion de code de barème à partir de la situation familiale saisie sur la fiche.
 * Genève n'émet que des codes « N » (sans impôt ecclésiastique). Ne remplace jamais un
 * choix explicite, sert seulement de valeur par défaut proposée à l'écran.
 */
function taxsource_suggest_code(string $civilStatus, bool $spouseWorks, int $children): string {
  $n = max(0, min(9, $children));
  $married = in_array($civilStatus, ['marie', 'partenariat'], true);
  if ($married) return ($spouseWorks ? 'C' : 'B') . $n . 'N';
  if (in_array($civilStatus, ['celibataire', 'divorce', 'separe', 'veuf'], true) && $n > 0) return 'H' . max(1, $n) . 'N';
  return 'A' . $n . 'N';
}
