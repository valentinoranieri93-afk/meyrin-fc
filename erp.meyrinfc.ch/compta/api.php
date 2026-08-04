<?php
/**
 * Comptabilité Meyrin FC — API backend
 * PHP 8.x + SQLite (PDO). Aucune dépendance externe.
 *
 * Phase 1 : plan comptable, écritures manuelles (partie double), comptes
 * bancaires, import de relevé camt.053 (ISO 20022) et rapprochement.
 * Les phases suivantes (branchement automatique de Sponsors/Commandes/RH/
 * Arbitrage, module Membres, rapports avancés) ne sont pas dans ce fichier.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/mfc_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------------------------------------------------------------- DB */

const DB_DIR  = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/compta.sqlite';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DB_DIR)) mkdir(DB_DIR, 0775, true);
    $ht = DB_DIR . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT '',
        class TEXT NOT NULL DEFAULT 'charge',
        currency TEXT NOT NULL DEFAULT 'CHF',
        allow_lettrage INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS periods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        closed INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS journal_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_date TEXT NOT NULL,
        piece_ref TEXT DEFAULT '',
        label TEXT NOT NULL,
        source_module TEXT NOT NULL DEFAULT 'manuel',
        source_ref_id TEXT DEFAULT '',
        period_id INTEGER REFERENCES periods(id),
        created_by TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS journal_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_id INTEGER NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id),
        debit REAL NOT NULL DEFAULT 0,
        credit REAL NOT NULL DEFAULT 0,
        label TEXT DEFAULT '',
        tiers_contact_id TEXT DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS bank_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'bank',
        iban TEXT DEFAULT '',
        currency TEXT NOT NULL DEFAULT 'CHF',
        account_id INTEGER REFERENCES accounts(id),
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS bank_import_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bank_account_id INTEGER NOT NULL REFERENCES bank_accounts(id),
        filename TEXT DEFAULT '',
        format TEXT DEFAULT 'camt053',
        imported_count INTEGER DEFAULT 0,
        imported_by TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now')),
        rolled_back INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS bank_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bank_account_id INTEGER NOT NULL REFERENCES bank_accounts(id),
        batch_id INTEGER REFERENCES bank_import_batches(id),
        booking_date TEXT NOT NULL,
        value_date TEXT DEFAULT '',
        amount REAL NOT NULL,
        currency TEXT NOT NULL DEFAULT 'CHF',
        label TEXT DEFAULT '',
        counterparty TEXT DEFAULT '',
        reference TEXT DEFAULT '',
        bank_ref TEXT DEFAULT '',
        reconciled_state TEXT NOT NULL DEFAULT 'unmatched',
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS reconciliations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bank_transaction_id INTEGER NOT NULL REFERENCES bank_transactions(id),
        journal_entry_id INTEGER NOT NULL REFERENCES journal_entries(id),
        amount_applied REAL NOT NULL,
        reconciled_at TEXT DEFAULT (datetime('now')),
        reconciled_by TEXT DEFAULT ''
    );
    ");

    /* Anti-doublon d'import : une référence banque (AcctSvcrRef) ne doit jamais
       être importée deux fois pour le même compte. Index créé séparément (pas
       en CREATE TABLE) pour pouvoir tolérer les bank_ref vides (relevés sans
       référence) sans bloquer tout le reste. */
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_txn_ref
                ON bank_transactions(bank_account_id, bank_ref) WHERE bank_ref != ''");

    // Seed du plan comptable réel du club, une seule fois.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    if ($count === 0) {
        seed_accounts($pdo);
    }
}

function seed_accounts(PDO $pdo): void {
    $ins = $pdo->prepare('INSERT OR IGNORE INTO accounts (number, name, category, class, allow_lettrage) VALUES (?,?,?,?,?)');
    foreach (COMPTA_ACCOUNTS_SEED as [$number, $name, $category, $class, $lettrage]) {
        $ins->execute([$number, $name, $category, $class, $lettrage]);
    }
}

/* ---------------------------------------------------------------- Helpers */

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $_POST ?: [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ($_POST ?: []);
}
function out(mixed $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $m, int $code = 400): never { out(['error' => $m], $code); }
function s(array $b, string $k, string $def = ''): string { return trim((string)($b[$k] ?? $def)); }
function i(array $b, string $k, int $def = 0): int { return (int)($b[$k] ?? $def); }
function n(array $b, string $k, float $def = 0.0): float { return (float)($b[$k] ?? $def); }
function esc_num(mixed $v): float { return round((float)$v, 2); }

$session = mfc_require_api('compta');
$userName = $session['name'] ?? '';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b      = body();

/* ---------------------------------------------------------------- Permissions
 *
 * Table déclarative, vérifiée en un seul point. REFUS PAR DÉFAUT : une action
 * absente exige journal.edit, le droit le plus sensible du module (créer une
 * écriture), plutôt qu'un accès libre.
 */
const CP_PERMS = [
    'me'                      => null,
    'accounts'                => ['GET' => 'accounts.view', 'write' => 'accounts.edit'],
    'entries'                 => ['GET' => 'journal.view',  'write' => 'journal.edit'],
    'entry_delete'            => 'journal.edit',
    'periods'                 => ['GET' => 'journal.view',  'write' => 'periods.close'],
    'bank_accounts'           => ['GET' => 'bank.view',     'write' => 'bank.manage'],
    'bank_transactions'       => 'bank.view',
    'bank_import_preview'     => 'bank.import',
    'bank_import_commit'      => 'bank.import',
    'bank_import_batches'     => 'bank.view',
    'bank_import_rollback'    => 'bank.import',
    'reconcile'               => 'bank.reconcile',
    'reconcile_undo'          => 'bank.reconcile',
    'reconcile_suggestions'   => 'bank.view',
    'report_balance'          => 'reports.view',
    'report_journal'          => 'reports.view',
];

$rule = array_key_exists($action, CP_PERMS) ? CP_PERMS[$action] : 'journal.edit';
if (is_array($rule)) $rule = ($method === 'GET') ? $rule['GET'] : $rule['write'];
if ($rule !== null) mfc_require_perm('compta.' . $rule);

switch ($action) {

case 'me':
    out(['user' => ['name' => $userName]]);

/* ============================================================ PLAN COMPTABLE */

case 'accounts': {
    $pdo = db();
    if ($method === 'GET') {
        $active = $_GET['active'] ?? '1';
        $sql = 'SELECT * FROM accounts';
        $params = [];
        if ($active !== 'all') { $sql .= ' WHERE active = ?'; $params[] = (int) $active; }
        $sql .= ' ORDER BY number';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        out($st->fetchAll());
    }
    if ($method === 'POST') {
        $number = s($b, 'number');
        $name   = s($b, 'name');
        if (!$number || !$name) fail('Numéro et nom du compte requis');
        $class = s($b, 'class', 'charge');
        if (!in_array($class, ['actif', 'passif', 'produit', 'charge'], true)) fail('Classe de compte invalide');
        try {
            $pdo->prepare('INSERT INTO accounts (number, name, category, class, currency, allow_lettrage) VALUES (?,?,?,?,?,?)')
                ->execute([$number, $name, s($b, 'category'), $class, s($b, 'currency', 'CHF'), i($b, 'allow_lettrage')]);
            out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        } catch (\Exception $e) { fail('Ce numéro de compte existe déjà'); }
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $class = s($b, 'class', 'charge');
        if (!in_array($class, ['actif', 'passif', 'produit', 'charge'], true)) fail('Classe de compte invalide');
        $pdo->prepare('UPDATE accounts SET name=?, category=?, class=?, currency=?, allow_lettrage=?, active=? WHERE id=?')
            ->execute([s($b, 'name'), s($b, 'category'), $class, s($b, 'currency', 'CHF'), i($b, 'allow_lettrage'), i($b, 'active', 1), $id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/* ============================================================ ÉCRITURES (JOURNAL) */

case 'entries': {
    $pdo = db();
    if ($method === 'GET') {
        $where = ['1=1']; $params = [];
        if (!empty($_GET['from'])) { $where[] = 'e.entry_date >= ?'; $params[] = $_GET['from']; }
        if (!empty($_GET['to']))   { $where[] = 'e.entry_date <= ?'; $params[] = $_GET['to']; }
        if (!empty($_GET['source_module'])) { $where[] = 'e.source_module = ?'; $params[] = $_GET['source_module']; }
        $sql = 'SELECT e.* FROM journal_entries e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.entry_date DESC, e.id DESC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $entries = $st->fetchAll();

        if ($entries) {
            $ids = array_column($entries, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $lst = $pdo->prepare("SELECT l.*, a.number AS account_number, a.name AS account_name
                                   FROM journal_lines l JOIN accounts a ON a.id = l.account_id
                                   WHERE l.entry_id IN ($ph) ORDER BY l.id");
            $lst->execute($ids);
            $byEntry = [];
            foreach ($lst->fetchAll() as $l) { $byEntry[$l['entry_id']][] = $l; }
            foreach ($entries as &$e) { $e['lines'] = $byEntry[$e['id']] ?? []; }
        }
        out($entries);
    }
    if ($method === 'POST') {
        $date  = s($b, 'entry_date');
        $label = s($b, 'label');
        $lines = $b['lines'] ?? [];
        if (!$date) fail('Date requise');
        if (!$label) fail('Libellé requis');
        if (!is_array($lines) || count($lines) < 2) fail('Une écriture nécessite au moins deux lignes');

        $totalDebit = 0.0; $totalCredit = 0.0;
        $cleanLines = [];
        foreach ($lines as $l) {
            $accountId = i($l, 'account_id');
            $debit     = esc_num($l['debit'] ?? 0);
            $credit    = esc_num($l['credit'] ?? 0);
            if (!$accountId) fail('Chaque ligne doit avoir un compte');
            if ($debit < 0 || $credit < 0) fail('Montants négatifs interdits');
            if ($debit > 0 && $credit > 0) fail('Une ligne ne peut pas être à la fois débitrice et créditrice');
            if ($debit == 0 && $credit == 0) continue;
            $totalDebit  += $debit;
            $totalCredit += $credit;
            $cleanLines[] = [$accountId, $debit, $credit, s($l, 'label'), s($l, 'tiers_contact_id')];
        }
        if (count($cleanLines) < 2) fail('Une écriture nécessite au moins deux lignes avec un montant');
        if (abs($totalDebit - $totalCredit) > 0.005) {
            fail(sprintf('Écriture déséquilibrée : débit %.2f ≠ crédit %.2f', $totalDebit, $totalCredit));
        }

        $periodId = resolve_period($pdo, $date);
        if ($periodId && period_is_closed($pdo, $periodId)) fail('La période comptable de cette date est clôturée');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO journal_entries (entry_date, piece_ref, label, source_module, source_ref_id, period_id, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$date, s($b, 'piece_ref'), $label, 'manuel', '', $periodId, $userName]);
            $entryId = (int) $pdo->lastInsertId();
            $lineSt = $pdo->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, label, tiers_contact_id) VALUES (?,?,?,?,?,?)');
            foreach ($cleanLines as $cl) { $lineSt->execute(array_merge([$entryId], $cl)); }
            $pdo->commit();
            out(['ok' => true, 'id' => $entryId]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail('Erreur lors de la création de l\'écriture : ' . $e->getMessage(), 500);
        }
    }
    fail('Méthode non supportée', 405);
}

case 'entry_delete': {
    $pdo = db();
    $id = i($_GET, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
    $st->execute([$id]);
    $entry = $st->fetch();
    if (!$entry) fail('Écriture introuvable', 404);
    if ($entry['source_module'] !== 'manuel') fail("Cette écriture vient d'un autre module, elle ne se supprime pas ici", 409);
    if ($entry['period_id'] && period_is_closed($pdo, (int) $entry['period_id'])) fail('La période comptable de cette écriture est clôturée');
    $recSt = $pdo->prepare('SELECT COUNT(*) FROM reconciliations WHERE journal_entry_id = ?');
    $recSt->execute([$id]);
    if ((int) $recSt->fetchColumn() > 0) fail('Cette écriture est rapprochée avec une transaction bancaire, annulez le rapprochement avant de la supprimer', 409);
    $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
    out(['ok' => true]);
}

/* ============================================================ PÉRIODES */

case 'periods': {
    $pdo = db();
    if ($method === 'GET') {
        out($pdo->query('SELECT * FROM periods ORDER BY start_date DESC')->fetchAll());
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        $start = s($b, 'start_date');
        $end   = s($b, 'end_date');
        if (!$label || !$start || !$end) fail('Libellé et dates requis');
        $pdo->prepare('INSERT INTO periods (label, start_date, end_date) VALUES (?,?,?)')
            ->execute([$label, $start, $end]);
        out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $pdo->prepare('UPDATE periods SET closed = ? WHERE id = ?')->execute([i($b, 'closed', 1), $id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/* ============================================================ COMPTES BANCAIRES */

case 'bank_accounts': {
    $pdo = db();
    if ($method === 'GET') {
        out($pdo->query('SELECT ba.*, a.number AS account_number FROM bank_accounts ba
                          LEFT JOIN accounts a ON a.id = ba.account_id ORDER BY ba.name')->fetchAll());
    }
    if ($method === 'POST') {
        $name = s($b, 'name');
        if (!$name) fail('Nom requis');
        $type = s($b, 'type', 'bank');
        if (!in_array($type, ['bank', 'card', 'cash'], true)) fail('Type de compte invalide');
        $pdo->prepare('INSERT INTO bank_accounts (name, type, iban, currency, account_id) VALUES (?,?,?,?,?)')
            ->execute([$name, $type, s($b, 'iban'), s($b, 'currency', 'CHF'), i($b, 'account_id') ?: null]);
        out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $pdo->prepare('UPDATE bank_accounts SET name=?, iban=?, currency=?, account_id=?, active=? WHERE id=?')
            ->execute([s($b, 'name'), s($b, 'iban'), s($b, 'currency', 'CHF'), i($b, 'account_id') ?: null, i($b, 'active', 1), $id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/* ============================================================ TRANSACTIONS BANCAIRES */

case 'bank_transactions': {
    $pdo = db();
    $where = ['1=1']; $params = [];
    if (!empty($_GET['bank_account_id'])) { $where[] = 'bank_account_id = ?'; $params[] = i($_GET, 'bank_account_id'); }
    if (!empty($_GET['state']))           { $where[] = 'reconciled_state = ?'; $params[] = $_GET['state']; }
    if (!empty($_GET['from']))            { $where[] = 'booking_date >= ?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']))              { $where[] = 'booking_date <= ?'; $params[] = $_GET['to']; }
    $sql = 'SELECT * FROM bank_transactions WHERE ' . implode(' AND ', $where) . ' ORDER BY booking_date DESC, id DESC LIMIT 1000';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    out($st->fetchAll());
}

/* ============================================================ IMPORT RELEVÉ (camt.053) */

case 'bank_import_preview': {
    if (empty($_FILES['file'])) fail('Fichier requis');
    $bankAccountId = i($_POST, 'bank_account_id');
    if (!$bankAccountId) fail('Compte bancaire cible requis');
    $content = file_get_contents($_FILES['file']['tmp_name']);
    if ($content === false) fail('Impossible de lire le fichier');
    try {
        $parsed = parse_camt053($content);
    } catch (\Exception $e) {
        fail('Fichier camt.053 invalide : ' . $e->getMessage());
    }
    $pdo = db();
    $existing = $pdo->prepare('SELECT bank_ref FROM bank_transactions WHERE bank_account_id = ? AND bank_ref = ? AND bank_ref != \'\'');
    $new = 0; $known = 0;
    foreach ($parsed['entries'] as &$e) {
        $isKnown = false;
        if ($e['bank_ref'] !== '') {
            $existing->execute([$bankAccountId, $e['bank_ref']]);
            $isKnown = (bool) $existing->fetchColumn();
        }
        $e['already_imported'] = $isKnown;
        $isKnown ? $known++ : $new++;
    }
    out([
        'account_iban' => $parsed['iban'],
        'currency'     => $parsed['currency'],
        'total'        => count($parsed['entries']),
        'new'          => $new,
        'already_known'=> $known,
        'entries'      => $parsed['entries'],
    ]);
}

case 'bank_import_commit': {
    if (empty($_FILES['file'])) fail('Fichier requis');
    $bankAccountId = i($_POST, 'bank_account_id');
    if (!$bankAccountId) fail('Compte bancaire cible requis');
    $content = file_get_contents($_FILES['file']['tmp_name']);
    if ($content === false) fail('Impossible de lire le fichier');
    try {
        $parsed = parse_camt053($content);
    } catch (\Exception $e) {
        fail('Fichier camt.053 invalide : ' . $e->getMessage());
    }

    $pdo = db();
    $ba = $pdo->prepare('SELECT id FROM bank_accounts WHERE id = ?');
    $ba->execute([$bankAccountId]);
    if (!$ba->fetchColumn()) fail('Compte bancaire introuvable', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO bank_import_batches (bank_account_id, filename, format, imported_by) VALUES (?,?,?,?)')
            ->execute([$bankAccountId, $_FILES['file']['name'] ?? '', 'camt053', $userName]);
        $batchId = (int) $pdo->lastInsertId();

        $insSt = $pdo->prepare('INSERT OR IGNORE INTO bank_transactions
            (bank_account_id, batch_id, booking_date, value_date, amount, currency, label, counterparty, reference, bank_ref)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $imported = 0;
        foreach ($parsed['entries'] as $e) {
            $insSt->execute([
                $bankAccountId, $batchId, $e['booking_date'], $e['value_date'], $e['amount'], $e['currency'],
                $e['label'], $e['counterparty'], $e['reference'], $e['bank_ref'],
            ]);
            if ($insSt->rowCount() > 0) $imported++;
        }
        $pdo->prepare('UPDATE bank_import_batches SET imported_count = ? WHERE id = ?')->execute([$imported, $batchId]);
        $pdo->commit();
        out(['ok' => true, 'batch_id' => $batchId, 'imported' => $imported, 'skipped' => count($parsed['entries']) - $imported]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur lors de l\'import : ' . $e->getMessage(), 500);
    }
}

case 'bank_import_batches': {
    $pdo = db();
    out($pdo->query("SELECT bib.*, ba.name AS bank_account_name FROM bank_import_batches bib
                      JOIN bank_accounts ba ON ba.id = bib.bank_account_id
                      ORDER BY bib.created_at DESC LIMIT 100")->fetchAll());
}

case 'bank_import_rollback': {
    $pdo = db();
    $id = i($_GET, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT * FROM bank_import_batches WHERE id = ?');
    $st->execute([$id]);
    $batch = $st->fetch();
    if (!$batch) fail('Lot introuvable', 404);
    if ((int) $batch['rolled_back'] === 1) fail('Ce lot a déjà été annulé');

    $recSt = $pdo->prepare('SELECT COUNT(*) FROM reconciliations r
                             JOIN bank_transactions t ON t.id = r.bank_transaction_id
                             WHERE t.batch_id = ?');
    $recSt->execute([$id]);
    if ((int) $recSt->fetchColumn() > 0) {
        fail('Des transactions de ce lot sont déjà rapprochées, annulez ces rapprochements avant de retirer le lot', 409);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM bank_transactions WHERE batch_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE bank_import_batches SET rolled_back = 1 WHERE id = ?')->execute([$id]);
        $pdo->commit();
        out(['ok' => true]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur lors de l\'annulation : ' . $e->getMessage(), 500);
    }
}

/* ============================================================ RAPPROCHEMENT */

case 'reconcile_suggestions': {
    $pdo = db();
    $txnId = i($_GET, 'bank_transaction_id');
    if (!$txnId) fail('Transaction requise');
    $txSt = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = ?');
    $txSt->execute([$txnId]);
    $txn = $txSt->fetch();
    if (!$txn) fail('Transaction introuvable', 404);

    /* Suggestions best-effort, jamais bloquantes : montant exact d'abord,
       puis même montant sur les 45 derniers jours. L'utilisateur tranche
       toujours in fine, ceci ne fait que lui éviter de chercher à l'aveugle. */
    $amountAbs = abs((float) $txn['amount']);
    $st = $pdo->prepare("
        SELECT e.id, e.entry_date, e.label, e.piece_ref, e.source_module,
               ABS((SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) FROM journal_lines WHERE entry_id = e.id
                    AND account_id IN (SELECT account_id FROM bank_accounts WHERE id = ?))) AS bank_side_amount
        FROM journal_entries e
        WHERE e.id NOT IN (SELECT journal_entry_id FROM reconciliations)
          AND e.entry_date BETWEEN date(?, '-45 days') AND date(?, '+45 days')
        HAVING ABS(bank_side_amount - ?) < 0.01
        ORDER BY ABS(julianday(e.entry_date) - julianday(?)) ASC
        LIMIT 10
    ");
    $st->execute([$txn['bank_account_id'], $txn['booking_date'], $txn['booking_date'], $amountAbs, $txn['booking_date']]);
    out($st->fetchAll());
}

case 'reconcile': {
    $pdo = db();
    $txnId   = i($b, 'bank_transaction_id');
    $entryId = i($b, 'journal_entry_id');
    $amount  = n($b, 'amount_applied');
    if (!$txnId || !$entryId) fail('Transaction et écriture requises');

    $txSt = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = ?');
    $txSt->execute([$txnId]);
    $txn = $txSt->fetch();
    if (!$txn) fail('Transaction introuvable', 404);

    $enSt = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
    $enSt->execute([$entryId]);
    if (!$enSt->fetch()) fail('Écriture introuvable', 404);

    if ($amount <= 0) $amount = abs((float) $txn['amount']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO reconciliations (bank_transaction_id, journal_entry_id, amount_applied, reconciled_by) VALUES (?,?,?,?)')
            ->execute([$txnId, $entryId, esc_num($amount), $userName]);
        $pdo->prepare("UPDATE bank_transactions SET reconciled_state = 'matched' WHERE id = ?")->execute([$txnId]);
        $pdo->commit();
        out(['ok' => true]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur lors du rapprochement : ' . $e->getMessage(), 500);
    }
}

case 'reconcile_undo': {
    $pdo = db();
    $id = i($_GET, 'id');
    if (!$id) fail('ID de rapprochement requis');
    $st = $pdo->prepare('SELECT * FROM reconciliations WHERE id = ?');
    $st->execute([$id]);
    $rec = $st->fetch();
    if (!$rec) fail('Rapprochement introuvable', 404);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM reconciliations WHERE id = ?')->execute([$id]);
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM reconciliations WHERE bank_transaction_id = ?');
        $remaining->execute([$rec['bank_transaction_id']]);
        if ((int) $remaining->fetchColumn() === 0) {
            $pdo->prepare("UPDATE bank_transactions SET reconciled_state = 'unmatched' WHERE id = ?")->execute([$rec['bank_transaction_id']]);
        }
        $pdo->commit();
        out(['ok' => true]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur : ' . $e->getMessage(), 500);
    }
}

/* ============================================================ RAPPORTS */

case 'report_balance': {
    $pdo = db();
    $from = s($_GET, 'from');
    $to   = s($_GET, 'to');
    $where = ['1=1']; $params = [];
    if ($from) { $where[] = 'e.entry_date >= ?'; $params[] = $from; }
    if ($to)   { $where[] = 'e.entry_date <= ?'; $params[] = $to; }
    $sql = "SELECT a.id, a.number, a.name, a.class,
                   COALESCE(SUM(l.debit),0) AS total_debit,
                   COALESCE(SUM(l.credit),0) AS total_credit
            FROM accounts a
            LEFT JOIN journal_lines l ON l.account_id = a.id
            LEFT JOIN journal_entries e ON e.id = l.entry_id AND " . implode(' AND ', $where) . "
            WHERE a.active = 1
            GROUP BY a.id
            ORDER BY a.number";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['balance'] = esc_num((float) $r['total_debit'] - (float) $r['total_credit']);
    }
    out($rows);
}

case 'report_journal': {
    $pdo = db();
    $from = s($_GET, 'from');
    $to   = s($_GET, 'to');
    $where = ['1=1']; $params = [];
    if ($from) { $where[] = 'e.entry_date >= ?'; $params[] = $from; }
    if ($to)   { $where[] = 'e.entry_date <= ?'; $params[] = $to; }
    $sql = "SELECT e.entry_date, e.piece_ref, e.label AS entry_label, e.source_module,
                   a.number AS account_number, a.name AS account_name, l.debit, l.credit, l.label AS line_label
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.entry_id
            JOIN accounts a ON a.id = l.account_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.entry_date, e.id, l.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    out($st->fetchAll());
}

default:
    fail('Action inconnue : ' . htmlspecialchars($action, ENT_QUOTES), 404);
}

/* ---------------------------------------------------------------- Fonctions métier */

/** Retrouve (sans en créer) la période comptable couvrant une date, s'il en existe une. */
function resolve_period(PDO $pdo, string $date): ?int {
    $st = $pdo->prepare('SELECT id FROM periods WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1');
    $st->execute([$date]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}
function period_is_closed(PDO $pdo, int $periodId): bool {
    $st = $pdo->prepare('SELECT closed FROM periods WHERE id = ?');
    $st->execute([$periodId]);
    return (bool) $st->fetchColumn();
}

/**
 * Parseur camt.053 (ISO 20022). Format confirmé sur un relevé réel Raiffeisen :
 * un <Stmt> unique, une liste d'<Ntry> (une par mouvement), chacune portant
 * éventuellement une <NtryDtls><TxDtls> avec la référence de paiement
 * (<RmtInf><Ustrd>, ex. "COTI/26-27/0583" ou "SP/25-26/0014") et le tiers
 * (<RltdPties>). Beaucoup d'écritures bancaires internes (frais, taxes) n'ont
 * ni RmtInf ni RltdPties : on retombe alors sur <AddtlNtryInf>.
 *
 * SimpleXML avec un espace de noms par défaut (sans préfixe dans le document)
 * ignore les accès directs par propriété : on utilise donc XPath partout,
 * avec un préfixe 'n' enregistré nous-mêmes.
 */
function parse_camt053(string $xmlContent): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        $err = libxml_get_errors();
        libxml_clear_errors();
        throw new \Exception($err ? trim($err[0]->message) : 'XML illisible');
    }
    $ns = 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.08';
    $xml->registerXPathNamespace('n', $ns);

    $stmts = $xml->xpath('//n:Stmt');
    if (!$stmts) throw new \Exception('Aucun relevé (Stmt) trouvé dans le fichier');
    $stmt = $stmts[0];
    $stmt->registerXPathNamespace('n', $ns);

    $iban = xp1($stmt, './/n:Acct/n:Id/n:IBAN');
    $ccy  = xp1($stmt, './/n:Acct/n:Ccy') ?: 'CHF';

    $entries = [];
    foreach ($stmt->xpath('.//n:Ntry') as $ntry) {
        $ntry->registerXPathNamespace('n', $ns);

        $amountNode = $ntry->xpath('./n:Amt');
        $amount = $amountNode ? (float) $amountNode[0] : 0.0;
        $entryCcy = $amountNode ? (string) ($amountNode[0]->attributes()['Ccy'] ?? $ccy) : $ccy;
        $cdtDbt = xp1($ntry, './n:CdtDbtInd');
        $signedAmount = ($cdtDbt === 'DBIT') ? -$amount : $amount;

        $bookDate = xp1($ntry, './n:BookgDt/n:Dt') ?: xp1($ntry, './n:BookgDt/n:DtTm');
        $valDate  = xp1($ntry, './n:ValDt/n:Dt')   ?: xp1($ntry, './n:ValDt/n:DtTm');
        if ($bookDate && strlen($bookDate) > 10) $bookDate = substr($bookDate, 0, 10);
        if ($valDate && strlen($valDate) > 10)   $valDate  = substr($valDate, 0, 10);

        $acctSvcrRef = xp1($ntry, './n:AcctSvcrRef');
        $addtlInfo   = xp1($ntry, './n:AddtlNtryInf');
        $ustrd       = xp1($ntry, './/n:NtryDtls/n:TxDtls/n:RmtInf/n:Ustrd');

        $counterparty = xp1($ntry, './/n:NtryDtls/n:TxDtls/n:RltdPties/n:UltmtDbtr/n:Pty/n:Nm')
            ?: xp1($ntry, './/n:NtryDtls/n:TxDtls/n:RltdPties/n:Dbtr/n:Pty/n:Nm')
            ?: xp1($ntry, './/n:NtryDtls/n:TxDtls/n:RltdPties/n:UltmtCdtr/n:Pty/n:Nm')
            ?: xp1($ntry, './/n:NtryDtls/n:TxDtls/n:RltdPties/n:Cdtr/n:Pty/n:Nm')
            ?: '';

        $label = $ustrd ?: $addtlInfo ?: ($counterparty ?: 'Mouvement bancaire');

        if (!$bookDate) continue; // entrée sans date exploitable : ignorée plutôt que de planter tout l'import

        $entries[] = [
            'booking_date' => $bookDate,
            'value_date'   => $valDate ?: $bookDate,
            'amount'       => round($signedAmount, 2),
            'currency'     => $entryCcy,
            'label'        => $label,
            'counterparty' => $counterparty,
            'reference'    => $ustrd,
            'bank_ref'     => $acctSvcrRef,
        ];
    }

    return ['iban' => $iban, 'currency' => $ccy, 'entries' => $entries];
}

/** Premier résultat XPath en chaîne, ou '' si absent — évite de répéter le garde ?? '' partout. */
function xp1(\SimpleXMLElement $node, string $path): string {
    $r = $node->xpath($path);
    return $r ? trim((string) $r[0]) : '';
}

/* ---------------------------------------------------------------- Plan comptable réel du club
 * Importé depuis l'export Odoo fourni par Valentino (2026-08-04). Colonnes :
 * [numéro, nom, catégorie Odoo d'origine, classe (actif/passif/produit/charge), lettrage autorisé].
 */
const COMPTA_ACCOUNTS_SEED = [
        ["1000", "Caisse secrétariat", "Banque et espèces", "actif", 0],
        ["1001", "Espèces", "Banque et espèces", "actif", 0],
        ["1002", "Espèces Magasin de vêtements", "Banque et espèces", "actif", 0],
        ["1019", "Banque Raiffeisen Crédit Covid", "Banque et espèces", "actif", 0],
        ["1020", "Banque Raiffeisen CC", "Banque et espèces", "actif", 0],
        ["1021", "Banque Raiffeisen Meyrin Cup \"52295\"", "Banque et espèces", "actif", 0],
        ["1022", "Compte d'attente de la banque", "Banque et espèces", "actif", 0],
        ["1023", "Compte transfert cash", "Banque et espèces", "actif", 0],
        ["1024", "Banque Raiffeisen \"Compte GEF\"", "Banque et espèces", "actif", 0],
        ["1030", "Compte MyPos", "Banque et espèces", "actif", 0],
        ["1090", "Transfert de liquidités", "Actifs circulants", "actif", 1],
        ["1100", "Débiteurs", "Client", "actif", 1],
        ["1170", "Impôt préalable: TVA s/matériel, marchandises, prestations et énergie", "Actifs circulants", "actif", 0],
        ["1171", "Impôt préalable: TVA s/investissements et autres charges d'exploitation", "Actifs circulants", "actif", 0],
        ["1200", "Stocks de marchandises commerciales", "Actifs circulants", "actif", 0],
        ["1300", "Actifs transitoires", "Actifs circulants", "actif", 0],
        ["1400", "Titres de sociétariat Raiffeisen", "Actifs immobilisés", "actif", 0],
        ["1500", "Machines et appareils", "Actifs immobilisés", "actif", 0],
        ["1510", "Mobilier et installations", "Actifs immobilisés", "actif", 0],
        ["2000", "Créanciers", "Fournisseur", "passif", 1],
        ["2002", "Salaires à payer", "Dettes à court terme", "passif", 1],
        ["2100", "Dettes bancaires à court terme", "Dettes à court terme", "passif", 0],
        ["2200", "TVA due", "Dettes à court terme", "passif", 0],
        ["2201", "Décompte TVA", "Dettes à court terme", "passif", 0],
        ["2205", "TVA à payer (DFN)", "Dettes à court terme", "passif", 1],
        ["2206", "Impôt anticipé dû", "Dettes à court terme", "passif", 0],
        ["2208", "Impôts directs", "Dettes à court terme", "passif", 0],
        ["2210", "Autres dettes à court terme", "Dettes à court terme", "passif", 0],
        ["2261", "Dividendes", "Dettes à court terme", "passif", 0],
        ["2270", "Compte courant LPP", "Dettes à court terme", "passif", 1],
        ["2271", "Compte courant OCAS", "Dettes à court terme", "passif", 1],
        ["2272", "Assurances sociales CAF", "Dettes à court terme", "passif", 0],
        ["2273", "Compte courant LAA", "Dettes à court terme", "passif", 1],
        ["2274", "Assurance maladie journalière IJM", "Dettes à court terme", "passif", 0],
        ["2279", "Compte courant impôt à la source", "Dettes à court terme", "passif", 1],
        ["2299", "Compte courant Buvette des Arbères", "Dettes à court terme", "passif", 0],
        ["2300", "Passifs transitoires", "Dettes à court terme", "passif", 0],
        ["2301", "Cotisations perçues en avance", "Dettes à court terme", "passif", 0],
        ["2302", "Autres encaissements reçus en avance (Camps)", "Dettes à court terme", "passif", 0],
        ["2325", "Provision buanderie", "Dettes à court terme", "passif", 0],
        ["2900", "Résultat exercices précédents", "Fonds propres", "passif", 0],
        ["2910", "Autres dettes à long terme", "Passif immobilisé", "passif", 0],
        ["3000", "Cotisations joueurs", "Revenus", "produit", 0],
        ["3003", "Cotis des membres supporters / club des 100", "Revenus", "produit", 0],
        ["3009", "Qualifications ASF (frais formation MFC)", "Revenus", "produit", 0],
        ["3010", "Matches de Championnat", "Revenus", "produit", 0],
        ["3011", "Matches de coupe de Suisse", "Revenus", "produit", 0],
        ["3013", "Matches amicaux / Gala", "Revenus", "produit", 0],
        ["3015", "Indemnité assurance accident LAA", "Revenus", "produit", 0],
        ["3020", "Subvention commune", "Revenus", "produit", 0],
        ["3099", "Billeterie abonnements", "Revenus", "produit", 0],
        ["3100", "Subvention SFL/PL/AF/ACGF", "Revenus", "produit", 0],
        ["3130", "Subventions diverses", "Revenus", "produit", 0],
        ["3140", "Subventions GEF", "Revenus", "produit", 0],
        ["3150", "Suvention Aide au Sport", "Revenus", "produit", 0],
        ["3190", "Subventions J+S", "Revenus", "produit", 0],
        ["3200", "Sponsoring", "Revenus", "produit", 0],
        ["3201", "Equipement équipe", "Revenus", "produit", 0],
        ["3210", "Banderoles/panneaux d'affichage", "Revenus", "produit", 0],
        ["3220", "Publicité dans le journal / publication / Site Web", "Revenus", "produit", 0],
        ["3250", "Programmes de matches", "Revenus", "produit", 0],
        ["3255", "Sponsors maillots", "Revenus", "produit", 0],
        ["3262", "Opérations commerciales (paninis, pandoros, …)", "Revenus", "produit", 0],
        ["3301", "Vente boutique", "Revenus", "produit", 0],
        ["3501", "Tournoi en salle", "Revenus", "produit", 0],
        ["3502", "Tournois MFC", "Revenus", "produit", 0],
        ["3504", "Manifs extra-sportives/Journée du club", "Revenus", "produit", 0],
        ["3505", "Fête 1er août", "Revenus", "produit", 0],
        ["3506", "Repas de soutien / Gala", "Revenus", "produit", 0],
        ["3507", "Camps", "Revenus", "produit", 0],
        ["3508", "Voyages et tournois (nvx dès 23/24)", "Revenus", "produit", 0],
        ["3510", "Tournois externe", "Revenus", "produit", 0],
        ["3800", "Loyer buvette Arbères", "Revenus", "produit", 0],
        ["3810", "Location diverses", "Revenus", "produit", 0],
        ["3850", "Donation", "Revenus", "produit", 0],
        ["3880", "Produits des amendes", "Revenus", "produit", 0],
        ["3890", "Produits divers et autres prod.expl.", "Revenus", "produit", 0],
        ["3895", "Produit SAE (nvx depuis 23/24)", "Revenus", "produit", 0],
        ["3899", "Retenue lavage 1ère", "Revenus", "produit", 0],
        ["4000", "Frais organisation de match", "Charges", "charge", 0],
        ["4001", "Frais de déplacement", "Charges", "charge", 0],
        ["4002", "Achat de matériel terrain (hors 11teamsport)", "Charges", "charge", 0],
        ["4003", "Qualifications/licences", "Charges", "charge", 0],
        ["4005", "Frais d'arbitres", "Charges", "charge", 0],
        ["4006", "Amendes", "Charges", "charge", 0],
        ["4009", "Frais de pharmacie/service médical", "Charges", "charge", 0],
        ["4010", "Equipements/Chaussures (y.c. flockage)", "Charges", "charge", 0],
        ["4011", "Tenue membre", "Charges", "charge", 0],
        ["4030", "Tournois (Frais d'inscription)", "Charges", "charge", 0],
        ["4031", "Matches amicaux-gala", "Charges", "charge", 0],
        ["4032", "Plateaux ACGF", "Charges", "charge", 0],
        ["4040", "Voyage / camps / activités 1ère", "Charges", "charge", 0],
        ["4060", "Scouting", "Charges", "charge", 0],
        ["4080", "Club des 100", "Charges", "charge", 0],
        ["4090", "Frais divers", "Charges", "charge", 0],
        ["4200", "Frais programme de matches", "Charges", "charge", 0],
        ["4230", "Frais de documentation, photos", "Charges", "charge", 0],
        ["4290", "Autres charges directes de publicité", "Charges", "charge", 0],
        ["4301", "Achat boutique et flocage", "Charges", "charge", 0],
        ["4410", "Repas SAE - Elite", "Charges", "charge", 0],
        ["4420", "Autes charges du GEF", "Charges", "charge", 0],
        ["4501", "1er août", "Charges", "charge", 0],
        ["4502", "Repas de soutien / Gala", "Charges", "charge", 0],
        ["4503", "Manifestation extra-sportive / journée du club", "Charges", "charge", 0],
        ["4504", "Camps MFC", "Charges", "charge", 0],
        ["4505", "Tournois en salle", "Charges", "charge", 0],
        ["4508", "Voyages et autres tournois (nvx 23/24)", "Charges", "charge", 0],
        ["4509", "Autres manifestations", "Charges", "charge", 0],
        ["4510", "Tournois externe", "Charges", "charge", 0],
        ["4610", "Supports et formation", "Charges", "charge", 0],
        ["4650", "Voyage et représentation", "Charges", "charge", 0],
        ["4690", "Footeco (Cotisation club d'origine)", "Charges", "charge", 0],
        ["4990", "Différences de paiement (profit)", "Revenus", "produit", 0],
        ["5000", "Salaires", "Charges", "charge", 0],
        ["5200", "Indemnités joueurs", "Charges", "charge", 0],
        ["5201", "Indemnités staff 1ère", "Charges", "charge", 0],
        ["5202", "Indemnités entraîneurs", "Charges", "charge", 0],
        ["5203", "Indemnités techniques", "Charges", "charge", 0],
        ["5206", "Primes de matches", "Charges", "charge", 0],
        ["5210", "Indemnités admin (brute)", "Charges", "charge", 0],
        ["5275", "Indeminté assurance accident", "Charges", "charge", 0],
        ["5282", "Frais de formation", "Charges", "charge", 0],
        ["5283", "Frais de voyages", "Charges", "charge", 0],
        ["5289", "Frais de repas,représent.,autres", "Charges", "charge", 0],
        ["5290", "Prestations de tiers/temporaires", "Charges", "charge", 0],
        ["5291", "Indemnités coach J+S", "Charges", "charge", 0],
        ["5292", "Indemnités coach camps", "Charges", "charge", 0],
        ["5700", "AVS,AI,APG,AC", "Charges", "charge", 0],
        ["5701", "Caisse d'allocations familiales (CAF)", "Charges", "charge", 0],
        ["5710", "Assurance-accidents LAA (primes)", "Charges", "charge", 0],
        ["5740", "Assurance maladie journalière IJM", "Charges", "charge", 0],
        ["5750", "Prévoyance professionnelle LPP", "Charges", "charge", 0],
        ["5770", "Impôt à la source", "Charges", "charge", 0],
        ["5880", "Autres frais de personnel", "Charges", "charge", 0],
        ["6000", "Salaires", "Charges", "charge", 0],
        ["6050", "Entretiens, installations", "Charges", "charge", 0],
        ["6060", "Achat de matériel", "Charges", "charge", 0],
        ["6310", "Primes d'assurance (hors LAA)", "Charges", "charge", 0],
        ["6320", "Assurance RC & ménage", "Charges", "charge", 0],
        ["6330", "Redevance à l'Association ACGF/ASF", "Charges", "charge", 0],
        ["6360", "Taxes et autorisations", "Charges", "charge", 0],
        ["6500", "Matériel de bureau", "Charges", "charge", 0],
        ["6501", "Affranchissement", "Charges", "charge", 0],
        ["6502", "Administration générale", "Charges", "charge", 0],
        ["6510", "Communication, téléphonie", "Charges", "charge", 0],
        ["6511", "Abonnement presse", "Charges", "charge", 0],
        ["6530", "Honoraires fiduciaire", "Charges", "charge", 0],
        ["6540", "Comité, AG, organe révision", "Charges", "charge", 0],
        ["6550", "Autres dépenses administratives", "Charges", "charge", 0],
        ["6570", "Frais informatique", "Charges", "charge", 0],
        ["6600", "Frais de publicité", "Charges", "charge", 0],
        ["6610", "Imprimés publicitaires", "Charges", "charge", 0],
        ["6630", "Frais de représentation", "Charges", "charge", 0],
        ["6650", "Opérations commerciales", "Charges", "charge", 0],
        ["6670", "Club des 100", "Charges", "charge", 0],
        ["6690", "Comm. Sur ventes", "Charges", "charge", 0],
        ["6780", "Lavage maillot", "Charges", "charge", 0],
        ["6790", "Autres charges d'exploitation", "Charges", "charge", 0],
        ["6795", "Pertes sur débiteurs", "Charges", "charge", 0],
        ["6800", "Amortissements", "Charges", "charge", 0],
        ["6810", "Charges TVA (DFN)", "Charges", "charge", 0],
        ["6930", "Commission 2% IS", "Charges", "charge", 0],
        ["6940", "Frais bancaires", "Charges", "charge", 0],
        ["6941", "Frais paiements MyPOS", "Charges", "charge", 0],
        ["6942", "Frais paiements Infomaniak", "Charges", "charge", 0],
        ["6950", "Intérets sur prêt bancaire RAIFFEISEN", "Charges", "charge", 0],
        ["6990", "Autres produits financiers", "Charges", "charge", 0],
        ["6991", "Différences de paiement (perte)", "Charges", "charge", 0],
        ["8500", "Charges exceptionnelles hors periode et exploitation", "Charges", "charge", 0],
        ["9999", "Doublon", "Charges", "charge", 0],
        ["999999", "Profits/pertes non distribués", "Bénéfices de l'exercice en cours", "produit", 0],
];
