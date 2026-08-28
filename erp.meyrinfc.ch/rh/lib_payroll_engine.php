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

require_once __DIR__ . '/lib_payroll_taxsource.php';

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

/** Affichage signé d'une ligne "Divers" sur le décompte PDF (2026-08-18) : une retenue (montant
 * positif) se lit "− X", un complément ajouté à la main (montant négatif, ex. trop prélevé le
 * mois précédent à rembourser) se lit "+ X" — jamais un signe "−" fixe, qui produirait "− -50,00"
 * pour un complément. Équivalent serveur de diversAmountDisplay() dans index.html. */
function divers_amount_fmt(?float $n): string {
  $v = (float)($n ?? 0);
  return ($v >= 0 ? '− ' : '+ ') . chf_fmt(abs($v));
}

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

/** Charges 100% employeur (2026-08-17) : aucune part employé, pas de case d'assujettissement
 * propre — calées sur payroll_shared_lines_catalog()['avs'] (assujettissement ET assiette,
 * salaire déterminant AVS, jamais plafonnée), décision de Valentino pour ne pas ajouter une
 * quatrième case à cocher sur chaque fiche. Miroir JS : PAIE_LINE_ACCOUNT_KEYS/labels dans
 * index.html (partie ajoutée) pour les paramètres de compte, pas de doublon des taux/labels
 * métier côté JS puisque ceux-ci ne s'affichent que via payroll_rate_settings. */
function payroll_employer_only_lines_catalog(): array {
  return [
    'scaf'        => 'Cotisation SCAF',
    'lfp'         => 'Cotisation LFP',
    'cpe'         => 'Cotisation CPE',
    'frais_admin' => "Frais d'administration",
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
             po.label AS poste_label, po.account_number, po.account_label, po.payslip_label
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
      // Priorité (2026-08-17) : intitulé propre au décompte (payslip_label, modifiable
      // librement dans Paramètres > Rôles), sinon le nom du compte comptable rattaché, sinon
      // le libellé du rôle + équipe/catégorie comme avant.
      $label = ($a['payslip_label'] ?: null) ?: ($a['account_label'] ?: ($a['poste_label'] . ($a['team_name'] ? ' — ' . $a['team_name'] : ($a['category_name'] ? ' — ' . $a['category_name'] . ' (catégorie)' : ''))));
      $revenueLines[] = ['label' => $label, 'montant' => round($periodAmount, 2),
        'account_number' => (string)($a['account_number'] ?? ''), 'account_label' => (string)($a['account_label'] ?? '')];
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
    // Même priorité que côté employé (2026-08-17) : payslip_label (texte libre, Paramètres >
    // Paie) sinon le nom du compte comptable rattaché, sinon l'intitulé générique d'origine.
    $salaryLabel = (string)($rs['player_salary_label'] ?? '') ?: ((string)($rs['player_salary_account_label'] ?? '') ?: 'Salaire fixe');
    $bonusLabel  = (string)($rs['player_bonus_label'] ?? '')  ?: ((string)($rs['player_bonus_account_label'] ?? '')  ?: 'Primes de match');
    if ($salaireMensuel > 0) $revenueLines[] = ['label' => $salaryLabel, 'montant' => round($salaireMensuel, 2),
      'account_number' => (string)($rs['player_salary_account_number'] ?? ''), 'account_label' => (string)($rs['player_salary_account_label'] ?? '')];
    if ($primes > 0) $revenueLines[] = ['label' => $bonusLabel, 'montant' => round($primes, 2),
      'account_number' => (string)($rs['player_bonus_account_number'] ?? ''), 'account_label' => (string)($rs['player_bonus_account_label'] ?? '')];
    // Pas d'affectation annuelle pour un joueur : la projection LPP ne s'applique pas
    // à cette population (§C.1 addendum — hors périmètre LPP en phase 1).
    $annualGross = $grossBase * 12;
  }

  $extraTotal = array_sum(array_map(fn($l) => (float)($l['montant'] ?? 0), $indemnites));
  $grossTotal = $grossBase + $extraTotal;
  foreach ($indemnites as $l) {
    $indLabel = (string)($l['account_label'] ?? '') !== '' ? (string)$l['account_label'] : (string)($l['label'] ?? '—');
    $revenueLines[] = ['label' => $indLabel, 'montant' => round((float)($l['montant'] ?? 0), 2),
      'account_number' => (string)($l['account_number'] ?? ''), 'account_label' => (string)($l['account_label'] ?? '')];
  }
  // Regroupement par compte comptable : deux rôles ou une indemnité rattachés au même compte
  // apparaissent en une seule ligne sur le document imprimé, plutôt qu'une ligne par
  // affectation — comportement déjà en place côté écran avant cette phase (groupedRevenueLines
  // dans index.html), repris ici côté serveur. Regroupé par compte (account_number) et non par
  // libellé (2026-08-18, corrigé sur signalement de Valentino) : un poste "Indemnités
  // entraîneurs" et une indemnité ponctuelle "Indemnité entraîneurs" pointant sur le même
  // compte comptable ne partagent pas forcément le même texte de libellé (pluriel, variante de
  // saisie...), et restaient donc affichés en deux lignes malgré un seul compte. Sans compte
  // renseigné, on retombe sur le libellé comme avant (rien à regrouper de façon fiable).
  $groupedRevenue = [];
  foreach ($revenueLines as $l) {
    $accountNumber = (string)($l['account_number'] ?? '');
    $key = $accountNumber !== '' ? 'acct:' . $accountNumber : 'label:' . $l['label'];
    if (isset($groupedRevenue[$key])) {
      $groupedRevenue[$key]['montant'] += $l['montant'];
      // Le libellé du compte comptable prévaut sur un texte de poste/indemnité divergent,
      // pour ne pas afficher un intitulé différent selon l'ordre d'arrivée des lignes.
      if ($accountNumber !== '' && (string)($l['account_label'] ?? '') !== '') {
        $groupedRevenue[$key]['label'] = $l['account_label'];
      }
    } else {
      $groupedRevenue[$key] = $l;
    }
  }
  $revenueLines = array_values(array_map(fn($l) => [
    'label' => $l['label'], 'montant' => round($l['montant'], 2),
    'account_number' => (string)($l['account_number'] ?? ''), 'account_label' => (string)($l['account_label'] ?? ''),
  ], $groupedRevenue));

  /* --- Statut AVS de la personne : franchise rentier / abattement minime importance -----
     Non cumulables (§6.2.4 SPEC, contrôle bloquant [9.2]).

     L'abattement minime importance était RÉSERVÉ AUX JOUEURS (players.avs_abatement) suite au
     revert du 2026-08-16 : une première généralisation aux employés traitait "pas de renonciation
     saisie" comme "abattement actif par défaut", or aucune interface ne permettait de cocher
     cette renonciation → tout employé (y compris le personnel administratif, jamais éligible)
     se voyait abattu par défaut, sans recours. Découvert sur la fiche de Valentino lui-même.

     Ouvert aux employés le 2026-08-18 (les entraîneurs, qui sont des employés et non des
     joueurs, doivent aussi pouvoir en bénéficier), mais sur le mode inverse cette fois :
     `employees.avs_minime_abatement` est un flag explicite, DÉCOCHÉ par défaut comme
     players.avs_abatement, à cocher à la main sur la fiche de la personne concernée
     (onglet Assurances). Plus de risque d'abattement silencieux par défaut. */
  $isRentier = $personType === 'employee' && (int)($person['avs_rentier'] ?? 0) === 1 && (int)($person['avs_franchise_renonce'] ?? 0) !== 1;
  $isMinimeAbated = ($personType === 'player' && (int)($person['avs_abatement'] ?? 0) === 1)
                  || ($personType === 'employee' && (int)($person['avs_minime_abatement'] ?? 0) === 1);
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
    // Abattement minime importance (players.avs_abatement ou employees.avs_minime_abatement,
    // voir plus haut) : le montant est paramétré et daté (payroll_rate_settings.avs_minime_abatement_semestriel,
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
  $employerChargeLines = [];
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

    // Part employeur (2026-08-16) : même assiette (après franchise/abattement, la loi réduit
    // le salaire déterminant pour les deux parts à la fois), taux séparé, jamais retenue sur
    // le net. Une ligne uniquement si un taux employeur est réellement paramétré pour cette
    // rubrique — la plupart des rubriques n'ont pas de contrepartie patronale (AANP, LAAC, IJM
    // suivent le contrat d'assurance réel du club, pas modélisé ici).
    $employerRate = (float)($rs[$key . '_employer_rate'] ?? 0);
    if ($employerRate > 0) {
      $employerAmount = round($base * $employerRate / 100, 2);
      $overrideEmployerKey = $key . '_employer';
      if (array_key_exists($overrideEmployerKey, $overrides) && $overrides[$overrideEmployerKey] !== null && $overrides[$overrideEmployerKey] !== '') {
        $employerAmount = round((float)$overrides[$overrideEmployerKey], 2);
      }
      $employerChargeLines[] = ['key' => $key, 'label' => $label . ' (part employeur)', 'base' => round($base, 2), 'rate' => $employerRate, 'amount' => $employerAmount];
    }

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

  /* --- Charges 100% employeur calées sur l'AVS (SCAF/LFP/CPE/frais d'administration, --------
     2026-08-17) : même assujettissement que l'AVS (salaire déterminant, après franchise
     rentier/abattement, jamais plafonnée) — voir payroll_employer_only_lines_catalog() plus
     haut. Pas de ligne 'charge' (employé) en contrepartie : ce sont de vraies charges
     patronales en plus du brut, jamais retenues sur le net.

     Cas particulier « frais d'administration » (précisé par Valentino le 2026-08-17) : ce
     n'est PAS un pourcentage du brut AVS, c'est un pourcentage de la COTISATION AVS elle-même
     (les 5,3% du brut) — donc frais_admin_rate% appliqué à (brut AVS × taux AVS employeur),
     pas directement au brut AVS. Le taux AVS employeur déjà paramétré (avs_employer_rate) sert
     de référence, avec repli sur avs_rate si l'employeur n'en a pas de distinct paramétré :
     décision explicite de ne pas dupliquer le taux légal dans un champ séparé. */
  $avsSubjectBase = max(0.0, $grossTotal - $chargeReduction);
  $avsRateForFraisAdmin = (float)($rs['avs_employer_rate'] ?? 0) ?: (float)($rs['avs_rate'] ?? 0);
  $avsContributionAmount = round($avsSubjectBase * $avsRateForFraisAdmin / 100, 2);
  if ((int)($person['avs_subject'] ?? 0) === 1) {
    foreach (payroll_employer_only_lines_catalog() as $key => $label) {
      $rate = (float)($rs[$key . '_rate'] ?? 0);
      if ($rate <= 0) continue;
      $lineBase = ($key === 'frais_admin') ? $avsContributionAmount : $avsSubjectBase;
      $amount = round($lineBase * $rate / 100, 2);
      $overrideKey = $key . '_employer';
      if (array_key_exists($overrideKey, $overrides) && $overrides[$overrideKey] !== null && $overrides[$overrideKey] !== '') {
        $amount = round((float)$overrides[$overrideKey], 2);
      }
      $employerChargeLines[] = ['key' => $key, 'label' => $label, 'base' => round($lineBase, 2), 'rate' => $rate, 'amount' => $amount];
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

      // Part employeur LPP (2026-08-16) : montant fixe par personne (employees.lpp_employer_amount,
      // en base depuis la phase précédente mais jamais lu par le moteur jusqu'ici), pas un taux —
      // la répartition employeur/employé de la LPP est libre contractuellement, contrairement aux
      // taux légaux fixes des autres rubriques.
      $lppEmployerAmount = round((float)($person['lpp_employer_amount'] ?? 0), 2);
      if ($lppEmployerAmount > 0) {
        $employerChargeLines[] = ['key' => 'lpp', 'label' => 'Prévoyance professionnelle (LPP) — part employeur', 'base' => round($periodCoordinated, 2), 'rate' => null, 'amount' => $lppEmployerAmount];
      }
    }
  }

  /* --- Impôt à la source (§6.4) : barème officiel AFC importé, sinon repli manuel --------
     Modèle mensuel genevois : le taux dépend du revenu brut du mois ($grossTotal, primes
     et indemnités comprises) ; il est choisi sur le "revenu déterminant pour le taux"
     (= revenu du mois + éventuel revenu déclaré chez un autre employeur), puis appliqué
     au seul revenu Meyrin. Employés et joueurs : même mécanisme (case unique tax_at_source).
     Repli inchangé si le barème n'est pas importé ou si un taux/montant manuel est saisi. */
  if ((int)($person['tax_at_source'] ?? 0) === 1) {
    $canton       = trim((string)($person['tax_canton'] ?? '')) ?: 'GE';
    $baremeCode   = trim((string)($person['tax_bareme'] ?? ''));
    $manualRate   = (float)($person['is_rate'] ?? 0);
    $manualAmount = (float)($person['is_amount'] ?? 0);
    $otherIncome  = (float)($person['is_rate_determining_income'] ?? 0);
    $determining  = max($grossTotal, $otherIncome);

    $scale = ($baremeCode !== '' && $manualRate <= 0 && $manualAmount <= 0)
      ? taxsource_rate($pdo, $canton, $year, $baremeCode, (int)round($determining * 100))
      : null;

    if ($scale !== null) {
      $rate = $scale['rate_pct'];
      $amount = max(round($grossTotal * $rate / 100, 2), round($scale['min_tax'] / 100, 2));
    } elseif ($manualRate > 0) {
      $rate = $manualRate;
      $amount = round($grossTotal * $rate / 100, 2);
      $warnings[] = 'Impôt à la source : taux saisi manuellement (le barème importé n\'est pas utilisé pour cette personne).';
    } elseif ($manualAmount > 0) {
      $rate = 0.0;
      $amount = round($manualAmount, 2);
      $warnings[] = 'Impôt à la source : montant fixe saisi manuellement.';
    } else {
      $rate = 0.0;
      $amount = 0.0;
      $warnings[] = "Impôt à la source coché mais aucun barème $canton $year importé pour le code «\u{202f}"
        . ($baremeCode !== '' ? $baremeCode : '(non renseigné)') . "\u{202f}» et aucun taux manuel : retenue nulle. "
        . 'Importer la grille dans Paramètres > Paie ou saisir un taux.';
    }

    if (array_key_exists('is', $overrides) && $overrides['is'] !== null && $overrides['is'] !== '') {
      $amount = round((float)$overrides['is'], 2);
    }
    $chargeLines[] = ['key' => 'is', 'label' => 'Impôt à la source', 'base' => round($grossTotal, 2), 'rate' => $rate, 'amount' => $amount];
  }

  /* --- Retenue lavage (joueurs, déjà paramétrée depuis le 2026-08-14) --------------------- */
  $diversLines = [];
  if ($personType === 'player' && $grossTotal > (float)$rs['lavage_threshold']) {
    $diversLines[] = ['label' => 'Retenue lavage', 'montant' => round((float)$rs['lavage_amount'], 2),
      'account_number' => (string)($rs['lavage_account_number'] ?? ''), 'account_label' => (string)($rs['lavage_account_label'] ?? '')];
  }
  foreach ($retenues as $l) {
    $diversLines[] = ['label' => (string)($l['label'] ?? '—'), 'montant' => round((float)($l['montant'] ?? 0), 2),
      'account_number' => (string)($l['account_number'] ?? ''), 'account_label' => (string)($l['account_label'] ?? '')];
  }

  $chargesTotal = round(array_sum(array_column($chargeLines, 'amount')), 2);
  $employerChargesTotal = round(array_sum(array_column($employerChargeLines, 'amount')), 2);
  // Bug corrigé (2026-08-17) : les lignes divers portent leur montant sous la clé 'montant'
  // (comme revenue_lines), pas 'amount' (réservée aux charge_lines/employer_charge_lines) —
  // array_column() sur la mauvaise clé renvoyait un tableau vide, donc un total et un net
  // toujours calculés comme si aucune retenue lavage/manuelle n'existait, silencieusement.
  $diversTotal  = round(array_sum(array_column($diversLines, 'montant')), 2);
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
    'employer_charge_lines' => $employerChargeLines,
    'legal_reference_lines' => $legalReferenceLines,
    'divers_lines' => $diversLines,
    'charges_total' => $chargesTotal,
    'employer_charges_total' => $employerChargesTotal,
    'divers_total' => $diversTotal,
    'net' => $net,
    'avs_reduction_applied' => $chargeReductionLabel,
    'warnings' => $warnings,
    // Le N° AVS manquant/invalide reste un avertissement affiché (utile pour du personnel pas
    // encore affilié, ex. un entraîneur récemment engagé), mais ne bloque plus la génération du
    // PDF (2026-08-18, demande de Valentino) — seuls le cumul franchise/abattement et un net
    // négatif restent des erreurs qui empêchent réellement de produire un décompte cohérent.
    'blocking' => array_values(array_filter($warnings, fn($w) =>
      str_contains($w, 'Franchise rentier') || str_contains($w, 'Net négatif'))),
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
 * $forceAdmin (2026-08-18, demande explicite de Valentino, réservée au rôle admin par
 * l'appelant) court-circuite ce refus : utile pour retirer un décompte de test ou reprendre
 * un décompte dont les réglages ont changé depuis, sans attendre le futur rectificatif.
 *
 * @return int L'id de la ligne payroll_payments.
 * @throws RuntimeException si un décompte verrouillé existe déjà pour cette période et que
 *         $forceAdmin est faux.
 */
function persist_payslip_lines(PDO $pdo, array $computed, bool $forceAdmin = false): int {
  $personType = $computed['person_type'];
  $personId = $computed['person_id'];
  $month = $computed['month'];

  $existing = $pdo->prepare('SELECT id, locked, gross_total, net_amount FROM payroll_payments WHERE person_type=? AND person_id=? AND period=?');
  $existing->execute([$personType, $personId, $month]);
  $row = $existing->fetch();
  if ($row && (int)$row['locked'] === 1 && !$forceAdmin) {
    /* Verrouillé ne doit bloquer qu'une vraie correction (montants différents de ceux déjà
       validés), pas un simple nouveau tirage du même PDF (fiche perdue, réimpression demandée
       par la personne...) : sans cette distinction, un décompte devenait illisible dès la
       deuxième génération, y compris quand rien n'avait changé. Comparé au centime près pour
       absorber l'arrondi flottant, pas de tolérance plus large qui masquerait une vraie dérive. */
    $sameGross = $row['gross_total'] !== null && round((float)$row['gross_total'], 2) === round((float)$computed['gross_total'], 2);
    $sameNet   = $row['net_amount']  !== null && round((float)$row['net_amount'],  2) === round((float)$computed['net'],  2);
    if (!$sameGross || !$sameNet) {
      throw new RuntimeException('Ce décompte est déjà validé et verrouillé pour cette période, et les montants recalculés diffèrent de ceux validés. Une correction passe par un rectificatif (hors périmètre actuel).');
    }
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
  $ins = $pdo->prepare('INSERT INTO payroll_payment_lines (payment_id, kind, line_key, label, base, rate, amount, is_legal_reference, sort_order, account_number, account_label) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
  $order = 0;
  foreach ($computed['revenue_lines'] as $l) {
    $ins->execute([$paymentId, 'revenue', '', $l['label'], null, null, $l['montant'], 0, $order++, (string)($l['account_number'] ?? ''), (string)($l['account_label'] ?? '')]);
  }
  foreach ($computed['charge_lines'] as $l) {
    $ins->execute([$paymentId, 'charge', $l['key'], $l['label'], $l['base'], $l['rate'], $l['amount'], 0, $order++, '', '']);
  }
  // Charges employeur (2026-08-16) : compte résolu au moment de la poussée comptable (par
  // line_key -> payroll_line_accounts, lib_payroll_compta.php), pas ici — c'est un réglage
  // partagé par toutes les fiches, pas une donnée propre à ce décompte.
  foreach ($computed['employer_charge_lines'] as $l) {
    $ins->execute([$paymentId, 'employer_charge', $l['key'], $l['label'], $l['base'], $l['rate'], $l['amount'], 0, $order++, '', '']);
  }
  foreach ($computed['legal_reference_lines'] as $l) {
    $ins->execute([$paymentId, 'charge', $l['key'], $l['label'], $l['base'], $l['rate'], $l['amount'], 1, $order++, '', '']);
  }
  foreach ($computed['divers_lines'] as $l) {
    $ins->execute([$paymentId, 'divers', '', $l['label'], null, null, $l['montant'], 0, $order++, (string)($l['account_number'] ?? ''), (string)($l['account_label'] ?? '')]);
  }

  return $paymentId;
}
