<?php
/**
 * mfc_compta.php — Pont entre la Comptabilité et les modules métier.
 *
 * PRINCIPE, hérité de mfc_club.php et mfc_contacts.php : chaque module garde
 * son modèle et son workflow, la Comptabilité ne les duplique pas. Le cycle de
 * vie d'une commande (draft→sent→received) reste dans Commandes, celui d'un
 * match dans Arbitrage. La Comptabilité ne possède que le plan comptable, le
 * journal, les factures et la banque.
 *
 * Ce fichier fait donc exactement deux choses :
 *
 *   1. LIRE les pièces à facturer d'un module           → compta_open_items()
 *   2. RÉÉCRIRE son statut « payé » quand elle est réglée → compta_mark_paid()
 *
 * L'accès se fait par ouverture directe du fichier SQLite du module, jamais par
 * appel HTTP — même choix que contacts/sync_modules.php:contacts_module_db().
 *
 * SOURCE DE VÉRITÉ : le statut « payé » d'une pièce reste écrit dans le module
 * qui la possède, pas recopié ici. compta_mark_paid() exécute donc exactement
 * la même requête que le code de paiement déjà écrit dans chaque module
 * (arbitrage/api.php:matches_bulk_pay, commandes/api.php:case 'invoice'), pour
 * qu'il n'existe jamais deux réponses différentes à « est-ce payé ? ».
 *
 * Ce fichier n'ouvre JAMAIS compta.sqlite lui-même : la base de la
 * Comptabilité lui est passée en paramètre par compta/api.php, qui en détient
 * l'unique connexion. Deux connexions concurrentes sur la même base pour un
 * seul processus n'apporteraient rien et brouilleraient les transactions.
 */

if (defined('MFC_COMPTA_LOADED')) return;
define('MFC_COMPTA_LOADED', true);

/** Modules sources branchés, et le fichier SQLite de chacun. */
const COMPTA_SOURCE_MODULES = [
    'arbitrage' => 'arbitrage.sqlite',
    'commandes' => 'commandes.sqlite',
    'sponsors'  => 'sponsorflow.sqlite',
];

/* ============================================================ ACCÈS MODULES */

/**
 * Ouvre la base d'un module source, ou null si l'application n'est pas
 * installée sur ce serveur. Un module absent ne doit jamais faire échouer la
 * Comptabilité : il disparaît simplement de la liste des sources.
 */
function compta_module_db(string $slug): ?PDO {
    static $cache = [];
    if (array_key_exists($slug, $cache)) return $cache[$slug];
    $cache[$slug] = null;

    $filename = COMPTA_SOURCE_MODULES[$slug] ?? '';
    if ($filename === '') return null;

    $base = mfc_app_path($slug);
    if (!$base) return null;

    $f = $base . '/data/' . $filename;
    if (!is_file($f)) return null;

    $pdo = new PDO('sqlite:' . $f);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    /* Le module tourne peut-être en parallèle : on patiente plutôt que
       d'échouer sur un verrou momentané. */
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $cache[$slug] = $pdo;
}

/** Liste des modules réellement disponibles, pour n'afficher que ceux-là. */
function compta_available_modules(): array {
    $out = [];
    foreach (array_keys(COMPTA_SOURCE_MODULES) as $slug) {
        if (compta_module_db($slug)) $out[] = $slug;
    }
    return $out;
}

/* ============================================================ PIÈCES OUVERTES
 *
 * Format normalisé, identique quel que soit le module, pour que l'écran de
 * génération de facture n'ait pas à connaître le modèle de chacun :
 *   { module, ref_id, date, label, tiers, tiers_contact_id, amount }
 */

/**
 * Pièces d'un module en attente de facturation.
 *
 * Exclut celles déjà reprises dans une facture (invoice_source_items) : c'est
 * ce qui rend l'écran rejouable sans jamais facturer deux fois la même chose.
 */
function compta_open_items(PDO $compta, string $module): array {
    $db = compta_module_db($module);
    if (!$db) return [];

    $already = compta_invoiced_refs($compta, $module);

    $items = match ($module) {
        'arbitrage' => compta_open_items_arbitrage($db),
        'commandes' => compta_open_items_commandes($db),
        'sponsors'  => compta_open_items_sponsors($db),
        default     => [],
    };

    return array_values(array_filter(
        $items,
        fn(array $it) => !isset($already[$it['ref_id']]) && (float) $it['amount'] != 0.0
    ));
}

/** Identifiants sources déjà facturés, en table de hachage pour un test O(1). */
function compta_invoiced_refs(PDO $compta, string $module): array {
    $st = $compta->prepare(
        "SELECT DISTINCT si.source_ref_id
           FROM invoice_source_items si
           JOIN invoices i ON i.id = si.invoice_id
          WHERE si.source_module = ? AND i.status != 'annulee'"
    );
    $st->execute([$module]);
    return array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
}

/**
 * Une colonne existe-t-elle dans la base d'un autre module ? La Comptabilité
 * lit ces bases sans les migrer : elle doit donc composer avec l'état où le
 * module propriétaire les a laissées, jamais présumer d'une migration.
 */
function compta_column_exists(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column . '.' . spl_object_id($db);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $cols = $db->query("PRAGMA table_info(" . str_replace('"', '', $table) . ")")->fetchAll(PDO::FETCH_COLUMN, 1);
    } catch (\PDOException $e) {
        return $cache[$key] = false;
    }
    return $cache[$key] = in_array($column, $cols, true);
}

/**
 * Arbitrage : un match non payé est une indemnité due à l'arbitre.
 * Le montant suit la règle déjà en place dans le module (arbitrage/api.php) :
 * l'indemnité négociée du match écrase le tarif standard de l'équipe.
 */
function compta_open_items_arbitrage(PDO $db): array {
    /* `fee_override` est ajoutée par une migration que seul arbitrage/api.php
       exécute, à son propre démarrage. La Comptabilité lit cette base sans
       jamais la faire démarrer : si elle exigeait la colonne, ouvrir la Compta
       avant Arbitrage après un déploiement suffirait à la mettre en erreur 500.
       Elle s'en passe donc quand elle est absente. */
    $amount = compta_column_exists($db, 'matches', 'fee_override')
        ? 'COALESCE(m.fee_override, t.fee_amount)'
        : 't.fee_amount';

    $rows = $db->query("
        SELECT m.id, m.match_date, m.match_number, m.opponent, m.competition,
               t.name AS team_name, t.category,
               $amount AS amount
          FROM matches m
          LEFT JOIN teams t ON t.id = m.team_id
         WHERE m.status = 'pending'
         ORDER BY m.match_date, m.id
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $team = trim((string) $r['team_name']) ?: 'Équipe inconnue';
        $out[] = [
            'module'           => 'arbitrage',
            'ref_id'           => (string) $r['id'],
            'date'             => (string) $r['match_date'],
            'label'            => sprintf('%s vs %s', $team, trim((string) $r['opponent']) ?: 'adversaire'),
            'detail'           => trim((string) $r['competition'] . ' ' . (string) $r['match_number']),
            'group'            => $team,          // sert au regroupement « tous les matchs d'une équipe »
            'tiers'            => 'Arbitrage ' . $team,
            'tiers_contact_id' => '',
            'amount'           => round((float) $r['amount'], 2),
        ];
    }
    return $out;
}

/**
 * Commandes : une facture fournisseur contrôlée ou approuvée est due.
 * Les factures encore « à contrôler » sont volontairement exclues : une facture
 * porteuse d'une anomalie de prix ou de quantité n'a pas à être comptabilisée
 * avant arbitrage humain.
 */
function compta_open_items_commandes(PDO $db): array {
    $rows = $db->query("
        SELECT i.id, i.amount, i.invoice_date, i.created_at, i.filename,
               po.id AS order_id, po.season, po.order_seq,
               s.id AS supplier_id, s.name AS supplier_name
          FROM invoices i
          JOIN purchase_orders po ON po.id = i.order_id
          LEFT JOIN suppliers s ON s.id = po.supplier_id
         WHERE i.status IN ('approuvee', 'en_attente_paiement')
         ORDER BY COALESCE(i.invoice_date, i.created_at), i.id
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $supplier = trim((string) $r['supplier_name']) ?: 'Fournisseur inconnu';
        $out[] = [
            'module'           => 'commandes',
            'ref_id'           => (string) $r['id'],
            'date'             => substr((string) ($r['invoice_date'] ?: $r['created_at']), 0, 10),
            'label'            => sprintf('Facture %s (commande #%d)', $supplier, (int) $r['order_id']),
            'detail'           => (string) $r['filename'],
            'group'            => $supplier,
            'tiers'            => $supplier,
            'tiers_contact_id' => compta_contact_ref('commandes', 'supplier:' . (int) $r['supplier_id']),
            'amount'           => round((float) $r['amount'], 2),
        ];
    }
    return $out;
}

/**
 * Sponsors : un contrat à facturer ou en attente de paiement est une créance.
 * Contrairement aux deux autres modules, Sponsors n'a pas d'objet facture : le
 * contrat lui-même fait office de pièce, et son statut texte porte l'étape.
 */
function compta_open_items_sponsors(PDO $db): array {
    $rows = $db->query("
        SELECT c.id, c.label, c.type, c.amount, c.start_date, c.end_date,
               s.id AS sponsor_id, s.name AS sponsor_name
          FROM contracts c
          JOIN sponsors s ON s.id = c.sponsor_id
         WHERE c.status IN ('Facture à envoyer', 'Attente de paiement')
         ORDER BY c.start_date, c.id
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $sponsor = trim((string) $r['sponsor_name']) ?: 'Sponsor inconnu';
        $out[] = [
            'module'           => 'sponsors',
            'ref_id'           => (string) $r['id'],
            'date'             => (string) ($r['start_date'] ?: date('Y-m-d')),
            'label'            => sprintf('%s — %s', $sponsor, trim((string) $r['label']) ?: 'contrat'),
            'detail'           => trim((string) $r['type']),
            'group'            => $sponsor,
            'tiers'            => $sponsor,
            'tiers_contact_id' => compta_contact_ref('sponsors', 'sponsor:' . (int) $r['sponsor_id']),
            'amount'           => round((float) $r['amount'], 2),
        ];
    }
    return $out;
}

/**
 * Identifiant stable du contact correspondant à un objet de module, ou '' s'il
 * n'est pas encore dans l'annuaire. Best-effort volontaire : une facture doit
 * pouvoir se créer même si le référentiel Contacts n'est pas installé ou pas
 * encore synchronisé. Le nom du tiers, lui, vient toujours du module.
 */
function compta_contact_ref(string $module, string $localId): string {
    if (!function_exists('mfc_contacts_by_source')) return '';
    try {
        $c = mfc_contacts_by_source(mfc_contacts_db(), $module, $localId);
        return $c ? (string) $c['ref_id'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

/* ============================================================ RETOUR « PAYÉ »
 *
 * Une seule source de vérité : c'est le module propriétaire qui porte le
 * statut. Chaque branche ci-dessous exécute la même requête que le code de
 * paiement déjà écrit et éprouvé dans le module concerné.
 */

/**
 * Marque une pièce source comme payée dans son module d'origine.
 * Renvoie false si le module est absent ou la pièce introuvable, sans jamais
 * lever : le règlement comptable ne doit pas échouer parce qu'un module tiers
 * est momentanément indisponible. L'appelant journalise l'échec.
 */
function compta_mark_paid(string $module, string $refId, string $paidAt): bool {
    $db = compta_module_db($module);
    if (!$db) return false;

    try {
        switch ($module) {
            /* Identique à arbitrage/api.php:matches_bulk_pay. */
            case 'arbitrage':
                $st = $db->prepare("UPDATE matches SET status='paid', paid_at=? WHERE id=? AND status='pending'");
                $st->execute([$paidAt, (int) $refId]);
                return $st->rowCount() > 0;

            /* Identique à commandes/api.php:case 'invoice'. */
            case 'commandes':
                $st = $db->prepare("UPDATE invoices SET status='payee' WHERE id=? AND status != 'payee'");
                $st->execute([(int) $refId]);
                return $st->rowCount() > 0;

            /* Sponsors n'a pas d'état « payé » : un contrat encaissé devient
               actif, ce qui le sort de la liste des pièces à facturer. */
            case 'sponsors':
                $st = $db->prepare("UPDATE contracts SET status='Actif' WHERE id=? AND status != 'Actif'");
                $st->execute([(int) $refId]);
                return $st->rowCount() > 0;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

/* ============================================================ MOTEUR JOURNAL
 *
 * Unique porte d'écriture dans le journal. Factures, règlements bancaires,
 * écriture d'ouverture, clôture d'exercice et saisie manuelle passent tous par
 * ici : le contrôle d'équilibre et le verrouillage de période ne peuvent donc
 * pas être contournés par un chemin oublié.
 */

class ComptaException extends RuntimeException {}

/**
 * Trace une action dans le journal d'audit (art. 957a CO — traçabilité).
 *
 * Best-effort et volontairement permissif sur les erreurs : un souci
 * d'écriture dans audit_log (disque plein, table verrouillée) ne doit jamais
 * faire échouer l'opération comptable qu'il accompagne. Appelé DANS la même
 * transaction que l'opération auditée quand elle existe : si celle-ci est
 * annulée (rollBack), la trace d'audit l'est avec elle — on ne veut pas
 * enregistrer qu'une écriture a été créée si elle ne l'a finalement pas été.
 *
 * $changes : diff avant/après pour une modification, ex. ['rate_percent' => [8.1, 7.7]].
 * Vide pour une création (rien à comparer) ou une suppression (l'état déjà
 * connu du $summary suffit).
 */
function compta_audit_log(PDO $pdo, string $entityType, ?int $entityId, string $action, string $summary, string $userName, array $changes = []): void {
    try {
        $pdo->prepare('INSERT INTO audit_log (entity_type, entity_id, action, summary, changes, user_name) VALUES (?,?,?,?,?,?)')
            ->execute([$entityType, $entityId, $action, $summary, $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : '', $userName]);
    } catch (\Throwable $e) {
        // Ne jamais bloquer la comptabilité pour un souci de traçabilité.
    }
}

/**
 * Enregistre une écriture équilibrée et renvoie son identifiant.
 *
 * $lines : liste de ['account_id' => int, 'debit' => float, 'credit' => float,
 *                    'label' => string, 'tiers_contact_id' => string,
 *                    'cost_center_id' => ?int]
 *
 * Les lignes à zéro des deux côtés sont ignorées (une grille de saisie en
 * comporte toujours), mais il doit en rester au moins deux non nulles.
 */
function compta_post_entry(PDO $pdo, array $e): int {
    $date  = trim((string) ($e['entry_date'] ?? ''));
    $label = trim((string) ($e['label'] ?? ''));
    if ($date === '')  throw new ComptaException('Date requise');
    if ($label === '') throw new ComptaException('Libellé requis');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ComptaException('Date invalide (format attendu AAAA-MM-JJ)');

    $totalDebit = 0.0; $totalCredit = 0.0; $clean = [];
    foreach ($e['lines'] ?? [] as $l) {
        $accountId = (int) ($l['account_id'] ?? 0);
        $debit     = round((float) ($l['debit'] ?? 0), 2);
        $credit    = round((float) ($l['credit'] ?? 0), 2);
        if ($debit == 0.0 && $credit == 0.0) continue;
        if (!$accountId) throw new ComptaException('Chaque ligne doit avoir un compte');
        if ($debit < 0 || $credit < 0) throw new ComptaException('Montants négatifs interdits');
        if ($debit > 0 && $credit > 0) throw new ComptaException('Une ligne ne peut pas être à la fois débitrice et créditrice');
        $totalDebit  += $debit;
        $totalCredit += $credit;
        $clean[] = [
            $accountId, $debit, $credit,
            trim((string) ($l['label'] ?? '')),
            trim((string) ($l['tiers_contact_id'] ?? '')),
            ($l['cost_center_id'] ?? null) ? (int) $l['cost_center_id'] : null,
        ];
    }
    if (count($clean) < 2) throw new ComptaException('Une écriture nécessite au moins deux lignes avec un montant');
    if (abs($totalDebit - $totalCredit) > 0.005) {
        throw new ComptaException(sprintf('Écriture déséquilibrée : débit %.2f ≠ crédit %.2f', $totalDebit, $totalCredit));
    }

    /* Une écriture appartient obligatoirement à un exercice ouvert. Sans cette
       règle, une date hors calendrier passait au travers du verrou de période
       (period_id restait NULL et le contrôle de clôture n'était jamais fait). */
    $fy = compta_fiscal_year_for($pdo, $date);
    if (!$fy) throw new ComptaException("Aucun exercice comptable ne couvre le $date. Créez-le d'abord dans l'onglet Exercices.");
    if ($fy['status'] === 'cloture') throw new ComptaException("L'exercice « {$fy['label']} » est clôturé, plus aucune écriture ne peut y être ajoutée.");

    $periodId = compta_period_for($pdo, $date, (int) $fy['id']);
    if ($periodId && compta_period_is_closed($pdo, $periodId)) {
        throw new ComptaException('La période comptable de cette date est clôturée');
    }

    /* Le journal détermine le préfixe du numéro de pièce. Un `journal_id` fourni
       par l'appelant l'emporte ; sinon on retombe sur la nature demandée, ce qui
       laisse fonctionner tel quel tout le code écrit avant les journaux. */
    $journal = compta_journal_resolve($pdo, $e['journal_id'] ?? null, (string) ($e['piece_kind'] ?? 'OD'));
    $kind     = $journal ? (string) $journal['code'] : (string) ($e['piece_kind'] ?? 'OD');
    $pieceRef = trim((string) ($e['piece_ref'] ?? '')) ?: compta_next_piece_ref($pdo, (int) $fy['id'], $kind);

    $pdo->prepare(
        'INSERT INTO journal_entries
            (entry_date, piece_ref, label, source_module, source_ref_id,
             period_id, fiscal_year_id, reversal_of_entry_id, journal_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $date, $pieceRef, $label,
        (string) ($e['source_module'] ?? 'manuel'),
        (string) ($e['source_ref_id'] ?? ''),
        $periodId, (int) $fy['id'],
        ($e['reversal_of_entry_id'] ?? null) ? (int) $e['reversal_of_entry_id'] : null,
        $journal ? (int) $journal['id'] : null,
        (string) ($e['created_by'] ?? ''),
    ]);
    $entryId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        'INSERT INTO journal_lines (entry_id, account_id, debit, credit, label, tiers_contact_id, cost_center_id)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($clean as $c) $ins->execute(array_merge([$entryId], $c));

    // Chemin unique de création d'écriture (saisie manuelle, extourne, facture,
    // ouverture/clôture d'exercice) : un seul point d'audit couvre tout.
    compta_audit_log($pdo, 'journal_entry', $entryId, 'create', "Écriture $pieceRef — $label", (string) ($e['created_by'] ?? ''));

    return $entryId;
}

/* ============================================================ BROUILLONS
 *
 * Étape intermédiaire, obligatoire pour toute génération (facture ou décompte
 * de paie) : rien n'est numéroté ni comptabilisé tant qu'un brouillon n'a pas
 * été validé depuis l'écran de revue. Contrairement à compta_post_entry(),
 * compta_create_draft() ne résout ni exercice, ni période, ni journal, ni
 * numéro de pièce — tout ça n'a de sens qu'à la validation, pour que la
 * numérotation continue des pièces ne souffre jamais d'un brouillon rejeté.
 */

/**
 * Dépose un brouillon d'écriture. Même contrôle de forme que
 * compta_post_entry() (au moins deux lignes non nulles, débit=crédit, un
 * compte par ligne), mais sans toucher au journal ni à la numérotation.
 *
 * $d : ['source_kind', 'entry_date', 'label', 'source_module', 'source_ref_id',
 *       'suggested_journal_id'?, 'piece_kind_suggested'?, 'warnings'?,
 *       'created_by'?, 'lines' => [['account_id','debit','credit','label'?,
 *       'tiers_contact_id'?,'cost_center_id'?], ...]]
 *
 * @return int L'id du brouillon (entry_drafts.id).
 */
function compta_create_draft(PDO $pdo, array $d): int {
    $date  = trim((string) ($d['entry_date'] ?? ''));
    $label = trim((string) ($d['label'] ?? ''));
    if ($date === '')  throw new ComptaException('Date requise');
    if ($label === '') throw new ComptaException('Libellé requis');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ComptaException('Date invalide (format attendu AAAA-MM-JJ)');

    $totalDebit = 0.0; $totalCredit = 0.0; $clean = [];
    foreach ($d['lines'] ?? [] as $l) {
        $accountId = (int) ($l['account_id'] ?? 0);
        $debit     = round((float) ($l['debit'] ?? 0), 2);
        $credit    = round((float) ($l['credit'] ?? 0), 2);
        if ($debit == 0.0 && $credit == 0.0) continue;
        if (!$accountId) throw new ComptaException('Chaque ligne doit avoir un compte');
        if ($debit < 0 || $credit < 0) throw new ComptaException('Montants négatifs interdits');
        if ($debit > 0 && $credit > 0) throw new ComptaException('Une ligne ne peut pas être à la fois débitrice et créditrice');
        $totalDebit  += $debit;
        $totalCredit += $credit;
        $clean[] = [
            $accountId, $debit, $credit,
            trim((string) ($l['label'] ?? '')),
            trim((string) ($l['tiers_contact_id'] ?? '')),
            ($l['cost_center_id'] ?? null) ? (int) $l['cost_center_id'] : null,
        ];
    }
    if (count($clean) < 2) throw new ComptaException('Une écriture nécessite au moins deux lignes avec un montant');
    if (abs($totalDebit - $totalCredit) > 0.005) {
        throw new ComptaException(sprintf('Brouillon déséquilibré : débit %.2f ≠ crédit %.2f', $totalDebit, $totalCredit));
    }

    $pdo->prepare(
        'INSERT INTO entry_drafts
            (source_kind, entry_date, label, source_module, source_ref_id,
             suggested_journal_id, piece_kind_suggested, warnings, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        (string) ($d['source_kind'] ?? 'manuel'),
        $date, $label,
        (string) ($d['source_module'] ?? 'manuel'),
        (string) ($d['source_ref_id'] ?? ''),
        ($d['suggested_journal_id'] ?? null) ? (int) $d['suggested_journal_id'] : null,
        (string) ($d['piece_kind_suggested'] ?? 'OD'),
        (string) ($d['warnings'] ?? ''),
        (string) ($d['created_by'] ?? ''),
    ]);
    $draftId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        'INSERT INTO entry_draft_lines (draft_id, account_id, debit, credit, label, tiers_contact_id, cost_center_id, position)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $order = 0;
    foreach ($clean as $c) $ins->execute(array_merge([$draftId], $c, [$order++]));

    return $draftId;
}

/**
 * Valide un brouillon : c'est SEULEMENT à cet instant que le journal est
 * résolu et que le numéro de pièce est attribué (compta_post_entry() fait
 * exactement ce que faisait invoice_create() avant l'introduction des
 * brouillons). Si le brouillon est lié à une facture (source_kind commençant
 * par 'invoice_'), la ligne `invoices` correspondante quitte le statut
 * 'brouillon' et reçoit son écriture définitive.
 *
 * @return int L'id de l'écriture définitive créée (journal_entries.id).
 * @throws ComptaException si le brouillon est introuvable, déjà validé, ou
 *         que ses lignes ont été éditées jusqu'à devenir déséquilibrées.
 */
function compta_validate_draft(PDO $pdo, int $draftId, string $userName, ?int $journalIdOverride = null): int {
    $st = $pdo->prepare('SELECT * FROM entry_drafts WHERE id = ?');
    $st->execute([$draftId]);
    $draft = $st->fetch();
    if (!$draft) throw new ComptaException('Brouillon introuvable');
    if ($draft['status'] !== 'brouillon') throw new ComptaException('Ce brouillon a déjà été traité (' . $draft['status'] . ')');

    $lines = $pdo->prepare('SELECT * FROM entry_draft_lines WHERE draft_id = ? ORDER BY position');
    $lines->execute([$draftId]);
    $entryLines = array_map(fn($l) => [
        'account_id'       => (int) $l['account_id'],
        'debit'            => (float) $l['debit'],
        'credit'           => (float) $l['credit'],
        'label'            => (string) $l['label'],
        'tiers_contact_id' => (string) $l['tiers_contact_id'],
        'cost_center_id'   => $l['cost_center_id'],
    ], $lines->fetchAll());

    $entryId = compta_post_entry($pdo, [
        'entry_date'    => $draft['entry_date'],
        'label'         => $draft['label'],
        'lines'         => $entryLines,
        'journal_id'    => $journalIdOverride ?? $draft['suggested_journal_id'],
        'piece_kind'    => $draft['piece_kind_suggested'],
        'source_module' => $draft['source_module'],
        'source_ref_id' => $draft['source_ref_id'],
        'created_by'    => $userName,
    ]);

    $pdo->prepare("UPDATE entry_drafts SET status='validee', validated_entry_id=? WHERE id=?")
        ->execute([$entryId, $draftId]);

    if (str_starts_with((string) $draft['source_kind'], 'invoice_')) {
        $pdo->prepare("UPDATE invoices SET status='ouverte', journal_entry_id=? WHERE draft_id=? AND status='brouillon'")
            ->execute([$entryId, $draftId]);
    }

    compta_audit_log($pdo, 'entry_draft', $draftId, 'validate', "Brouillon #$draftId validé — " . $draft['label'], $userName);

    return $entryId;
}

/**
 * Rejette un brouillon jamais validé : suppression pure (cascade sur ses
 * lignes). S'il est lié à une facture, la facture disparaît avec lui — elle
 * n'a jamais existé comptablement, à la différence d'une facture annulée
 * après validation, qui reste tracée par extourne (invoice_cancel).
 */
function compta_reject_draft(PDO $pdo, int $draftId, string $userName): void {
    $st = $pdo->prepare('SELECT * FROM entry_drafts WHERE id = ?');
    $st->execute([$draftId]);
    $draft = $st->fetch();
    if (!$draft) throw new ComptaException('Brouillon introuvable');
    if ($draft['status'] !== 'brouillon') throw new ComptaException('Ce brouillon a déjà été traité (' . $draft['status'] . ')');

    if (str_starts_with((string) $draft['source_kind'], 'invoice_')) {
        $pdo->prepare("DELETE FROM invoices WHERE draft_id=? AND status='brouillon'")->execute([$draftId]);
    }
    $pdo->prepare('DELETE FROM entry_drafts WHERE id = ?')->execute([$draftId]);

    compta_audit_log($pdo, 'entry_draft', $draftId, 'reject', "Brouillon #$draftId rejeté — " . $draft['label'], $userName);
}

/* ------------------------------------------------------- Exercices, périodes */

/** Exercice couvrant une date, quel que soit son statut, ou null. */
function compta_fiscal_year_for(PDO $pdo, string $date): ?array {
    $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1');
    $st->execute([$date]);
    return $st->fetch() ?: null;
}

/**
 * Exercice courant : celui qui couvre aujourd'hui, sinon le plus récent encore
 * ouvert. Même logique que mfc_club_current_season_id() pour les saisons
 * sportives, l'exercice comptable épousant la saison (1er juillet - 30 juin).
 */
function compta_current_fiscal_year(PDO $pdo): ?array {
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date LIMIT 1');
    $st->execute([$today]);
    if ($fy = $st->fetch()) return $fy;
    return $pdo->query("SELECT * FROM fiscal_years WHERE status = 'ouvert' ORDER BY start_date DESC LIMIT 1")->fetch() ?: null;
}

function compta_period_for(PDO $pdo, string $date, int $fiscalYearId): ?int {
    $st = $pdo->prepare('SELECT id FROM periods WHERE ? BETWEEN start_date AND end_date AND (fiscal_year_id = ? OR fiscal_year_id IS NULL) ORDER BY start_date DESC LIMIT 1');
    $st->execute([$date, $fiscalYearId]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function compta_period_is_closed(PDO $pdo, int $periodId): bool {
    $st = $pdo->prepare('SELECT closed FROM periods WHERE id = ?');
    $st->execute([$periodId]);
    return (bool) $st->fetchColumn();
}

/* ------------------------------------------------------------- Numérotation */

/**
 * Natures de pièce d'origine. Elles restent le socle de la numérotation, mais
 * ne l'enferment plus : depuis les journaux, tout code de journal existant est
 * une nature valable. Cette table sert de repli quand la table `journals` n'est
 * pas encore semée, et de garde-fou pour les codes engendrés par le moteur.
 */
const COMPTA_PIECE_KINDS = [
    'OUV' => "Écriture d'ouverture",
    'OD'  => 'Opération diverse',
    'ACH' => 'Facture fournisseur',
    'VTE' => 'Facture client',
    'BQ'  => 'Règlement bancaire',
    'CLO' => "Clôture d'exercice",
];

/**
 * Journal d'une écriture : celui demandé s'il est actif, sinon celui dont le
 * code correspond à la nature de pièce. Renvoie null si la table n'existe pas
 * encore, auquel cas la numérotation retombe sur la nature seule.
 */
function compta_journal_resolve(PDO $pdo, mixed $journalId, string $kind): ?array {
    static $missing = false;
    if ($missing) return null;
    try {
        if ($journalId) {
            $st = $pdo->prepare('SELECT * FROM journals WHERE id = ? AND active = 1');
            $st->execute([(int) $journalId]);
            if ($j = $st->fetch()) return $j;
            throw new ComptaException('Journal inconnu ou désactivé');
        }
        $st = $pdo->prepare('SELECT * FROM journals WHERE code = ? AND active = 1');
        $st->execute([$kind !== '' ? $kind : 'OD']);
        if ($j = $st->fetch()) return $j;
        $st->execute(['OD']);
        return $st->fetch() ?: null;
    } catch (ComptaException $e) {
        throw $e;
    } catch (\PDOException $e) {
        $missing = true;   // base antérieure aux journaux : on n'insiste pas
        return null;
    }
}

/**
 * Numéro de pièce suivant, continu et sans trou par exercice et par nature
 * (ex. VTE-2627-0042). La continuité est une exigence de traçabilité : c'est
 * elle qui permet à l'organe de révision de constater qu'aucune pièce n'a
 * disparu. Une écriture erronée s'extourne, elle ne se supprime pas.
 */
function compta_next_piece_ref(PDO $pdo, int $fiscalYearId, string $kind): string {
    /* Le code peut venir d'un journal créé par l'utilisateur : il est accepté
       tel quel dès lors qu'il ne contient que des majuscules et des chiffres,
       puisqu'il sert de préfixe à un numéro de pièce. */
    if (!isset(COMPTA_PIECE_KINDS[$kind]) && !preg_match('/^[A-Z0-9]{2,8}$/', $kind)) $kind = 'OD';

    $pdo->prepare('INSERT OR IGNORE INTO piece_sequences (fiscal_year_id, kind, last_number) VALUES (?,?,0)')
        ->execute([$fiscalYearId, $kind]);
    $pdo->prepare('UPDATE piece_sequences SET last_number = last_number + 1 WHERE fiscal_year_id = ? AND kind = ?')
        ->execute([$fiscalYearId, $kind]);

    $st = $pdo->prepare('SELECT last_number FROM piece_sequences WHERE fiscal_year_id = ? AND kind = ?');
    $st->execute([$fiscalYearId, $kind]);
    $n = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT start_date, end_date FROM fiscal_years WHERE id = ?');
    $st->execute([$fiscalYearId]);
    $fy = $st->fetch();
    /* Suffixe d'exercice « 2627 » pour la saison 2026-2027 : lisible sur une
       pièce papier, et stable même si le libellé de l'exercice est renommé. */
    $tag = $fy ? substr((string) $fy['start_date'], 2, 2) . substr((string) $fy['end_date'], 2, 2) : date('y');

    return sprintf('%s-%s-%04d', $kind, $tag, $n);
}

/* ----------------------------------------------------------------- Réglages */

/**
 * Valeur d'un réglage, ou null. Les réglages portent soit un compte
 * (account_id), soit une valeur texte, selon leur nature.
 */
function compta_setting(PDO $pdo, string $scope, string $key): ?array {
    $st = $pdo->prepare('SELECT * FROM compta_settings WHERE scope = ? AND setting_key = ? LIMIT 1');
    $st->execute([$scope, $key]);
    return $st->fetch() ?: null;
}

function compta_setting_account(PDO $pdo, string $scope, string $key): ?int {
    $s = compta_setting($pdo, $scope, $key);
    return ($s && $s['account_id']) ? (int) $s['account_id'] : null;
}

function compta_setting_value(PDO $pdo, string $scope, string $key, string $default = ''): string {
    $s = compta_setting($pdo, $scope, $key);
    return ($s && $s['value'] !== null && $s['value'] !== '') ? (string) $s['value'] : $default;
}

/** Identifiant d'un compte à partir de son numéro, ou null s'il n'existe pas. */
function compta_account_by_number(PDO $pdo, string $number): ?int {
    $st = $pdo->prepare('SELECT id FROM accounts WHERE number = ? LIMIT 1');
    $st->execute([$number]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}
