<?php
/**
 * Moteur de calcul de paie — module RH Meyrin FC.
 *
 * Remplace le calcul qui vivait jusqu'ici côté navigateur (index.html, buildAutoLines /
 * buildPayslipPayload / renderPayslipDoc et leurs équivalents joueur). Une seule fonction,
 * compute_payslip(), sert à la fois l'aperçu en direct (action 'payslip_compute') et la
 * validation qui persiste et verrouille le décompte (action 'payslip_pdf').
 *
 * Portée de cette phase 1 (voir GAP-ANALYSIS-RH.md) : bases distinctes par assurance avec
 * plafonds (AC/LAA/LPP), franchise rentier (employés). L'abattement minime importance reste
 * réservé aux joueurs (players.avs_abatement, mécanisme inchangé) : une généralisation aux
 * employés a été tentée puis annulée le 2026-08-16, faute d'interface pour la piloter, elle
 * abattait tout employé par défaut y compris le personnel administratif. Restent hors phase 1 :
 * projection annuelle automatique du seuil de 2'500 avec régularisation rétroactive (§6.1.1
 * SPEC), modèle d'impôt à la source annuel genevois (§6.4), unification joueurs/employés.
 *
 * Rien ici ne dépend de $_GET/$_POST ni des helpers out()/fail() de api.php : ce fichier est
 * pur calcul, testable indépendamment du routeur.
 */

declare(strict_types=1);

/** Libellé français d'un mois ("2026-07" -> "Juillet 2026"), miroir de PAYSLIP_MONTH_LABELS
 * dans index.html, pour l'en-tête du PDF. */
function payslip_month_label(string $month): string {
  $labels = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $mm)) return $month;
  $idx = ((int)$mm[2]) - 1;
  $name = $labels[$idx] ?? '';
  return $name ? (ucfirst($name) . ' ' . $mm[1]) : $month;
}

/** Formatage fr-CH d'un montant (apostrophe comme séparateur de milliers, virgule décimale,
 * 2 décimales) — équivalent serveur de chf() dans index.html. Utilisé uniquement pour
 * composer le PDF (render_payslip_pdf_html attend des chaînes déjà mises en forme, comme
 * avant cette phase) ; le JSON de payslip_compute renvoie des nombres bruts, pas ce format. */
function chf_fmt(?float $n): string { return number_format((float)($n ?? 0), 2, ',', "'"); }

/** Équivalent serveur de pctSmart() : jusqu'à 3 décimales, sans décimale superflue pour un
 * taux rond, nécessaire pour l'AMat genevois (0,029 %) sans écraser 5,3 % en "5". */
function pct_fmt(float $n): string {
  $v = round($n, 3);
  $isWhole = abs($v - round($v)) < 0.0001;
  $formatted = number_format($v, 3, ',', "'");
  if (str_contains($formatted, ',')) $formatted = rtrim(rtrim($formatted, '0'), ',');
  if (!$isWhole && !str_contains($formatted, ',')) $formatted .= ',0';
  return $formatted . ' %';
}

/** Les six lignes de charges à taux partagé (Paramètres > Paie), dans l'ordre où elles
 * apparaissent sur le décompte. Miroir exact de SHARED_SOCIAL_LINES dans index.html —
 * garder les deux synchronisés si une ligne est ajoutée. */
function payroll_shared_lines_catalog(): array {
  return [
    'avs'  => 'AVS / AI / APG',
    'ac'   => 'Assurance chômage (AC)',
    'amat' => 'Assurance maternité (AMat GE)',
    'aanp' => 'Accidents non professionnels (AANP)',
    'laac' => 'Accidents complémentaire (LAAC)',
    'ijm'  => 'Indemnités journalières maladie',
  ];
}

/** Clés de lignes à taux partagé plafonnées par le gain maximum LAA (148'200 en 2026) :
 * accidents et leurs compléments suivent ce plafond en droit suisse. AC a son propre
 * plafond (identique en valeur en 2026, mais paramétré séparément — les deux peuvent
 * diverger si la LAA et l'AC sont révisées à des dates différentes). AVS/AMat/IJM ne sont
 * volontairement pas plafonnées ici (l'AVS ne l'est jamais ; AMat/IJM suivent le contrat
 * d'assurance réel du club, non modélisé en détail dans cette phase). */
function payroll_laa_family_keys(): array { return ['aanp', 'laac']; }

/** Valide la clé de contrôle d'un numéro AVS suisse (format 756.XXXX.XXXX.XC), algorithme
 * EAN-13 : chaque chiffre pondéré alternativement par 1 et 3 en partant de la droite (hors
 * clé), la clé est ce qui complète le total au multiple de 10 supérieur. Rejette aussi un
 * numéro absent ou mal formé — c'est un contrôle bloquant [9.1] SPEC, pas juste indicatif. */
function avs_number_checksum_valid(string $avs): bool {
  $digits = preg_replace('/\D/', '', $avs);
  if (strlen($digits) !== 13 || !str_starts_with($digits, '756')) return false;
  $sum = 0;
  for ($i = 0; $i < 12; $i++) {
    $sum += (int)$digits[$i] * ($i % 2 === 0 ? 1 : 3);
  }
  $checkDigit = (10 - ($sum % 10)) % 10;
  return $checkDigit === (int)$digits[12];
}

/**
 * Calcule le décompte d'une personne pour une période.
 *
 * @param PDO    $pdo
 * @param string $personType 'employee' | 'player'
 * @param int    $personId
 * @param string $month      'YYYY-MM'
 * @param array  $manual     ['season_id'?, 'indemnites' => [{label,montant,account_number?,account_label?}],
 *                            'retenues' => [{label,montant}], 'overrides' => {ligne_key: montant|null}]
 * @return array Structure complète du décompte (bases, taux, montants, lignes de référence
 *               légale, avertissements de contrôle). Ne persiste ni ne verrouille rien.
 */
function compute_payslip(PDO $pdo, string $personType, int $personId, string $month, array $manual = []): array {
  if (!in_array($personType, ['employee', 'player'], true)) {
    throw new InvalidArgumentException('person_type invalide');
  }
  if (!preg_match('/^(\d{4})-\d{2}$/', $month, $mm)) {
    throw new InvalidArgumentException('month invalide (attendu YYYY-MM)');
  }
  $year = (int) $mm[1];
  $rs = payroll_rate_settings_for_year($pdo, $year);
  $indemnites = is_array($manual['indemnites'] ?? null) ? $manual['indemnites'] : [];
  $retenues   = is_array($manual['retenues'] ?? null) ? $manual['retenues'] : [];
  $overrides  = is_array($manual['overrides'] ?? null) ? $manual['overrides'] : [];

  $warnings = [];

  if ($personType === 'employee') {
    $st = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $st->execute([$personId]);
    $person = $st->fetch();
    if (!$person) throw new RuntimeException('Employé introuvable');

    $seasonId = (int) ($manual['season_id'] ?? 0) ?: ensure_current_season($pdo);
    $periodDiv = ($person['paiement'] === 'semestriel') ? 2 : 12;

    $asg = $pdo->prepare("SELECT ea.*, t.name AS team_name, tc.name AS category_name,
             po.label AS poste_label, po.account_number, po.account_label
      FROM employee_assignments ea
      JOIN postes po ON po.id = ea.poste_id
      LEFT JOIN teams t ON t.id = ea.team_id
      LEFT JOIN team_categories tc ON tc.id = ea.category_id
      WHERE ea.employee_id = ? AND ea.season_id = ? AND ea.active = 1");
    $asg->execute([$personId, $seasonId]);
    $assignments = $asg->fetchAll();

    $grossBase = 0.0;
    $revenueLines = [];
    foreach ($assignments as $a) {
      $periodAmount = (float)$a['montant'] / $periodDiv;
      $grossBase += $periodAmount;
      $label = $a['account_label'] ?: ($a['poste_label'] . ($a['team_name'] ? ' — ' . $a['team_name'] : ($a['category_name'] ? ' — ' . $a['category_name'] . ' (catégorie)' : '')));
      $revenueLines[] = ['label' => $label, 'montant' => round($periodAmount, 2)];
    }
    // Le brut projeté sur l'année sert de base au seuil LPP (§4.4) : la somme des montants
    // annuels des affectations, indépendamment de la périodicité de versement.
    $annualGross = array_sum(array_map(fn($a) => (float)$a['montant'], $assignments));

  } else {
    $st = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $st->execute([$personId]);
    $person = $st->fetch();
    if (!$person) throw new RuntimeException('Joueur introuvable');

    $periodDiv = 1; // les joueurs sont toujours décomptés mensuellement, pas de bascule semestrielle
    $salaireMensuel = (float)($person['salaire_mensuel'] ?? 0);
    $pr = $pdo->prepare("SELECT COALESCE(SUM(mp.montant), 0) FROM match_players mp
      JOIN matches m ON m.id = mp.match_id WHERE mp.player_id = ? AND strftime('%Y-%m', m.date) = ?");
    $pr->execute([$personId, $month]);
    $primes = (float) $pr->fetchColumn();

    $grossBase = $salaireMensuel + $primes;
    $revenueLines = [];
    if ($salaireMensuel > 0) $revenueLines[] = ['label' => 'Salaire fixe', 'montant' => round($salaireMensuel, 2)];
    if ($primes > 0) $revenueLines[] = ['label' => 'Primes de match', 'montant' => round($primes, 2)];
    // Pas d'affectation annuelle pour un joueur : la projection LPP ne s'applique pas
    // à cette population (§C.1 addendum — hors périmètre LPP en phase 1).
    $annualGross = $grossBase * 12;
  }

  $extraTotal = array_sum(array_map(fn($l) => (float)($l['montant'] ?? 0), $indemnites));
  $grossTotal = $grossBase + $extraTotal;
  foreach ($indemnites as $l) {
    $indLabel = (string)($l['account_label'] ?? '') !== '' ? (string)$l['account_label'] : (string)($l['label'] ?? '—');
    $revenueLines[] = ['label' => $indLabel, 'montant' => round((float)($l['montant'] ?? 0), 2)];
  }
  // Regroupement par intitulé (compte comptable ou libellé de poste) : deux rôles ou une
  // indemnité rattachés au même compte apparaissent en une seule ligne sur le document
  // imprimé, plutôt qu'une ligne par affectation — comportement déjà en place côté écran
  // avant cette phase (groupedRevenueLines dans index.html), repris ici côté serveur.
  $groupedRevenue = [];
  foreach ($revenueLines as $l) {
    $key = $l['label'];
    if (isset($groupedRevenue[$key])) $groupedRevenue[$key]['montant'] += $l['montant'];
    else $groupedRevenue[$key] = $l;
  }
  $revenueLines = array_values(array_map(fn($l) => ['label' => $l['label'], 'montant' => round($l['montant'], 2)], $groupedRevenue));

  /* --- Statut AVS de la personne : franchise rentier / abattement minime importance -----
     Non cumulables (§6.2.4 SPEC, contrôle bloquant [9.2]).

     L'abattement minime importance reste RÉSERVÉ AUX JOUEURS (players.avs_abatement, mécanisme
     inchangé depuis avant cette phase), comme avant cette phase — pas de généralisation aux
     employés. Revert du 2026-08-16 : la généralisation initiale traitait avs_minime_renonce=0
     comme "abattement actif par défaut, sauf renonciation explicite", or aucune interface ne
     permet de cocher cette renonciation → tout employé (y compris le personnel administratif,
     jamais éligible à cet arrangement) se voyait abattu par défaut, sans recours. Découvert sur
     la fiche de Valentino lui-même. La colonne `employees.avs_minime_renonce` reste en base
     (migration additive déjà déployée, inoffensive) mais n'est plus lue ici. */
  $isRentier = $personType === 'employee' && (int)($person['avs_rentier'] ?? 0) === 1 && (int)($person['avs_franchise_renonce'] ?? 0) !== 1;
  $isMinimeAbated = $personType === 'player' && (int)($person['avs_abatement'] ?? 0) === 1;
  $nonCumulViolation = $isRentier && $isMinimeAbated;
  if ($nonCumulViolation) {
    $warnings[] = "Franchise rentier et abattement minime importance sont actifs en même temps sur cette personne : les deux ne peuvent pas se cumuler (§6.2.4). Corriger avant de valider.";
  }

  $chargeReduction = 0.0;
  $chargeReductionLabel = '';
  if ($isRentier && !$nonCumulViolation) {
    $chargeReduction = $periodDiv === 2 ? (float)$rs['avs_rentier_franchise_annual'] / 2 : (float)$rs['avs_rentier_franchise_monthly'];
    $chargeReductionLabel = 'Franchise rentier : − CHF ' . chf_fmt($chargeReduction);
  } elseif ($isMinimeAbated && !$nonCumulViolation) {
    // Abattement joueur (players.avs_abatement, réservé à cette population, voir plus haut) :
    // le montant est paramétré et daté (payroll_rate_settings.avs_minime_abatement_semestriel,
    // modifiable dans Paramètres > Paie), plus une constante JS en dur. Périmètre : appliqué à
    // toutes les charges sociales à taux partagé (AVS, AC, AMat, AANP, LAAC, IJM), pas
    // seulement à l'AVS (confirmé par Valentino, 2026-08-16).
    $monthlyAbatement = (float)$rs['avs_minime_abatement_semestriel'] / 6;
    $chargeReduction = $periodDiv === 2 ? $monthlyAbatement * 6 : $monthlyAbatement;
    // Libellé demandé par Valentino (2026-08-16) : le montant réel déduit sur CE décompte,
    // pas une description abstraite du mécanisme.
    $chargeReductionLabel = 'Abattement OCAS : − CHF ' . chf_fmt($chargeReduction);
  }

  /* --- Bases par assurance, avec plafonds, puis cotisations ----------------------------- */
  $chargeLines = [];
  $legalReferenceLines = [];
  foreach (payroll_shared_lines_catalog() as $key => $label) {
    if ((int)($person[$key . '_subject'] ?? 0) !== 1) continue;
    $rate = (float)($rs[$key . '_rate'] ?? 0);

    $base = $grossTotal;
    if ($key === 'ac') $base = min($base, (float)$rs['ac_ceiling']);
    if (in_array($key, payroll_laa_family_keys(), true)) $base = min($base, (float)$rs['laa_ceiling']);

    $legalBase = $base; // avant réduction franchise/abattement
    if ($chargeReduction > 0) {
      $base = max(0.0, $base - $chargeReduction);
    }

    $amount = round($base * $rate / 100, 2);
    $overrideKey = $key;
    if (array_key_exists($overrideKey, $overrides) && $overrides[$overrideKey] !== null && $overrides[$overrideKey] !== '') {
      $amount = round((float)$overrides[$overrideKey], 2);
    }
    $chargeLines[] = ['key' => $key, 'label' => $label, 'base' => round($base, 2), 'rate' => $rate, 'amount' => $amount];

    // Double calcul légal/arrangement (§C.2.1.c addendum), pour chaque assiette réellement
    // réduite : sert à mesurer l'exposition du club si l'arrangement OCAS (aujourd'hui sans
    // trace écrite, voir GAP-ANALYSIS-RH.md) était un jour remis en cause — jamais utilisé
    // dans le calcul du net.
    if ($chargeReduction > 0 && $rate > 0) {
      $legalAmount = round($legalBase * $rate / 100, 2);
      $legalReferenceLines[] = ['key' => $key . '_legal', 'label' => "$label — référence légale stricte ($chargeReductionLabel non appliqué)",
                                 'base' => round($legalBase, 2), 'rate' => $rate, 'amount' => $legalAmount, 'is_legal_reference' => true];
    }
  }

  /* --- LPP : seuil d'entrée et coordination (employés uniquement, §4.4 SPEC) ------------- */
  if ($personType === 'employee' && (int)($person['lpp_subject'] ?? 0) === 1) {
    if ($annualGross < (float)$rs['lpp_entry_threshold']) {
      $warnings[] = 'LPP cochée mais le brut annuel projeté (' . round($annualGross, 2) . ') est sous le seuil d\'entrée (' . $rs['lpp_entry_threshold'] . ') : ligne ignorée.';
    } else {
      $coordinated = max((float)$rs['lpp_coordinated_min'], min($annualGross, (float)$rs['lpp_ceiling']) - (float)$rs['lpp_coordination_deduction']);
      $periodCoordinated = $coordinated / $periodDiv;
      $rate = (float)($person['lpp_rate'] ?? 0);
      $amount = $rate > 0 ? round($periodCoordinated * $rate / 100, 2) : round((float)($person['lpp_amount'] ?? 0), 2);
      if (array_key_exists('lpp', $overrides) && $overrides['lpp'] !== null && $overrides['lpp'] !== '') {
        $amount = round((float)$overrides['lpp'], 2);
      }
      $chargeLines[] = ['key' => 'lpp', 'label' => 'Prévoyance professionnelle (LPP)', 'base' => round($periodCoordinated, 2), 'rate' => $rate, 'amount' => $amount];
    }
  }

  /* --- Impôt à la source : hors phase 1, saisie personnelle reprise telle quelle --------- */
  if ($personType === 'employee' && (int)($person['is_subject'] ?? 0) === 1) {
    $rate = (float)($person['is_rate'] ?? 0);
    $amount = $rate > 0 ? round($grossTotal * $rate / 100, 2) : round((float)($person['is_amount'] ?? 0), 2);
    if (array_key_exists('is', $overrides) && $overrides['is'] !== null && $overrides['is'] !== '') {
      $amount = round((float)$overrides['is'], 2);
    }
    $chargeLines[] = ['key' => 'is', 'label' => 'Impôt à la source', 'base' => round($grossTotal, 2), 'rate' => $rate, 'amount' => $amount];
    $warnings[] = 'Impôt à la source calculé sur un taux/montant saisi manuellement : le modèle annuel genevois (barème AFC-GE) n\'est pas encore implémenté (§6.4 SPEC).';
  }

  /* --- Retenue lavage (joueurs, déjà paramétrée depuis le 2026-08-14) --------------------- */
  $diversLines = [];
  if ($personType === 'player' && $grossTotal > (float)$rs['lavage_threshold']) {
    $diversLines[] = ['label' => 'Retenue lavage', 'montant' => round((float)$rs['lavage_amount'], 2)];
  }
  foreach ($retenues as $l) {
    $diversLines[] = ['label' => (string)($l['label'] ?? '—'), 'montant' => round((float)($l['montant'] ?? 0), 2)];
  }

  $chargesTotal = round(array_sum(array_column($chargeLines, 'amount')), 2);
  $diversTotal  = round(array_sum(array_column($diversLines, 'amount')), 2);
  $net = round($grossTotal - $chargesTotal - $diversTotal, 2);
  if ($net < 0) $warnings[] = 'Net négatif (' . $net . ') : les retenues dépassent le brut.';

  if ($personType === 'employee' && !avs_number_checksum_valid((string)($person['avs_number'] ?? ''))) {
    $warnings[] = 'N° AVS manquant ou clé de contrôle invalide.';
  }

  // hook posé avant le return, voir persist_payslip_lines() plus bas pour la persistance
  return [
    'person_type' => $personType,
    'person_id' => $personId,
    'month' => $month,
    'period_div' => $periodDiv,
    'params_year' => $year,
    'gross_base' => round($grossBase, 2),
    'gross_total' => round($grossTotal, 2),
    'revenue_lines' => $revenueLines,
    'charge_lines' => $chargeLines,
    'legal_reference_lines' => $legalReferenceLines,
    'divers_lines' => $diversLines,
    'charges_total' => $chargesTotal,
    'divers_total' => $diversTotal,
    'net' => $net,
    'avs_reduction_applied' => $chargeReductionLabel,
    'warnings' => $warnings,
    'blocking' => array_values(array_filter($warnings, fn($w) =>
      str_contains($w, 'Franchise rentier') || str_contains($w, 'N° AVS') || str_contains($w, 'Net négatif'))),
  ];
}

/**
 * Persiste le résultat de compute_payslip() : upsert de l'en-tête dans payroll_payments,
 * remplacement complet des lignes dans payroll_payment_lines. Verrouille l'en-tête
 * (locked=1) — à appeler uniquement au moment de la validation (génération du PDF), jamais
 * pour un aperçu. Refuse d'écrire si un décompte verrouillé existe déjà pour cette période :
 * un décompte validé est immuable (§5.4 SPEC), le corriger passe par un rectificatif, pas
 * par une réécriture — la gestion complète des rectificatifs reste hors phase 1, ce refus
 * est le garde-fou minimal en attendant.
 *
 * @return int L'id de la ligne payroll_payments.
 * @throws RuntimeException si un décompte verrouillé existe déjà pour cette période.
 */
function persist_payslip_lines(PDO $pdo, array $computed): int {
  $personType = $computed['person_type'];
  $personId = $computed['person_id'];
  $month = $computed['month'];

  $existing = $pdo->prepare('SELECT id, locked FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
  $existing->execute([$personType, $personId, $month]);
  $row = $existing->fetch();
  if ($row && (int)$row['locked'] === 1) {
    throw new RuntimeException('Ce décompte est déjà validé et verrouillé pour cette période. Une correction passe par un rectificatif (hors périmètre actuel).');
  }

  // Référence légale AVS spécifiquement (ligne d'exposition la plus importante, base de
  // l'arrangement OCAS) : les autres assiettes réduites (AC, AMat, AANP, LAAC, IJM) ont aussi
  // leur ligne de référence dans payroll_payment_lines (is_legal_reference=1), mais seule
  // celle de l'AVS est reprise en résumé sur l'en-tête payroll_payments.
  $legalAvs = null;
  foreach ($computed['legal_reference_lines'] as $l) if ($l['key'] === 'avs_legal') { $legalAvs = $l['amount']; break; }

  if ($row) {
    $paymentId = (int)$row['id'];
    $pdo->prepare('UPDATE payroll_payments SET gross_total=?, net_amount=?, legal_reference_avs=?, params_year=?, computed_at=datetime(\'now\'), locked=1 WHERE id=?')
      ->execute([$computed['gross_total'], $computed['net'], $legalAvs, $computed['params_year'], $paymentId]);
  } else {
    $pdo->prepare('INSERT INTO payroll_payments (person_type, person_id, period, gross_total, net_amount, legal_reference_avs, params_year, computed_at, locked)
      VALUES (?,?,?,?,?,?,?,datetime(\'now\'),1)')
      ->execute([$personType, $personId, $month, $computed['gross_total'], $computed['net'], $legalAvs, $computed['params_year']]);
    $paymentId = (int)$pdo->lastInsertId();
  }

  $pdo->prepare('DELETE FROM payroll_payment_lines WHERE payment_id=?')->execute([$paymentId]);
  $ins = $pdo->prepare('INSERT INTO payroll_payment_lines (payment_id, kind, line_key, label, base, rate, amount, is_legal_reference, sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
  $order = 0;
  foreach ($computed['revenue_lines'] as $l) {
    $ins->execute([$paymentId, 'revenue', '', $l['label'], null, null, $l['montant'], 0, $order++]);
  }
  foreach ($computed['charge_lines'] as $l) {
    $ins->execute([$paymentId, 'charge', $l['key'], $l['label'], $l['base'], $l['rate'], $l['amount'], 0, $order++]);
  }
  foreach ($computed['legal_reference_lines'] as $l) {
    $ins->execute([$paymentId, 'charge', $l['key'], $l['label'], $l['base'], $l['rate'], $l['amount'], 1, $order++]);
  }
  foreach ($computed['divers_lines'] as $l) {
    $ins->execute([$paymentId, 'divers', '', $l['label'], null, null, $l['montant'], 0, $order++]);
  }

  return $paymentId;
}
