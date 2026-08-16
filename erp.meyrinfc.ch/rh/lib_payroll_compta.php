<?php
/**
 * lib_payroll_compta.php — Pont RH → Compta pour la paie.
 *
 * Symétrique de erp.meyrinfc.ch/lib/mfc_compta.php (qui lit RH depuis Compta
 * pour les factures) : ici c'est RH qui ouvre directement compta.sqlite,
 * jamais par appel HTTP, même principe que le reste des ponts inter-modules
 * de cet ERP (mfc_club.php, mfc_contacts.php, mfc_compta.php).
 *
 * Un décompte de paie n'est pas une facture (pas un tiers unique, plusieurs
 * créanciers à la fois — net à payer, OCAS, assureur LAA, institution LPP...) :
 * il ne passe donc pas par invoice_create(), mais dépose directement un
 * brouillon générique via compta_create_draft() (lib/mfc_compta.php).
 *
 * Best-effort partout : Compta absente ou en échec ne doit jamais empêcher
 * la génération d'une fiche de paie, qui reste l'action principale demandée
 * par l'utilisateur au moment de l'appel.
 */

if (defined('MFC_PAYROLL_COMPTA_LOADED')) return;
define('MFC_PAYROLL_COMPTA_LOADED', true);

/** Ouvre compta.sqlite en direct, ou null si le module Compta n'est pas installé
 * sur ce serveur — jamais une erreur, RH doit continuer à fonctionner sans lui. */
function payroll_compta_db(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($tried) return $pdo;
    $tried = true;

    if (!function_exists('mfc_app_path')) return null;
    $base = mfc_app_path('compta');
    if (!$base) return null;

    $f = $base . '/data/compta.sqlite';
    if (!is_file($f)) return null;

    try {
        $pdo = new PDO('sqlite:' . $f);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } catch (\Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Charge lib/mfc_compta.php (compta_create_draft() etc.), une fois. */
function payroll_require_compta_lib(): bool {
    if (function_exists('compta_create_draft')) return true;
    foreach ([__DIR__ . '/../lib/mfc_compta.php', dirname(__DIR__) . '/lib/mfc_compta.php'] as $f) {
        if (is_file($f)) { require_once $f; return function_exists('compta_create_draft'); }
    }
    return false;
}

/**
 * Résout un numéro de compte du plan comptable Compta vers son account_id,
 * ou null si absent/non paramétré/introuvable. $number vide est un cas
 * normal (rubrique sans part employeur, ou compte pas encore paramétré).
 */
function payroll_compta_account_id(PDO $comptaPdo, string $number): ?int {
    $number = trim($number);
    if ($number === '') return null;
    $st = $comptaPdo->prepare('SELECT id FROM accounts WHERE number = ? AND active = 1 LIMIT 1');
    $st->execute([$number]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Dépose un brouillon comptable pour un décompte de paie déjà persisté et
 * verrouillé (payroll_payments.locked = 1). Construit une écriture équilibrée :
 *
 *   Débit  brut (par compte de poste/indemnité)
 *   Débit  charges employeur (par compte de charge du prestataire)
 *   Crédit retenues employé + charges employeur, regroupées par compte de
 *          dette du prestataire (les deux parts d'une même rubrique, ex. AVS,
 *          vont au même compte : c'est bien ce que le club devra à l'OCAS)
 *   Crédit lignes diverses (lavage, retenues manuelles) sur leur propre
 *          compte, ou le compte de suspens si aucun n'est configuré
 *   Crédit solde restant sur le compte "Salaire à payer"
 *
 * Ne lève jamais : retourne l'id du brouillon, ou null si Compta est
 * indisponible ou si le dépôt échoue (log applicatif via error_log()).
 */
function payroll_push_draft(PDO $rhPdo, int $paymentId): ?int {
    $comptaPdo = payroll_compta_db();
    if (!$comptaPdo || !payroll_require_compta_lib()) return null;

    try {
        $accSettings = $rhPdo->query('SELECT * FROM payroll_accounting_settings WHERE id = 1')->fetch();
        if (!$accSettings || (int) ($accSettings['push_enabled'] ?? 0) !== 1) return null;

        $pmt = $rhPdo->prepare('SELECT * FROM payroll_payments WHERE id = ?');
        $pmt->execute([$paymentId]);
        $payment = $pmt->fetch();
        if (!$payment) return null;

        $lines = $rhPdo->prepare('SELECT * FROM payroll_payment_lines WHERE payment_id = ? AND is_legal_reference = 0 ORDER BY sort_order');
        $lines->execute([$paymentId]);
        $lines = $lines->fetchAll();

        $lineAccounts = $rhPdo->query('SELECT * FROM payroll_line_accounts')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

        if ($payment['person_type'] === 'employee') {
            $p = $rhPdo->prepare('SELECT first_name, last_name FROM employees WHERE id = ?');
        } else {
            $p = $rhPdo->prepare('SELECT first_name, last_name FROM players WHERE id = ?');
        }
        $p->execute([(int) $payment['person_id']]);
        $person = $p->fetch();
        $personName = $person ? trim($person['first_name'] . ' ' . $person['last_name']) : ('#' . $payment['person_id']);
        $monthLabel = function_exists('payslip_month_label') ? payslip_month_label((string) $payment['period']) : (string) $payment['period'];

        $warnings = [];
        // account_number => ['debit'|'credit' => montant]
        $byAccount = [];
        $addAmount = function (string $accountNumber, string $accountLabel, string $side, float $amount) use (&$byAccount) {
            if ($amount <= 0 || $accountNumber === '') return;
            $byAccount[$accountNumber]['label'] = $accountLabel ?: $accountNumber;
            $byAccount[$accountNumber][$side] = ($byAccount[$accountNumber][$side] ?? 0.0) + $amount;
        };

        $suspenseNumber = (string) ($accSettings['suspense_account_number'] ?? '');
        $suspenseLabel  = (string) ($accSettings['suspense_account_label'] ?? 'Suspens paie');

        foreach ($lines as $l) {
            $amount = round((float) $l['amount'], 2);
            if ($amount <= 0) continue;

            if ($l['kind'] === 'revenue') {
                if ((string) $l['account_number'] === '') {
                    $warnings[] = "Ligne « {$l['label']} » sans compte comptable, affectée au compte de suspens.";
                    $addAmount($suspenseNumber, $suspenseLabel, 'debit', $amount);
                } else {
                    $addAmount((string) $l['account_number'], (string) $l['account_label'], 'debit', $amount);
                }
                continue;
            }

            if ($l['kind'] === 'divers') {
                if ((string) $l['account_number'] === '') {
                    $warnings[] = "Retenue « {$l['label']} » sans compte comptable, affectée au compte de suspens.";
                    $addAmount($suspenseNumber, $suspenseLabel, 'credit', $amount);
                } else {
                    $addAmount((string) $l['account_number'], (string) $l['account_label'], 'credit', $amount);
                }
                continue;
            }

            // 'charge' (part employé, déjà dans le brut) et 'employer_charge' (part employeur,
            // vraie charge en plus du brut) partagent le même compte de dette par prestataire :
            // c'est le total réellement dû à l'OCAS/l'assureur/l'institution, tous versants
            // confondus. Seule la part employeur ouvre en plus une ligne de charge (classe 5).
            $la = $lineAccounts[$l['line_key']] ?? null;
            if (!$la || (string) $la['payable_account_number'] === '') {
                $warnings[] = "Rubrique « {$l['label']} » sans compte de dette configuré (Paramètres > Paie > Comptabilisation), affectée au compte de suspens.";
                $addAmount($suspenseNumber, $suspenseLabel, 'credit', $amount);
            } else {
                $addAmount((string) $la['payable_account_number'], (string) $la['payable_account_label'], 'credit', $amount);
            }

            if ($l['kind'] === 'employer_charge') {
                if (!$la || (string) $la['charge_account_number'] === '') {
                    $warnings[] = "Charge employeur « {$l['label']} » sans compte de charge configuré, affectée au compte de suspens.";
                    $addAmount($suspenseNumber, $suspenseLabel, 'debit', $amount);
                } else {
                    $addAmount((string) $la['charge_account_number'], (string) $la['charge_account_label'], 'debit', $amount);
                }
            }
        }

        // Solde restant sur le net à payer : par construction (net = brut - charges employé -
        // divers), c'est ce qui équilibre l'écriture avec le brut + les charges employeur.
        $totalDebit = array_sum(array_column($byAccount, 'debit'));
        $totalCreditSoFar = array_sum(array_column($byAccount, 'credit'));
        $netPayable = round($totalDebit - $totalCreditSoFar, 2);
        if ($netPayable > 0) {
            $addAmount(
                (string) ($accSettings['net_payable_account_number'] ?? '2002'),
                (string) ($accSettings['net_payable_account_label'] ?? 'Salaire à payer'),
                'credit', $netPayable
            );
        }

        $entryLines = [];
        foreach ($byAccount as $number => $agg) {
            $accountId = payroll_compta_account_id($comptaPdo, $number);
            if (!$accountId) {
                $warnings[] = "Compte $number introuvable dans le plan comptable, ligne ignorée.";
                continue;
            }
            $debit = round((float) ($agg['debit'] ?? 0), 2);
            $credit = round((float) ($agg['credit'] ?? 0), 2);
            if ($debit > 0) $entryLines[] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => 0.0, 'label' => $agg['label']];
            if ($credit > 0) $entryLines[] = ['account_id' => $accountId, 'debit' => 0.0, 'credit' => $credit, 'label' => $agg['label']];
        }
        if (count($entryLines) < 2) return null;

        $journal = null;
        $journalCode = (string) ($accSettings['journal_code'] ?? 'SAL');
        if ($journalCode !== '') {
            $js = $comptaPdo->prepare('SELECT id FROM journals WHERE code = ? AND active = 1');
            $js->execute([$journalCode]);
            $jid = $js->fetchColumn();
            if ($jid !== false) $journal = (int) $jid;
        }

        $draftId = compta_create_draft($comptaPdo, [
            'source_kind'          => 'payroll',
            'entry_date'           => date('Y-m-d'),
            'label'                => "Décompte de paie — $personName — $monthLabel",
            'piece_kind_suggested' => 'SAL',
            'suggested_journal_id' => $journal,
            'source_module'        => 'rh',
            'source_ref_id'        => (string) $paymentId,
            'warnings'             => implode(' ', $warnings),
            'created_by'           => 'rh',
            'lines'                => $entryLines,
        ]);

        return $draftId;
    } catch (\Throwable $e) {
        error_log('payroll_push_draft: ' . $e->getMessage());
        return null;
    }
}
