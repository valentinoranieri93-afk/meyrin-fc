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

/* Incrémenter pour rejouer seed_defaults() sur les bases déjà en service. */
/* Incrémenté à chaque nouveau contenu à semer : c'est ce qui déclenche une
   seule fois, sur une base déjà en service, la création des journaux et le
   rattachement des écritures existantes. */
const COMPTA_SEED_VERSION = 4;

/* Sens comptable de chaque module source : structurel, pas paramétrable.
   Un match d'arbitrage est toujours une charge, un contrat sponsor un produit. */
const COMPTA_MODULE_DIRECTION = [
    'arbitrage' => 'a_payer',
    'commandes' => 'a_payer',
    'sponsors'  => 'a_recevoir',
];

/* Regroupement PAR DÉFAUT à la génération, débrayable à chaque fois.
   'group' : une facture par groupe naturel du module (l'équipe, pour Arbitrage).
   'piece' : une facture par pièce — un contrat sponsor et une facture
   fournisseur sont déjà des pièces unitaires, les regrouper n'aurait pas de sens. */
const COMPTA_MODULE_GROUPING = [
    'arbitrage' => 'group',
    'commandes' => 'piece',
    'sponsors'  => 'piece',
];

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
    CREATE TABLE IF NOT EXISTS fiscal_years (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'ouvert',
        closed_at TEXT DEFAULT '',
        closed_by TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS piece_sequences (
        fiscal_year_id INTEGER NOT NULL REFERENCES fiscal_years(id) ON DELETE CASCADE,
        kind TEXT NOT NULL,
        last_number INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (fiscal_year_id, kind)
    );
    CREATE TABLE IF NOT EXISTS periods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        closed INTEGER NOT NULL DEFAULT 0,
        fiscal_year_id INTEGER REFERENCES fiscal_years(id)
    );
    CREATE TABLE IF NOT EXISTS journal_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_date TEXT NOT NULL,
        piece_ref TEXT DEFAULT '',
        label TEXT NOT NULL,
        source_module TEXT NOT NULL DEFAULT 'manuel',
        source_ref_id TEXT DEFAULT '',
        period_id INTEGER REFERENCES periods(id),
        fiscal_year_id INTEGER REFERENCES fiscal_years(id),
        reversal_of_entry_id INTEGER REFERENCES journal_entries(id),
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
        tiers_contact_id TEXT DEFAULT '',
        cost_center_id INTEGER REFERENCES cost_centers(id)
    );
    CREATE TABLE IF NOT EXISTS cost_centers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'autre',
        club_ref TEXT DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS vat_rates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        rate_percent REAL NOT NULL DEFAULT 0,
        kind TEXT NOT NULL DEFAULT 'vente',
        active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS vat_settlement_rates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        rate_percent REAL NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS compta_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL DEFAULT 'global',
        setting_key TEXT NOT NULL,
        label TEXT DEFAULT '',
        account_id INTEGER REFERENCES accounts(id),
        value TEXT DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        UNIQUE(scope, setting_key)
    );
    CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        direction TEXT NOT NULL DEFAULT 'a_payer',
        piece_ref TEXT DEFAULT '',
        invoice_number TEXT DEFAULT '',
        entry_date TEXT NOT NULL,
        due_date TEXT DEFAULT '',
        label TEXT NOT NULL,
        tiers_contact_id TEXT DEFAULT '',
        tiers_label TEXT DEFAULT '',
        source_module TEXT NOT NULL DEFAULT 'manuel',
        account_id INTEGER REFERENCES accounts(id),
        cost_center_id INTEGER REFERENCES cost_centers(id),
        vat_rate_id INTEGER REFERENCES vat_rates(id),
        amount_vat REAL NOT NULL DEFAULT 0,
        amount_total REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'ouverte',
        journal_entry_id INTEGER REFERENCES journal_entries(id),
        qr_reference TEXT DEFAULT '',
        notes TEXT DEFAULT '',
        created_by TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS invoice_source_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        source_module TEXT NOT NULL,
        source_ref_id TEXT NOT NULL,
        label TEXT DEFAULT '',
        amount REAL NOT NULL DEFAULT 0,
        marked_paid INTEGER NOT NULL DEFAULT 0
    );
    /* Le compte, le prix et la TVA sont COPIÉS sur la ligne à la création, pas
       relus depuis le produit : reclasser un produit l'an prochain ne doit pas
       réécrire une facture déjà comptabilisée. */
    CREATE TABLE IF NOT EXISTS invoice_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        position INTEGER NOT NULL DEFAULT 0,
        product_id INTEGER REFERENCES products(id),
        label TEXT NOT NULL DEFAULT '',
        quantity REAL NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL DEFAULT 0,
        amount REAL NOT NULL DEFAULT 0,
        account_id INTEGER REFERENCES accounts(id),
        vat_rate_id INTEGER REFERENCES vat_rates(id),
        cost_center_id INTEGER REFERENCES cost_centers(id)
    );
    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        account_id INTEGER REFERENCES accounts(id),
        unit_price REAL NOT NULL DEFAULT 0,
        vat_rate_id INTEGER REFERENCES vat_rates(id),
        unit TEXT NOT NULL DEFAULT 'unité',
        direction TEXT NOT NULL DEFAULT 'a_recevoir',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    /* Journaux : chaque écriture est classée dans un journal, qui donne aussi
       le préfixe de sa numérotation (ACH-2627-0042). Les six journaux d'origine
       sont créés au démarrage avec les codes déjà utilisés par les séquences de
       pièces, pour que la numérotation en cours se poursuive sans rupture. */
    CREATE TABLE IF NOT EXISTS journals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'od',
        bank_account_id INTEGER REFERENCES bank_accounts(id),
        is_default INTEGER NOT NULL DEFAULT 0,
        system INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        position INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS payment_terms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        days INTEGER NOT NULL DEFAULT 0,
        is_default INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS invoice_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        filename TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        mime TEXT DEFAULT '',
        size INTEGER NOT NULL DEFAULT 0,
        uploaded_by TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
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
        invoice_id INTEGER REFERENCES invoices(id),
        amount_applied REAL NOT NULL,
        reconciled_at TEXT DEFAULT (datetime('now')),
        reconciled_by TEXT DEFAULT ''
    );
    /* Journal d'audit (art. 957a CO — traçabilité) : qui a fait quoi, sur quelle
       pièce, et avec quelle valeur avant/après pour les modifications de
       paramètres. Alimenté par compta_audit_log() (lib/mfc_compta.php), jamais
       écrit directement depuis un écran. Ne référence pas ses entités par clé
       étrangère : une écriture ou un compte supprimé ne doit pas pouvoir
       emporter la trace de son existence passée avec lui.
       'changes' : JSON best-effort {champ: [avant, après]}, vide si non applicable
       (une création n'a pas d'« avant »). */
    CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT NOT NULL,
        entity_id INTEGER,
        action TEXT NOT NULL,
        summary TEXT NOT NULL DEFAULT '',
        changes TEXT DEFAULT '',
        user_name TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    );
    ");

    /* Anti-doublon d'import : une référence banque (AcctSvcrRef) ne doit jamais
       être importée deux fois pour le même compte. Index créé séparément (pas
       en CREATE TABLE) pour pouvoir tolérer les bank_ref vides (relevés sans
       référence) sans bloquer tout le reste. */
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_txn_ref
                ON bank_transactions(bank_account_id, bank_ref) WHERE bank_ref != ''");

    /* Migrations des bases livrées en Phase 1 : CREATE TABLE IF NOT EXISTS
       n'ajoute pas de colonne à une table déjà créée. Le module tourne en
       production depuis le 2026-08-04, ces ALTER sont donc le seul chemin. */
    ensure_column($pdo, 'periods',         'fiscal_year_id',       'INTEGER');
    ensure_column($pdo, 'journal_entries', 'fiscal_year_id',       'INTEGER');
    ensure_column($pdo, 'journal_entries', 'reversal_of_entry_id', 'INTEGER');
    ensure_column($pdo, 'journal_lines',   'cost_center_id',       'INTEGER');
    ensure_column($pdo, 'reconciliations', 'invoice_id',           'INTEGER');
    ensure_column($pdo, 'accounts',        'vat_settlement_rate_id', 'INTEGER');
    ensure_column($pdo, 'invoices',        'payment_term_id',      'INTEGER');
    /* Lot 3 : TVA par défaut portée par le compte, et journal de l'écriture. */
    ensure_column($pdo, 'accounts',        'vat_rate_id',          'INTEGER');
    ensure_column($pdo, 'journal_entries', 'journal_id',           'INTEGER');

    /* Index créés APRÈS les migrations, jamais dans le bloc CREATE TABLE :
       sur une base déjà en service, CREATE TABLE IF NOT EXISTS ne fait rien, si
       bien qu'un index portant sur une colonne nouvelle s'exécuterait avant que
       l'ALTER ne l'ait ajoutée. Le module entier tombait alors en 500 dès la
       première requête après déploiement. */
    $pdo->exec("
    CREATE INDEX IF NOT EXISTS ix_lines_entry   ON journal_lines(entry_id);
    CREATE INDEX IF NOT EXISTS ix_lines_account ON journal_lines(account_id);
    CREATE INDEX IF NOT EXISTS ix_entries_date  ON journal_entries(entry_date);
    CREATE INDEX IF NOT EXISTS ix_entries_fy    ON journal_entries(fiscal_year_id);
    CREATE INDEX IF NOT EXISTS ix_inv_status    ON invoices(status);
    CREATE INDEX IF NOT EXISTS ix_inv_src       ON invoice_source_items(source_module, source_ref_id);
    CREATE INDEX IF NOT EXISTS ix_inv_lines     ON invoice_lines(invoice_id);
    CREATE INDEX IF NOT EXISTS ix_inv_att       ON invoice_attachments(invoice_id);
    CREATE INDEX IF NOT EXISTS ix_audit_entity  ON audit_log(entity_type, entity_id);
    CREATE INDEX IF NOT EXISTS ix_audit_date    ON audit_log(created_at);
    ");

    /* Reprise des factures créées avant les lignes : chacune devient une facture
       à une seule ligne, portant son compte et son montant d'en-tête. Sans ça,
       une facture existante s'afficherait vide dans le nouveau formulaire. */
    backfill_invoice_lines($pdo);

    // Seed du plan comptable réel du club, une seule fois.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    if ($count === 0) {
        seed_accounts($pdo);
    }
    seed_defaults($pdo);
}

/**
 * Type réel d'un fichier téléversé, d'après son contenu.
 *
 * Renvoie [mime, extension] ou [null, null] si le type n'est pas accepté.
 *
 * L'extension `fileinfo` est utilisée quand elle est là, mais le contrôle ne
 * dépend PAS d'elle : sur un hébergement qui ne la charge pas, le module
 * tomberait sinon en erreur 500 à chaque dépôt de facture. Le repli lit les
 * octets de tête, ce qui reste un contrôle de contenu — jamais une confiance
 * accordée à l'extension du nom de fichier, qui se falsifie.
 */
function detect_upload_type(string $path): array {
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
    ];

    if (class_exists('finfo')) {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return isset($allowed[$mime]) ? [$mime, $allowed[$mime]] : [null, null];
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) return [null, null];
    $head = (string) fread($fh, 12);
    fclose($fh);

    if (str_starts_with($head, '%PDF-'))                              return ['application/pdf', 'pdf'];
    if (str_starts_with($head, "\xFF\xD8\xFF"))                       return ['image/jpeg', 'jpg'];
    if (str_starts_with($head, "\x89PNG\r\n\x1A\n"))                  return ['image/png', 'png'];
    if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) return ['image/gif', 'gif'];

    return [null, null];
}

/**
 * Donne une ligne aux factures créées avant que les lignes n'existent.
 *
 * Ne touche qu'aux factures qui n'en ont aucune : rejouable sans risque, et
 * sans effet dès que toutes les factures sont passées au nouveau modèle.
 */
function backfill_invoice_lines(PDO $pdo): void {
    $orphans = $pdo->query(
        'SELECT i.id, i.label, i.amount_total, i.account_id, i.vat_rate_id, i.cost_center_id
           FROM invoices i
          WHERE NOT EXISTS (SELECT 1 FROM invoice_lines l WHERE l.invoice_id = i.id)'
    )->fetchAll();
    if (!$orphans) return;

    $ins = $pdo->prepare(
        'INSERT INTO invoice_lines (invoice_id, position, label, quantity, unit_price, amount, account_id, vat_rate_id, cost_center_id)
         VALUES (?,0,?,1,?,?,?,?,?)'
    );
    foreach ($orphans as $o) {
        $ins->execute([
            (int) $o['id'], (string) $o['label'], (float) $o['amount_total'], (float) $o['amount_total'],
            $o['account_id'], $o['vat_rate_id'], $o['cost_center_id'],
        ]);
    }
}

/** Ajoute une colonne si elle manque. Idempotent, sûr à chaque démarrage. */
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($column, $cols, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function seed_accounts(PDO $pdo): void {
    $ins = $pdo->prepare('INSERT OR IGNORE INTO accounts (number, name, category, class, allow_lettrage) VALUES (?,?,?,?,?)');
    foreach (compta_accounts_seed() as [$number, $name, $category, $class, $lettrage]) {
        $ins->execute([$number, $name, $category, $class, $lettrage]);
    }
}

/**
 * Réglages et taux attendus par le module, créés s'ils manquent.
 *
 * INSERT OR IGNORE plutôt qu'un test « déjà initialisé » : une version
 * ultérieure qui ajoute un réglage doit le voir apparaître sur une base
 * existante, et une valeur saisie par l'utilisateur ne doit jamais être
 * réécrite. Les comptes proposés par défaut ne le sont que là où ils sont
 * évidents ; ailleurs le réglage reste vide et l'écran demande de le définir.
 */
function seed_defaults(PDO $pdo): void {
    /* Ce semis tourne à chaque démarrage : sans garde, il coûterait une
       vingtaine de requêtes par appel d'API. Le marqueur de version le rend
       gratuit en régime normal, tout en permettant à une version ultérieure de
       le rejouer (INSERT OR IGNORE ne réécrit jamais une valeur saisie).
       Rangé hors du scope 'global' pour ne pas apparaître dans les Réglages. */
    if ((int) compta_setting_value($pdo, 'system', 'schema_version', '0') >= COMPTA_SEED_VERSION) return;

    $acc = fn(string $n) => compta_account_by_number($pdo, $n);

    $settings = [
        // scope,        clé,                        libellé,                                            compte par défaut
        ['global',    'creditors_account',        'Compte collectif Créanciers (fournisseurs)',        $acc('2000')],
        ['global',    'debtors_account',          'Compte collectif Débiteurs (clients)',              $acc('1100')],
        ['global',    'suspense_account',         "Compte d'attente bancaire",                         $acc('1022')],
        ['global',    'result_account',           "Compte de résultat de clôture d'exercice",          $acc('2900')],
        ['global',    'vat_charge_account',       'Charge TVA (dette fiscale nette)',                  $acc('6810')],
        ['global',    'vat_payable_account',      'TVA à payer (dette fiscale nette)',                 $acc('2205')],
        ['arbitrage', 'default_expense_account',  'Arbitrage : compte de charge par défaut',           $acc('4005')],
        ['commandes', 'default_expense_account',  'Commandes : compte de charge par défaut',           null],
        ['sponsors',  'default_revenue_account',  'Sponsors : compte de produit par défaut',           $acc('3200')],
    ];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO compta_settings (scope, setting_key, label, account_id) VALUES (?,?,?,?)');
    foreach ($settings as [$scope, $key, $label, $accountId]) {
        $ins->execute([$scope, $key, $label, $accountId]);
    }

    /* Réglages porteurs d'une valeur texte plutôt que d'un compte : méthode de
       décompte TVA et coordonnées du créancier pour la QR-facture. */
    $textSettings = [
        ['global', 'vat_method',       'Méthode de décompte TVA', 'convenues'],
        ['global', 'creditor_name',    'QR-facture : raison sociale',  CLUB_NAME],
        ['global', 'creditor_street',  'QR-facture : rue et numéro',   ''],
        ['global', 'creditor_zip',     'QR-facture : NPA',             ''],
        ['global', 'creditor_city',    'QR-facture : localité',        ''],
        ['global', 'creditor_country', 'QR-facture : pays',            'CH'],
    ];
    $insT = $pdo->prepare('INSERT OR IGNORE INTO compta_settings (scope, setting_key, label, value) VALUES (?,?,?,?)');
    foreach ($textSettings as [$scope, $key, $label, $value]) {
        $insT->execute([$scope, $key, $label, $value]);
    }

    /* Taux facturés en vigueur depuis le 01.01.2024. Les taux de la dette
       fiscale nette, eux, sont attribués individuellement par l'AFC : aucun
       n'est deviné ici, la liste reste vide tant qu'ils ne sont pas saisis. */
    $rates = [
        ['TVA 8.1% (taux normal)',      8.1, 'vente'],
        ['TVA 2.6% (taux réduit)',      2.6, 'vente'],
        ['Exclu du champ de la TVA',    0.0, 'vente'],
        ['TVA 8.1% (taux normal)',      8.1, 'achat'],
        ['TVA 2.6% (taux réduit)',      2.6, 'achat'],
        ['Sans TVA',                    0.0, 'achat'],
    ];
    $has = (int) $pdo->query('SELECT COUNT(*) FROM vat_rates')->fetchColumn();
    if ($has === 0) {
        $insR = $pdo->prepare('INSERT INTO vat_rates (label, rate_percent, kind) VALUES (?,?,?)');
        foreach ($rates as $r) $insR->execute($r);
    }

    /* Conditions de paiement usuelles. Modifiables et complétables en Réglages ;
       « 30 jours » est le défaut, c'est l'usage courant du club. */
    if ((int) $pdo->query('SELECT COUNT(*) FROM payment_terms')->fetchColumn() === 0) {
        $insP = $pdo->prepare('INSERT INTO payment_terms (label, days, is_default) VALUES (?,?,?)');
        foreach ([['À réception', 0, 0], ['10 jours', 10, 0], ['15 jours', 15, 0], ['30 jours', 30, 1], ['60 jours', 60, 0]] as $t) {
            $insP->execute($t);
        }
    }

    /* Les six journaux d'origine reprennent exactement les codes déjà utilisés
       par la numérotation des pièces : les séquences en cours continuent, aucune
       référence déjà imprimée ne change. `system = 1` protège de la suppression
       ce dont le moteur comptable a besoin (ouverture, clôture, opérations
       diverses) ; les journaux d'achat, de vente et de banque sont, eux,
       duplicables autant que voulu. */
    $insJ = $pdo->prepare('INSERT OR IGNORE INTO journals (code, label, kind, is_default, system, position) VALUES (?,?,?,?,?,?)');
    foreach ([
        ['OUV', "Écriture d'ouverture", 'ouverture', 0, 1, 10],
        ['ACH', 'Achats',               'achat',     1, 0, 20],
        ['VTE', 'Ventes',               'vente',     1, 0, 30],
        ['BQ',  'Banque',               'banque',    1, 0, 40],
        ['OD',  'Opérations diverses',  'od',        1, 1, 50],
        ['CLO', "Clôture d'exercice",   'cloture',   0, 1, 60],
    ] as $j) $insJ->execute($j);

    /* Rattachement des écritures déjà passées à leur journal, déduit du préfixe
       de leur numéro de pièce : sans cela le grand livre et les filtres par
       journal seraient vides pour tout l'historique. */
    $pdo->exec("UPDATE journal_entries
                   SET journal_id = (SELECT j.id FROM journals j
                                      WHERE j.code = substr(journal_entries.piece_ref, 1, instr(journal_entries.piece_ref, '-') - 1))
                 WHERE journal_id IS NULL AND instr(piece_ref, '-') > 1");
    $pdo->exec("UPDATE journal_entries SET journal_id = (SELECT id FROM journals WHERE code = 'OD') WHERE journal_id IS NULL");

    $pdo->prepare("INSERT OR IGNORE INTO compta_settings (scope, setting_key, label, value) VALUES ('system','schema_version','',?)")
        ->execute([(string) COMPTA_SEED_VERSION]);
    $pdo->prepare("UPDATE compta_settings SET value = ? WHERE scope = 'system' AND setting_key = 'schema_version'")
        ->execute([(string) COMPTA_SEED_VERSION]);
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
    'bootstrap'               => null,
    'accounts'                => ['GET' => 'accounts.view', 'write' => 'accounts.edit'],
    'entries'                 => ['GET' => 'journal.view',  'write' => 'journal.edit'],
    'entry_delete'            => 'journal.edit',
    'entry_reverse'           => 'journal.edit',
    'periods'                 => ['GET' => 'journal.view',  'write' => 'periods.close'],
    'fiscal_years'            => ['GET' => 'journal.view',  'write' => 'fiscalyear.close'],
    'opening_entry'           => ['GET' => 'journal.view',  'write' => 'fiscalyear.close'],
    'fiscal_year_close'       => 'fiscalyear.close',
    'bank_accounts'           => ['GET' => 'bank.view',     'write' => 'bank.manage'],
    'bank_transactions'       => 'bank.view',
    'bank_import_preview'     => 'bank.import',
    'bank_import_commit'      => 'bank.import',
    'bank_import_batches'     => 'bank.view',
    'bank_import_rollback'    => 'bank.import',
    'reconcile'               => 'bank.reconcile',
    'reconcile_undo'          => 'bank.reconcile',
    'reconcile_invoices'      => 'bank.view',
    'invoices'                => ['GET' => 'invoices.view', 'write' => 'invoices.edit'],
    'invoice'                 => 'invoices.view',
    'invoice_cancel'          => 'invoices.edit',
    'invoice_generate'        => 'invoices.edit',
    'invoice_open_items'      => 'invoices.edit',
    'products'                => ['GET' => 'invoices.view', 'write' => 'settings.manage'],
    'journals'                => ['GET' => 'journal.view',  'write' => 'settings.manage'],
    'payment_terms'           => ['GET' => 'invoices.view', 'write' => 'settings.manage'],
    'contacts_search'         => 'invoices.edit',
    'contact_create'          => 'invoices.edit',
    'invoice_attachments'     => 'invoices.view',
    'invoice_attach'          => 'invoices.edit',
    'invoice_attach_delete'   => 'invoices.edit',
    'invoice_attach_download' => 'invoices.view',
    'entry_lines'             => 'journal.view',
    'settings'                => ['GET' => 'journal.view',  'write' => 'settings.manage'],
    'vat_rates'               => ['GET' => 'journal.view',  'write' => 'settings.manage'],
    'vat_settlement_rates'    => ['GET' => 'journal.view',  'write' => 'settings.manage'],
    'cost_centers'            => ['GET' => 'journal.view',  'write' => 'settings.manage'],
    'cost_centers_sync_club'  => 'settings.manage',
    'line_cost_center'        => 'journal.edit',
    'report_balance'          => 'reports.view',
    'report_journal'          => 'reports.view',
    'report_ledger'           => 'reports.view',
    'report_balance_sheet'    => 'reports.view',
    'report_income_statement' => 'reports.view',
    'report_aged_balance'     => 'reports.view',
    'audit_log'               => 'audit.view',
];

$rule = array_key_exists($action, CP_PERMS) ? CP_PERMS[$action] : 'journal.edit';
if (is_array($rule)) $rule = ($method === 'GET') ? $rule['GET'] : $rule['write'];
if ($rule !== null) mfc_require_perm('compta.' . $rule);

switch ($action) {

case 'me':
    out(['user' => ['name' => $userName]]);

/**
 * Contexte de démarrage en un seul appel.
 *
 * Chaque bloc est isolé : un refus de droit dégrade l'écran concerné, il ne
 * vide pas la page entière. C'est la leçon du chargement groupé du module RH,
 * où un seul 403 dans un Promise.all laissait des pages complètement blanches.
 */
case 'bootstrap': {
    $pdo = db();
    $res = ['user' => ['name' => $userName]];

    $safe = function (callable $fn) {
        try { return $fn(); } catch (\Throwable $e) { return null; }
    };

    $res['fiscal_years'] = $safe(fn() => $pdo->query('SELECT * FROM fiscal_years ORDER BY start_date DESC')->fetchAll());
    $cur = $safe(fn() => compta_current_fiscal_year($pdo));
    $res['current_fiscal_year'] = $cur ?: null;
    $res['accounts']     = $safe(fn() => $pdo->query('SELECT id, number, name, class, category FROM accounts WHERE active = 1 ORDER BY number')->fetchAll());
    $res['cost_centers'] = $safe(fn() => $pdo->query('SELECT * FROM cost_centers WHERE active = 1 ORDER BY kind, label')->fetchAll());
    $res['vat_rates']    = $safe(fn() => $pdo->query('SELECT * FROM vat_rates WHERE active = 1 ORDER BY kind, rate_percent DESC')->fetchAll());
    $res['products']     = $safe(fn() => $pdo->query('SELECT id, code, label, account_id, unit_price, vat_rate_id, unit, direction FROM products WHERE active = 1 ORDER BY label')->fetchAll());
    $res['journals']     = $safe(fn() => $pdo->query('SELECT id, code, label, kind, bank_account_id, is_default FROM journals WHERE active = 1 ORDER BY position, code')->fetchAll());
    $res['payment_terms']= $safe(fn() => $pdo->query('SELECT * FROM payment_terms WHERE active = 1 ORDER BY days')->fetchAll());
    $res['bank_accounts']= $safe(fn() => $pdo->query('SELECT id, name, type, iban, currency, account_id FROM bank_accounts WHERE active = 1 ORDER BY name')->fetchAll());
    $res['modules']      = $safe(fn() => array_map(
        fn($slug) => ['slug' => $slug, 'direction' => COMPTA_MODULE_DIRECTION[$slug] ?? 'a_payer'],
        compta_available_modules()
    ));

    /* Ce que l'utilisateur a le droit de faire : l'interface masque ce qui est
       refusé au lieu d'afficher un bouton qui renverra 403. */
    $res['can'] = [];
    foreach (['accounts.edit', 'journal.edit', 'periods.close', 'fiscalyear.close',
              'bank.manage', 'bank.import', 'bank.reconcile',
              'invoices.view', 'invoices.edit', 'invoices.issue',
              'reports.view', 'reports.export', 'settings.manage'] as $p) {
        $res['can'][$p] = mfc_can('compta.' . $p);
    }
    out($res);
}

/* ============================================================ PLAN COMPTABLE */

case 'accounts': {
    $pdo = db();
    if ($method === 'GET') {
        $active = $_GET['active'] ?? '1';
        $sql = 'SELECT a.*, v.label AS vat_label, v.rate_percent AS vat_percent
                  FROM accounts a LEFT JOIN vat_rates v ON v.id = a.vat_rate_id';
        $params = [];
        if ($active !== 'all') { $sql .= ' WHERE a.active = ?'; $params[] = (int) $active; }
        $sql .= ' ORDER BY a.number';
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
            $pdo->prepare('INSERT INTO accounts (number, name, category, class, currency, allow_lettrage, vat_rate_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$number, $name, s($b, 'category'), $class, s($b, 'currency', 'CHF'), i($b, 'allow_lettrage'), i($b, 'vat_rate_id') ?: null]);
            $newId = (int) $pdo->lastInsertId();
            compta_audit_log($pdo, 'account', $newId, 'create', "Compte $number — $name", $userName);
            out(['ok' => true, 'id' => $newId]);
        } catch (\Exception $e) { fail('Ce numéro de compte existe déjà'); }
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $class = s($b, 'class', 'charge');
        if (!in_array($class, ['actif', 'passif', 'produit', 'charge'], true)) fail('Classe de compte invalide');
        $old = $pdo->prepare('SELECT * FROM accounts WHERE id = ?'); $old->execute([$id]); $old = $old->fetch();
        if (!$old) fail('Compte introuvable', 404);
        $new = [
            'name' => s($b, 'name'), 'category' => s($b, 'category'), 'class' => $class,
            'currency' => s($b, 'currency', 'CHF'), 'allow_lettrage' => i($b, 'allow_lettrage'),
            'vat_rate_id' => i($b, 'vat_rate_id') ?: null, 'active' => i($b, 'active', 1),
        ];
        $pdo->prepare('UPDATE accounts SET name=?, category=?, class=?, currency=?, allow_lettrage=?, vat_rate_id=?, active=? WHERE id=?')
            ->execute([$new['name'], $new['category'], $new['class'], $new['currency'], $new['allow_lettrage'],
                       $new['vat_rate_id'], $new['active'], $id]);
        $diff = [];
        foreach ($new as $k => $v) { if ((string) $old[$k] !== (string) $v) $diff[$k] = [$old[$k], $v]; }
        if ($diff) compta_audit_log($pdo, 'account', $id, 'update', "Compte {$old['number']} — {$old['name']}", $userName, $diff);
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
        if (!empty($_GET['fiscal_year_id'])) { $where[] = 'e.fiscal_year_id = ?'; $params[] = (int) $_GET['fiscal_year_id']; }
        /* Une écriture déjà extournée, et l'extourne elle-même, restent
           visibles : c'est tout l'intérêt de la contre-passation face à une
           suppression. Le drapeau sert seulement à les signaler à l'écran. */
        $sql = 'SELECT e.*, (SELECT r.piece_ref FROM journal_entries r WHERE r.reversal_of_entry_id = e.id LIMIT 1) AS reversed_by_ref,
                       (SELECT o.piece_ref FROM journal_entries o WHERE o.id = e.reversal_of_entry_id) AS reverses_ref
                  FROM journal_entries e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.entry_date DESC, e.id DESC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $entries = $st->fetchAll();

        if ($entries) {
            $ids = array_column($entries, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $lst = $pdo->prepare("SELECT l.*, a.number AS account_number, a.name AS account_name,
                                         cc.code AS cost_center_code, cc.label AS cost_center_label
                                   FROM journal_lines l
                                   JOIN accounts a ON a.id = l.account_id
                                   LEFT JOIN cost_centers cc ON cc.id = l.cost_center_id
                                   WHERE l.entry_id IN ($ph) ORDER BY l.id");
            $lst->execute($ids);
            $byEntry = [];
            foreach ($lst->fetchAll() as $l) { $byEntry[$l['entry_id']][] = $l; }
            foreach ($entries as &$e) { $e['lines'] = $byEntry[$e['id']] ?? []; }
        }
        out($entries);
    }
    if ($method === 'POST') {
        /* Toute la validation (équilibre, exercice ouvert, période close,
           numérotation) vit dans compta_post_entry : la saisie manuelle, les
           factures, les règlements et la clôture empruntent le même chemin. */
        $pdo->beginTransaction();
        try {
            $entryId = compta_post_entry($pdo, [
                'entry_date'    => s($b, 'entry_date'),
                'label'         => s($b, 'label'),
                'piece_ref'     => s($b, 'piece_ref'),
                'piece_kind'    => 'OD',
                'journal_id'    => i($b, 'journal_id') ?: null,
                'source_module' => 'manuel',
                'created_by'    => $userName,
                'lines'         => is_array($b['lines'] ?? null) ? $b['lines'] : [],
            ]);
            $pdo->commit();
            out(['ok' => true, 'id' => $entryId]);
        } catch (ComptaException $e) {
            $pdo->rollBack();
            fail($e->getMessage());
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail("Erreur lors de la création de l'écriture : " . $e->getMessage(), 500);
        }
    }
    fail('Méthode non supportée', 405);
}

/**
 * Suppression, volontairement étroite : elle ne sert qu'à rattraper une faute
 * de frappe fraîche, sur une écriture saisie à la main, dans un exercice
 * ouvert, encore rattachée à rien. Tout le reste passe par l'extourne, pour
 * que la numérotation des pièces reste continue et vérifiable.
 */
case 'entry_delete': {
    $pdo = db();
    $id = i($_GET, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
    $st->execute([$id]);
    $entry = $st->fetch();
    if (!$entry) fail('Écriture introuvable', 404);
    if ($entry['source_module'] !== 'manuel') {
        fail("Cette écriture vient de « {$entry['source_module']} », elle ne se supprime pas ici. Utilisez l'extourne.", 409);
    }
    if ($entry['fiscal_year_id']) {
        $fySt = $pdo->prepare('SELECT status, label FROM fiscal_years WHERE id = ?');
        $fySt->execute([(int) $entry['fiscal_year_id']]);
        $fy = $fySt->fetch();
        if ($fy && $fy['status'] === 'cloture') fail("L'exercice « {$fy['label']} » est clôturé. Utilisez l'extourne sur l'exercice courant.", 409);
    }
    if ($entry['period_id'] && compta_period_is_closed($pdo, (int) $entry['period_id'])) fail('La période comptable de cette écriture est clôturée');
    $recSt = $pdo->prepare('SELECT COUNT(*) FROM reconciliations WHERE journal_entry_id = ?');
    $recSt->execute([$id]);
    if ((int) $recSt->fetchColumn() > 0) fail('Cette écriture est rapprochée avec une transaction bancaire, annulez le rapprochement avant de la supprimer', 409);
    $invSt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE journal_entry_id = ?');
    $invSt->execute([$id]);
    if ((int) $invSt->fetchColumn() > 0) fail("Cette écriture porte une facture, annulez la facture plutôt que l'écriture", 409);
    // Tracé AVANT la suppression physique : c'est la seule trace qui subsistera de cette écriture.
    compta_audit_log($pdo, 'journal_entry', $id, 'delete', 'Écriture ' . ($entry['piece_ref'] ?: "#$id") . ' — ' . $entry['label'], $userName);
    $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
    out(['ok' => true]);
}

/**
 * Extourne (contre-passation) : l'écriture d'origine reste lisible, une
 * écriture miroir la neutralise. C'est le seul moyen correct de corriger une
 * écriture déjà rapprochée, issue d'un module, ou d'un exercice clôturé.
 */
case 'entry_reverse': {
    $pdo = db();
    $id = i($b, 'id') ?: i($_GET, 'id');
    if (!$id) fail('ID requis');

    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
    $st->execute([$id]);
    $entry = $st->fetch();
    if (!$entry) fail('Écriture introuvable', 404);

    $dupSt = $pdo->prepare('SELECT piece_ref FROM journal_entries WHERE reversal_of_entry_id = ? LIMIT 1');
    $dupSt->execute([$id]);
    if ($already = $dupSt->fetchColumn()) fail("Cette écriture est déjà extournée par la pièce $already", 409);

    $lnSt = $pdo->prepare('SELECT * FROM journal_lines WHERE entry_id = ? ORDER BY id');
    $lnSt->execute([$id]);
    $lines = $lnSt->fetchAll();
    if (!$lines) fail('Écriture sans ligne, rien à extourner', 409);

    /* Date de l'extourne : celle demandée, sinon aujourd'hui. Elle doit tomber
       dans un exercice ouvert, ce que compta_post_entry vérifie — extourner
       dans un exercice clôturé n'aurait aucun sens. */
    $date = s($b, 'entry_date') ?: date('Y-m-d');

    $mirror = [];
    foreach ($lines as $l) {
        $mirror[] = [
            'account_id'       => (int) $l['account_id'],
            'debit'            => (float) $l['credit'],
            'credit'           => (float) $l['debit'],
            'label'            => (string) $l['label'],
            'tiers_contact_id' => (string) $l['tiers_contact_id'],
            'cost_center_id'   => $l['cost_center_id'] ?? null,
        ];
    }

    $pdo->beginTransaction();
    try {
        $newId = compta_post_entry($pdo, [
            'entry_date'           => $date,
            'label'                => 'Extourne de ' . ($entry['piece_ref'] ?: "l'écriture #$id") . ' — ' . $entry['label'],
            'piece_kind'           => 'OD',
            'source_module'        => 'extourne',
            'source_ref_id'        => (string) $id,
            'reversal_of_entry_id' => $id,
            'created_by'           => $userName,
            'lines'                => $mirror,
        ]);
        $pdo->commit();
        out(['ok' => true, 'id' => $newId]);
    } catch (ComptaException $e) {
        $pdo->rollBack();
        fail($e->getMessage());
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail("Erreur lors de l'extourne : " . $e->getMessage(), 500);
    }
}

/* ============================================================ EXERCICES */

case 'fiscal_years': {
    $pdo = db();
    if ($method === 'GET') {
        $rows = $pdo->query('SELECT * FROM fiscal_years ORDER BY start_date DESC')->fetchAll();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE fiscal_year_id = ?');
        $opn = $pdo->prepare("SELECT id FROM journal_entries WHERE fiscal_year_id = ? AND source_module = 'ouverture' LIMIT 1");
        foreach ($rows as &$r) {
            $cnt->execute([(int) $r['id']]);
            $r['entry_count'] = (int) $cnt->fetchColumn();
            $opn->execute([(int) $r['id']]);
            $r['has_opening'] = (bool) $opn->fetchColumn();
        }
        $cur = compta_current_fiscal_year($pdo);
        out(['years' => $rows, 'current_id' => $cur ? (int) $cur['id'] : null]);
    }
    if ($method === 'POST') {
        $start = s($b, 'start_date');
        $end   = s($b, 'end_date');
        $label = s($b, 'label');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) fail('Dates de début et de fin requises (AAAA-MM-JJ)');
        if ($end <= $start) fail('La date de fin doit suivre la date de début');
        if (!$label) $label = 'Saison ' . substr($start, 0, 4) . '-' . substr($end, 0, 4);

        $ov = $pdo->prepare('SELECT label FROM fiscal_years WHERE start_date <= ? AND end_date >= ? LIMIT 1');
        $ov->execute([$end, $start]);
        if ($clash = $ov->fetchColumn()) fail("Cette période chevauche l'exercice « $clash »");

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO fiscal_years (label, start_date, end_date) VALUES (?,?,?)')->execute([$label, $start, $end]);
            $fyId = (int) $pdo->lastInsertId();
            /* Rattachement des écritures orphelines : celles saisies en Phase 1,
               avant l'existence des exercices, sinon invisibles dans tous les
               rapports filtrés par exercice. */
            $st = $pdo->prepare('UPDATE journal_entries SET fiscal_year_id = ? WHERE fiscal_year_id IS NULL AND entry_date BETWEEN ? AND ?');
            $st->execute([$fyId, $start, $end]);
            $adopted = $st->rowCount();
            $pdo->prepare('UPDATE periods SET fiscal_year_id = ? WHERE fiscal_year_id IS NULL AND start_date >= ? AND end_date <= ?')
                ->execute([$fyId, $start, $end]);
            $pdo->commit();
            out(['ok' => true, 'id' => $fyId, 'adopted_entries' => $adopted]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail("Erreur lors de la création de l'exercice : " . $e->getMessage(), 500);
        }
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $st = $pdo->prepare('SELECT status FROM fiscal_years WHERE id = ?');
        $st->execute([$id]);
        if (($st->fetchColumn() ?: '') === 'cloture') fail('Un exercice clôturé ne se renomme plus', 409);
        $pdo->prepare('UPDATE fiscal_years SET label = ? WHERE id = ?')->execute([s($b, 'label'), $id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/**
 * Écriture d'ouverture : reprise des soldes de bilan, une seule par exercice.
 * GET renvoie les comptes de bilan et, le cas échéant, l'ouverture existante.
 */
case 'opening_entry': {
    $pdo = db();
    $fyId = i($_GET, 'fiscal_year_id') ?: i($b, 'fiscal_year_id');
    if (!$fyId) fail('Exercice requis');
    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $fySt->execute([$fyId]);
    $fy = $fySt->fetch();
    if (!$fy) fail('Exercice introuvable', 404);

    $exSt = $pdo->prepare("SELECT * FROM journal_entries WHERE fiscal_year_id = ? AND source_module = 'ouverture' LIMIT 1");
    $exSt->execute([$fyId]);
    $existing = $exSt->fetch();

    if ($method === 'GET') {
        $accounts = $pdo->query("SELECT id, number, name, class, category FROM accounts
                                  WHERE active = 1 AND class IN ('actif','passif') ORDER BY number")->fetchAll();
        $lines = [];
        if ($existing) {
            $lnSt = $pdo->prepare('SELECT * FROM journal_lines WHERE entry_id = ? ORDER BY id');
            $lnSt->execute([(int) $existing['id']]);
            $lines = $lnSt->fetchAll();
        }
        out(['fiscal_year' => $fy, 'accounts' => $accounts, 'existing' => $existing ?: null, 'lines' => $lines]);
    }

    if ($method === 'POST') {
        if ($existing) fail("L'exercice « {$fy['label']} » a déjà son écriture d'ouverture (pièce {$existing['piece_ref']}). Extournez-la avant d'en saisir une autre.", 409);
        if ($fy['status'] === 'cloture') fail('Exercice clôturé', 409);

        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        /* Un compte de charge ou de produit dans une ouverture est toujours une
           erreur de saisie : les comptes de résultat repartent de zéro. */
        $ids = array_values(array_filter(array_map(fn($l) => (int) ($l['account_id'] ?? 0), $lines)));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $bad = $pdo->prepare("SELECT number, name FROM accounts WHERE id IN ($ph) AND class NOT IN ('actif','passif') LIMIT 1");
            $bad->execute($ids);
            if ($r = $bad->fetch()) fail("Le compte {$r['number']} ({$r['name']}) est un compte de résultat : il n'a pas sa place dans une écriture d'ouverture");
        }

        $pdo->beginTransaction();
        try {
            $entryId = compta_post_entry($pdo, [
                'entry_date'    => (string) $fy['start_date'],
                'label'         => "Écriture d'ouverture — {$fy['label']}",
                'piece_kind'    => 'OUV',
                'source_module' => 'ouverture',
                'created_by'    => $userName,
                'lines'         => $lines,
            ]);
            $pdo->commit();
            out(['ok' => true, 'id' => $entryId]);
        } catch (ComptaException $e) {
            $pdo->rollBack();
            fail($e->getMessage());
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail("Erreur lors de l'ouverture : " . $e->getMessage(), 500);
        }
    }
    fail('Méthode non supportée', 405);
}

/**
 * Clôture d'exercice : solde les comptes de résultat contre le compte de
 * résultat reporté, verrouille l'exercice, puis crée l'exercice suivant et son
 * écriture d'ouverture à partir des soldes de bilan.
 */
case 'fiscal_year_close': {
    $pdo = db();
    $fyId = i($b, 'fiscal_year_id');
    if (!$fyId) fail('Exercice requis');
    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $fySt->execute([$fyId]);
    $fy = $fySt->fetch();
    if (!$fy) fail('Exercice introuvable', 404);
    if ($fy['status'] === 'cloture') fail('Cet exercice est déjà clôturé', 409);

    $resultAccount = compta_setting_account($pdo, 'global', 'result_account');
    if (!$resultAccount) fail("Aucun compte de résultat n'est défini dans les Réglages, la clôture ne peut pas virer le résultat");

    /* Soldes des comptes de résultat sur l'exercice. */
    $st = $pdo->prepare("
        SELECT a.id, a.number, a.name, a.class,
               COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS balance
          FROM accounts a
          JOIN journal_lines l ON l.account_id = a.id
          JOIN journal_entries e ON e.id = l.entry_id
         WHERE e.fiscal_year_id = ? AND a.class IN ('charge','produit')
         GROUP BY a.id HAVING ABS(balance) > 0.005 ORDER BY a.number");
    $st->execute([$fyId]);
    $rows = $st->fetchAll();
    if (!$rows) fail("Aucun mouvement de charge ou de produit sur cet exercice, il n'y a rien à clôturer");

    $lines = []; $net = 0.0;
    foreach ($rows as $r) {
        $bal = round((float) $r['balance'], 2);   // > 0 = solde débiteur (charge)
        $net += $bal;
        $lines[] = [
            'account_id' => (int) $r['id'],
            'debit'      => $bal < 0 ? -$bal : 0.0,
            'credit'     => $bal > 0 ? $bal : 0.0,
            'label'      => 'Solde de clôture',
        ];
    }
    $net = round($net, 2);   // > 0 = perte (charges > produits)
    $lines[] = [
        'account_id' => $resultAccount,
        'debit'      => $net > 0 ? $net : 0.0,
        'credit'     => $net < 0 ? -$net : 0.0,
        'label'      => $net > 0 ? "Perte de l'exercice" : "Bénéfice de l'exercice",
    ];

    $pdo->beginTransaction();
    try {
        compta_post_entry($pdo, [
            'entry_date'    => (string) $fy['end_date'],
            'label'         => "Clôture — {$fy['label']}",
            'piece_kind'    => 'CLO',
            'source_module' => 'cloture',
            'created_by'    => $userName,
            'lines'         => $lines,
        ]);

        $pdo->prepare("UPDATE fiscal_years SET status='cloture', closed_at=datetime('now'), closed_by=? WHERE id=?")
            ->execute([$userName, $fyId]);
        $pdo->prepare('UPDATE periods SET closed = 1 WHERE fiscal_year_id = ?')->execute([$fyId]);

        /* Exercice suivant : même durée, à partir du lendemain. */
        $nextStart = date('Y-m-d', strtotime($fy['end_date'] . ' +1 day'));
        $nextEnd   = date('Y-m-d', strtotime($nextStart . ' +1 year -1 day'));
        $nextLabel = 'Saison ' . substr($nextStart, 0, 4) . '-' . substr($nextEnd, 0, 4);

        $ex = $pdo->prepare('SELECT id FROM fiscal_years WHERE start_date = ? LIMIT 1');
        $ex->execute([$nextStart]);
        $nextId = (int) ($ex->fetchColumn() ?: 0);
        if (!$nextId) {
            $pdo->prepare('INSERT INTO fiscal_years (label, start_date, end_date) VALUES (?,?,?)')
                ->execute([$nextLabel, $nextStart, $nextEnd]);
            $nextId = (int) $pdo->lastInsertId();
        }

        /* À-nouveaux : soldes de bilan cumulés de TOUS les exercices clôturés,
           pas du seul exercice qui vient de fermer — un compte sans mouvement
           cette année doit malgré tout être reporté. */
        $opSt = $pdo->prepare("
            SELECT a.id, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS balance
              FROM accounts a
              JOIN journal_lines l ON l.account_id = a.id
              JOIN journal_entries e ON e.id = l.entry_id
             WHERE a.class IN ('actif','passif') AND e.entry_date <= ?
             GROUP BY a.id HAVING ABS(balance) > 0.005 ORDER BY a.number");
        $opSt->execute([$fy['end_date']]);

        $openLines = [];
        foreach ($opSt->fetchAll() as $r) {
            $bal = round((float) $r['balance'], 2);
            $openLines[] = [
                'account_id' => (int) $r['id'],
                'debit'      => $bal > 0 ? $bal : 0.0,
                'credit'     => $bal < 0 ? -$bal : 0.0,
                'label'      => "À-nouveau {$fy['label']}",
            ];
        }

        $openingId = null;
        $hasOpening = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE fiscal_year_id = ? AND source_module = 'ouverture'");
        $hasOpening->execute([$nextId]);
        if (count($openLines) >= 2 && (int) $hasOpening->fetchColumn() === 0) {
            $openingId = compta_post_entry($pdo, [
                'entry_date'    => $nextStart,
                'label'         => "Écriture d'ouverture — $nextLabel",
                'piece_kind'    => 'OUV',
                'source_module' => 'ouverture',
                'created_by'    => $userName,
                'lines'         => $openLines,
            ]);
        }

        $pdo->commit();
        out([
            'ok' => true,
            'result'          => -$net,          // positif = bénéfice, lecture naturelle
            'next_year_id'    => $nextId,
            'next_year_label' => $nextLabel,
            'opening_entry_id'=> $openingId,
        ]);
    } catch (ComptaException $e) {
        $pdo->rollBack();
        fail($e->getMessage());
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur lors de la clôture : ' . $e->getMessage(), 500);
    }
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

/* ============================================================ FACTURES
 *
 * Objet central du module : toute somme due ou à encaisser passe par une
 * facture, qu'elle vienne d'un module métier ou d'une saisie directe. C'est
 * elle que le rapprochement bancaire vient solder, jamais l'écriture nue.
 */

/** Modules branchés et réellement installés, avec leur sens comptable. */
case 'invoice_open_items': {
    $pdo = db();
    $module = s($_GET, 'module');
    if ($module === '') {
        $mods = [];
        foreach (compta_available_modules() as $slug) {
            $mods[] = [
                'slug'      => $slug,
                'direction' => COMPTA_MODULE_DIRECTION[$slug] ?? 'a_payer',
                'count'     => count(compta_open_items($pdo, $slug)),
            ];
        }
        out(['modules' => $mods]);
    }
    if (!isset(COMPTA_SOURCE_MODULES[$module])) fail('Module inconnu');

    $direction = COMPTA_MODULE_DIRECTION[$module] ?? 'a_payer';
    $key       = $direction === 'a_recevoir' ? 'default_revenue_account' : 'default_expense_account';
    out([
        'module'           => $module,
        'direction'        => $direction,
        'default_account'  => compta_setting_account($pdo, $module, $key),
        'default_grouping' => COMPTA_MODULE_GROUPING[$module] ?? 'piece',
        'items'            => compta_open_items($pdo, $module),
    ]);
}

case 'invoice_generate': {
    $pdo = db();
    $module = s($b, 'module');
    if (!isset(COMPTA_SOURCE_MODULES[$module])) fail('Module inconnu');

    $wanted = array_map('strval', is_array($b['ref_ids'] ?? null) ? $b['ref_ids'] : []);
    if (!$wanted) fail('Sélectionnez au moins une pièce à facturer');

    /* Les montants sont relus à la source, jamais repris de la requête : le
       navigateur propose une sélection, il ne décide pas de ce qu'on
       comptabilise. */
    $available = [];
    foreach (compta_open_items($pdo, $module) as $it) $available[$it['ref_id']] = $it;

    $picked = []; $total = 0.0;
    foreach ($wanted as $ref) {
        if (!isset($available[$ref])) fail("La pièce $ref n'est plus disponible à la facturation (déjà facturée ou statut modifié). Rechargez la liste.");
        $picked[] = $available[$ref];
        $total += (float) $available[$ref]['amount'];
    }
    $total = esc_num($total);
    if ($total == 0.0) fail('Le total des pièces sélectionnées est nul');

    $direction = COMPTA_MODULE_DIRECTION[$module] ?? 'a_payer';
    $accountId = i($b, 'account_id') ?: compta_setting_account($pdo, $module, $direction === 'a_recevoir' ? 'default_revenue_account' : 'default_expense_account');
    if (!$accountId) fail("Aucun compte de contrepartie : choisissez-en un, ou définissez le compte par défaut de « $module » dans les Réglages");

    /* Regroupement : défaut du module, débrayable à chaque génération. Il ne
       porte que sur la sélection en cours — deux passes sur la même équipe
       donnent bien deux factures distinctes, ce qui couvre le match amical
       remboursé à part. */
    $mode = s($b, 'grouping') ?: (COMPTA_MODULE_GROUPING[$module] ?? 'piece');
    if (!in_array($mode, ['group', 'piece'], true)) fail('Mode de regroupement invalide');

    $buckets = [];
    foreach ($picked as $it) {
        $key = $mode === 'group' ? ($it['group'] ?: $it['tiers'] ?: $module) : ('#' . $it['ref_id']);
        $buckets[$key][] = $it;
    }

    $termId  = i($b, 'payment_term_id') ?: null;
    $vatId   = i($b, 'vat_rate_id') ?: null;
    $costId  = i($b, 'cost_center_id') ?: null;
    $date    = s($b, 'entry_date') ?: date('Y-m-d');
    $created = [];

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO invoice_source_items (invoice_id, source_module, source_ref_id, label, amount) VALUES (?,?,?,?,?)');

        foreach ($buckets as $key => $items) {
            $first = $items[0];
            $label = s($b, 'label');
            if ($label === '') {
                $label = $mode === 'group'
                    ? sprintf('%s — %d pièce(s)', $first['group'] ?: $first['tiers'] ?: $module, count($items))
                    : (string) $first['label'];
            } elseif (count($buckets) > 1) {
                /* Un libellé unique imposé à plusieurs factures les rendrait
                   indiscernables : on le qualifie par le groupe. */
                $label .= ' — ' . ($first['group'] ?: $first['tiers'] ?: $key);
            }

            /* Chaque pièce source devient une LIGNE : la facture envoyée au
               tiers détaille les matchs ou les prestations, elle n'affiche pas
               un total opaque. */
            $lines = [];
            foreach ($items as $it) {
                $lines[] = [
                    'label'          => trim(($it['date'] ? $it['date'] . ' · ' : '') . $it['label']),
                    'quantity'       => 1,
                    'unit_price'     => esc_num($it['amount']),
                    'amount'         => esc_num($it['amount']),
                    'account_id'     => $accountId,
                    'vat_rate_id'    => $vatId,
                    'cost_center_id' => $costId,
                ];
            }

            $invoiceId = invoice_create($pdo, [
                'direction'        => $direction,
                'entry_date'       => $date,
                'due_date'         => s($b, 'due_date'),
                'payment_term_id'  => $termId,
                'label'            => $label,
                'tiers_label'      => s($b, 'tiers_label') ?: (string) $first['tiers'],
                'tiers_contact_id' => s($b, 'tiers_contact_id') ?: (string) $first['tiers_contact_id'],
                'source_module'    => $module,
                'journal_id'       => i($b, 'journal_id') ?: null,
                'invoice_number'   => s($b, 'invoice_number'),
                'notes'            => s($b, 'notes'),
                'created_by'       => $userName,
                'lines'            => $lines,
            ]);

            foreach ($items as $it) {
                $ins->execute([$invoiceId, $module, (string) $it['ref_id'], (string) $it['label'], esc_num($it['amount'])]);
            }
            $created[] = ['id' => $invoiceId, 'label' => $label, 'items' => count($items)];
        }
        $pdo->commit();
        out(['ok' => true, 'invoices' => $created, 'count' => count($created), 'items' => count($picked), 'amount_total' => $total]);
    } catch (ComptaException $e) {
        $pdo->rollBack();
        fail($e->getMessage());
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur lors de la génération de la facture : ' . $e->getMessage(), 500);
    }
}

case 'invoices': {
    $pdo = db();
    if ($method === 'GET') {
        $where = ['1=1']; $params = [];
        if (!empty($_GET['status']))    { $where[] = 'i.status = ?';        $params[] = $_GET['status']; }
        if (!empty($_GET['direction'])) { $where[] = 'i.direction = ?';     $params[] = $_GET['direction']; }
        if (!empty($_GET['module']))    { $where[] = 'i.source_module = ?'; $params[] = $_GET['module']; }
        if (!empty($_GET['from']))      { $where[] = 'i.entry_date >= ?';   $params[] = $_GET['from']; }
        if (!empty($_GET['to']))        { $where[] = 'i.entry_date <= ?';   $params[] = $_GET['to']; }
        if (!empty($_GET['q'])) {
            $where[] = '(i.label LIKE ? OR i.tiers_label LIKE ? OR i.piece_ref LIKE ? OR i.invoice_number LIKE ?)';
            $like = '%' . $_GET['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $st = $pdo->prepare('SELECT ' . invoice_select() . ' WHERE ' . implode(' AND ', $where) .
                            ' ORDER BY i.entry_date DESC, i.id DESC LIMIT 500');
        $st->execute($params);
        out(array_map('invoice_decorate', $st->fetchAll()));
    }
    if ($method === 'POST') {
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        if (!$lines) fail('Ajoutez au moins une ligne à la facture');

        $pdo->beginTransaction();
        try {
            $invoiceId = invoice_create($pdo, [
                'direction'        => s($b, 'direction', 'a_payer'),
                'entry_date'       => s($b, 'entry_date') ?: date('Y-m-d'),
                'due_date'         => s($b, 'due_date'),
                'payment_term_id'  => i($b, 'payment_term_id') ?: null,
                'label'            => s($b, 'label'),
                'tiers_label'      => s($b, 'tiers_label'),
                'tiers_contact_id' => s($b, 'tiers_contact_id'),
                'source_module'    => 'manuel',
                'journal_id'       => i($b, 'journal_id') ?: null,
                'invoice_number'   => s($b, 'invoice_number'),
                'notes'            => s($b, 'notes'),
                'created_by'       => $userName,
                'lines'            => $lines,
            ]);
            $pdo->commit();
            out(['ok' => true, 'id' => $invoiceId]);
        } catch (ComptaException $e) {
            $pdo->rollBack();
            fail($e->getMessage());
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail('Erreur lors de la création de la facture : ' . $e->getMessage(), 500);
        }
    }
    if ($method === 'PUT') {
        /* Seules les données non comptables se modifient après coup. Changer le
           montant ou le compte reviendrait à réécrire une écriture déjà passée :
           il faut annuler la facture (extourne) et en refaire une. */
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $oldSt = $pdo->prepare('SELECT due_date, invoice_number, notes, amount_vat, vat_rate_id, cost_center_id, tiers_label, piece_ref, label FROM invoices WHERE id = ?');
        $oldSt->execute([$id]);
        $old = $oldSt->fetch();
        if (!$old) fail('Facture introuvable', 404);
        $new = [
            'due_date' => s($b, 'due_date'), 'invoice_number' => s($b, 'invoice_number'), 'notes' => s($b, 'notes'),
            'amount_vat' => esc_num($b['amount_vat'] ?? 0), 'vat_rate_id' => i($b, 'vat_rate_id') ?: null,
            'cost_center_id' => i($b, 'cost_center_id') ?: null, 'tiers_label' => s($b, 'tiers_label'),
        ];
        $pdo->prepare('UPDATE invoices SET due_date=?, invoice_number=?, notes=?, amount_vat=?, vat_rate_id=?, cost_center_id=?, tiers_label=? WHERE id=?')
            ->execute([
                $new['due_date'], $new['invoice_number'], $new['notes'],
                $new['amount_vat'], $new['vat_rate_id'], $new['cost_center_id'], $new['tiers_label'], $id,
            ]);
        $diff = [];
        foreach ($new as $k => $v) { if ((string) $old[$k] !== (string) $v) $diff[$k] = [$old[$k], $v]; }
        if ($diff) compta_audit_log($pdo, 'invoice', $id, 'update', 'Facture ' . ($old['piece_ref'] ?: "#$id") . ' — ' . $old['label'], $userName, $diff);
        /* Le centre de coût suit sur la ligne de résultat de l'écriture : il est
           analytique, il n'entre pas dans l'équilibre débit/crédit. */
        $inv = $pdo->prepare('SELECT journal_entry_id, account_id FROM invoices WHERE id = ?');
        $inv->execute([$id]);
        if ($row = $inv->fetch()) {
            if ($row['journal_entry_id']) {
                $pdo->prepare('UPDATE journal_lines SET cost_center_id = ? WHERE entry_id = ? AND account_id = ?')
                    ->execute([i($b, 'cost_center_id') ?: null, (int) $row['journal_entry_id'], (int) $row['account_id']]);
            }
        }
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

case 'invoice': {
    $pdo = db();
    $id = i($_GET, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT ' . invoice_select() . ' WHERE i.id = ?');
    $st->execute([$id]);
    $inv = $st->fetch();
    if (!$inv) fail('Facture introuvable', 404);
    $inv = invoice_decorate($inv);

    $ln = $pdo->prepare(
        'SELECT l.*, a.number AS account_number, a.name AS account_name,
                p.label AS product_label, v.label AS vat_label, v.rate_percent AS vat_percent,
                cc.label AS cost_center_label
           FROM invoice_lines l
           LEFT JOIN accounts a      ON a.id  = l.account_id
           LEFT JOIN products p      ON p.id  = l.product_id
           LEFT JOIN vat_rates v     ON v.id  = l.vat_rate_id
           LEFT JOIN cost_centers cc ON cc.id = l.cost_center_id
          WHERE l.invoice_id = ? ORDER BY l.position, l.id'
    );
    $ln->execute([$id]);
    $inv['lines'] = $ln->fetchAll();

    $at = $pdo->prepare('SELECT id, filename, mime, size, uploaded_by, created_at FROM invoice_attachments WHERE invoice_id = ? ORDER BY id');
    $at->execute([$id]);
    $inv['attachments'] = $at->fetchAll();

    /* L'écriture générée, en lecture : c'est l'onglet « Écritures comptables »
       de la facture, et le seul moyen de vérifier ce qui a réellement été
       comptabilisé sans quitter l'écran. */
    $inv['entry_lines'] = [];
    if (!empty($inv['journal_entry_id'])) {
        $el = $pdo->prepare(
            'SELECT l.debit, l.credit, l.label, a.number AS account_number, a.name AS account_name
               FROM journal_lines l JOIN accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? ORDER BY l.id'
        );
        $el->execute([(int) $inv['journal_entry_id']]);
        $inv['entry_lines'] = $el->fetchAll();
    }

    $si = $pdo->prepare('SELECT * FROM invoice_source_items WHERE invoice_id = ? ORDER BY id');
    $si->execute([$id]);
    $inv['source_items'] = $si->fetchAll();

    $rc = $pdo->prepare('SELECT r.*, t.booking_date, t.label AS txn_label, t.amount AS txn_amount
                           FROM reconciliations r JOIN bank_transactions t ON t.id = r.bank_transaction_id
                          WHERE r.invoice_id = ? ORDER BY r.id');
    $rc->execute([$id]);
    $inv['reconciliations'] = $rc->fetchAll();

    out($inv);
}

/**
 * Annulation : la facture reste, son écriture est extournée. Refusée dès qu'un
 * encaissement lui est rattaché — un mouvement bancaire réel ne s'efface pas
 * d'un clic, il faut d'abord défaire le rapprochement.
 */
case 'invoice_cancel': {
    $pdo = db();
    $id = i($b, 'id') ?: i($_GET, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $st->execute([$id]);
    $inv = $st->fetch();
    if (!$inv) fail('Facture introuvable', 404);
    if ($inv['status'] === 'annulee') fail('Cette facture est déjà annulée', 409);

    $rc = $pdo->prepare('SELECT COUNT(*) FROM reconciliations WHERE invoice_id = ?');
    $rc->execute([$id]);
    if ((int) $rc->fetchColumn() > 0) fail('Cette facture est rapprochée avec un mouvement bancaire, annulez le rapprochement avant', 409);

    $pdo->beginTransaction();
    try {
        if ($inv['journal_entry_id']) {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE reversal_of_entry_id = ?');
            $dup->execute([(int) $inv['journal_entry_id']]);
            if ((int) $dup->fetchColumn() === 0) {
                $lnSt = $pdo->prepare('SELECT * FROM journal_lines WHERE entry_id = ? ORDER BY id');
                $lnSt->execute([(int) $inv['journal_entry_id']]);
                $mirror = [];
                foreach ($lnSt->fetchAll() as $l) {
                    $mirror[] = [
                        'account_id'       => (int) $l['account_id'],
                        'debit'            => (float) $l['credit'],
                        'credit'           => (float) $l['debit'],
                        'label'            => (string) $l['label'],
                        'tiers_contact_id' => (string) $l['tiers_contact_id'],
                        'cost_center_id'   => $l['cost_center_id'] ?? null,
                    ];
                }
                compta_post_entry($pdo, [
                    'entry_date'           => s($b, 'entry_date') ?: date('Y-m-d'),
                    'label'                => 'Annulation facture ' . ($inv['piece_ref'] ?: "#$id") . ' — ' . $inv['label'],
                    'piece_kind'           => 'OD',
                    'source_module'        => 'extourne',
                    'source_ref_id'        => (string) $inv['journal_entry_id'],
                    'reversal_of_entry_id' => (int) $inv['journal_entry_id'],
                    'created_by'           => $userName,
                    'lines'                => $mirror,
                ]);
            }
        }
        $pdo->prepare("UPDATE invoices SET status = 'annulee' WHERE id = ?")->execute([$id]);
        compta_audit_log($pdo, 'invoice', $id, 'cancel', 'Facture ' . ($inv['piece_ref'] ?: "#$id") . ' — ' . $inv['label'], $userName);
        $pdo->commit();
        out(['ok' => true]);
    } catch (ComptaException $e) {
        $pdo->rollBack();
        fail($e->getMessage());
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail("Erreur lors de l'annulation : " . $e->getMessage(), 500);
    }
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

/**
 * Factures ouvertes proposées face à une transaction bancaire.
 *
 * Le sens du mouvement filtre déjà l'essentiel : un débit règle une facture
 * fournisseur, un crédit encaisse une facture client. Les candidates dont le
 * reste dû tombe pile sur le montant sont remontées en tête, sans jamais rien
 * décider à la place de l'utilisateur.
 */
case 'reconcile_invoices': {
    $pdo = db();
    $txnId = i($_GET, 'bank_transaction_id');
    $q     = s($_GET, 'q');

    $where  = ["i.status IN ('ouverte','reglee_partielle')"];
    $params = [];
    $amountAbs = null;

    if ($txnId) {
        $txSt = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = ?');
        $txSt->execute([$txnId]);
        $txn = $txSt->fetch();
        if (!$txn) fail('Transaction introuvable', 404);
        $amountAbs = abs((float) $txn['amount']);
        $where[] = 'i.direction = ?';
        $params[] = ((float) $txn['amount']) < 0 ? 'a_payer' : 'a_recevoir';
    }
    if ($q !== '') {
        $where[] = '(i.label LIKE ? OR i.tiers_label LIKE ? OR i.piece_ref LIKE ? OR i.invoice_number LIKE ?)';
        $like = "%$q%";
        array_push($params, $like, $like, $like, $like);
    }

    $st = $pdo->prepare('SELECT ' . invoice_select() . ' WHERE ' . implode(' AND ', $where) .
                        ' ORDER BY i.entry_date DESC, i.id DESC LIMIT 200');
    $st->execute($params);
    $rows = array_map('invoice_decorate', $st->fetchAll());

    /* Le reste dû n'est calculable qu'après décoration : le tri se fait donc
       ici plutôt qu'en SQL. */
    if ($amountAbs !== null) {
        usort($rows, function ($x, $y) use ($amountAbs) {
            $dx = abs((float) $x['amount_open'] - $amountAbs) < 0.005 ? 0 : 1;
            $dy = abs((float) $y['amount_open'] - $amountAbs) < 0.005 ? 0 : 1;
            return $dx <=> $dy ?: strcmp((string) $y['entry_date'], (string) $x['entry_date']);
        });
    }
    out($rows);
}

/**
 * Règlement : rapproche une transaction bancaire d'une ou plusieurs factures.
 *
 * C'est ici que naît la seconde écriture du cycle. La première, à la
 * facturation, a constaté la dette ou la créance sur le compte collectif ; le
 * règlement la solde contre le compte de banque :
 *   achat : 2000 Créanciers / Banque      vente : Banque / 1100 Débiteurs
 */
case 'reconcile': {
    $pdo = db();
    $txnId = i($b, 'bank_transaction_id');
    if (!$txnId) fail('Transaction requise');

    /* Une facture avec un montant, ou plusieurs d'un coup (un virement groupé
       règle souvent plusieurs pièces). */
    $allocations = is_array($b['allocations'] ?? null) ? $b['allocations'] : [];
    if (!$allocations && i($b, 'invoice_id')) {
        $allocations = [['invoice_id' => i($b, 'invoice_id'), 'amount' => n($b, 'amount_applied')]];
    }
    if (!$allocations) fail('Sélectionnez au moins une facture à solder');

    $txSt = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = ?');
    $txSt->execute([$txnId]);
    $txn = $txSt->fetch();
    if (!$txn) fail('Transaction introuvable', 404);

    $baSt = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ?');
    $baSt->execute([(int) $txn['bank_account_id']]);
    $bankAccount = $baSt->fetch();
    if (!$bankAccount) fail('Compte bancaire introuvable', 404);
    if (empty($bankAccount['account_id'])) {
        fail("Le compte bancaire « {$bankAccount['name']} » n'est rattaché à aucun compte du plan comptable. Renseignez-le dans l'onglet Banque avant de rapprocher.");
    }

    /* Contrôle de sur-affectation avant toute écriture : le déjà-rapproché est
       relu en base, jamais déduit de ce que dit le navigateur. */
    $invSt   = $pdo->prepare('SELECT i.*, (SELECT COALESCE(SUM(r.amount_applied),0) FROM reconciliations r WHERE r.invoice_id = i.id) AS settled FROM invoices i WHERE i.id = ?');
    $planned = []; $sumApplied = 0.0;
    foreach ($allocations as $al) {
        $invId = (int) ($al['invoice_id'] ?? 0);
        if (!$invId) fail('Facture invalide dans la sélection');
        $invSt->execute([$invId]);
        $inv = $invSt->fetch();
        if (!$inv) fail("Facture #$invId introuvable", 404);
        if ($inv['status'] === 'annulee') fail("La facture {$inv['piece_ref']} est annulée");

        $open   = esc_num((float) $inv['amount_total'] - (float) $inv['settled']);
        $amount = esc_num($al['amount'] ?? 0);
        if ($amount <= 0) $amount = $open;
        if ($amount > $open + 0.005) {
            fail(sprintf('La facture %s ne doit plus que %.2f, impossible d\'y affecter %.2f', $inv['piece_ref'], $open, $amount));
        }
        $planned[] = ['invoice' => $inv, 'amount' => $amount];
        $sumApplied += $amount;
    }
    $sumApplied = esc_num($sumApplied);
    if ($sumApplied > abs((float) $txn['amount']) + 0.005) {
        fail(sprintf('Le total affecté (%.2f) dépasse le montant du mouvement bancaire (%.2f)', $sumApplied, abs((float) $txn['amount'])));
    }

    $bankAccountId = (int) $bankAccount['account_id'];
    $date          = s($b, 'entry_date') ?: (string) $txn['booking_date'];
    $notify        = [];
    /* Journal du règlement : celui demandé, sinon celui rattaché à ce compte
       bancaire, sinon le journal de banque par défaut. */
    $bankJournalId = i($b, 'journal_id') ?: journal_for_bank($pdo, (int) $bankAccount['id']);

    $pdo->beginTransaction();
    try {
        foreach ($planned as $p) {
            $inv    = $p['invoice'];
            $amount = $p['amount'];

            $collectiveKey = $inv['direction'] === 'a_recevoir' ? 'debtors_account' : 'creditors_account';
            $collective    = compta_setting_account($pdo, 'global', $collectiveKey);
            if (!$collective) throw new ComptaException("Le compte collectif n'est pas défini dans les Réglages");

            $lines = $inv['direction'] === 'a_recevoir'
                ? [   // encaissement : la banque augmente, la créance disparaît
                    ['account_id' => $bankAccountId, 'debit' => $amount, 'credit' => 0.0, 'label' => (string) $inv['tiers_label']],
                    ['account_id' => $collective,    'debit' => 0.0, 'credit' => $amount, 'label' => (string) $inv['label'], 'tiers_contact_id' => (string) $inv['tiers_contact_id']],
                  ]
                : [   // paiement : la dette disparaît, la banque diminue
                    ['account_id' => $collective,    'debit' => $amount, 'credit' => 0.0, 'label' => (string) $inv['label'], 'tiers_contact_id' => (string) $inv['tiers_contact_id']],
                    ['account_id' => $bankAccountId, 'debit' => 0.0, 'credit' => $amount, 'label' => (string) $inv['tiers_label']],
                  ];

            $entryId = compta_post_entry($pdo, [
                'entry_date'    => $date,
                'label'         => 'Règlement ' . ($inv['piece_ref'] ?: '#' . $inv['id']) . ' — ' . $inv['label'],
                'piece_kind'    => 'BQ',
                'journal_id'    => $bankJournalId,
                'source_module' => 'banque',
                'source_ref_id' => (string) $inv['id'],
                'created_by'    => $userName,
                'lines'         => $lines,
            ]);

            $pdo->prepare('INSERT INTO reconciliations (bank_transaction_id, journal_entry_id, invoice_id, amount_applied, reconciled_by) VALUES (?,?,?,?,?)')
                ->execute([$txnId, $entryId, (int) $inv['id'], $amount, $userName]);

            $res = invoice_refresh_status($pdo, (int) $inv['id'], $date);
            if ($res['failed']) $notify = array_merge($notify, $res['failed']);
        }

        $pdo->prepare("UPDATE bank_transactions SET reconciled_state = 'matched' WHERE id = ?")->execute([$txnId]);
        $pdo->commit();
        out(['ok' => true, 'applied' => $sumApplied, 'unsynced_sources' => $notify]);
    } catch (ComptaException $e) {
        $pdo->rollBack();
        fail($e->getMessage());
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur lors du rapprochement : ' . $e->getMessage(), 500);
    }
}

/**
 * Défait un rapprochement : extourne l'écriture de règlement (elle ne se
 * supprime pas, la pièce est numérotée) et remet la facture à son statut réel.
 */
case 'reconcile_undo': {
    $pdo = db();
    $id = i($_GET, 'id') ?: i($b, 'id');
    if (!$id) fail('ID de rapprochement requis');
    $st = $pdo->prepare('SELECT * FROM reconciliations WHERE id = ?');
    $st->execute([$id]);
    $rec = $st->fetch();
    if (!$rec) fail('Rapprochement introuvable', 404);

    $pdo->beginTransaction();
    try {
        $entryId = (int) $rec['journal_entry_id'];
        $dup = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE reversal_of_entry_id = ?');
        $dup->execute([$entryId]);
        if ((int) $dup->fetchColumn() === 0) {
            $lnSt = $pdo->prepare('SELECT * FROM journal_lines WHERE entry_id = ? ORDER BY id');
            $lnSt->execute([$entryId]);
            $mirror = [];
            foreach ($lnSt->fetchAll() as $l) {
                $mirror[] = [
                    'account_id'       => (int) $l['account_id'],
                    'debit'            => (float) $l['credit'],
                    'credit'           => (float) $l['debit'],
                    'label'            => (string) $l['label'],
                    'tiers_contact_id' => (string) $l['tiers_contact_id'],
                    'cost_center_id'   => $l['cost_center_id'] ?? null,
                ];
            }
            $orig = $pdo->prepare('SELECT piece_ref, label FROM journal_entries WHERE id = ?');
            $orig->execute([$entryId]);
            $o = $orig->fetch();
            if ($mirror) {
                compta_post_entry($pdo, [
                    'entry_date'           => s($b, 'entry_date') ?: date('Y-m-d'),
                    'label'                => 'Annulation règlement ' . (($o['piece_ref'] ?? '') ?: "#$entryId"),
                    'piece_kind'           => 'OD',
                    'source_module'        => 'extourne',
                    'source_ref_id'        => (string) $entryId,
                    'reversal_of_entry_id' => $entryId,
                    'created_by'           => $userName,
                    'lines'                => $mirror,
                ]);
            }
        }

        $pdo->prepare('DELETE FROM reconciliations WHERE id = ?')->execute([$id]);

        $remaining = $pdo->prepare('SELECT COUNT(*) FROM reconciliations WHERE bank_transaction_id = ?');
        $remaining->execute([$rec['bank_transaction_id']]);
        if ((int) $remaining->fetchColumn() === 0) {
            $pdo->prepare("UPDATE bank_transactions SET reconciled_state = 'unmatched' WHERE id = ?")->execute([$rec['bank_transaction_id']]);
        }
        if (!empty($rec['invoice_id'])) {
            invoice_refresh_status($pdo, (int) $rec['invoice_id'], date('Y-m-d'));
        }
        $pdo->commit();
        out(['ok' => true]);
    } catch (ComptaException $e) {
        $pdo->rollBack();
        fail($e->getMessage());
    } catch (\Exception $e) {
        $pdo->rollBack();
        fail('Erreur : ' . $e->getMessage(), 500);
    }
}

/* ============================================================ PIÈCES JOINTES
 *
 * Même dispositif que celui posé sur Events et Sponsors le 2026-07-30 : le
 * dossier est fermé par .htaccess, le nom stocké est imprévisible, et le
 * téléchargement passe par un point d'entrée qui revalide le droit et ne sert
 * que ce qui est référencé en base. Un fichier déposé par un autre moyen dans
 * uploads/ n'est donc jamais servi.
 */

case 'invoice_attachments': {
    $pdo = db();
    $id = i($_GET, 'invoice_id');
    if (!$id) fail('Facture requise');
    $st = $pdo->prepare('SELECT id, invoice_id, filename, mime, size, uploaded_by, created_at FROM invoice_attachments WHERE invoice_id = ? ORDER BY id');
    $st->execute([$id]);
    out($st->fetchAll());
}

case 'invoice_attach': {
    $pdo = db();
    $invoiceId = i($_POST, 'invoice_id');
    if (!$invoiceId) fail('Facture requise');
    if (empty($_FILES['file'])) fail('Fichier requis');
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail("Échec du téléversement (code {$f['error']})");
    if (($f['size'] ?? 0) > 15 * 1024 * 1024) fail('Fichier trop volumineux (15 Mo maximum)');

    $chk = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE id = ?');
    $chk->execute([$invoiceId]);
    if (!(int) $chk->fetchColumn()) fail('Facture introuvable', 404);

    /* Le type est déduit du CONTENU, jamais de l'extension ni de l'en-tête
       envoyé par le navigateur : les deux se falsifient. */
    [$mime, $ext] = detect_upload_type($f['tmp_name']);
    if ($mime === null) fail('Seuls les PDF et les images JPEG, PNG ou GIF sont acceptés');

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) fail('Impossible d\'enregistrer le fichier', 500);

    $pdo->prepare('INSERT INTO invoice_attachments (invoice_id, filename, stored_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?)')
        ->execute([$invoiceId, basename((string) $f['name']), $stored, $mime, (int) $f['size'], $userName]);
    out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

case 'invoice_attach_delete': {
    $pdo = db();
    $id = i($_GET, 'id') ?: i($b, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT stored_name FROM invoice_attachments WHERE id = ?');
    $st->execute([$id]);
    $stored = $st->fetchColumn();
    if ($stored === false) fail('Pièce jointe introuvable', 404);
    $pdo->prepare('DELETE FROM invoice_attachments WHERE id = ?')->execute([$id]);
    $path = __DIR__ . '/uploads/' . basename((string) $stored);
    if (is_file($path)) @unlink($path);
    out(['ok' => true]);
}

case 'invoice_attach_download': {
    $pdo = db();
    $id = i($_GET, 'id');
    if (!$id) fail('ID requis');
    $st = $pdo->prepare('SELECT * FROM invoice_attachments WHERE id = ?');
    $st->execute([$id]);
    $att = $st->fetch();
    if (!$att) fail('Pièce jointe introuvable', 404);

    $path = __DIR__ . '/uploads/' . basename((string) $att['stored_name']);
    if (!is_file($path)) fail('Fichier absent du serveur', 404);

    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode((string) $att['filename']) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ============================================================ MOUVEMENTS (vue Écritures)
 *
 * Liste plate de lignes, et non de pièces : on cherche un montant sur un
 * compte, pas une écriture dont on connaîtrait déjà le numéro. Chaque ligne
 * porte l'identifiant de la facture qui l'a produite, quand il y en a une.
 */
case 'entry_lines': {
    $pdo = db();
    $where = ['1=1']; $params = [];
    if (!empty($_GET['account_id']))     { $where[] = 'l.account_id = ?';     $params[] = (int) $_GET['account_id']; }
    if (!empty($_GET['fiscal_year_id'])) { $where[] = 'e.fiscal_year_id = ?'; $params[] = (int) $_GET['fiscal_year_id']; }
    if (!empty($_GET['from']))           { $where[] = 'e.entry_date >= ?';    $params[] = $_GET['from']; }
    if (!empty($_GET['to']))             { $where[] = 'e.entry_date <= ?';    $params[] = $_GET['to']; }
    if (!empty($_GET['source_module']))  { $where[] = 'e.source_module = ?';  $params[] = $_GET['source_module']; }
    if (!empty($_GET['journal_id']))     { $where[] = 'e.journal_id = ?';     $params[] = (int) $_GET['journal_id']; }
    if (!empty($_GET['q'])) {
        $where[] = '(e.label LIKE ? OR l.label LIKE ? OR e.piece_ref LIKE ? OR a.number LIKE ? OR a.name LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $st = $pdo->prepare(
        'SELECT l.id AS line_id, l.debit, l.credit, l.label AS line_label,
                e.id AS entry_id, e.entry_date, e.piece_ref, e.label AS entry_label,
                e.source_module, e.reversal_of_entry_id,
                a.id AS account_id, a.number AS account_number, a.name AS account_name, a.class,
                cc.code AS cost_center_code, j.code AS journal_code, j.label AS journal_label,
                (SELECT i.id FROM invoices i WHERE i.journal_entry_id = e.id LIMIT 1) AS invoice_id
           FROM journal_lines l
           JOIN journal_entries e ON e.id = l.entry_id
           JOIN accounts a        ON a.id = l.account_id
           LEFT JOIN journals j      ON j.id = e.journal_id
           LEFT JOIN cost_centers cc ON cc.id = l.cost_center_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY e.entry_date DESC, e.id DESC, l.id LIMIT 1000'
    );
    $st->execute($params);
    out($st->fetchAll());
}

/* ============================================================ PRODUITS */

case 'products': {
    $pdo = db();
    if ($method === 'GET') {
        $active = $_GET['active'] ?? '1';
        $sql = 'SELECT p.*, a.number AS account_number, a.name AS account_name, v.label AS vat_label
                  FROM products p
                  LEFT JOIN accounts a  ON a.id = p.account_id
                  LEFT JOIN vat_rates v ON v.id = p.vat_rate_id';
        $params = [];
        if ($active !== 'all') { $sql .= ' WHERE p.active = ?'; $params[] = (int) $active; }
        if (!empty($_GET['direction'])) {
            $sql .= ($active !== 'all' ? ' AND' : ' WHERE') . ' p.direction = ?';
            $params[] = $_GET['direction'];
        }
        $sql .= ' ORDER BY p.label';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        out($st->fetchAll());
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        if (!$label) fail('Libellé requis');
        $dir = s($b, 'direction', 'a_recevoir');
        if (!in_array($dir, ['a_payer', 'a_recevoir'], true)) fail('Sens invalide');
        $code = s($b, 'code') ?: cost_center_code($pdo, $label, 'products');
        $ins  = $pdo->prepare('INSERT INTO products (code, label, account_id, unit_price, vat_rate_id, unit, direction) VALUES (?,?,?,?,?,?,?)');
        $args = [$code, $label, i($b, 'account_id') ?: null, esc_num($b['unit_price'] ?? 0),
                 i($b, 'vat_rate_id') ?: null, s($b, 'unit', 'unité'), $dir];
        /* Le code est engendré par le serveur : une collision est un problème
           interne, pas une erreur de saisie. On resuffixe au lieu de renvoyer
           l'utilisateur devant un champ qu'il ne peut pas corriger. */
        for ($try = 0; $try < 5; $try++) {
            try {
                $ins->execute($args);
                out(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'code' => $args[0]]);
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'UNIQUE') === false) fail('Création impossible : ' . $e->getMessage());
                if (s($b, 'code')) fail('Ce code produit existe déjà');
                $args[0] = substr($code, 0, 8) . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 4));
            }
        }
        fail('Impossible de générer un code produit unique');
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $pdo->prepare('UPDATE products SET label=?, account_id=?, unit_price=?, vat_rate_id=?, unit=?, direction=?, active=? WHERE id=?')
            ->execute([s($b, 'label'), i($b, 'account_id') ?: null, esc_num($b['unit_price'] ?? 0),
                       i($b, 'vat_rate_id') ?: null, s($b, 'unit', 'unité'), s($b, 'direction', 'a_recevoir'), i($b, 'active', 1), $id]);
        out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = i($_GET, 'id');
        if (!$id) fail('ID requis');
        /* Un produit déjà facturé est désactivé, jamais supprimé : les lignes
           de facture le référencent, et le retirer viderait leur historique. */
        $used = $pdo->prepare('SELECT COUNT(*) FROM invoice_lines WHERE product_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$id]);
            out(['ok' => true, 'deactivated' => true]);
        }
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/* ============================================================ JOURNAUX
 *
 * Un journal classe les écritures et donne le préfixe de leur numéro de pièce.
 * Les six journaux d'origine portent les codes déjà employés par la
 * numérotation : les rebaptiser est sans effet sur les pièces déjà émises,
 * puisque c'est le code, jamais le libellé, qui compose le numéro. Le code d'un
 * journal ayant déjà servi n'est donc pas modifiable.
 */

case 'journals': {
    $pdo = db();
    if ($method === 'GET') {
        $active = $_GET['active'] ?? '1';
        $sql = "SELECT j.*, b.name AS bank_name,
                       (SELECT COUNT(*) FROM journal_entries e WHERE e.journal_id = j.id) AS entry_count
                  FROM journals j LEFT JOIN bank_accounts b ON b.id = j.bank_account_id";
        $params = [];
        if ($active !== 'all') { $sql .= ' WHERE j.active = ?'; $params[] = (int) $active; }
        $sql .= ' ORDER BY j.position, j.code';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        out($st->fetchAll());
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        if (!$label) fail('Libellé requis');
        $kind = s($b, 'kind', 'od');
        if (!in_array($kind, ['achat', 'vente', 'banque', 'od'], true)) {
            fail("Type de journal invalide. Les journaux d'ouverture et de clôture ne se créent pas à la main.");
        }
        /* Le code sert de préfixe à un numéro de pièce : majuscules et chiffres
           seulement, court, et stable une fois qu'il a servi. */
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', s($b, 'code')));
        if ($code === '') $code = cost_center_code($pdo, $label, 'journals');
        $code = substr(preg_replace('/[^A-Z0-9]/', '', $code), 0, 8);
        if (strlen($code) < 2) fail('Le code du journal doit compter au moins 2 caractères (lettres ou chiffres)');
        $dup = $pdo->prepare('SELECT COUNT(*) FROM journals WHERE code = ?');
        $dup->execute([$code]);
        if ((int) $dup->fetchColumn()) fail("Le code « $code » est déjà pris par un autre journal");

        $pos = (int) $pdo->query('SELECT COALESCE(MAX(position),0) + 10 FROM journals')->fetchColumn();
        $pdo->prepare('INSERT INTO journals (code, label, kind, bank_account_id, position) VALUES (?,?,?,?,?)')
            ->execute([$code, $label, $kind, i($b, 'bank_account_id') ?: null, $pos]);
        out(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'code' => $code]);
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $st = $pdo->prepare('SELECT * FROM journals WHERE id = ?');
        $st->execute([$id]);
        $j = $st->fetch();
        if (!$j) fail('Journal introuvable', 404);

        $active = i($b, 'active', 1);
        if (!$active && (int) $j['system']) fail("Le journal « {$j['label']} » est nécessaire au fonctionnement du module et ne peut pas être désactivé");

        /* Un seul journal par défaut et par type : celui qui sera proposé
           d'office à la saisie d'une facture ou à un import bancaire. */
        if (i($b, 'is_default')) {
            $pdo->prepare('UPDATE journals SET is_default = 0 WHERE kind = ?')->execute([$j['kind']]);
        }
        $pdo->prepare('UPDATE journals SET label=?, bank_account_id=?, is_default=?, active=? WHERE id=?')
            ->execute([s($b, 'label') ?: $j['label'], i($b, 'bank_account_id') ?: null, i($b, 'is_default'), $active, $id]);
        out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = i($_GET, 'id');
        if (!$id) fail('ID requis');
        $st = $pdo->prepare('SELECT * FROM journals WHERE id = ?');
        $st->execute([$id]);
        $j = $st->fetch();
        if (!$j) fail('Journal introuvable', 404);
        if ((int) $j['system']) fail("Le journal « {$j['label']} » est nécessaire au fonctionnement du module");

        /* Un journal ayant servi est désactivé, jamais supprimé : ses écritures
           perdraient sinon leur classement, et son code pourrait être réattribué
           alors que des pièces le portent déjà dans leur numéro. */
        $used = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE journal_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE journals SET active = 0 WHERE id = ?')->execute([$id]);
            out(['ok' => true, 'deactivated' => true]);
        }
        $pdo->prepare('DELETE FROM journals WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/* ============================================================ CONDITIONS DE PAIEMENT */

case 'payment_terms': {
    $pdo = db();
    if ($method === 'GET') {
        out($pdo->query('SELECT * FROM payment_terms WHERE active = 1 ORDER BY days')->fetchAll());
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        if (!$label) fail('Libellé requis');
        $pdo->prepare('INSERT INTO payment_terms (label, days) VALUES (?,?)')->execute([$label, i($b, 'days')]);
        out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        if (!empty($b['is_default'])) {
            /* Un seul défaut à la fois, sinon le formulaire en choisirait un au hasard. */
            $pdo->exec('UPDATE payment_terms SET is_default = 0');
            $pdo->prepare('UPDATE payment_terms SET is_default = 1 WHERE id = ?')->execute([$id]);
            out(['ok' => true]);
        }
        $pdo->prepare('UPDATE payment_terms SET label=?, days=?, active=? WHERE id=?')
            ->execute([s($b, 'label'), i($b, 'days'), i($b, 'active', 1), $id]);
        out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = i($_GET, 'id');
        if (!$id) fail('ID requis');
        $pdo->prepare('UPDATE payment_terms SET active = 0 WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/* ============================================================ ANNUAIRE (lecture depuis le socle)
 *
 * Accès direct au référentiel partagé, jamais par un appel HTTP à
 * contacts/api.php : c'est le principe appliqué partout dans l'ERP.
 *
 * Gardé par compta.invoices.edit et non par contacts.edit : exiger un droit sur
 * un autre module casserait le geste voulu — créer le tiers sans quitter la
 * facture. Le dédoublonnage du socle empêche les doublons, et la provenance
 * 'compta' dit d'où vient la fiche.
 */

case 'contacts_search': {
    $q = s($_GET, 'q');
    if (mb_strlen($q) < 2) out([]);
    try {
        $cdb = mfc_contacts_db();
    } catch (\Throwable $e) {
        out([]);   // annuaire indisponible : la saisie libre du tiers reste possible
    }
    $like = '%' . mb_strtolower($q) . '%';
    $st = $cdb->prepare(
        "SELECT ref_id, type, first_name, last_name, email, city, org_ref_id
           FROM contacts
          WHERE active = 1 AND (norm_name LIKE ? OR LOWER(email) LIKE ? OR LOWER(last_name) LIKE ?)
          ORDER BY last_name, first_name LIMIT 20"
    );
    $st->execute([$like, $like, $like]);
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        $out[] = [
            'ref_id'  => $c['ref_id'],
            'name'    => $name !== '' ? $name : (string) $c['last_name'],
            'email'   => (string) $c['email'],
            'city'    => (string) $c['city'],
            'is_org'  => ($c['type'] ?? '') === 'organisation',
        ];
    }
    out($out);
}

case 'contact_create': {
    $name = s($b, 'name');
    if ($name === '') fail('Nom requis');
    $isOrg = !empty($b['is_org']);
    try {
        $cdb = mfc_contacts_db();
    } catch (\Throwable $e) {
        fail("Le référentiel Contacts n'est pas disponible sur ce serveur", 503);
    }

    /* Une organisation porte son nom entier en last_name, une personne se
       découpe au premier espace : c'est la convention déjà en place dans le
       socle et dans la reprise des modules. */
    $payload = ['type' => $isOrg ? 'organisation' : 'personne', 'email' => s($b, 'email'), 'city' => s($b, 'city')];
    if ($isOrg) {
        $payload['last_name'] = $name;
    } else {
        $parts = preg_split('/\s+/', $name, 2);
        $payload['first_name'] = $parts[0] ?? '';
        $payload['last_name']  = $parts[1] ?? '';
        if ($payload['last_name'] === '') { $payload['last_name'] = $payload['first_name']; $payload['first_name'] = ''; }
    }

    try {
        $r = mfc_contacts_ingest($cdb, $payload, 'compta', 'invoice-party:' . md5(mb_strtolower($name) . '|' . s($b, 'email')));
        out(['ok' => true, 'ref_id' => $r['ref_id'], 'name' => $name, 'recognized' => $r['verdict'] === MFC_CONTACTS_CERTAIN]);
    } catch (\Throwable $e) {
        fail('Impossible de créer le contact : ' . $e->getMessage(), 500);
    }
}

/* ============================================================ RÉGLAGES */

case 'settings': {
    $pdo = db();
    if ($method === 'GET') {
        /* Le scope 'system' porte le marqueur de version du semis, il n'a rien
           à faire dans un écran de réglages. */
        $rows = $pdo->query("SELECT cs.*, a.number AS account_number, a.name AS account_name
                               FROM compta_settings cs
                               LEFT JOIN accounts a ON a.id = cs.account_id
                              WHERE cs.scope != 'system'
                              ORDER BY cs.scope, cs.id")->fetchAll();
        out($rows);
    }
    if ($method === 'PUT') {
        $items = is_array($b['settings'] ?? null) ? $b['settings'] : [$b];
        $selOld = $pdo->prepare('SELECT account_id, value, label FROM compta_settings WHERE scope = ? AND setting_key = ?');
        $upd = $pdo->prepare('UPDATE compta_settings SET account_id = ?, value = ? WHERE scope = ? AND setting_key = ? AND scope != \'system\'');
        $pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                $scope = trim((string) ($it['scope'] ?? ''));
                $key   = trim((string) ($it['setting_key'] ?? ''));
                if ($scope === '' || $key === '') continue;
                $selOld->execute([$scope, $key]);
                $old = $selOld->fetch();
                $newAccountId = !empty($it['account_id']) ? (int) $it['account_id'] : null;
                $newValue     = (string) ($it['value'] ?? '');
                $upd->execute([$newAccountId, $newValue, $scope, $key]);
                if ($old && ((string) $old['account_id'] !== (string) $newAccountId || $old['value'] !== $newValue)) {
                    compta_audit_log($pdo, 'compta_settings', null, 'update', 'Réglage ' . ($old['label'] ?: "$scope.$key"), $userName,
                        ['account_id' => [$old['account_id'], $newAccountId], 'value' => [$old['value'], $newValue]]);
                }
            }
            $pdo->commit();
            out(['ok' => true]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            fail('Erreur lors de l\'enregistrement des réglages : ' . $e->getMessage(), 500);
        }
    }
    fail('Méthode non supportée', 405);
}

case 'vat_rates': {
    $pdo = db();
    if ($method === 'GET') {
        out($pdo->query('SELECT * FROM vat_rates ORDER BY kind, rate_percent DESC, label')->fetchAll());
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        if (!$label) fail('Libellé requis');
        $kind = s($b, 'kind', 'vente');
        if (!in_array($kind, ['vente', 'achat'], true)) fail('Type de taux invalide');
        $pdo->prepare('INSERT INTO vat_rates (label, rate_percent, kind) VALUES (?,?,?)')
            ->execute([$label, esc_num($b['rate_percent'] ?? 0), $kind]);
        $newId = (int) $pdo->lastInsertId();
        compta_audit_log($pdo, 'vat_rate', $newId, 'create', "Taux TVA $label", $userName);
        out(['ok' => true, 'id' => $newId]);
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $cur = $pdo->prepare('SELECT * FROM vat_rates WHERE id = ?');
        $cur->execute([$id]);
        $old = $cur->fetch();
        if (!$old) fail('Taux introuvable', 404);

        $newRate = esc_num($b['rate_percent'] ?? 0);
        $changed = abs((float) $old['rate_percent'] - $newRate) > 0.0001;

        /* Changer un taux réécrit la TVA de toutes les factures qui le portent.
           Sous le régime de la dette fiscale nette la TVA est informative, aucune
           écriture ne bouge : le recalcul est donc sans danger comptable. Il
           reste visible sur des pièces déjà envoyées, d'où la confirmation. */
        $touched = vat_rate_invoice_ids($pdo, $id);
        if ($changed && $touched && empty($b['confirm'])) {
            out([
                'needs_confirm' => true,
                'affected'      => count($touched),
                'old_rate'      => esc_num($old['rate_percent']),
                'new_rate'      => $newRate,
            ]);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE vat_rates SET label=?, rate_percent=?, active=? WHERE id=?')
                ->execute([s($b, 'label'), $newRate, i($b, 'active', 1), $id]);
            $recomputed = 0;
            if ($changed) {
                foreach ($touched as $invId) { invoice_recompute_vat($pdo, $invId); $recomputed++; }
            }
            $diff = [];
            if ($changed) $diff['rate_percent'] = [esc_num($old['rate_percent']), $newRate];
            if ($old['label'] !== s($b, 'label')) $diff['label'] = [$old['label'], s($b, 'label')];
            if ($diff) compta_audit_log($pdo, 'vat_rate', $id, 'update', 'Taux TVA ' . $old['label'], $userName, $diff);
            $pdo->commit();
        } catch (\Exception $e) { $pdo->rollBack(); fail($e->getMessage()); }
        out(['ok' => true, 'recomputed' => $recomputed]);
    }
    if ($method === 'DELETE') {
        $id = i($_GET, 'id');
        if (!$id) fail('ID requis');
        $rateSt = $pdo->prepare('SELECT label FROM vat_rates WHERE id = ?'); $rateSt->execute([$id]); $rateLabel = $rateSt->fetchColumn();
        if ($rateLabel === false) fail('Taux introuvable', 404);
        /* Un taux déjà porté par une facture est désactivé, jamais supprimé :
           l'effacer rendrait illisible la TVA d'une pièce déjà comptabilisée.
           Un taux servant encore de défaut à un produit ou à un compte est
           conservé de même, sinon la référence pointerait dans le vide. */
        $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM invoices      WHERE vat_rate_id = :id)
                                    + (SELECT COUNT(*) FROM invoice_lines WHERE vat_rate_id = :id)
                                    + (SELECT COUNT(*) FROM products      WHERE vat_rate_id = :id)
                                    + (SELECT COUNT(*) FROM accounts      WHERE vat_rate_id = :id)');
        $used->execute([':id' => $id]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE vat_rates SET active = 0 WHERE id = ?')->execute([$id]);
            compta_audit_log($pdo, 'vat_rate', $id, 'deactivate', "Taux TVA $rateLabel", $userName);
            out(['ok' => true, 'deactivated' => true]);
        }
        compta_audit_log($pdo, 'vat_rate', $id, 'delete', "Taux TVA $rateLabel", $userName);
        $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/**
 * Taux de la dette fiscale nette, attribués par l'AFC (jusqu'à deux par
 * activité). Rattachés à un compte de produit, ils rendent le décompte
 * trimestriel calculable par simple regroupement.
 */
case 'vat_settlement_rates': {
    $pdo = db();
    if ($method === 'GET') {
        $rates = $pdo->query('SELECT * FROM vat_settlement_rates ORDER BY rate_percent DESC, label')->fetchAll();
        $mapped = $pdo->query("SELECT id, number, name, vat_settlement_rate_id FROM accounts
                                WHERE class = 'produit' AND active = 1 ORDER BY number")->fetchAll();
        out(['rates' => $rates, 'revenue_accounts' => $mapped]);
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        if (!$label) fail('Libellé requis');
        $pdo->prepare('INSERT INTO vat_settlement_rates (label, rate_percent) VALUES (?,?)')
            ->execute([$label, esc_num($b['rate_percent'] ?? 0)]);
        $newId = (int) $pdo->lastInsertId();
        compta_audit_log($pdo, 'vat_settlement_rate', $newId, 'create', "TDFN $label", $userName);
        out(['ok' => true, 'id' => $newId]);
    }
    if ($method === 'PUT') {
        /* Deux usages : modifier un taux, ou rattacher un taux à un compte. */
        if (isset($b['account_id'])) {
            $accId = i($b, 'account_id');
            $accSt = $pdo->prepare('SELECT number, name, vat_settlement_rate_id FROM accounts WHERE id = ?'); $accSt->execute([$accId]); $acc = $accSt->fetch();
            $newRateId = i($b, 'vat_settlement_rate_id') ?: null;
            $pdo->prepare('UPDATE accounts SET vat_settlement_rate_id = ? WHERE id = ?')->execute([$newRateId, $accId]);
            if ($acc && (string) $acc['vat_settlement_rate_id'] !== (string) $newRateId) {
                compta_audit_log($pdo, 'account', $accId, 'update', "Compte {$acc['number']} — {$acc['name']}", $userName,
                    ['vat_settlement_rate_id' => [$acc['vat_settlement_rate_id'], $newRateId]]);
            }
            out(['ok' => true]);
        }
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $old = $pdo->prepare('SELECT * FROM vat_settlement_rates WHERE id = ?'); $old->execute([$id]); $old = $old->fetch();
        if (!$old) fail('Taux introuvable', 404);
        $newLabel = s($b, 'label'); $newRate = esc_num($b['rate_percent'] ?? 0); $newActive = i($b, 'active', 1);
        $pdo->prepare('UPDATE vat_settlement_rates SET label=?, rate_percent=?, active=? WHERE id=?')
            ->execute([$newLabel, $newRate, $newActive, $id]);
        $diff = [];
        if ($old['label'] !== $newLabel) $diff['label'] = [$old['label'], $newLabel];
        if (abs((float) $old['rate_percent'] - $newRate) > 0.0001) $diff['rate_percent'] = [esc_num($old['rate_percent']), $newRate];
        if ((int) $old['active'] !== $newActive) $diff['active'] = [(int) $old['active'], $newActive];
        if ($diff) compta_audit_log($pdo, 'vat_settlement_rate', $id, 'update', 'TDFN ' . $old['label'], $userName, $diff);
        out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = i($_GET, 'id');
        if (!$id) fail('ID requis');
        $rateSt = $pdo->prepare('SELECT label FROM vat_settlement_rates WHERE id = ?'); $rateSt->execute([$id]); $rateLabel = $rateSt->fetchColumn();
        if ($rateLabel === false) fail('Taux introuvable', 404);
        $pdo->prepare('UPDATE accounts SET vat_settlement_rate_id = NULL WHERE vat_settlement_rate_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vat_settlement_rates WHERE id = ?')->execute([$id]);
        compta_audit_log($pdo, 'vat_settlement_rate', $id, 'delete', "TDFN $rateLabel", $userName);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

case 'cost_centers': {
    $pdo = db();
    if ($method === 'GET') {
        $active = $_GET['active'] ?? '1';
        $sql = 'SELECT * FROM cost_centers';
        $params = [];
        if ($active !== 'all') { $sql .= ' WHERE active = ?'; $params[] = (int) $active; }
        $sql .= ' ORDER BY kind, label';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        out($st->fetchAll());
    }
    if ($method === 'POST') {
        $label = s($b, 'label');
        if (!$label) fail('Libellé requis');
        $kind = s($b, 'kind', 'autre');
        if (!in_array($kind, ['equipe', 'categorie', 'autre'], true)) fail('Type de centre de coût invalide');
        $code = s($b, 'code') ?: cost_center_code($pdo, $label);
        try {
            $pdo->prepare('INSERT INTO cost_centers (code, label, kind, club_ref) VALUES (?,?,?,?)')
                ->execute([$code, $label, $kind, s($b, 'club_ref')]);
            $newId = (int) $pdo->lastInsertId();
            compta_audit_log($pdo, 'cost_center', $newId, 'create', "Centre de coût $code — $label", $userName);
            out(['ok' => true, 'id' => $newId]);
        } catch (\Exception $e) { fail('Ce code de centre de coût existe déjà'); }
    }
    if ($method === 'PUT') {
        $id = i($b, 'id');
        if (!$id) fail('ID requis');
        $old = $pdo->prepare('SELECT * FROM cost_centers WHERE id = ?'); $old->execute([$id]); $old = $old->fetch();
        if (!$old) fail('Centre de coût introuvable', 404);
        $newLabel = s($b, 'label'); $newKind = s($b, 'kind', 'autre'); $newActive = i($b, 'active', 1);
        $pdo->prepare('UPDATE cost_centers SET label=?, kind=?, active=? WHERE id=?')
            ->execute([$newLabel, $newKind, $newActive, $id]);
        $diff = [];
        if ($old['label'] !== $newLabel) $diff['label'] = [$old['label'], $newLabel];
        if ($old['kind'] !== $newKind) $diff['kind'] = [$old['kind'], $newKind];
        if ((int) $old['active'] !== $newActive) $diff['active'] = [(int) $old['active'], $newActive];
        if ($diff) compta_audit_log($pdo, 'cost_center', $id, 'update', "Centre de coût {$old['code']} — {$old['label']}", $userName, $diff);
        out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = i($_GET, 'id');
        if (!$id) fail('ID requis');
        $ccSt = $pdo->prepare('SELECT code, label FROM cost_centers WHERE id = ?'); $ccSt->execute([$id]); $cc = $ccSt->fetch();
        if (!$cc) fail('Centre de coût introuvable', 404);
        /* Un centre de coût déjà utilisé est désactivé : le supprimer viderait
           l'analytique des écritures passées. */
        $used = $pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE cost_center_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE cost_centers SET active = 0 WHERE id = ?')->execute([$id]);
            compta_audit_log($pdo, 'cost_center', $id, 'deactivate', "Centre de coût {$cc['code']} — {$cc['label']}", $userName);
            out(['ok' => true, 'deactivated' => true]);
        }
        compta_audit_log($pdo, 'cost_center', $id, 'delete', "Centre de coût {$cc['code']} — {$cc['label']}", $userName);
        $pdo->prepare('DELETE FROM cost_centers WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
}

/**
 * Reprise des équipes et catégories depuis le référentiel club partagé.
 * À la demande, jamais au démarrage : une reprise automatique ressusciterait
 * les centres de coût désactivés à la main.
 */
case 'cost_centers_sync_club': {
    $pdo = db();
    $created = 0;
    $ins = $pdo->prepare('INSERT OR IGNORE INTO cost_centers (code, label, kind, club_ref) VALUES (?,?,?,?)');

    /* Le référentiel club identifie une catégorie par `id` et une équipe par
       son `ref_id` (identité stable d'une saison à l'autre) : c'est cette clé
       qu'on garde en club_ref, pas le nom, qui peut être modifié. */
    foreach (mfc_club_categories(true) as $cat) {
        $ins->execute(['CAT-' . $cat['id'], (string) $cat['name'], 'categorie', (string) $cat['id']]);
        $created += $ins->rowCount();
    }
    $seasonId = mfc_club_current_season_id();
    if ($seasonId !== null) {
        foreach (mfc_club_teams($seasonId) as $team) {
            $ins->execute(['EQ-' . $team['ref_id'], (string) $team['name'], 'equipe', (string) $team['ref_id']]);
            $created += $ins->rowCount();
        }
    }
    out(['ok' => true, 'created' => $created, 'season_id' => $seasonId]);
}

/** Ligne analytique modifiable après coup : elle n'entre pas dans l'équilibre. */
case 'line_cost_center': {
    $pdo = db();
    $lineId = i($b, 'line_id');
    if (!$lineId) fail('Ligne requise');
    $pdo->prepare('UPDATE journal_lines SET cost_center_id = ? WHERE id = ?')
        ->execute([i($b, 'cost_center_id') ?: null, $lineId]);
    out(['ok' => true]);
}

/**
 * Consultation du journal d'audit — art. 957a CO. Lecture seule : rien
 * n'écrit ici, seul compta_audit_log() alimente la table (voir les points
 * d'audit répartis dans ce fichier et dans mfc_compta.php::compta_post_entry
 * / invoice_create). Filtrable par type d'entité, par pièce précise, par
 * période et par mot-clé (utilisateur ou résumé), plafonné à 500 lignes.
 */
case 'audit_log': {
    $pdo = db();
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $where = ['1=1']; $params = [];
    if (!empty($_GET['entity_type'])) { $where[] = 'entity_type = ?'; $params[] = $_GET['entity_type']; }
    if (!empty($_GET['entity_id']))   { $where[] = 'entity_id = ?';   $params[] = (int) $_GET['entity_id']; }
    if (!empty($_GET['from']))        { $where[] = 'date(created_at) >= ?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'date(created_at) <= ?'; $params[] = $_GET['to']; }
    if (!empty($_GET['q'])) {
        $where[] = '(summary LIKE ? OR user_name LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like);
    }
    $st = $pdo->prepare('SELECT * FROM audit_log WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 500');
    $st->execute($params);
    out($st->fetchAll());
}

/* ============================================================ RAPPORTS */

case 'report_balance': {
    $pdo = db();
    $from = s($_GET, 'from');
    $to   = s($_GET, 'to');
    $where = ['1=1']; $params = [];
    if ($from) { $where[] = 'e.entry_date >= ?'; $params[] = $from; }
    if ($to)   { $where[] = 'e.entry_date <= ?'; $params[] = $to; }
    /* Même correction que dans balances_at() : la condition de date vit dans la
       jointure externe vers journal_entries, donc une écriture hors plage
       laisse sa ligne en place avec `e` nul. Sans le CASE, les bornes from/to
       de ce rapport étaient purement décoratives — défaut présent depuis la
       Phase 1, la balance ignorait sa période. */
    $sql = "SELECT a.id, a.number, a.name, a.class,
                   COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit  ELSE 0 END), 0) AS total_debit,
                   COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS total_credit
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

/**
 * Grand livre : mouvements d'un compte avec solde progressif, précédés du
 * report à nouveau (le cumul antérieur à la période). Sans ce report, le solde
 * affiché serait juste sur la période mais faux dans l'absolu.
 */
/**
 * Grand livre. Sans compte choisi, il déroule TOUS les comptes mouvementés sur
 * la période, chacun avec son report à nouveau, ses mouvements et son solde :
 * c'est la forme sous laquelle un grand livre se remet à un réviseur. Le filtre
 * par compte n'est qu'une réduction de cette vue.
 */
case 'report_ledger': {
    $pdo = db();
    $accountId = i($_GET, 'account_id');
    $from = s($_GET, 'from');
    $to   = s($_GET, 'to');
    $journalId = i($_GET, 'journal_id');

    $account = null;
    if ($accountId) {
        $acc = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $acc->execute([$accountId]);
        $account = $acc->fetch();
        if (!$account) fail('Compte introuvable', 404);
    }

    /* Reports à nouveau de tous les comptes concernés en une requête : un aller
       par compte aurait multiplié les requêtes par le nombre de comptes. */
    $openings = [];
    if ($from) {
        $ow = ['e.entry_date < ?']; $op = [$from];
        if ($accountId) { $ow[] = 'l.account_id = ?'; $op[] = $accountId; }
        $st = $pdo->prepare('SELECT l.account_id, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS bal
                               FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                              WHERE ' . implode(' AND ', $ow) . ' GROUP BY l.account_id');
        $st->execute($op);
        foreach ($st->fetchAll() as $r) $openings[(int) $r['account_id']] = esc_num($r['bal']);
    }

    $where = ['1=1']; $params = [];
    if ($accountId) { $where[] = 'l.account_id = ?';  $params[] = $accountId; }
    if ($from)      { $where[] = 'e.entry_date >= ?'; $params[] = $from; }
    if ($to)        { $where[] = 'e.entry_date <= ?'; $params[] = $to; }
    if ($journalId) { $where[] = 'e.journal_id = ?';  $params[] = $journalId; }

    $st = $pdo->prepare('SELECT e.id AS entry_id, e.entry_date, e.piece_ref, e.label AS entry_label,
                                e.source_module, l.id AS line_id, l.account_id, l.debit, l.credit,
                                l.label AS line_label, cc.code AS cost_center_code,
                                a.number AS account_number, a.name AS account_name, a.class,
                                j.code AS journal_code,
                                (SELECT i.id FROM invoices i WHERE i.journal_entry_id = e.id LIMIT 1) AS invoice_id
                           FROM journal_lines l
                           JOIN journal_entries e ON e.id = l.entry_id
                           JOIN accounts a        ON a.id = l.account_id
                           LEFT JOIN journals j      ON j.id = e.journal_id
                           LEFT JOIN cost_centers cc ON cc.id = l.cost_center_id
                          WHERE ' . implode(' AND ', $where) . '
                          ORDER BY a.number, e.entry_date, e.id, l.id
                          LIMIT 5000');
    $st->execute($params);
    $all = $st->fetchAll();

    /* Un grand livre se lit compte par compte, chacun avec son solde progressif :
       le regroupement se fait ici et non à l'écran, pour que l'export et
       l'impression en héritent sans le refaire. */
    $groups = []; $running = [];
    foreach ($all as $r) {
        $aid = (int) $r['account_id'];
        if (!isset($groups[$aid])) {
            $groups[$aid] = [
                'account_id'      => $aid,
                'account_number'  => $r['account_number'],
                'account_name'    => $r['account_name'],
                'class'           => $r['class'],
                'opening_balance' => $openings[$aid] ?? 0.0,
                'total_debit'     => 0.0,
                'total_credit'    => 0.0,
                'lines'           => [],
            ];
            $running[$aid] = (float) ($openings[$aid] ?? 0.0);
        }
        $running[$aid] = round($running[$aid] + (float) $r['debit'] - (float) $r['credit'], 2);
        $r['running_balance'] = $running[$aid];
        $groups[$aid]['lines'][]      = $r;
        $groups[$aid]['total_debit']  = esc_num($groups[$aid]['total_debit']  + (float) $r['debit']);
        $groups[$aid]['total_credit'] = esc_num($groups[$aid]['total_credit'] + (float) $r['credit']);
        $groups[$aid]['closing_balance'] = $running[$aid];
    }
    $groups = array_values($groups);

    /* `account` et `lines` restent renseignés quand un seul compte est demandé :
       c'est la forme que consommait déjà l'écran, elle ne doit pas se rompre. */
    $single = $accountId ? ($groups[0] ?? null) : null;
    out([
        'account'         => $account,
        'accounts'        => $groups,
        'truncated'       => count($all) >= 5000,
        'opening_balance' => $single['opening_balance'] ?? 0.0,
        'closing_balance' => $single['closing_balance'] ?? 0.0,
        'lines'           => $single['lines'] ?? [],
    ]);
}

/**
 * Bilan au sens de l'art. 959 CO, avec comparatif de l'exercice précédent
 * (art. 958d al. 2 CO : les chiffres de l'exercice précédent doivent figurer).
 *
 * Un bilan se lit en cumulé depuis l'origine, pas sur la seule période : les
 * soldes sont donc arrêtés à une date, pas calculés entre deux dates.
 */
case 'report_balance_sheet': {
    $pdo = db();
    $fyId = i($_GET, 'fiscal_year_id');
    $fy   = null;
    if ($fyId) {
        $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ?');
        $st->execute([$fyId]);
        $fy = $st->fetch();
    } else {
        $fy = compta_current_fiscal_year($pdo);
    }
    if (!$fy) fail("Aucun exercice comptable n'est défini");

    $prevSt = $pdo->prepare('SELECT * FROM fiscal_years WHERE end_date < ? ORDER BY end_date DESC LIMIT 1');
    $prevSt->execute([(string) $fy['start_date']]);
    $prev = $prevSt->fetch() ?: null;

    /* Le bilan se lit à partir du DÉBUT de l'exercice, pas depuis l'origine :
       l'écriture d'ouverture porte déjà toute la position reportée. Cumuler
       depuis l'origine compterait chaque solde deux fois, une fois par les
       mouvements d'origine et une fois par l'à-nouveau qui les reprend. */
    $rows = balances_at($pdo, (string) $fy['end_date'], ['actif', 'passif'], (string) $fy['start_date']);
    $prevRows = $prev ? balances_at($pdo, (string) $prev['end_date'], ['actif', 'passif'], (string) $prev['start_date']) : [];
    $prevById = [];
    foreach ($prevRows as $p) $prevById[$p['id']] = (float) $p['balance'];

    /* Corollaire : sans écriture d'ouverture, le bilan est muet sur tout ce qui
       précède l'exercice. L'avertissement ne dépend PAS de l'existence d'un
       exercice précédent dans l'ERP : lors de la reprise depuis un autre outil,
       il n'y en a justement aucun, et c'est le cas où le bilan est le plus
       trompeur. Un bilan incomplet doit se dire, il s'équilibre malgré tout
       (la partie double y veille) et ne se trahit donc pas tout seul. */
    $opSt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE fiscal_year_id = ? AND source_module = 'ouverture'");
    $opSt->execute([(int) $fy['id']]);
    $hasOpening = (int) $opSt->fetchColumn() > 0;

    /* Le résultat de l'exercice n'est viré aux fonds propres qu'à la clôture.
       Tant que l'exercice est ouvert, il doit malgré tout apparaître au passif,
       sans quoi le bilan ne s'équilibre pas. */
    $pnl = balances_at($pdo, (string) $fy['end_date'], ['charge', 'produit'], (string) $fy['start_date']);
    $resultCurrent = 0.0;
    foreach ($pnl as $p) $resultCurrent += (float) $p['balance'];
    $resultCurrent = esc_num(-$resultCurrent);   // positif = bénéfice

    $actif = []; $passif = []; $totalActif = 0.0; $totalPassif = 0.0;
    foreach ($rows as $r) {
        $bal = (float) $r['balance'];
        if (abs($bal) < 0.005 && !isset($prevById[$r['id']])) continue;
        $r['balance_prev'] = esc_num($prevById[$r['id']] ?? 0);
        if ($r['class'] === 'actif') {
            $r['balance'] = esc_num($bal);
            $totalActif += $bal;
            $actif[] = $r;
        } else {
            /* Un passif est créditeur : on le présente en positif. */
            $r['balance'] = esc_num(-$bal);
            $r['balance_prev'] = esc_num(-($prevById[$r['id']] ?? 0));
            $totalPassif += -$bal;
            $passif[] = $r;
        }
    }

    out([
        'fiscal_year'    => $fy,
        'previous_year'  => $prev,
        'as_of'          => $fy['end_date'],
        'actif'          => $actif,
        'passif'         => $passif,
        'total_actif'    => esc_num($totalActif),
        'total_passif'   => esc_num($totalPassif),
        'result_current' => $fy['status'] === 'cloture' ? 0.0 : $resultCurrent,
        'balanced'       => abs($totalActif - $totalPassif - ($fy['status'] === 'cloture' ? 0.0 : $resultCurrent)) < 0.05,
        'has_opening'    => $hasOpening,
        'warning'        => $hasOpening ? null : sprintf(
            "Bilan incomplet : cet exercice n'a pas encore d'écriture d'ouverture. Seuls les mouvements depuis le %s y figurent%s. "
          . "Le compte de résultat, lui, est juste : les comptes de charge et de produit repartent de zéro à chaque exercice.",
            $fy['start_date'],
            $prev ? ", les soldes repris de « {$prev['label']} » en sont absents" : ', les soldes repris de la comptabilité précédente en sont absents'
        ),
    ]);
}

/** Compte de résultat de l'exercice, avec comparatif de l'exercice précédent. */
case 'report_income_statement': {
    $pdo = db();
    $fyId = i($_GET, 'fiscal_year_id');
    if ($fyId) {
        $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ?');
        $st->execute([$fyId]);
        $fy = $st->fetch();
    } else {
        $fy = compta_current_fiscal_year($pdo);
    }
    if (!$fy) fail("Aucun exercice comptable n'est défini");

    $prevSt = $pdo->prepare('SELECT * FROM fiscal_years WHERE end_date < ? ORDER BY end_date DESC LIMIT 1');
    $prevSt->execute([(string) $fy['start_date']]);
    $prev = $prevSt->fetch() ?: null;

    $cur  = balances_at($pdo, (string) $fy['end_date'], ['charge', 'produit'], (string) $fy['start_date']);
    $prevRows = $prev ? balances_at($pdo, (string) $prev['end_date'], ['charge', 'produit'], (string) $prev['start_date']) : [];
    $prevById = [];
    foreach ($prevRows as $p) $prevById[$p['id']] = (float) $p['balance'];

    $charges = []; $produits = []; $totalCharges = 0.0; $totalProduits = 0.0;
    foreach ($cur as $r) {
        $bal = (float) $r['balance'];
        if (abs($bal) < 0.005 && !isset($prevById[$r['id']])) continue;
        if ($r['class'] === 'charge') {
            $r['balance'] = esc_num($bal);
            $r['balance_prev'] = esc_num($prevById[$r['id']] ?? 0);
            $totalCharges += $bal;
            $charges[] = $r;
        } else {
            $r['balance'] = esc_num(-$bal);
            $r['balance_prev'] = esc_num(-($prevById[$r['id']] ?? 0));
            $totalProduits += -$bal;
            $produits[] = $r;
        }
    }

    $prevCharges = 0.0; $prevProduits = 0.0;
    foreach ($prevRows as $p) {
        $bal = (float) $p['balance'];
        if ($p['class'] === 'charge') $prevCharges += $bal; else $prevProduits += -$bal;
    }

    out([
        'fiscal_year'   => $fy,
        'previous_year' => $prev,
        'charges'       => $charges,
        'produits'      => $produits,
        'total_charges' => esc_num($totalCharges),
        'total_produits'=> esc_num($totalProduits),
        'result'        => esc_num($totalProduits - $totalCharges),
        'result_prev'   => esc_num($prevProduits - $prevCharges),
    ]);
}

/**
 * Balance âgée : ce qui reste dû, par tranche d'ancienneté d'échéance.
 * Les factures sans échéance sont comptées à partir de leur date de pièce,
 * plutôt qu'ignorées.
 */
case 'report_aged_balance': {
    $pdo = db();
    $direction = s($_GET, 'direction', 'a_recevoir');
    if (!in_array($direction, ['a_payer', 'a_recevoir'], true)) fail('Sens invalide');

    $st = $pdo->prepare('SELECT ' . invoice_select() . "
                          WHERE i.direction = ? AND i.status IN ('ouverte','reglee_partielle')
                          ORDER BY i.due_date, i.entry_date");
    $st->execute([$direction]);

    $today   = date('Y-m-d');
    $buckets = ['non_echu' => 0.0, 'j0_30' => 0.0, 'j31_60' => 0.0, 'j61_90' => 0.0, 'j90_plus' => 0.0];
    $rows = [];
    foreach ($st->fetchAll() as $inv) {
        $inv = invoice_decorate($inv);
        $open = (float) $inv['amount_open'];
        if ($open <= 0.005) continue;

        $ref  = $inv['due_date'] ?: $inv['entry_date'];
        $days = (int) floor((strtotime($today) - strtotime((string) $ref)) / 86400);
        $key  = $days <= 0 ? 'non_echu' : ($days <= 30 ? 'j0_30' : ($days <= 60 ? 'j31_60' : ($days <= 90 ? 'j61_90' : 'j90_plus')));
        $buckets[$key] += $open;

        $inv['days_late'] = max(0, $days);
        $inv['bucket']    = $key;
        $rows[] = $inv;
    }
    foreach ($buckets as $k => $v) $buckets[$k] = esc_num($v);

    out(['direction' => $direction, 'buckets' => $buckets, 'total' => esc_num(array_sum($buckets)), 'invoices' => $rows]);
}

default:
    fail('Action inconnue : ' . htmlspecialchars($action, ENT_QUOTES), 404);
}

/* ---------------------------------------------------------------- Fonctions métier
 *
 * La résolution d'exercice et de période vit désormais dans lib/mfc_compta.php
 * (compta_fiscal_year_for, compta_period_for, compta_period_is_closed), pour
 * que factures, règlements, ouverture et clôture partagent exactement les
 * mêmes règles que la saisie manuelle.
 */

/**
 * Projection commune à la liste et au détail d'une facture.
 *
 * Une FONCTION, pas une constante : les fonctions sont hissées à la
 * compilation du fichier, alors qu'un `const` de premier niveau n'existe qu'une
 * fois l'exécution arrivée dessus. Comme tout le routeur sort par `exit`, une
 * constante déclarée ici, sous le switch, serait introuvable au moment où les
 * actions l'utilisent.
 */
function invoice_select(): string {
    return "i.*,
        a.number AS account_number, a.name AS account_name,
        cc.code AS cost_center_code, cc.label AS cost_center_label,
        vr.label AS vat_rate_label, vr.rate_percent AS vat_rate_percent,
        e.piece_ref AS entry_piece_ref, e.journal_id AS journal_id,
        j.code AS journal_code, j.label AS journal_label,
        (SELECT COALESCE(SUM(r.amount_applied), 0) FROM reconciliations r WHERE r.invoice_id = i.id) AS amount_settled
      FROM invoices i
      LEFT JOIN accounts a        ON a.id  = i.account_id
      LEFT JOIN cost_centers cc   ON cc.id = i.cost_center_id
      LEFT JOIN vat_rates vr      ON vr.id = i.vat_rate_id
      LEFT JOIN journal_entries e ON e.id  = i.journal_entry_id
      LEFT JOIN journals j        ON j.id  = e.journal_id";
}

/**
 * Soldes par compte arrêtés à une date, pour une ou plusieurs classes.
 *
 * $since borne le cumul par le bas : absent pour un bilan (qui se lit depuis
 * l'origine), renseigné pour un compte de résultat (qui ne porte que
 * l'exercice). Le solde renvoyé est toujours débit moins crédit ; c'est
 * l'appelant qui décide de la présentation.
 */
function balances_at(PDO $pdo, string $asOf, array $classes, ?string $since = null): array {
    /* Le filtre de date porte sur la jointure, pas sur le WHERE : un compte
       sans mouvement doit rester dans le résultat avec un solde à zéro, sinon
       il disparaîtrait du bilan et de son comparatif. Les paramètres suivent
       donc l'ordre du SQL : dates (jointure) puis classes (WHERE). */
    $dateJoin = 'e.entry_date <= ?';
    $params   = [$asOf];
    if ($since !== null) {
        $dateJoin .= ' AND e.entry_date >= ?';
        $params[] = $since;
    }
    $ph = implode(',', array_fill(0, count($classes), '?'));
    $params = array_merge($params, $classes);

    /* Les sommes sont conditionnées par `e.id IS NOT NULL`, et ce n'est pas une
       précaution cosmétique : dans une jointure EXTERNE, une écriture hors
       plage ne fait pas disparaître sa ligne, elle rend seulement `e` nul. Sans
       ce CASE, SUM(l.debit) additionnerait aussi les lignes que la condition de
       date était censée écarter, et le filtre ne filtrerait rien. */
    $st = $pdo->prepare("
        SELECT a.id, a.number, a.name, a.class, a.category,
               COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit  ELSE 0 END), 0) AS total_debit,
               COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS total_credit,
               COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit  ELSE 0 END), 0)
             - COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS balance
          FROM accounts a
          LEFT JOIN journal_lines l   ON l.account_id = a.id
          LEFT JOIN journal_entries e ON e.id = l.entry_id AND $dateJoin
         WHERE a.class IN ($ph)
         GROUP BY a.id
         ORDER BY a.number");
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Code court d'un centre de coût saisi à la main, dérivé de son libellé.
 * Suffixé si nécessaire : deux libellés voisins ne doivent pas se disputer la
 * contrainte d'unicité, la saisie doit passer du premier coup.
 */
function cost_center_code(PDO $pdo, string $label, string $table = 'cost_centers'): string {
    /* Le code est cherché dans la table où il sera inséré. Le chercher ailleurs
       (les produits contrôlés contre les centres de coût) laissait passer un
       doublon que la contrainte UNIQUE rejetait ensuite, avec un message
       parlant d'un champ que l'utilisateur ne voit même pas. */
    if (!in_array($table, ['cost_centers', 'products', 'journals'], true)) $table = 'cost_centers';
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', @iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: $label));
    $base = trim(substr($base, 0, 12), '-') ?: 'CC';
    $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE code = ?");
    $st->execute([$base]);
    if (!(int) $st->fetchColumn()) return $base;
    for ($n = 2; $n < 100; $n++) {
        $st->execute(["$base-$n"]);
        if (!(int) $st->fetchColumn()) return "$base-$n";
    }
    return $base . '-' . substr(md5(uniqid('', true)), 0, 4);
}

/** Champs calculés attendus par l'interface : reste dû et retard d'échéance. */
function invoice_decorate(array $inv): array {
    $total    = (float) ($inv['amount_total'] ?? 0);
    $settled  = (float) ($inv['amount_settled'] ?? 0);
    $inv['amount_open'] = esc_num($total - $settled);
    $inv['is_overdue']  = !empty($inv['due_date'])
        && $inv['due_date'] < date('Y-m-d')
        && !in_array($inv['status'], ['reglee', 'annulee'], true);
    return $inv;
}

/**
 * Crée une facture et son écriture comptable. À appeler dans une transaction
 * déjà ouverte par l'appelant.
 *
 * Sous le régime de la dette fiscale nette, la TVA n'est PAS ventilée : la
 * charge comme le produit sont comptabilisés pour leur montant TTC. La TVA
 * enregistrée sur la facture est informative et sert de base au décompte
 * trimestriel, elle ne produit aucune ligne d'écriture.
 */
function invoice_create(PDO $pdo, array $d): int {
    $direction = (string) $d['direction'];
    if (!in_array($direction, ['a_payer', 'a_recevoir'], true)) throw new ComptaException('Sens de facture invalide');
    $label = trim((string) $d['label']) ?: 'Facture';
    $date  = (string) $d['entry_date'];
    $tiers = (string) ($d['tiers_contact_id'] ?? '');

    $collectiveKey = $direction === 'a_recevoir' ? 'debtors_account' : 'creditors_account';
    $collective    = compta_setting_account($pdo, 'global', $collectiveKey);
    if (!$collective) {
        $human = $direction === 'a_recevoir' ? 'Débiteurs' : 'Créanciers';
        throw new ComptaException("Le compte collectif $human n'est pas défini dans les Réglages");
    }

    $lines = invoice_normalize_lines($pdo, $d['lines'] ?? [], $collective);
    if (!$lines) throw new ComptaException('Une facture doit comporter au moins une ligne avec un montant');

    $total = 0.0; $vatTotal = 0.0;
    foreach ($lines as $l) { $total += $l['amount']; $vatTotal += $l['vat_amount']; }
    $total    = esc_num($total);
    $vatTotal = esc_num($vatTotal);
    if ($total <= 0) throw new ComptaException('Le total de la facture doit être supérieur à zéro');

    /* Échéance : celle fournie, sinon calculée depuis la condition de paiement. */
    $dueDate = trim((string) ($d['due_date'] ?? ''));
    $termId  = !empty($d['payment_term_id']) ? (int) $d['payment_term_id'] : null;
    if ($dueDate === '' && $termId) {
        $ts = $pdo->prepare('SELECT days FROM payment_terms WHERE id = ?');
        $ts->execute([$termId]);
        $days = $ts->fetchColumn();
        if ($days !== false) $dueDate = date('Y-m-d', strtotime($date . ' +' . (int) $days . ' days'));
    }

    /* Une ligne d'écriture PAR COMPTE : deux prestations sur le même compte
       n'ont pas à produire deux lignes au journal. Le centre de coût suit tant
       qu'il est unique pour le compte, sinon il reste à la ligne de facture,
       qui garde le détail analytique fin. */
    $byAccount = [];
    foreach ($lines as $l) {
        $k = (int) $l['account_id'];
        if (!isset($byAccount[$k])) {
            $byAccount[$k] = ['amount' => 0.0, 'cost_center_id' => $l['cost_center_id'], 'mixed_cc' => false];
        }
        $byAccount[$k]['amount'] += $l['amount'];
        if ($byAccount[$k]['cost_center_id'] !== $l['cost_center_id']) $byAccount[$k]['mixed_cc'] = true;
    }

    $entryLines = [];
    foreach ($byAccount as $accountId => $agg) {
        $amount = esc_num($agg['amount']);
        if ($amount == 0.0) continue;
        $entryLines[] = [
            'account_id'       => $accountId,
            'debit'            => $direction === 'a_recevoir' ? 0.0 : $amount,
            'credit'           => $direction === 'a_recevoir' ? $amount : 0.0,
            'label'            => $label,
            'tiers_contact_id' => $tiers,
            'cost_center_id'   => $agg['mixed_cc'] ? null : $agg['cost_center_id'],
        ];
    }
    $entryLines[] = [
        'account_id'       => $collective,
        'debit'            => $direction === 'a_recevoir' ? $total : 0.0,
        'credit'           => $direction === 'a_recevoir' ? 0.0 : $total,
        'label'            => (string) ($d['tiers_label'] ?? '') ?: $label,
        'tiers_contact_id' => $tiers,
    ];

    $entryId = compta_post_entry($pdo, [
        'entry_date'    => $date,
        'label'         => $label,
        'piece_kind'    => $direction === 'a_recevoir' ? 'VTE' : 'ACH',
        'journal_id'    => !empty($d['journal_id']) ? (int) $d['journal_id'] : null,
        'source_module' => (string) ($d['source_module'] ?? 'manuel'),
        'created_by'    => (string) ($d['created_by'] ?? ''),
        'lines'         => $entryLines,
    ]);

    $pieceSt = $pdo->prepare('SELECT piece_ref FROM journal_entries WHERE id = ?');
    $pieceSt->execute([$entryId]);
    $pieceRef = (string) $pieceSt->fetchColumn();

    /* L'en-tête conserve le compte et la TVA de la PREMIÈRE ligne : ils servent
       de repli aux écrans qui ne lisent pas encore les lignes, jamais de source
       de vérité comptable — celle-ci est l'écriture. */
    $first = $lines[0];
    $pdo->prepare(
        'INSERT INTO invoices
            (direction, piece_ref, invoice_number, entry_date, due_date, label,
             tiers_contact_id, tiers_label, source_module, account_id, cost_center_id,
             vat_rate_id, amount_vat, amount_total, status, journal_entry_id, notes,
             payment_term_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $direction, $pieceRef, (string) ($d['invoice_number'] ?? ''), $date,
        $dueDate, $label, $tiers, (string) ($d['tiers_label'] ?? ''),
        (string) ($d['source_module'] ?? 'manuel'), $first['account_id'], $first['cost_center_id'],
        $first['vat_rate_id'], $vatTotal, $total,
        'ouverte', $entryId, (string) ($d['notes'] ?? ''), $termId, (string) ($d['created_by'] ?? ''),
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    $insLine = $pdo->prepare(
        'INSERT INTO invoice_lines (invoice_id, position, product_id, label, quantity, unit_price, amount, account_id, vat_rate_id, cost_center_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($lines as $pos => $l) {
        $insLine->execute([
            $invoiceId, $pos, $l['product_id'], $l['label'], $l['quantity'],
            $l['unit_price'], $l['amount'], $l['account_id'], $l['vat_rate_id'], $l['cost_center_id'],
        ]);
    }

    // Chemin unique de création de facture (saisie manuelle, génération depuis
    // un module métier) : un seul point d'audit couvre tout.
    compta_audit_log($pdo, 'invoice', $invoiceId, 'create', "Facture $pieceRef — $label", (string) ($d['created_by'] ?? ''));

    return $invoiceId;
}

/**
 * Nettoie et complète les lignes reçues : montant = quantité × prix, compte et
 * TVA repris du produit quand la ligne ne les impose pas.
 *
 * Le compte est résolu ICI, une fois pour toutes, et recopié sur la ligne. Le
 * produit n'est plus qu'un modèle de saisie une fois la facture enregistrée.
 */
function invoice_normalize_lines(PDO $pdo, array $raw, int $collective): array {
    $prodSt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $out = [];

    foreach ($raw as $l) {
        $productId = !empty($l['product_id']) ? (int) $l['product_id'] : null;
        $product   = null;
        if ($productId) {
            $prodSt->execute([$productId]);
            $product = $prodSt->fetch() ?: null;
            if (!$product) throw new ComptaException("Produit #$productId introuvable");
        }

        $label = trim((string) ($l['label'] ?? '')) ?: (string) ($product['label'] ?? '');
        if ($label === '') throw new ComptaException('Chaque ligne doit porter une description');

        $qty   = isset($l['quantity']) && $l['quantity'] !== '' ? (float) $l['quantity'] : 1.0;
        $price = isset($l['unit_price']) && $l['unit_price'] !== '' ? (float) $l['unit_price'] : (float) ($product['unit_price'] ?? 0);
        /* Un montant fourni directement l'emporte : c'est le cas des lignes
           générées depuis un module, où le montant vient de la pièce source. */
        $amount = isset($l['amount']) && $l['amount'] !== '' ? esc_num($l['amount']) : esc_num($qty * $price);
        if ($amount == 0.0) continue;
        if ($amount < 0) throw new ComptaException('Les montants négatifs ne sont pas acceptés sur une facture');

        $accountId = !empty($l['account_id']) ? (int) $l['account_id'] : (int) ($product['account_id'] ?? 0);
        if (!$accountId) {
            throw new ComptaException("La ligne « $label » n'a pas de compte de contrepartie. Choisissez-en un, ou renseignez-le sur le produit.");
        }
        if ($accountId === $collective) {
            throw new ComptaException("La ligne « $label » ne peut pas viser le compte collectif lui-même");
        }

        /* Cascade des défauts de TVA : ce qui est saisi sur la ligne l'emporte,
           sinon le produit, sinon le compte de contrepartie. Rattacher le taux
           au compte évite de le redire sur chaque produit, et rend la TVA juste
           même pour une ligne saisie sans produit. Tout reste modifiable. */
        $vatRateId = null;
        if (!empty($l['vat_rate_id']))            $vatRateId = (int) $l['vat_rate_id'];
        elseif (!empty($product['vat_rate_id']))  $vatRateId = (int) $product['vat_rate_id'];
        else {
            $as = $pdo->prepare('SELECT vat_rate_id FROM accounts WHERE id = ?');
            $as->execute([$accountId]);
            $accVat = $as->fetchColumn();
            if ($accVat) $vatRateId = (int) $accVat;
        }
        $vatAmount = 0.0;
        if ($vatRateId) {
            $rs = $pdo->prepare('SELECT rate_percent FROM vat_rates WHERE id = ?');
            $rs->execute([$vatRateId]);
            $rate = (float) $rs->fetchColumn();
            /* TVA incluse dans le TTC : sous le régime de la dette fiscale nette
               elle n'est qu'une information, elle ne quitte jamais la ligne. */
            $vatAmount = $rate > 0 ? esc_num($amount - $amount / (1 + $rate / 100)) : 0.0;
        }

        $out[] = [
            'product_id'     => $productId,
            'label'          => $label,
            'quantity'       => $qty,
            'unit_price'     => esc_num($price),
            'amount'         => $amount,
            'account_id'     => $accountId,
            'vat_rate_id'    => $vatRateId,
            'vat_amount'     => $vatAmount,
            'cost_center_id' => !empty($l['cost_center_id']) ? (int) $l['cost_center_id'] : null,
        ];
    }
    return $out;
}

/**
 * Journal de banque à utiliser pour un compte bancaire donné : celui qui lui
 * est rattaché, sinon le journal de banque par défaut. Avec deux comptes
 * bancaires, chacun tient ainsi son propre journal sans rien avoir à choisir.
 */
function journal_for_bank(PDO $pdo, ?int $bankAccountId): ?int {
    if ($bankAccountId) {
        $st = $pdo->prepare("SELECT id FROM journals WHERE kind = 'banque' AND bank_account_id = ? AND active = 1 LIMIT 1");
        $st->execute([$bankAccountId]);
        if ($id = $st->fetchColumn()) return (int) $id;
    }
    $id = $pdo->query("SELECT id FROM journals WHERE kind = 'banque' AND active = 1 ORDER BY is_default DESC, position LIMIT 1")->fetchColumn();
    return $id ? (int) $id : null;
}

/** Factures portant un taux de TVA, par leur en-tête ou par l'une de leurs lignes. */
function vat_rate_invoice_ids(PDO $pdo, int $vatRateId): array {
    $st = $pdo->prepare('SELECT id FROM invoices WHERE vat_rate_id = :id
                          UNION
                         SELECT invoice_id FROM invoice_lines WHERE vat_rate_id = :id');
    $st->execute([':id' => $vatRateId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Recalcule la TVA d'une facture à partir de ses lignes et des taux en vigueur.
 * Seul `amount_vat` bouge : le total TTC est le montant réellement facturé, il
 * ne se recalcule jamais, et l'écriture comptable n'est pas touchée.
 */
function invoice_recompute_vat(PDO $pdo, int $invoiceId): float {
    $st = $pdo->prepare('SELECT l.amount, COALESCE(v.rate_percent, 0) AS rate
                           FROM invoice_lines l
                           LEFT JOIN vat_rates v ON v.id = l.vat_rate_id
                          WHERE l.invoice_id = ?');
    $st->execute([$invoiceId]);
    $rows = $st->fetchAll();

    $vat = 0.0;
    foreach ($rows as $r) {
        $rate = (float) $r['rate'];
        if ($rate > 0) $vat += (float) $r['amount'] - (float) $r['amount'] / (1 + $rate / 100);
    }
    $vat = esc_num($vat);
    $pdo->prepare('UPDATE invoices SET amount_vat = ? WHERE id = ?')->execute([$vat, $invoiceId]);
    return $vat;
}

/**
 * Recalcule le statut d'une facture d'après ce qui a été rapproché, et
 * répercute « payé » dans le module d'origine dès qu'elle est intégralement
 * réglée. Le statut vit dans le module propriétaire, jamais en double ici.
 */
function invoice_refresh_status(PDO $pdo, int $invoiceId, string $paidAt): array {
    $st = $pdo->prepare('SELECT i.*, (SELECT COALESCE(SUM(r.amount_applied),0) FROM reconciliations r WHERE r.invoice_id = i.id) AS settled
                           FROM invoices i WHERE i.id = ?');
    $st->execute([$invoiceId]);
    $inv = $st->fetch();
    if (!$inv) return ['status' => null, 'marked' => 0, 'failed' => []];

    $total   = (float) $inv['amount_total'];
    $settled = (float) $inv['settled'];
    $status  = $inv['status'];
    if ($status !== 'annulee') {
        if ($settled >= $total - 0.005)   $status = 'reglee';
        elseif ($settled > 0.005)         $status = 'reglee_partielle';
        else                              $status = 'ouverte';
        $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$status, $invoiceId]);
    }

    $marked = 0; $failed = [];
    if ($status === 'reglee') {
        $si = $pdo->prepare('SELECT * FROM invoice_source_items WHERE invoice_id = ? AND marked_paid = 0');
        $si->execute([$invoiceId]);
        $upd = $pdo->prepare('UPDATE invoice_source_items SET marked_paid = 1 WHERE id = ?');
        foreach ($si->fetchAll() as $item) {
            if (compta_mark_paid((string) $item['source_module'], (string) $item['source_ref_id'], $paidAt)) {
                $upd->execute([(int) $item['id']]);
                $marked++;
            } else {
                /* Un module momentanément indisponible ne doit pas faire échouer
                   le règlement comptable : on signale, la facture reste réglée
                   et l'élément non marqué sera repris au prochain passage. */
                $failed[] = $item['source_module'] . '#' . $item['source_ref_id'];
            }
        }
    }
    return ['status' => $status, 'marked' => $marked, 'failed' => $failed];
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
 *
 * Fonction et non constante, pour la même raison que invoice_select() : le
 * semis tourne depuis db(), au milieu du routeur, donc bien avant que
 * l'exécution n'atteigne le bas de ce fichier. En constante, la toute première
 * requête sur une base neuve échouait en « Undefined constant ».
 */
function compta_accounts_seed(): array {
    return [
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
}
