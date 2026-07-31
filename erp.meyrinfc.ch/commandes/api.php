<?php
/**
 * CRM Commande — API backend
 * PHP 8.x + SQLite (PDO). Aucune dépendance externe.
 * Compatible hébergement mutualisé Infomaniak.
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

/* Plus de session PHP propre a l'application : l'identite vient de la session
   unique de l'ERP (cookie JWT). Cette app conserve trois regimes d'acces
   distincts, voir la table CMD_PERMS plus bas :
     - la boutique publique, sans aucune authentification ;
     - deux points d'entree machine a machine appeles par la Caisse, acceptant
       soit une session ERP avec acces a la Caisse, soit une cle API ;
     - tout le reste, sous session ERP et permission. */
require_once __DIR__ . '/mfc_boot.php';

/* Ces deux listes sont declarees ici, et non plus bas avec CMD_PERMS, parce que
   la politique CORS ci-dessous en depend et s'applique avant tout traitement. */
const CMD_PUBLIC  = ['shop_catalog', 'shop_validate_promo', 'shop_request', 'shop_cancel'];
const CMD_MACHINE = ['vendable_articles', 'decrement_stock'];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* CORS reserve a la boutique publique, desormais seul appelant d'une autre
   origine : elle est servie sur boutique.meyrinfc.ch alors que cette API vit
   sous erp.meyrinfc.ch. La Caisse, elle, a rejoint ce meme domaine et
   s'authentifie par la session ERP ; elle n'a donc plus besoin ni de CORS ni
   de cle, ce qui evite d'exposer un secret dans son JavaScript.
   Aucun Allow-Credentials : la boutique est anonyme, aucun cookie ne doit
   accompagner ces requetes. */
const CMD_SHOP_ORIGIN = 'https://boutique.meyrinfc.ch';
if (in_array($_GET['action'] ?? '', CMD_PUBLIC, true)
    && ($_SERVER['HTTP_ORIGIN'] ?? '') === CMD_SHOP_ORIGIN) {
  header('Access-Control-Allow-Origin: ' . CMD_SHOP_ORIGIN);
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Max-Age: 600');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ---------------------------------------------------------------- DB */

const DB_DIR = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/commandes.sqlite';

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
  // DELETE plutôt que WAL : le mode WAL s'appuie sur de la mémoire partagée (mmap)
  // qui n'est pas fiable sur certains hébergements mutualisés (stockage réseau),
  // ce qui peut faire "perdre" les écritures d'une requête à l'autre.
  $pdo->exec('PRAGMA journal_mode = DELETE');
  $pdo->exec('PRAGMA foreign_keys = ON');
  init_schema($pdo);
  return $pdo;
}

/** Ajoute une colonne à une table existante si elle n'existe pas déjà (migration additive). */
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
  $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array($column, $cols, true)) {
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
  }
}

/** stock_levels avait UNIQUE(variant_id, location_id) : impossible d'ajouter usage sans reconstruire la table. */
function migrate_stock_levels_usage(PDO $pdo): void {
  $cols = $pdo->query("PRAGMA table_info(stock_levels)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if (in_array('usage', $cols, true)) return;
  $pdo->exec("ALTER TABLE stock_levels RENAME TO stock_levels_old");
  $pdo->exec("
    CREATE TABLE stock_levels (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
      location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
      usage TEXT NOT NULL DEFAULT 'non_affecte',
      quantity REAL NOT NULL DEFAULT 0,
      UNIQUE(variant_id, location_id, usage)
    )
  ");
  // Stock déjà en place : considéré affecté à la boutique par défaut (comportement antérieur, nature='vendable').
  $pdo->exec("INSERT INTO stock_levels (id, variant_id, location_id, usage, quantity)
              SELECT id, variant_id, location_id, 'boutique', quantity FROM stock_levels_old");
  $pdo->exec("DROP TABLE stock_levels_old");
}

/** budgets pointait sur categories(category_id) : migré vers departments(department_id), une seule fois. */
function migrate_budgets_department(PDO $pdo): void {
  $cols = $pdo->query("PRAGMA table_info(budgets)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if (in_array('department_id', $cols, true)) return;
  $pdo->exec("INSERT INTO departments (name)
              SELECT DISTINCT c.name FROM budgets b JOIN categories c ON c.id = b.category_id
              WHERE c.name NOT IN (SELECT name FROM departments)");
  $pdo->exec("ALTER TABLE budgets RENAME TO budgets_old");
  $pdo->exec("
    CREATE TABLE budgets (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
      fiscal_year TEXT NOT NULL,
      amount_allocated REAL NOT NULL DEFAULT 0,
      UNIQUE(department_id, fiscal_year)
    )
  ");
  $pdo->exec("
    INSERT INTO budgets (id, department_id, fiscal_year, amount_allocated)
    SELECT bo.id, d.id, bo.fiscal_year, bo.amount_allocated
    FROM budgets_old bo
    JOIN categories c ON c.id = bo.category_id
    JOIN departments d ON d.name = c.name
  ");
  $pdo->exec("DROP TABLE budgets_old");
}

/** Migre une seule fois les anciens champs contact_name/email/phone/address de suppliers vers
 *  supplier_contacts/supplier_addresses (nouvelles listes). Idempotent via un flag en settings :
 *  on ne se base pas sur le COUNT de lignes existantes pour ne pas ré-insérer après une suppression manuelle. */
function migrate_supplier_contacts_addresses(PDO $pdo): void {
  $done = $pdo->query("SELECT value FROM settings WHERE key = 'supplier_contacts_migrated'")->fetchColumn();
  if ($done === '1') return;
  $suppliers = $pdo->query('SELECT id, contact_name, email, phone, address FROM suppliers')->fetchAll();
  $insAddr = $pdo->prepare("INSERT INTO supplier_addresses (supplier_id, label, address) VALUES (?, 'Général', ?)");
  $insContact = $pdo->prepare('INSERT INTO supplier_contacts (supplier_id, name, email, phone) VALUES (?,?,?,?)');
  foreach ($suppliers as $sp) {
    if (trim((string)$sp['address']) !== '') $insAddr->execute([$sp['id'], $sp['address']]);
    if (trim((string)$sp['contact_name']) !== '') $insContact->execute([$sp['id'], $sp['contact_name'], $sp['email'], $sp['phone']]);
  }
  $pdo->prepare("INSERT INTO settings (key, value) VALUES ('supplier_contacts_migrated', '1')
                 ON CONFLICT(key) DO UPDATE SET value = '1'")->execute();
}

/** Migre une seule fois les commandes existantes (créées avant l'introduction de la répartition budgétaire
 *  manuelle) vers purchase_order_budget_allocations, en se basant sur le département alors renseigné sur
 *  chaque article commandé (au mieux : les lignes dont l'article n'a aucun département restent non réparties,
 *  à corriger manuellement si besoin). Les commandes créées après cette migration passent obligatoirement
 *  par la répartition manuelle (voir case 'orders' POST/PUT), donc jamais concernées par ce backfill. */
function migrate_order_budget_allocations(PDO $pdo): void {
  $done = $pdo->query("SELECT value FROM settings WHERE key = 'order_budget_allocations_migrated'")->fetchColumn();
  if ($done === '1') return;
  $pdo->exec("
    INSERT OR IGNORE INTO purchase_order_budget_allocations (order_id, department_id, amount)
    SELECT pol.order_id, a.department_id, SUM(pol.quantity_ordered * pol.unit_price)
    FROM purchase_order_lines pol
    JOIN article_variants v ON v.id = pol.variant_id
    JOIN articles a ON a.id = v.article_id
    WHERE a.department_id IS NOT NULL
    GROUP BY pol.order_id, a.department_id
  ");
  $pdo->prepare("INSERT INTO settings (key, value) VALUES ('order_budget_allocations_migrated', '1')
                 ON CONFLICT(key) DO UPDATE SET value = '1'")->execute();
}

/** Migre une seule fois les commandes existantes (créées avant l'introduction de la saison/numérotation)
 *  vers season/order_seq, déduits de leur date de commande (ou création) via fiscal_year(), numérotées dans
 *  l'ordre chronologique au sein de chaque saison. Nécessite fiscal_year(), définie plus bas dans ce fichier
 *  mais déjà chargée à l'exécution de cette fonction (appelée après la définition complète des fonctions). */
function migrate_order_seasons(PDO $pdo): void {
  $done = $pdo->query("SELECT value FROM settings WHERE key = 'order_seasons_migrated'")->fetchColumn();
  if ($done === '1') return;
  $orders = $pdo->query("SELECT id, order_date, created_at FROM purchase_orders WHERE season IS NULL ORDER BY created_at ASC")->fetchAll();
  $seqBySeason = [];
  $upd = $pdo->prepare('UPDATE purchase_orders SET season = ?, order_seq = ? WHERE id = ?');
  foreach ($orders as $o) {
    $season = fiscal_year($o['order_date'] ?: $o['created_at']);
    $seqBySeason[$season] = ($seqBySeason[$season] ?? 0) + 1;
    $upd->execute([$season, $seqBySeason[$season], $o['id']]);
  }
  $pdo->prepare("INSERT INTO settings (key, value) VALUES ('order_seasons_migrated', '1')
                 ON CONFLICT(key) DO UPDATE SET value = '1'")->execute();
}

function init_schema(PDO $pdo): void {
  $pdo->exec("
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'demandeur', -- admin | responsable | demandeur
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
  );
  CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    parent_id INTEGER REFERENCES categories(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Département : axe budgétaire (où investir dans le club), indépendant de la catégorie d'article.
  CREATE TABLE IF NOT EXISTS departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Forme : liste à choix fixe complémentaire de la catégorie (ex. Coupe droite, Coupe ajustée).
  CREATE TABLE IF NOT EXISTS formes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    contact_name TEXT DEFAULT '', -- déprécié : remplacé par supplier_contacts (conservé en base, plus affiché)
    email TEXT DEFAULT '', -- déprécié
    phone TEXT DEFAULT '', -- déprécié
    address TEXT DEFAULT '', -- déprécié : remplacé par supplier_addresses (conservé en base, plus affiché)
    notes TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Adresses multiples avec intitulé (ex. Facturation, Entrepôt) pour un fournisseur.
  CREATE TABLE IF NOT EXISTS supplier_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    label TEXT NOT NULL DEFAULT '',
    address TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Contacts multiples (rôle/notes en texte libre) pour un fournisseur.
  CREATE TABLE IF NOT EXISTS supplier_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    name TEXT NOT NULL DEFAULT '',
    role TEXT DEFAULT '',
    email TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    article_number TEXT DEFAULT '',
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    forme_id INTEGER REFERENCES formes(id) ON DELETE SET NULL,
    nature TEXT NOT NULL DEFAULT 'interne', -- déprécié, conservé pour compat : remplacé par stock_levels.usage
    unit TEXT DEFAULT 'pièce',
    photo TEXT DEFAULT '',
    supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL,
    catalog_price_ht REAL NOT NULL DEFAULT 0,
    discount_percent REAL NOT NULL DEFAULT 0,
    purchase_price REAL NOT NULL DEFAULT 0,
    sale_price_ttc REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Photo dédiée par couleur (valeur de l'attribut principal), en plus de la photo générale de articles.photo.
  CREATE TABLE IF NOT EXISTS article_color_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    color_value TEXT NOT NULL,
    photo TEXT NOT NULL,
    UNIQUE(article_id, color_value)
  );
  CREATE TABLE IF NOT EXISTS attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS attribute_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attribute_id INTEGER NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
    value TEXT NOT NULL,
    UNIQUE(attribute_id, value)
  );
  -- Une variante = l'unité réellement stockée/vendue/commandée d'un article (ex. T-shirt / Taille M).
  -- Un article sans déclinaison (matériel bureau, etc.) a simplement une seule variante par défaut.
  -- Le prix d'achat et le prix de vente TTC sont communs à tout le produit (table articles) ;
  -- seuls le code-barres, le seuil d'alerte et le stock sont propres à chaque variante.
  CREATE TABLE IF NOT EXISTS article_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    label TEXT DEFAULT '',
    barcode TEXT DEFAULT '',
    alert_threshold INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE UNIQUE INDEX IF NOT EXISTS idx_variants_barcode ON article_variants(barcode) WHERE barcode != '';
  -- is_primary distingue l'attribut principal (ex. Couleur) de l'attribut secondaire (ex. Taille)
  -- quand une variante combine les deux (ex. Rouge / M).
  CREATE TABLE IF NOT EXISTS variant_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    attribute_id INTEGER NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
    value TEXT DEFAULT '',
    is_primary INTEGER NOT NULL DEFAULT 1,
    UNIQUE(variant_id, attribute_id)
  );
  -- usage : non_affecte (reçu mais pas encore dispatché) | boutique | equipement
  CREATE TABLE IF NOT EXISTS stock_levels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    usage TEXT NOT NULL DEFAULT 'non_affecte',
    quantity REAL NOT NULL DEFAULT 0,
    UNIQUE(variant_id, location_id, usage)
  );
  CREATE TABLE IF NOT EXISTS stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    usage TEXT NOT NULL DEFAULT 'boutique', -- bucket affecté par le mouvement
    type TEXT NOT NULL, -- entry | exit | adjustment | transfer | dispatch
    quantity REAL NOT NULL,
    reason TEXT DEFAULT '',
    ref_type TEXT DEFAULT 'manuel', -- reception | demande | vente | manuel | transfert | dispatch | pret | don
    ref_id INTEGER DEFAULT NULL,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Équipement prêté à un joueur : reste propriété du club (compté dans l'inventaire) mais indisponible.
  -- Un don (transfert définitif) se traduit par un stock_movements de type exit, ref_type='don' : il sort
  -- réellement du stock équipement et donc de la valorisation d'inventaire.
  CREATE TABLE IF NOT EXISTS equipment_loans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    player_name TEXT NOT NULL,
    quantity REAL NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'pret', -- pret | rendu
    loan_date TEXT NOT NULL DEFAULT (datetime('now')),
    return_date TEXT DEFAULT NULL,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    quantity REAL NOT NULL DEFAULT 1,
    requester_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'pending', -- pending | approved | rejected | ordered | received
    motive TEXT DEFAULT '',
    reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TEXT DEFAULT NULL,
    reject_reason TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Panier de demandes : regroupe plusieurs lignes 'requests' soumises en une fois par un demandeur.
  -- Nullable côté requests.cart_id : les demandes créées avant cette fonctionnalité restent des lignes isolées.
  CREATE TABLE IF NOT EXISTS request_carts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    requester_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    motive TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Colonnes guest_* : identité déclarée par un visiteur non connecté via la boutique publique (/shop). Le panier
  -- reste techniquement rattaché au compte système réservé 'guest_user_id()' (requester_id), l'identité réelle
  -- (nom/prénom/e-mail/téléphone, obligatoires côté /shop) est stockée ici pour l'affichage staff et le contact.
  -- Codes promo : utilisables pour l'instant uniquement depuis la boutique publique (/shop). discount_percent
  -- s'applique au total du panier (calculé côté client, jamais figé en base — voir commentaire sur requests).
  CREATE TABLE IF NOT EXISTS promo_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    discount_percent REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT DEFAULT NULL,
    max_uses INTEGER DEFAULT NULL,
    used_count INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS purchase_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'draft', -- draft | sent | confirmed | received_partial | received_total
    order_date TEXT DEFAULT NULL, -- date de commande (distincte de created_at, modifiable tant que la commande est en brouillon)
    season TEXT DEFAULT NULL, -- exercice club (format 2026-2027) auquel la commande est affiliée, modifiable en tout temps
    order_seq INTEGER DEFAULT NULL, -- numéro de séquence au sein de la saison (voir assign_order_season())
    expected_date TEXT DEFAULT NULL,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    sent_at TEXT DEFAULT NULL,
    notes TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS purchase_order_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    quantity_ordered REAL NOT NULL DEFAULT 1,
    quantity_received REAL NOT NULL DEFAULT 0,
    unit_price REAL NOT NULL DEFAULT 0
  );
  -- Répartition budgétaire d'une commande entre un ou plusieurs départements, saisie manuellement sur la
  -- commande (remplace le rattachement automatique via articles.department_id, trop rigide : un même article
  -- peut être commandé une fois pour un département puis une autre fois pour un autre). La somme des montants
  -- doit correspondre au total HT de la commande (contrôle applicatif, pas de contrainte SQL).
  CREATE TABLE IF NOT EXISTS purchase_order_budget_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
    amount REAL NOT NULL DEFAULT 0,
    UNIQUE(order_id, department_id)
  );
  CREATE TABLE IF NOT EXISTS purchase_order_requests (
    order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    PRIMARY KEY (order_id, request_id)
  );
  CREATE TABLE IF NOT EXISTS budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
    fiscal_year TEXT NOT NULL,
    amount_allocated REAL NOT NULL DEFAULT 0,
    UNIQUE(department_id, fiscal_year)
  );
  CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    invoice_date TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'a_controler', -- a_controler | approuvee | en_attente_paiement | payee
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  -- Ligne facturée par le fournisseur, comparée à purchase_order_lines.quantity_ordered pour détecter les écarts.
  CREATE TABLE IF NOT EXISTS invoice_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    quantity_invoiced REAL NOT NULL DEFAULT 0,
    unit_price_invoiced REAL NOT NULL DEFAULT 0
  );
  CREATE TABLE IF NOT EXISTS invoice_anomalies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    variant_id INTEGER REFERENCES article_variants(id) ON DELETE SET NULL,
    type TEXT NOT NULL, -- quantity_mismatch | price_mismatch | unknown_article
    expected_value TEXT DEFAULT '',
    actual_value TEXT DEFAULT '',
    resolved INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    detail TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  ");

  // Migrations additives (colonnes ajoutées après le déploiement initial)
  ensure_column($pdo, 'articles', 'purchase_price', 'REAL NOT NULL DEFAULT 0');
  ensure_column($pdo, 'articles', 'sale_price_ttc', 'REAL NOT NULL DEFAULT 0');
  // Prix catalogue HT (fournisseur) + rabais MFC en % : le prix d'achat MFC HT (purchase_price) est
  // recalculé automatiquement à partir de ces deux valeurs à chaque création/modification d'article.
  ensure_column($pdo, 'articles', 'catalog_price_ht', 'REAL NOT NULL DEFAULT 0');
  ensure_column($pdo, 'articles', 'discount_percent', 'REAL NOT NULL DEFAULT 0');
  // Boutique publique (/shop, sans compte) : identité du visiteur + jeton d'annulation self-service.
  ensure_column($pdo, 'request_carts', 'guest_first_name', "TEXT DEFAULT ''");
  ensure_column($pdo, 'request_carts', 'guest_last_name', "TEXT DEFAULT ''");
  ensure_column($pdo, 'request_carts', 'guest_email', "TEXT DEFAULT ''");
  ensure_column($pdo, 'request_carts', 'guest_phone', "TEXT DEFAULT ''");
  ensure_column($pdo, 'request_carts', 'cancel_token', "TEXT DEFAULT ''");
  ensure_column($pdo, 'request_carts', 'source', "TEXT NOT NULL DEFAULT 'app'");
  ensure_column($pdo, 'request_carts', 'promo_code', "TEXT DEFAULT ''");
  ensure_column($pdo, 'request_carts', 'discount_percent', 'REAL NOT NULL DEFAULT 0');
  ensure_column($pdo, 'purchase_orders', 'order_date', 'TEXT DEFAULT NULL');
  ensure_column($pdo, 'purchase_orders', 'season', 'TEXT DEFAULT NULL');
  ensure_column($pdo, 'purchase_orders', 'order_seq', 'INTEGER DEFAULT NULL');
  // Backfill : commandes existantes sans date de commande explicite -> date de leur création.
  $pdo->exec("UPDATE purchase_orders SET order_date = date(created_at) WHERE order_date IS NULL");
  ensure_column($pdo, 'variant_attributes', 'is_primary', 'INTEGER NOT NULL DEFAULT 1');
  ensure_column($pdo, 'categories', 'parent_id', 'INTEGER REFERENCES categories(id) ON DELETE CASCADE');
  ensure_column($pdo, 'articles', 'article_number', "TEXT DEFAULT ''");
  ensure_column($pdo, 'articles', 'forme_id', 'INTEGER REFERENCES formes(id) ON DELETE SET NULL');
  // Département : axe budgétaire de l'article (indépendant de category_id, utilisé pour le suivi des budgets).
  ensure_column($pdo, 'articles', 'department_id', 'INTEGER REFERENCES departments(id) ON DELETE SET NULL');
  ensure_column($pdo, 'stock_movements', 'usage', "TEXT NOT NULL DEFAULT 'boutique'");
  ensure_column($pdo, 'invoices', 'status', "TEXT NOT NULL DEFAULT 'a_controler'");
  ensure_column($pdo, 'attribute_values', 'color_code', "TEXT DEFAULT ''");
  // Ordre d'affichage personnalisé des valeurs (ex. S, M, L, XL plutôt que l'ordre alphabétique), réglable
  // dans Paramètres. Les valeurs sans ordre explicite (0, celles créées avant cette fonctionnalité ou à la
  // volée par un import) restent triées alphabétiquement entre elles, à la suite des valeurs déjà ordonnées.
  ensure_column($pdo, 'attribute_values', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
  // Rattachement d'une demande à un panier (regroupement de plusieurs lignes soumises ensemble). Nullable : compat anciennes demandes.
  ensure_column($pdo, 'requests', 'cart_id', 'INTEGER REFERENCES request_carts(id) ON DELETE CASCADE');
  // Visibilité sur la boutique publique (/shop) : par variante (pas par article entier), pour pouvoir réserver
  // certaines couleurs/déclinaisons à une catégorie de personnes tout en gardant les mêmes tailles ouvertes sur
  // d'autres couleurs. Défaut 0 (masqué) : chaque variante existante ou nouvelle doit être ouverte explicitement,
  // rien n'apparaît sur /shop tant que le staff ne l'a pas décidé.
  ensure_column($pdo, 'article_variants', 'shop_visible', 'INTEGER NOT NULL DEFAULT 0');

  // Reconstructions de tables (contraintes UNIQUE/FK non modifiables par simple ALTER en SQLite)
  migrate_stock_levels_usage($pdo);
  migrate_budgets_department($pdo);
  migrate_supplier_contacts_addresses($pdo);
  migrate_order_budget_allocations($pdo);
  migrate_order_seasons($pdo);

  // Numéro d'article rétroactif pour les articles créés avant l'introduction du champ
  $pdo->exec("UPDATE articles SET article_number = 'ART-' || substr('0000' || id, -4, 4) WHERE article_number = '' OR article_number IS NULL");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_articles_number ON articles(article_number) WHERE article_number != ''");

  // Réglages par défaut
  $defaults = [
    'fiscal_year_start_month' => '7',
    'request_stale_days'      => '5',
    'order_forgotten_days'    => '7',
    'caisse_api_key'          => bin2hex(random_bytes(16)),
    'label_format'            => '62x29',
    'vat_rate'                => '8.1',
  ];
  $st = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?,?)');
  foreach ($defaults as $k => $v) $st->execute([$k, $v]);

  // Emplacement par défaut (utilisé par decrement_stock si aucun n'est précisé)
  $count = (int) $pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn();
  if ($count === 0) {
    $pdo->exec("INSERT INTO locations (name) VALUES ('Local principal')");
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

/**
 * Ligne `users` locale de la personne connectee.
 *
 * La table n'authentifie plus rien mais reste indispensable : created_by des
 * commandes fournisseurs et des prets, requester_id des demandes (contrainte
 * NOT NULL) et user_id des mouvements de stock pointent dessus.
 * mfc_local_user() rattache le compte ERP sans toucher aux lignes existantes.
 */
function current_user(): ?array {
  static $u = null;
  if ($u !== null) return $u;
  $s = mfc_session();
  if (!$s) return null;
  $u = mfc_local_user(db(), $s);
  return $u;
}

function require_auth(): array {
  $u = current_user();
  if (!$u) fail('Non authentifié', 401);
  return $u;
}

/* --------------------------------------------------- Référentiel contacts (ERP)
 *
 * Deux populations à faire suivre : les fournisseurs (organisations) et leurs
 * interlocuteurs (personnes). Chaque création/modification alimente le
 * référentiel partagé en continu. Jamais bloquant pour Commandes lui-même.
 */
function sync_supplier_org_to_contacts(int $supplierId, string $name): void {
  try {
    mfc_contacts_ingest(mfc_contacts_db(), [
      'type' => 'organisation', 'last_name' => $name, 'qualities' => ['fournisseur'],
    ], 'commandes', 'supplier:' . $supplierId);
  } catch (Throwable $e) { /* l'annuaire n'est pas critique pour Commandes lui-même */ }
}
function sync_supplier_contact_to_contacts(int $contactId, int $supplierId, string $name, string $email, string $phone): void {
  try {
    $cdb = mfc_contacts_db();
    $org = mfc_contacts_by_source($cdb, 'commandes', 'supplier:' . $supplierId);
    [$first, $last] = mfc_contacts_split_name($name);
    mfc_contacts_ingest($cdb, [
      'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
      'org_ref_id' => $org['ref_id'] ?? '', 'qualities' => ['fournisseur'],
    ], 'commandes', 'contact:' . $contactId);
  } catch (Throwable $e) { /* idem */ }
}

/** Enrichit les interlocuteurs fournisseur avec ce que l'annuaire sait en plus (mobile, adresse,
 * autres qualités de la personne dans le club). */
function attach_contacts_directory(array &$contacts): void {
  if (!$contacts) return;
  try {
    $cdb = mfc_contacts_db();
    $localIds = array_map(fn($c) => 'contact:' . $c['id'], $contacts);
    $ph = implode(',', array_fill(0, count($localIds), '?'));
    $st = $cdb->prepare("SELECT l.local_id, c.id AS contact_id, c.ref_id, c.mobile, c.street, c.zip, c.city
      FROM contact_links l JOIN contacts c ON c.id = l.contact_id
      WHERE l.module = 'commandes' AND l.local_id IN ($ph)");
    $st->execute($localIds);
    $byLocal = [];
    foreach ($st->fetchAll() as $r) $byLocal[$r['local_id']] = $r;
    if (!$byLocal) return;

    $cids = array_values(array_unique(array_column($byLocal, 'contact_id')));
    $ph2 = implode(',', array_fill(0, count($cids), '?'));

    $qualities = [];
    $stq = $cdb->prepare("SELECT contact_id, quality FROM contact_qualities WHERE contact_id IN ($ph2) AND quality != 'fournisseur'");
    $stq->execute($cids);
    foreach ($stq->fetchAll() as $r) $qualities[(int)$r['contact_id']][] = $r['quality'];

    $stp = $cdb->prepare("SELECT contact_id FROM contact_duplicates WHERE status='pending' AND (contact_id IN ($ph2) OR other_id IN ($ph2))");
    $stp->execute([...$cids, ...$cids]);
    $pending = array_flip($stp->fetchAll(PDO::FETCH_COLUMN));

    foreach ($contacts as &$c) {
      $ct = $byLocal['contact:' . $c['id']] ?? null;
      if (!$ct) { $c['annuaire'] = null; continue; }
      $cid = (int)$ct['contact_id'];
      $addr = trim($ct['street'] . (($ct['zip'] || $ct['city']) ? ', ' . trim($ct['zip'] . ' ' . $ct['city']) : ''));
      $c['annuaire'] = [
        'ref_id' => $ct['ref_id'], 'mobile' => $ct['mobile'], 'address' => $addr,
        'other_qualities' => $qualities[$cid] ?? [], 'a_verifier' => isset($pending[$cid]),
      ];
    }
    unset($c);
  } catch (Throwable $e) { /* annuaire indisponible : la liste reste utilisable sans lui */ }
}

/* require_role() a disparu : les droits ne dependent plus d'un role local
   (demandeur/responsable/admin) mais des permissions portees par le jeton,
   verifiees en un seul point par la table CMD_PERMS du routeur. */

/** Id du compte système réservé aux demandes de la boutique publique (/shop, sans authentification).
 *  Ce compte est créé au premier besoin, désactivé (active=0, mot de passe aléatoire inutilisable) : il ne sert
 *  qu'à satisfaire la contrainte NOT NULL de requests.requester_id, jamais à une connexion. L'identité réelle du
 *  visiteur est portée par les colonnes guest_* de request_carts. */
function guest_user_id(): int {
  $pdo = db();
  $email = 'boutique-publique@commandes.meyrinfc.ch';
  $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
  $st->execute([$email]);
  $id = $st->fetchColumn();
  if ($id !== false) return (int) $id;
  $pdo->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?,?,?,?,0)')
      ->execute(['Boutique publique', $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'demandeur']);
  return (int) $pdo->lastInsertId();
}

/** Retourne le code promo actif et valide (non expiré, quota non atteint), ou null. Comparaison
 *  insensible à la casse, code normalisé en majuscules à la création (voir case 'promo_codes'). */
function validate_promo_code(string $code): ?array {
  $code = strtoupper(trim($code));
  if ($code === '') return null;
  $st = db()->prepare('SELECT * FROM promo_codes WHERE code = ? AND active = 1');
  $st->execute([$code]);
  $p = $st->fetch();
  if (!$p) return null;
  if ($p['expires_at'] && $p['expires_at'] < date('Y-m-d')) return null;
  if ($p['max_uses'] !== null && (int)$p['used_count'] >= (int)$p['max_uses']) return null;
  return $p;
}

/** Valide la répartition budgétaire d'une commande : au moins un département, montants positifs, somme
 *  égale au total (tolérance de 1 centime pour les arrondis flottants). Termine la requête (fail()) si invalide. */
function validate_allocations(array $allocations, float $total): array {
  $clean = []; $sum = 0.0;
  foreach ($allocations as $a) {
    $depId = (int)($a['department_id'] ?? 0);
    $amt = (float)($a['amount'] ?? 0);
    if (!$depId || $amt <= 0) continue;
    $clean[] = ['department_id' => $depId, 'amount' => $amt];
    $sum += $amt;
  }
  if (!count($clean)) fail('Répartition budgétaire requise (au moins un département)');
  if (abs($sum - $total) > 0.01) {
    fail('La répartition budgétaire (' . number_format($sum, 2, '.', '') . ' CHF) ne correspond pas au total de la commande (' . number_format($total, 2, '.', '') . ' CHF)');
  }
  return $clean;
}

function log_activity(?int $uid, string $action, string $detail = ''): void {
  $st = db()->prepare('INSERT INTO activity (user_id, action, detail) VALUES (?,?,?)');
  $st->execute([$uid, $action, $detail]);
}

function s(array $b, string $k, string $def = ''): string { return trim((string)($b[$k] ?? $def)); }
function n(array $b, string $k, float $def = 0): float { return (float)($b[$k] ?? $def); }
function i(array $b, string $k, int $def = 0): int { return (int)($b[$k] ?? $def); }

function setting(string $key, string $def = ''): string {
  $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
  $st->execute([$key]);
  $v = $st->fetchColumn();
  return $v !== false ? (string)$v : $def;
}
function set_setting(string $key, string $value): void {
  db()->prepare('INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
      ->execute([$key, $value]);
}

/** Exercice du club au format "2026-2027" pour une date donnée (défaut aujourd'hui). */
function fiscal_year(?string $date = null): string {
  $startMonth = (int) setting('fiscal_year_start_month', '7');
  $d = $date ? new DateTime($date) : new DateTime();
  $y = (int) $d->format('Y');
  $m = (int) $d->format('n');
  return $m >= $startMonth ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
}

/** Bornes [début, fin] au format Y-m-d pour un exercice "2026-2027". */
function fiscal_year_range(string $fy): array {
  $startMonth = (int) setting('fiscal_year_start_month', '7');
  [$y1, $y2] = array_map('intval', explode('-', $fy));
  $start = sprintf('%04d-%02d-01', $y1, $startMonth);
  $end = (new DateTime(sprintf('%04d-%02d-01', $y2, $startMonth)))->modify('-1 day')->format('Y-m-d');
  return [$start, $end];
}

/** Forme courte "26-27" d'un exercice "2026-2027", utilisée dans le numéro de commande et l'UI. */
function fiscal_year_short(string $fy): string {
  $parts = explode('-', $fy);
  if (count($parts) !== 2) return $fy;
  return substr($parts[0], -2) . '-' . substr($parts[1], -2);
}

/** Liste de saisons candidates pour les sélecteurs (3 précédentes, la courante, la suivante). */
function seasons_list(): array {
  $current = fiscal_year();
  $y1 = (int) explode('-', $current)[0];
  $out = [];
  for ($i = -3; $i <= 1; $i++) {
    $fy = ($y1 + $i) . '-' . ($y1 + $i + 1);
    $out[] = ['value' => $fy, 'label' => fiscal_year_short($fy), 'is_current' => $fy === $current];
  }
  return $out;
}

/** Affilie une commande à une saison (explicite si fournie et valide "YYYY-YYYY", sinon déduite de $dateForAuto
 *  via fiscal_year()) et lui attribue le prochain numéro de séquence de cette saison (1, 2, 3...). Appelée à la
 *  création d'une commande et à chaque changement manuel de saison (voir case 'orders' POST/PUT) : dans ce
 *  second cas, le numéro de commande affiché change de préfixe de saison et reprend une suite propre à la
 *  saison cible, plutôt que de garder un numéro figé qui ne correspondrait plus à sa saison affichée. */
function assign_order_season(PDO $pdo, int $orderId, string $seasonInput, string $dateForAuto): array {
  $season = (preg_match('/^\d{4}-\d{4}$/', $seasonInput) && (int)substr($seasonInput,5,4) === (int)substr($seasonInput,0,4)+1)
    ? $seasonInput : fiscal_year($dateForAuto);
  $st = $pdo->prepare('SELECT COALESCE(MAX(order_seq),0)+1 FROM purchase_orders WHERE season = ? AND id != ?');
  $st->execute([$season, $orderId]);
  $seq = (int) $st->fetchColumn();
  $pdo->prepare('UPDATE purchase_orders SET season = ?, order_seq = ? WHERE id = ?')->execute([$season, $seq, $orderId]);
  return [$season, $seq];
}

/** Numéro de commande affiché "26-27/001" à partir des colonnes season/order_seq (peuvent être NULL sur une
 *  commande jamais passée par assign_order_season, en théorie impossible après migration). */
function format_order_number(?string $season, ?int $seq): string {
  if (!$season || !$seq) return '—';
  return fiscal_year_short($season) . '/' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
}

/** Delta appliqué à stock_levels.quantity pour un type de mouvement donné (entry/adjustment : +qty, exit : -qty). */
function movement_delta(string $type, float $qty): float {
  return $type === 'exit' ? -$qty : $qty;
}

/** Quantité en stock pour un triplet variante/lieu/usage (0 si aucune ligne). */
function get_stock_qty(int $variantId, int $locationId, string $usage): float {
  $st = db()->prepare('SELECT quantity FROM stock_levels WHERE variant_id = ? AND location_id = ? AND usage = ?');
  $st->execute([$variantId, $locationId, $usage]);
  $v = $st->fetchColumn();
  return $v !== false ? (float) $v : 0.0;
}

/** Quantité d'équipement actuellement prêtée (status='pret') pour une variante/lieu donnés. */
function get_equipment_loaned_qty(int $variantId, int $locationId): float {
  $st = db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM equipment_loans WHERE variant_id = ? AND location_id = ? AND status = 'pret'");
  $st->execute([$variantId, $locationId]);
  return (float) $st->fetchColumn();
}

/** Quantité réellement disponible : pour l'usage 'equipement', on retranche ce qui est actuellement prêté. */
function get_available_qty(int $variantId, int $locationId, string $usage): float {
  $total = get_stock_qty($variantId, $locationId, $usage);
  if ($usage === 'equipement') $total -= get_equipment_loaned_qty($variantId, $locationId);
  return $total;
}

/** Applique un delta brut à stock_levels (créé la ligne si absente), sans journaliser de mouvement. */
function adjust_stock_level(int $variantId, int $locationId, string $usage, float $delta): void {
  $pdo = db();
  $pdo->prepare('INSERT INTO stock_levels (variant_id, location_id, usage, quantity) VALUES (?,?,?,0)
                 ON CONFLICT(variant_id, location_id, usage) DO NOTHING')->execute([$variantId, $locationId, $usage]);
  $pdo->prepare('UPDATE stock_levels SET quantity = quantity + ? WHERE variant_id = ? AND location_id = ? AND usage = ?')
      ->execute([$delta, $variantId, $locationId, $usage]);
}

/** Ajuste le stock d'un article à un emplacement (créé la ligne si absente) et journalise le mouvement.
 *  $usage : bucket affecté (boutique | equipement | non_affecte). Par défaut 'boutique' pour préserver le
 *  comportement historique (tout le stock géré était potentiellement vendable en caisse).
 *  Refuse (fail() → sortie JSON 409) toute sortie qui dépasserait le stock réellement disponible ; pour
 *  l'usage 'equipement', "disponible" exclut ce qui est actuellement prêté (equipment_loans status='pret').
 *  Retourne l'id du mouvement inséré (utile pour lier deux mouvements d'une même opération via ref_id). */
function apply_stock_movement(int $variantId, int $locationId, string $type, float $qty, string $reason, string $refType, ?int $refId, ?int $userId, string $usage = 'boutique'): int {
  $pdo = db();
  $delta = movement_delta($type, $qty);
  if ($delta < 0) {
    $available = get_available_qty($variantId, $locationId, $usage);
    if ($qty > $available + 1e-9) {
      $suffix = $usage === 'equipement' ? ' (hors quantité actuellement prêtée)' : '';
      fail('Stock insuffisant : ' . $available . ' disponible(s)' . $suffix, 409);
    }
  }
  adjust_stock_level($variantId, $locationId, $usage, $delta);
  $pdo->prepare('INSERT INTO stock_movements (variant_id, location_id, usage, type, quantity, reason, ref_type, ref_id, user_id) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$variantId, $locationId, $usage, $type, $qty, $reason, $refType, $refId, $userId]);
  return (int) $pdo->lastInsertId();
}

/* ---------------------------------------------------------------- Router */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

/* ---------------------------------------------------------------- Permissions
 *
 * Trois regimes d'acces coexistent dans cette application :
 *
 *  1. CMD_PUBLIC   : la boutique publique. Aucune authentification, par
 *                    conception : n'importe qui doit pouvoir commander.
 *  2. CMD_MACHINE  : appels de la Caisse. Acceptent une session ERP donnant
 *                    acces a la Caisse, ou a defaut la cle API (X-Api-Key),
 *                    verifiees par cmd_require_caisse() dans chaque action.
 *  3. tout le reste : session ERP + permission listee dans CMD_PERMS.
 *
 * Format d'une entree de CMD_PERMS :
 *    'action' => 'perm'                          toutes methodes
 *    'action' => ['GET'=>'a', 'write'=>'b']      lecture / ecriture
 *    'action' => ['GET'=>'a','POST'=>'b','PUT'=>'c','DELETE'=>'c']  par methode
 *    'action' => null                            session suffisante
 *
 * REFUS PAR DEFAUT : une action absente exige settings.manage, le droit le plus
 * eleve. Sur 63 points d'entree, en oublier un est certain ; le defaut doit donc
 * etre ferme.
 *
 * CMD_PUBLIC et CMD_MACHINE sont declarees en tete de fichier, la politique
 * CORS en dependant avant tout traitement.
 */

/**
 * Autorise un appel de la Caisse vers les points d'entree machine.
 *
 * Deux voies, dans cet ordre :
 *   1. session ERP avec acces a l'application Caisse. C'est la voie utilisee
 *      par l'interface : les deux applications partagent desormais le meme
 *      domaine, le cookie de session suffit. Aucun secret ne transite donc
 *      plus par le JavaScript, et retirer ses droits a quelqu'un lui retire
 *      aussi cet acces, immediatement.
 *   2. cle API, conservee pour un eventuel appel serveur a serveur qui n'a pas
 *      de session. hash_equals compare en temps constant.
 */
function cmd_require_caisse(): void {
  if (mfc_session() && mfc_can_access('caisse')) return;
  $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
  $expected = (string) setting('caisse_api_key');
  if ($key !== '' && $expected !== '' && hash_equals($expected, $key)) return;
  fail('Acces refuse : session Caisse ou cle API requise', 401);
}

const CMD_PERMS = [
  /* --- session suffisante ------------------------------------------------ */
  'me'                       => null,
  'seasons_list'             => null,
  'vat_rate'                 => null,
  'users'                    => ['GET' => 'catalog.view', 'write' => null],

  /* --- catalogue --------------------------------------------------------- */
  'categories'               => ['GET' => 'catalog.view', 'write' => 'catalog.edit'],
  'departments'              => ['GET' => 'catalog.view', 'write' => 'catalog.edit'],
  'formes'                   => ['GET' => 'catalog.view', 'write' => 'catalog.edit'],
  'attributes'               => ['GET' => 'catalog.view', 'write' => 'catalog.edit'],
  'attribute_values'         => 'catalog.edit',
  'attribute_values_reorder' => 'catalog.edit',
  'locations'                => ['GET' => 'catalog.view', 'write' => 'catalog.edit'],
  'articles'                 => ['GET' => 'catalog.view', 'write' => 'catalog.edit'],
  'article_lookup'           => 'catalog.view',
  'article_history'          => 'catalog.view',
  'article_photo'            => 'catalog.edit',
  'article_color_photo'      => 'catalog.edit',
  'articles_import'          => 'catalog.edit',
  'variants'                 => 'catalog.edit',
  'variants_batch'           => 'catalog.edit',
  'variants_shop_visibility' => 'catalog.edit',
  'label_settings'           => 'catalog.view',

  /* --- fournisseurs ------------------------------------------------------ */
  /* La liste est consultable par qui voit le catalogue : elle sert a afficher
     le fournisseur d'un article. La modifier demande le droit dedie. */
  'suppliers'                => ['GET' => 'catalog.view', 'write' => 'suppliers.manage'],
  'supplier_address'         => 'suppliers.manage',
  'supplier_contact'         => 'suppliers.manage',

  /* --- stock ------------------------------------------------------------- */
  'stock'                    => 'stock.view',
  'stock_movement'           => 'stock.move',
  'stock_movements'          => ['GET' => 'stock.view', 'write' => 'stock.move'],
  'stock_transfer'           => 'stock.move',
  'stock_dispatch'           => 'stock.move',
  'equipment_loans'          => ['GET' => 'stock.view', 'write' => 'stock.move'],
  'equipment_don'            => 'stock.move',

  /* --- demandes de materiel ---------------------------------------------- */
  /* Creer une demande et la valider sont deux droits distincts : c'est le
     decoupage voulu (un stagiaire demande, un responsable valide). D'ou le
     detail par methode plutot qu'un simple lecture / ecriture. */
  'requests'                 => ['GET'    => 'requests.create',
                                 'POST'   => 'requests.create',
                                 'PUT'    => 'requests.validate',
                                 'DELETE' => 'requests.validate'],
  'request_cart'             => 'requests.create',
  'request_fulfill_stock'    => 'requests.validate',
  'request_to_order'         => 'requests.validate',

  /* --- commandes fournisseurs -------------------------------------------- */
  'orders'                   => ['GET' => 'orders.view', 'write' => 'orders.edit'],
  'orders_quick_from_request'=> 'orders.edit',
  'orders_receive'           => 'orders.receive',
  'orders_receive_scan'      => 'orders.receive',
  'order_export_csv'         => 'orders.view',

  /* --- factures ---------------------------------------------------------- */
  'invoice_upload'           => 'invoices.manage',
  'invoice'                  => 'invoices.manage',
  'invoice_anomaly'          => 'invoices.manage',

  /* --- budgets et rapports ----------------------------------------------- */
  'budgets'                  => ['GET' => 'budgets.view', 'write' => 'budgets.edit'],
  'reports'                  => 'reports.view',
  'stock_value'              => 'reports.view',
  'activity'                 => 'reports.view',
  'dashboard'                => 'reports.view',

  /* --- fichiers ---------------------------------------------------------- */
  /* Le droit exact est determine dans le bloc : une facture exige
     invoices.manage, une photo d'article se contente de catalog.view. */
  'download'                 => null,

  /* --- administration ---------------------------------------------------- */
  /* settings contient la cle API de la Caisse : lecture comme ecriture sont
     reservees a l'administration. */
  'settings'                 => 'settings.manage',
  'promo_codes'              => 'settings.manage',
  'backup_db'                => 'settings.manage',
];

/* Points d'entree supprimes : l'authentification est centralisee dans l'ERP. */
if (in_array($action, ['login', 'logout', 'setup', 'status', 'change_password'], true)) {
  fail("Cette application n'a plus de connexion propre. Utilisez l'ERP : " . ERP_URL, 410);
}

if (in_array($action, CMD_PUBLIC, true)) {
  /* Boutique publique : rien a verifier, c'est voulu. */
} elseif (in_array($action, CMD_MACHINE, true)) {
  /* Machine a machine : la cle API est verifiee dans le bloc de l'action. */
} else {
  mfc_require_api('commandes');
  $cmd_rule = array_key_exists($action, CMD_PERMS) ? CMD_PERMS[$action] : 'settings.manage';
  if (is_array($cmd_rule)) {
    if (array_key_exists($method, $cmd_rule)) {
      $cmd_rule = $cmd_rule[$method];
    } elseif ($method === 'GET') {
      $cmd_rule = $cmd_rule['GET'] ?? 'settings.manage';
    } else {
      $cmd_rule = array_key_exists('write', $cmd_rule) ? $cmd_rule['write'] : 'settings.manage';
    }
  }
  if ($cmd_rule !== null) {
    mfc_require_perm('commandes.' . $cmd_rule);
  }
}

switch ($action) {

  /* ============ AUTH & SETUP ============ */

  /* status, setup, login, logout et change_password ont ete supprimes :
     l'authentification est centralisee dans l'ERP. Les appels a ces actions
     sont interceptes plus haut et renvoient un 410 explicite. */

  case 'me': {
    out(['user' => require_auth()]);
  }

  /* Annuaire local, en lecture seule : il alimente les listes de demandeurs et
     de responsables, et l'affichage des noms dans les historiques. Les comptes
     se gerent exclusivement dans l'ERP.
     Le compte systeme de la boutique publique est masque : ce n'est pas une
     personne, il ne doit apparaitre dans aucune liste. */
  case 'users': {
    require_auth();
    if ($method === 'GET') {
      $st = db()->prepare('SELECT id, name, email, role, active FROM users WHERE email <> ? ORDER BY id');
      $st->execute(['boutique-publique@commandes.meyrinfc.ch']);
      out($st->fetchAll());
    }
    fail("Les comptes se gerent dans l'ERP : " . ERP_URL, 403);
  }

  case 'settings': {
    $u = require_auth();
    if ($method === 'GET') {
      $rows = db()->query('SELECT key, value FROM settings')->fetchAll();
      $out = [];
      foreach ($rows as $r) $out[$r['key']] = $r['value'];
      out($out);
    }
    if ($method === 'PUT') {
      foreach (['fiscal_year_start_month', 'request_stale_days', 'order_forgotten_days', 'label_format'] as $k) {
        if (isset($b[$k])) set_setting($k, (string)$b[$k]);
      }
      log_activity($u['id'], 'Paramètres modifiés');
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  // Version restreinte de "settings" : ne renvoie que le format d'étiquette par défaut, utilisable par les
  // écrans d'impression d'étiquettes sans exposer le reste de la table settings (notamment caisse_api_key).
  case 'label_settings': {
    $u = require_auth();
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    out(['label_format' => setting('label_format', '62x29')]);
  }

  /* ============ CATÉGORIES ============ */

  case 'categories': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT * FROM categories ORDER BY COALESCE(parent_id, id), (parent_id IS NOT NULL), name COLLATE NOCASE')->fetchAll());
    }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      $parentId = i($b, 'parent_id') ?: null;
      if ($parentId) {
        $parent = db()->query("SELECT parent_id FROM categories WHERE id=$parentId")->fetch();
        if (!$parent) fail('Catégorie parente introuvable');
        if ($parent['parent_id']) fail('Impossible : une sous-catégorie ne peut pas avoir de sous-catégorie');
      }
      db()->prepare('INSERT INTO categories (name, parent_id) VALUES (?,?)')->execute([$name, $parentId]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      if (array_key_exists('parent_id', $b)) {
        $parentId = i($b, 'parent_id') ?: null;
        if ($parentId) {
          if ($parentId === $id) fail('Une catégorie ne peut pas être sa propre parente');
          $parent = db()->query("SELECT parent_id FROM categories WHERE id=$parentId")->fetch();
          if (!$parent) fail('Catégorie parente introuvable');
          if ($parent['parent_id']) fail('Impossible : une sous-catégorie ne peut pas avoir de sous-catégorie');
          $hasChildren = (int) db()->query("SELECT COUNT(*) FROM categories WHERE parent_id=$id")->fetchColumn();
          if ($hasChildren) fail('Impossible : cette catégorie a des sous-catégories, elle ne peut pas devenir une sous-catégorie');
        }
        db()->prepare('UPDATE categories SET name=?, parent_id=? WHERE id=?')->execute([s($b,'name'), $parentId, $id]);
      } else {
        db()->prepare('UPDATE categories SET name=? WHERE id=?')->execute([s($b,'name'), $id]);
      }
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM categories WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ DÉPARTEMENTS (axe budgétaire) ============ */

  case 'departments': {
    $u = require_auth();
    if ($method === 'GET') { out(db()->query('SELECT * FROM departments ORDER BY name COLLATE NOCASE')->fetchAll()); }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      db()->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$name]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE departments SET name=? WHERE id=?')->execute([s($b,'name'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM departments WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ FORMES ============ */

  case 'formes': {
    $u = require_auth();
    if ($method === 'GET') { out(db()->query('SELECT * FROM formes ORDER BY name COLLATE NOCASE')->fetchAll()); }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      try {
        db()->prepare('INSERT INTO formes (name) VALUES (?)')->execute([$name]);
      } catch (PDOException $e) { fail('Une forme porte déjà ce nom'); }
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE formes SET name=? WHERE id=?')->execute([s($b,'name'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM formes WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ATTRIBUTS PERSONNALISÉS ============ */

  case 'attributes': {
    $u = require_auth();
    if ($method === 'GET') {
      $rows = db()->query('SELECT * FROM attributes ORDER BY name COLLATE NOCASE')->fetchAll();
      $values = db()->query('SELECT * FROM attribute_values ORDER BY sort_order, value COLLATE NOCASE')->fetchAll();
      $byAttr = [];
      foreach ($values as $v) { $byAttr[$v['attribute_id']][] = $v; }
      foreach ($rows as &$r) { $r['values'] = $byAttr[$r['id']] ?? []; }
      out($rows);
    }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      try {
        db()->prepare('INSERT INTO attributes (name) VALUES (?)')->execute([$name]);
      } catch (PDOException $e) { fail('Un attribut porte déjà ce nom'); }
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE attributes SET name=? WHERE id=?')->execute([s($b,'name'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM attributes WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'attribute_values': {
    $u = require_auth();
    if ($method === 'POST') {
      $attrId = i($b, 'attribute_id'); $value = s($b, 'value'); $colorCode = s($b, 'color_code');
      if (!$attrId || !$value) fail('Attribut et valeur requis');
      try {
        db()->prepare('INSERT INTO attribute_values (attribute_id, value, color_code) VALUES (?,?,?)')->execute([$attrId, $value, $colorCode]);
      } catch (PDOException $e) { fail('Cette valeur existe déjà pour cet attribut'); }
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE attribute_values SET color_code = ? WHERE id = ?')->execute([s($b, 'color_code'), i($b, 'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM attribute_values WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Réordonnancement complet des valeurs d'un attribut depuis Paramètres : reçoit les ids dans l'ordre
   * d'affichage souhaité, le rang dans le tableau devient le sort_order (0, 1, 2...). */
  case 'attribute_values_reorder': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $ids = $b['ids'] ?? [];
    if (!is_array($ids) || !count($ids)) fail('Liste ordonnée requise');
    $st = db()->prepare('UPDATE attribute_values SET sort_order = ? WHERE id = ?');
    foreach ($ids as $idx => $id) $st->execute([$idx, (int) $id]);
    out(['ok' => true]);
  }

  /* ============ LIEUX DE STOCKAGE ============ */

  case 'locations': {
    $u = require_auth();
    if ($method === 'GET') { out(db()->query('SELECT * FROM locations ORDER BY active DESC, name COLLATE NOCASE')->fetchAll()); }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      db()->prepare('INSERT INTO locations (name) VALUES (?)')->execute([$name]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE locations SET name=?, active=? WHERE id=?')
          ->execute([s($b,'name'), i($b,'active',1), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      $used = (int) db()->query("SELECT COUNT(*) FROM stock_levels WHERE location_id=$id AND quantity != 0")->fetchColumn();
      if ($used > 0) fail('Impossible : ce lieu contient encore du stock');
      db()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ FOURNISSEURS ============ */

  case 'suppliers': {
    $u = require_auth();
    if ($method === 'GET') {
      $rows = db()->query('SELECT * FROM suppliers ORDER BY name COLLATE NOCASE')->fetchAll();
      $addresses = db()->query('SELECT * FROM supplier_addresses ORDER BY id')->fetchAll();
      $contacts = db()->query('SELECT * FROM supplier_contacts ORDER BY id')->fetchAll();
      $addrBySupplier = []; foreach ($addresses as $ad) $addrBySupplier[$ad['supplier_id']][] = $ad;
      $contactsBySupplier = []; foreach ($contacts as $c) $contactsBySupplier[$c['supplier_id']][] = $c;
      foreach ($rows as &$r) {
        $r['orders_count'] = (int) db()->query("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id={$r['id']}")->fetchColumn();
        $avg = db()->query("SELECT AVG(julianday(sent_at2) - julianday(sent_at)) FROM (
                              SELECT po.sent_at, MIN(sm.created_at) AS sent_at2
                              FROM purchase_orders po
                              JOIN purchase_order_lines pol ON pol.order_id = po.id
                              JOIN stock_movements sm ON sm.variant_id = pol.variant_id AND sm.ref_type='reception' AND sm.ref_id = po.id
                              WHERE po.supplier_id = {$r['id']} AND po.sent_at IS NOT NULL
                              GROUP BY po.id)")->fetchColumn();
        $r['avg_delay_days'] = $avg ? round((float)$avg, 1) : null;
        $r['addresses'] = $addrBySupplier[$r['id']] ?? [];
        $r['contacts'] = $contactsBySupplier[$r['id']] ?? [];
        attach_contacts_directory($r['contacts']);
      }
      out($rows);
    }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      db()->prepare('INSERT INTO suppliers (name, notes) VALUES (?,?)')
          ->execute([$name, s($b,'notes')]);
      $newId = (int) db()->lastInsertId();
      sync_supplier_org_to_contacts($newId, $name);
      out(['ok' => true, 'id' => $newId]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE suppliers SET name=?, notes=? WHERE id=?')
          ->execute([s($b,'name'), s($b,'notes'), i($b,'id')]);
      sync_supplier_org_to_contacts(i($b,'id'), s($b,'name'));
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      $ordersCount = (int) db()->query("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id=$id")->fetchColumn();
      if ($ordersCount > 0) fail("Impossible de supprimer : $ordersCount commande(s) liée(s) à ce fournisseur. Modifie-le plutôt, ou supprime d'abord les commandes concernées.");
      db()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ADRESSES FOURNISSEUR ============ */

  case 'supplier_address': {
    $u = require_auth();
    if ($method === 'POST') {
      $supplierId = i($b, 'supplier_id'); $label = s($b, 'label');
      if (!$supplierId) fail('Fournisseur requis');
      if (!$label) fail('Intitulé requis');
      db()->prepare('INSERT INTO supplier_addresses (supplier_id, label, address) VALUES (?,?,?)')
          ->execute([$supplierId, $label, s($b,'address')]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $label = s($b, 'label'); if (!$label) fail('Intitulé requis');
      db()->prepare('UPDATE supplier_addresses SET label=?, address=? WHERE id=?')
          ->execute([$label, s($b,'address'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM supplier_addresses WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ CONTACTS FOURNISSEUR ============ */

  case 'supplier_contact': {
    $u = require_auth();
    if ($method === 'POST') {
      $supplierId = i($b, 'supplier_id'); $name = s($b, 'name');
      if (!$supplierId) fail('Fournisseur requis');
      if (!$name) fail('Nom requis');
      db()->prepare('INSERT INTO supplier_contacts (supplier_id, name, role, email, phone, notes) VALUES (?,?,?,?,?,?)')
          ->execute([$supplierId, $name, s($b,'role'), s($b,'email'), s($b,'phone'), s($b,'notes')]);
      $newId = (int) db()->lastInsertId();
      sync_supplier_contact_to_contacts($newId, $supplierId, $name, s($b,'email'), s($b,'phone'));
      out(['ok' => true, 'id' => $newId]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      $supplierId = (int) db()->query("SELECT supplier_id FROM supplier_contacts WHERE id=$id")->fetchColumn();
      db()->prepare('UPDATE supplier_contacts SET name=?, role=?, email=?, phone=?, notes=? WHERE id=?')
          ->execute([$name, s($b,'role'), s($b,'email'), s($b,'phone'), s($b,'notes'), $id]);
      sync_supplier_contact_to_contacts($id, $supplierId, $name, s($b,'email'), s($b,'phone'));
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM supplier_contacts WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ARTICLES (catalogue) ============ */

  case 'articles': {
    $u = require_auth();
    if ($method === 'GET') {
      $products = db()->query('SELECT a.*, c.name AS category_name, c.parent_id AS category_parent_id, pc.name AS parent_category_name,
                            f.name AS forme_name, d.name AS department_name, s.name AS supplier_name
                            FROM articles a
                            LEFT JOIN categories c ON c.id = a.category_id
                            LEFT JOIN categories pc ON pc.id = c.parent_id
                            LEFT JOIN formes f ON f.id = a.forme_id
                            LEFT JOIN departments d ON d.id = a.department_id
                            LEFT JOIN suppliers s ON s.id = a.supplier_id
                            ORDER BY a.name COLLATE NOCASE')->fetchAll();
      $variants = db()->query('SELECT * FROM article_variants ORDER BY label COLLATE NOCASE')->fetchAll();
      $levels = db()->query('SELECT sl.variant_id, sl.location_id, sl.quantity, loc.name AS location_name FROM stock_levels sl JOIN locations loc ON loc.id = sl.location_id')->fetchAll();
      $vAttrs = db()->query('SELECT va.variant_id, va.attribute_id, va.value, va.is_primary, at.name AS attribute_name, av.color_code, av.sort_order
                             FROM variant_attributes va
                             JOIN attributes at ON at.id = va.attribute_id
                             LEFT JOIN attribute_values av ON av.attribute_id = va.attribute_id AND av.value = va.value
                             ORDER BY va.is_primary DESC')->fetchAll();
      $colorPhotos = db()->query('SELECT * FROM article_color_photos')->fetchAll();
      $levelsByVariant = []; foreach ($levels as $l) { $levelsByVariant[$l['variant_id']][] = $l; }
      $attrsByVariant = []; foreach ($vAttrs as $va) { $attrsByVariant[$va['variant_id']][] = $va; }
      $colorsByArticle = []; foreach ($colorPhotos as $cp) { $colorsByArticle[$cp['article_id']][] = $cp; }
      $variantsByArticle = [];
      foreach ($variants as &$v) {
        $v['stock_by_location'] = $levelsByVariant[$v['id']] ?? [];
        $v['total_stock'] = array_sum(array_column($v['stock_by_location'], 'quantity'));
        $v['attributes'] = $attrsByVariant[$v['id']] ?? [];
        $variantsByArticle[$v['article_id']][] = $v;
      }
      $vatRate = (float) setting('vat_rate', '8.1');
      foreach ($products as &$p) {
        $p['variants'] = $variantsByArticle[$p['id']] ?? [];
        $p['total_stock'] = array_sum(array_column($p['variants'], 'total_stock'));
        $p['variant_count'] = count($p['variants']);
        $p['color_photos'] = $colorsByArticle[$p['id']] ?? [];
        $purchaseTtc = (float) $p['purchase_price'] * (1 + $vatRate / 100);
        $p['purchase_price_ttc'] = $purchaseTtc;
        $p['margin_percent'] = $p['sale_price_ttc'] > 0 ? (($p['sale_price_ttc'] - $purchaseTtc) / $p['sale_price_ttc']) * 100 : null;
      }
      out($products);
    }
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Le nom de l\'article est requis');
      $pdo = db();
      $catalogPriceHt = n($b, 'catalog_price_ht');
      $discountPercent = n($b, 'discount_percent');
      $purchasePrice = $catalogPriceHt * (1 - $discountPercent / 100);
      $pdo->beginTransaction();
      try {
        $pdo->prepare('INSERT INTO articles (name, category_id, forme_id, department_id, unit, supplier_id, catalog_price_ht, discount_percent, purchase_price, sale_price_ttc) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$name, i($b,'category_id') ?: null, i($b,'forme_id') ?: null, i($b,'department_id') ?: null, s($b,'unit','pièce'), i($b,'supplier_id') ?: null, $catalogPriceHt, $discountPercent, $purchasePrice, n($b,'sale_price_ttc')]);
        $articleId = (int) $pdo->lastInsertId();
        $articleNumber = trim((string) s($b, 'article_number'));
        if ($articleNumber !== '') {
          $dup = $pdo->prepare('SELECT id FROM articles WHERE article_number = ? AND id != ?');
          $dup->execute([$articleNumber, $articleId]);
          if ($dup->fetch()) { $pdo->rollBack(); fail('Ce numéro d\'article est déjà utilisé'); }
        } else {
          $articleNumber = 'ART-' . str_pad((string)$articleId, 4, '0', STR_PAD_LEFT);
        }
        $pdo->prepare('UPDATE articles SET article_number = ? WHERE id = ?')->execute([$articleNumber, $articleId]);
        $pdo->prepare('INSERT INTO article_variants (article_id, label) VALUES (?, ?)')->execute([$articleId, 'Standard']);
        $variantId = (int) $pdo->lastInsertId();
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        fail('Création impossible : ' . $e->getMessage(), 500);
      }
      log_activity($u['id'], 'Article créé', $name);
      out(['ok' => true, 'id' => $articleId, 'default_variant_id' => $variantId, 'article_number' => $articleNumber]);
    }
    if ($method === 'PUT') {
      $articleId = i($b, 'id');
      $articleNumber = trim((string) s($b, 'article_number'));
      if ($articleNumber !== '') {
        $dup = db()->prepare('SELECT id FROM articles WHERE article_number = ? AND id != ?');
        $dup->execute([$articleNumber, $articleId]);
        if ($dup->fetch()) fail('Ce numéro d\'article est déjà utilisé');
      } else {
        $articleNumber = 'ART-' . str_pad((string)$articleId, 4, '0', STR_PAD_LEFT);
      }
      $catalogPriceHt = n($b, 'catalog_price_ht');
      $discountPercent = n($b, 'discount_percent');
      $purchasePrice = $catalogPriceHt * (1 - $discountPercent / 100);
      db()->prepare('UPDATE articles SET name=?, article_number=?, category_id=?, forme_id=?, department_id=?, unit=?, supplier_id=?, catalog_price_ht=?, discount_percent=?, purchase_price=?, sale_price_ttc=?, active=? WHERE id=?')
          ->execute([s($b,'name'), $articleNumber, i($b,'category_id') ?: null, i($b,'forme_id') ?: null, i($b,'department_id') ?: null, s($b,'unit','pièce'), i($b,'supplier_id') ?: null, $catalogPriceHt, $discountPercent, $purchasePrice, n($b,'sale_price_ttc'), i($b,'active',1), $articleId]);
      log_activity($u['id'], 'Article modifié', s($b,'name'));
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM articles WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ATTRIBUTS / VARIANTES D'ARTICLE ============ */

  /** Création en masse : un attribut (ex. Taille) + plusieurs valeurs (ex. XS, S, M, L), chacune avec
   *  son propre code-barres et un stock initial optionnel. Le prix reste au niveau de l'article. */
  case 'variants_batch': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $articleId = i($b, 'article_id'); $primaryAttrId = i($b, 'primary_attribute_id'); $secondaryAttrId = i($b, 'secondary_attribute_id');
    $locationId = i($b, 'location_id');
    $items = $b['items'] ?? [];
    if (!$articleId || !$primaryAttrId || !is_array($items) || !count($items)) fail('Article, attribut principal et au moins une valeur requis');
    $pdo = db();
    $pdo->beginTransaction();
    $created = 0;
    try {
      $insVar = $pdo->prepare('INSERT INTO article_variants (article_id, label, barcode, alert_threshold) VALUES (?,?,?,?)');
      $insAttr = $pdo->prepare('INSERT INTO variant_attributes (variant_id, attribute_id, value, is_primary) VALUES (?,?,?,?)');
      foreach ($items as $item) {
        $primaryValue = trim((string)($item['primary_value'] ?? ''));
        if ($primaryValue === '') continue;
        $secondaryValue = trim((string)($item['secondary_value'] ?? ''));
        $label = $secondaryValue !== '' ? "$primaryValue / $secondaryValue" : $primaryValue;
        $barcode = trim((string)($item['barcode'] ?? ''));
        $threshold = (int)($item['alert_threshold'] ?? 0);
        $qty = (float)($item['quantity'] ?? 0);
        try {
          $insVar->execute([$articleId, $label, $barcode, $threshold]);
        } catch (PDOException $e) { fail("Le code-barres « $barcode » est déjà utilisé par une autre variante"); }
        $variantId = (int) $pdo->lastInsertId();
        $insAttr->execute([$variantId, $primaryAttrId, $primaryValue, 1]);
        if ($secondaryAttrId && $secondaryValue !== '') {
          $insAttr->execute([$variantId, $secondaryAttrId, $secondaryValue, 0]);
        }
        if ($qty > 0 && $locationId) {
          apply_stock_movement($variantId, $locationId, 'entry', $qty, 'Stock initial', 'manuel', null, (int)$u['id']);
        }
        $created++;
      }
      if (!$created) fail('Aucune valeur valide fournie');
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Création impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Variantes créées', "$created pour article $articleId");
    out(['ok' => true, 'created' => $created]);
  }

  case 'variants': {
    $u = require_auth();
    if ($method === 'PUT') {
      $id = i($b, 'id');
      try {
        db()->prepare('UPDATE article_variants SET barcode=?, alert_threshold=?, active=?, shop_visible=? WHERE id=?')
            ->execute([s($b,'barcode'), i($b,'alert_threshold'), i($b,'active',1), i($b,'shop_visible',0), $id]);
      } catch (PDOException $e) { fail('Ce code-barres est déjà utilisé par une autre variante'); }
      log_activity($u['id'], 'Variante modifiée', "ID $id");
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM article_variants WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Bascule groupée de la visibilité shop (voir shop_visible) pour un lot de variantes en un clic, typiquement
   * toutes les variantes d'une même couleur (mêmes tailles réparties sur des couleurs, certaines réservées). */
  case 'variants_shop_visibility': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $ids = $b['ids'] ?? [];
    if (!is_array($ids) || !count($ids)) fail('Liste de variantes requise');
    $visible = i($b, 'visible', 0) ? 1 : 0;
    $st = db()->prepare('UPDATE article_variants SET shop_visible = ? WHERE id = ?');
    foreach ($ids as $id) $st->execute([$visible, (int) $id]);
    log_activity($u['id'], $visible ? 'Variantes affichées sur le shop' : 'Variantes masquées du shop', implode(',', $ids));
    out(['ok' => true]);
  }

  case 'article_lookup': {
    $u = require_auth();
    $barcode = s($_GET, 'barcode');
    if (!$barcode) fail('Code-barres requis');
    $st = db()->prepare('SELECT v.*, a.name AS article_name, a.category_id FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.barcode = ?');
    $st->execute([$barcode]);
    $v = $st->fetch();
    if (!$v) fail('Aucune variante pour ce code-barres', 404);
    out($v);
  }

  case 'article_photo': {
    $u = require_auth();
    if (empty($_FILES['file'])) fail('Aucun fichier reçu');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
    if ($f['size'] > 8 * 1024 * 1024) fail('Image trop volumineuse (8 Mo max)');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) fail('Format d\'image non autorisé (.' . $ext . ')');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); file_put_contents($dir . '/index.html', ''); }
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) fail('Impossible d\'enregistrer le fichier', 500);
    $id = i($_POST, 'article_id');
    if ($id) db()->prepare('UPDATE articles SET photo=? WHERE id=?')->execute([$stored, $id]);
    out(['ok' => true, 'photo' => $stored]);
  }

  /* Photo dédiée à une valeur de couleur (variant_attributes.is_primary=1), en plus de articles.photo. */
  case 'article_color_photo': {
    $u = require_auth();
    if ($method === 'POST') {
      if (empty($_FILES['file'])) fail('Aucun fichier reçu');
      $f = $_FILES['file'];
      if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
      if ($f['size'] > 8 * 1024 * 1024) fail('Image trop volumineuse (8 Mo max)');
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) fail('Format d\'image non autorisé (.' . $ext . ')');
      $articleId = i($_POST, 'article_id'); $color = s($_POST, 'color_value');
      if (!$articleId || !$color) fail('Article et couleur requis');
      $dir = __DIR__ . '/uploads';
      if (!is_dir($dir)) { mkdir($dir, 0775, true); file_put_contents($dir . '/index.html', ''); }
      $stored = bin2hex(random_bytes(12)) . '.' . $ext;
      if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) fail('Impossible d\'enregistrer le fichier', 500);
      db()->prepare('INSERT INTO article_color_photos (article_id, color_value, photo) VALUES (?,?,?)
                     ON CONFLICT(article_id, color_value) DO UPDATE SET photo = excluded.photo')
          ->execute([$articleId, $color, $stored]);
      out(['ok' => true, 'photo' => $stored]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM article_color_photos WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Import CSV : une ligne = une variante précise (un SKU). Upsert de l'article (par article_number ou
   * name) puis upsert de la variante (par barcode, sinon par combinaison des valeurs d'attributs). */
  case 'articles_import': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $rows = $b['articles'] ?? null;
    if (!is_array($rows) || !count($rows)) fail('Aucune ligne à importer');
    $pdo = db();

    $fixedCols = ['article_number','name','category','subcategory','forme','unit','supplier',
                  'purchase_price','sale_price_ttc','active','variant_label','barcode','alert_threshold'];

    $cats = [];
    foreach ($pdo->query('SELECT id, name, parent_id FROM categories') as $c) {
      $cats[mb_strtolower(trim($c['name'])) . '|' . ($c['parent_id'] ?? 0)] = (int)$c['id'];
    }
    $formes = [];
    foreach ($pdo->query('SELECT id, name FROM formes') as $f) $formes[mb_strtolower(trim($f['name']))] = (int)$f['id'];
    $suppliers = [];
    foreach ($pdo->query('SELECT id, name FROM suppliers') as $sp) $suppliers[mb_strtolower(trim($sp['name']))] = (int)$sp['id'];
    $attributes = [];
    foreach ($pdo->query('SELECT id, name FROM attributes') as $at) $attributes[mb_strtolower(trim($at['name']))] = (int)$at['id'];
    $articlesByNumber = []; $articlesByName = [];
    foreach ($pdo->query('SELECT id, article_number, name FROM articles') as $a) {
      if ($a['article_number']) $articlesByNumber[$a['article_number']] = (int)$a['id'];
      $articlesByName[mb_strtolower(trim($a['name']))] = (int)$a['id'];
    }
    $existingBarcodes = [];
    foreach ($pdo->query("SELECT id, article_id, barcode FROM article_variants WHERE barcode != ''") as $v) {
      $existingBarcodes[$v['barcode']] = ['id' => (int)$v['id'], 'article_id' => (int)$v['article_id']];
    }

    $createdArticles = 0; $updatedArticles = 0; $createdVariants = 0; $updatedVariants = 0; $skipped = 0;

    $pdo->beginTransaction();
    try {
      $insCat = $pdo->prepare('INSERT INTO categories (name, parent_id) VALUES (?, ?)');
      $insForme = $pdo->prepare('INSERT INTO formes (name) VALUES (?)');
      $insSupplier = $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
      $insAttr = $pdo->prepare('INSERT INTO attributes (name) VALUES (?)');
      $insArt = $pdo->prepare("INSERT INTO articles (name, article_number, category_id, forme_id, unit, supplier_id, purchase_price, sale_price_ttc, active) VALUES (?,'',?,?,?,?,?,?,?)");
      $updArt = $pdo->prepare('UPDATE articles SET name=?, category_id=?, forme_id=?, unit=?, supplier_id=?, purchase_price=?, sale_price_ttc=?, active=? WHERE id=?');
      $setArtNumber = $pdo->prepare('UPDATE articles SET article_number=? WHERE id=?');
      $insVar = $pdo->prepare('INSERT INTO article_variants (article_id, label, barcode, alert_threshold, active) VALUES (?,?,?,?,?)');
      $updVar = $pdo->prepare('UPDATE article_variants SET label=?, barcode=?, alert_threshold=?, active=? WHERE id=?');
      $insVarAttr = $pdo->prepare('INSERT INTO variant_attributes (variant_id, attribute_id, value, is_primary) VALUES (?,?,?,?)');
      $delVarAttr = $pdo->prepare('DELETE FROM variant_attributes WHERE variant_id = ?');
      $getVarAttrs = $pdo->prepare('SELECT attribute_id, value FROM variant_attributes WHERE variant_id = ?');

      foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') { $skipped++; continue; }

        $catName = trim((string)($row['category'] ?? ''));
        $subCatName = trim((string)($row['subcategory'] ?? ''));
        $catId = null;
        if ($catName !== '') {
          $key = mb_strtolower($catName) . '|0';
          if (!isset($cats[$key])) { $insCat->execute([$catName, null]); $cats[$key] = (int)$pdo->lastInsertId(); }
          $catId = $cats[$key];
          if ($subCatName !== '') {
            $subKey = mb_strtolower($subCatName) . '|' . $catId;
            if (!isset($cats[$subKey])) { $insCat->execute([$subCatName, $catId]); $cats[$subKey] = (int)$pdo->lastInsertId(); }
            $catId = $cats[$subKey]; // la catégorie effective de l'article = la sous-catégorie si fournie
          }
        }

        $formeName = trim((string)($row['forme'] ?? ''));
        $formeId = null;
        if ($formeName !== '') {
          $fkey = mb_strtolower($formeName);
          if (!isset($formes[$fkey])) { $insForme->execute([$formeName]); $formes[$fkey] = (int)$pdo->lastInsertId(); }
          $formeId = $formes[$fkey];
        }

        $supplierName = trim((string)($row['supplier'] ?? ''));
        $supplierId = null;
        if ($supplierName !== '') {
          $skey = mb_strtolower($supplierName);
          if (!isset($suppliers[$skey])) { $insSupplier->execute([$supplierName]); $suppliers[$skey] = (int)$pdo->lastInsertId(); }
          $supplierId = $suppliers[$skey];
        }

        $unit = trim((string)($row['unit'] ?? '')) ?: 'pièce';
        $purchasePrice = (float)($row['purchase_price'] ?? 0);
        $salePrice = (float)($row['sale_price_ttc'] ?? 0);
        $active = 1;
        if (isset($row['active']) && trim((string)$row['active']) !== '') {
          $active = filter_var($row['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $articleNumber = trim((string)($row['article_number'] ?? ''));

        $articleId = null;
        if ($articleNumber !== '' && isset($articlesByNumber[$articleNumber])) $articleId = $articlesByNumber[$articleNumber];
        elseif (isset($articlesByName[mb_strtolower($name)])) $articleId = $articlesByName[mb_strtolower($name)];

        if ($articleId) {
          $updArt->execute([$name, $catId, $formeId, $unit, $supplierId, $purchasePrice, $salePrice, $active, $articleId]);
          $updatedArticles++;
        } else {
          $insArt->execute([$name, $catId, $formeId, $unit, $supplierId, $purchasePrice, $salePrice, $active]);
          $articleId = (int) $pdo->lastInsertId();
          $num = $articleNumber !== '' ? $articleNumber : ('ART-' . str_pad((string)$articleId, 4, '0', STR_PAD_LEFT));
          try { $setArtNumber->execute([$num, $articleId]); }
          catch (PDOException $e) { $num = 'ART-' . str_pad((string)$articleId, 4, '0', STR_PAD_LEFT); $setArtNumber->execute([$num, $articleId]); }
          $articlesByNumber[$num] = $articleId;
          $articlesByName[mb_strtolower($name)] = $articleId;
          $createdArticles++;
        }

        // Colonnes hors champs fixes = colonnes d'attribut (ex. Couleur, Taille)
        $attrValues = [];
        foreach ($row as $col => $val) {
          if (in_array($col, $fixedCols, true)) continue;
          $val = trim((string)$val);
          if ($val === '') continue;
          $attrValues[$col] = $val;
        }

        $barcode = trim((string)($row['barcode'] ?? ''));
        $label = trim((string)($row['variant_label'] ?? '')) ?: ($attrValues ? implode(' / ', $attrValues) : 'Standard');
        $threshold = (int)($row['alert_threshold'] ?? 0);

        $variantId = null;
        if ($barcode !== '' && isset($existingBarcodes[$barcode]) && $existingBarcodes[$barcode]['article_id'] === $articleId) {
          $variantId = $existingBarcodes[$barcode]['id'];
        } elseif ($barcode === '' || !isset($existingBarcodes[$barcode])) {
          foreach ($pdo->query("SELECT id FROM article_variants WHERE article_id=$articleId") as $cand) {
            $cur = []; $getVarAttrs->execute([$cand['id']]);
            foreach ($getVarAttrs->fetchAll() as $va) $cur[(int)$va['attribute_id']] = $va['value'];
            if (count($cur) !== count($attrValues)) continue;
            $match = true;
            foreach ($attrValues as $colName => $val) {
              $attrId = $attributes[mb_strtolower($colName)] ?? null;
              if (!$attrId || !isset($cur[$attrId]) || $cur[$attrId] !== $val) { $match = false; break; }
            }
            if ($match) { $variantId = (int)$cand['id']; break; }
          }
        }
        // Code-barres déjà utilisé par une autre variante d'un autre article : on ignore cette ligne.
        if ($barcode !== '' && isset($existingBarcodes[$barcode]) && $existingBarcodes[$barcode]['article_id'] !== $articleId && $variantId === null) {
          $skipped++; continue;
        }

        if ($variantId) {
          $updVar->execute([$label, $barcode, $threshold, $active, $variantId]);
          $updatedVariants++;
        } else {
          try { $insVar->execute([$articleId, $label, $barcode, $threshold, $active]); }
          catch (PDOException $e) { $skipped++; continue; }
          $variantId = (int) $pdo->lastInsertId();
          $createdVariants++;
        }
        if ($barcode !== '') $existingBarcodes[$barcode] = ['id' => $variantId, 'article_id' => $articleId];

        if ($attrValues) {
          $delVarAttr->execute([$variantId]);
          $isPrimary = 1;
          foreach ($attrValues as $colName => $val) {
            $akey = mb_strtolower($colName);
            if (!isset($attributes[$akey])) { $insAttr->execute([$colName]); $attributes[$akey] = (int)$pdo->lastInsertId(); }
            $insVarAttr->execute([$variantId, $attributes[$akey], $val, $isPrimary]);
            $isPrimary = 0;
          }
        }
      }
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Import interrompu, aucune donnée enregistrée : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Import catalogue', "$createdArticles article(s) créé(s), $updatedArticles mis à jour, $createdVariants variante(s) créée(s), $updatedVariants mise(s) à jour, $skipped ignorée(s)");
    out(['ok' => true, 'created_articles' => $createdArticles, 'updated_articles' => $updatedArticles,
         'created_variants' => $createdVariants, 'updated_variants' => $updatedVariants, 'skipped' => $skipped]);
  }

  case 'article_history': {
    $u = require_auth();
    $id = i($_GET, 'id'); // variant_id
    if (!$id) fail('Variante requise');
    $pdo = db();
    $timeline = [];
    foreach ($pdo->query("SELECT r.*, u.name AS requester_name FROM requests r JOIN users u ON u.id = r.requester_id WHERE r.variant_id=$id") as $r) {
      $timeline[] = ['type' => 'demande', 'date' => $r['created_at'], 'label' => "Demande de {$r['quantity']} par {$r['requester_name']}", 'status' => $r['status'], 'data' => $r];
    }
    foreach ($pdo->query("SELECT pol.*, po.status AS order_status, po.created_at AS order_date, s.name AS supplier_name
                           FROM purchase_order_lines pol
                           JOIN purchase_orders po ON po.id = pol.order_id
                           JOIN suppliers s ON s.id = po.supplier_id
                           WHERE pol.variant_id=$id") as $l) {
      $timeline[] = ['type' => 'commande', 'date' => $l['order_date'], 'label' => "Commande de {$l['quantity_ordered']} chez {$l['supplier_name']}", 'status' => $l['order_status'], 'data' => $l];
    }
    foreach ($pdo->query("SELECT sm.*, u.name AS user_name, loc.name AS location_name FROM stock_movements sm
                           LEFT JOIN users u ON u.id = sm.user_id
                           JOIN locations loc ON loc.id = sm.location_id
                           WHERE sm.variant_id=$id") as $m) {
      $timeline[] = ['type' => 'mouvement', 'date' => $m['created_at'], 'label' => "{$m['type']} de {$m['quantity']} — {$m['location_name']}", 'status' => $m['ref_type'], 'data' => $m];
    }
    foreach ($pdo->query("SELECT inv.*, po.id AS order_id FROM invoices inv
                           JOIN purchase_orders po ON po.id = inv.order_id
                           JOIN purchase_order_lines pol ON pol.order_id = po.id
                           WHERE pol.variant_id=$id GROUP BY inv.id") as $f) {
      $timeline[] = ['type' => 'facture', 'date' => $f['created_at'], 'label' => "Facture liée — {$f['amount']} CHF", 'status' => 'facture', 'data' => $f];
    }
    usort($timeline, fn($a2, $b2) => strcmp($b2['date'], $a2['date']));
    out($timeline);
  }

  /* ============ STOCK ============ */

  case 'stock': {
    $u = require_auth();
    $rows = db()->query("SELECT sl.*, a.name AS article_name, v.label AS variant_label, v.alert_threshold, loc.name AS location_name
                      FROM stock_levels sl
                      JOIN article_variants v ON v.id = sl.variant_id
                      JOIN articles a ON a.id = v.article_id
                      JOIN locations loc ON loc.id = sl.location_id
                      ORDER BY a.name COLLATE NOCASE, v.label COLLATE NOCASE")->fetchAll();
    $loaned = [];
    foreach (db()->query("SELECT variant_id, location_id, SUM(quantity) AS qty FROM equipment_loans WHERE status='pret' GROUP BY variant_id, location_id") as $l) {
      $loaned[$l['variant_id'] . '|' . $l['location_id']] = (float) $l['qty'];
    }
    foreach ($rows as &$r) {
      $r['loaned_quantity'] = $r['usage'] === 'equipement' ? ($loaned[$r['variant_id'] . '|' . $r['location_id']] ?? 0) : 0;
      $r['available_quantity'] = (float) $r['quantity'] - $r['loaned_quantity'];
    }
    unset($r);
    out($rows);
  }

  case 'stock_movement': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $variantId = i($b, 'variant_id'); $locationId = i($b, 'location_id');
    $type = s($b, 'type'); $qty = n($b, 'quantity');
    if (!$variantId || !$locationId || $qty <= 0) fail('Variante, lieu et quantité (> 0) requis');
    if (!in_array($type, ['entry', 'exit', 'adjustment'], true)) fail('Type de mouvement invalide');
    $usage = s($b, 'usage', 'boutique');
    if (!in_array($usage, ['boutique', 'equipement', 'non_affecte'], true)) fail('Usage invalide');
    apply_stock_movement($variantId, $locationId, $type, $qty, s($b, 'reason'), 'manuel', null, (int)$u['id'], $usage);
    log_activity($u['id'], 'Mouvement de stock', "$type de $qty");
    out(['ok' => true]);
  }

  /* Historique des mouvements de stock : liste, modification et suppression (avec gestion des paires
   * transfert/dispatch pour ne jamais laisser une moitié orpheline). */
  case 'stock_movements': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query("SELECT sm.*, a.name AS article_name, v.label AS variant_label, loc.name AS location_name, usr.name AS user_name
                        FROM stock_movements sm
                        JOIN article_variants v ON v.id = sm.variant_id
                        JOIN articles a ON a.id = v.article_id
                        JOIN locations loc ON loc.id = sm.location_id
                        LEFT JOIN users usr ON usr.id = sm.user_id
                        ORDER BY sm.created_at DESC, sm.id DESC LIMIT 500")->fetchAll());
    }
    $pdo = db();

    /** Charge le mouvement + son groupe (lui-même seul, ou toutes les lignes liées par ref_type+ref_id pour
     *  un transfert/dispatch), pour ne jamais modifier/supprimer une moitié d'opération isolément. */
    $loadGroup = function (int $id) use ($pdo): array {
      $row = $pdo->query("SELECT * FROM stock_movements WHERE id=$id")->fetch();
      if (!$row) fail('Mouvement introuvable', 404);
      if (in_array($row['ref_type'], ['transfert', 'dispatch'], true) && $row['ref_id']) {
        $st = $pdo->prepare('SELECT * FROM stock_movements WHERE ref_type = ? AND ref_id = ? ORDER BY id');
        $st->execute([$row['ref_type'], $row['ref_id']]);
        return [$row, $st->fetchAll()];
      }
      return [$row, [$row]];
    };

    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      [$row, $group] = $loadGroup($id);
      // Pré-validation : simule les annulations et vérifie qu'aucun triplet variante/lieu/usage ne passerait sous 0.
      $deltas = [];
      foreach ($group as $m) {
        $key = $m['variant_id'] . '|' . $m['location_id'] . '|' . $m['usage'];
        $deltas[$key] = ($deltas[$key] ?? 0) - movement_delta($m['type'], (float)$m['quantity']);
      }
      foreach ($deltas as $key => $d) {
        [$vid, $lid, $us] = explode('|', $key);
        $cur = get_stock_qty((int)$vid, (int)$lid, $us);
        if ($cur + $d < -1e-9) fail('Suppression refusée : le stock deviendrait négatif pour un article concerné', 409);
      }
      $pdo->beginTransaction();
      try {
        foreach ($group as $m) {
          adjust_stock_level((int)$m['variant_id'], (int)$m['location_id'], $m['usage'], -movement_delta($m['type'], (float)$m['quantity']));
          $pdo->prepare('DELETE FROM stock_movements WHERE id=?')->execute([$m['id']]);
        }
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        fail('Suppression impossible : ' . $e->getMessage(), 500);
      }
      log_activity($u['id'], 'Mouvement supprimé', "ID $id" . (count($group) > 1 ? ' (groupe de ' . count($group) . ')' : ''));
      out(['ok' => true]);
    }

    if ($method === 'PUT') {
      $id = i($b, 'id');
      [$row, $group] = $loadGroup($id);
      $newQty = isset($b['quantity']) ? n($b, 'quantity') : (float)$row['quantity'];
      if ($newQty <= 0) fail('Quantité invalide');

      if (count($group) > 1) {
        // Mouvements liés (transfert / dispatch) : seule la quantité peut être modifiée, appliquée à
        // toutes les lignes du groupe pour rester cohérent (jamais de moitié orpheline).
        if ($row['ref_type'] === 'dispatch' && count($group) > 2) {
          fail('Un dispatch réparti sur plusieurs usages ne peut pas être modifié : supprimez-le et recréez-le');
        }
        foreach ($group as $m) {
          $reversal = -movement_delta($m['type'], (float)$m['quantity']);
          $reapply = movement_delta($m['type'], $newQty);
          $cur = get_stock_qty((int)$m['variant_id'], (int)$m['location_id'], $m['usage']);
          if ($cur + $reversal + $reapply < -1e-9) fail('Modification refusée : stock insuffisant pour ce mouvement lié', 409);
        }
        $pdo->beginTransaction();
        try {
          foreach ($group as $m) {
            adjust_stock_level((int)$m['variant_id'], (int)$m['location_id'], $m['usage'], -movement_delta($m['type'], (float)$m['quantity']));
            adjust_stock_level((int)$m['variant_id'], (int)$m['location_id'], $m['usage'], movement_delta($m['type'], $newQty));
            $pdo->prepare('UPDATE stock_movements SET quantity=? WHERE id=?')->execute([$newQty, $m['id']]);
          }
          $pdo->commit();
        } catch (Exception $e) {
          $pdo->rollBack();
          fail('Modification impossible : ' . $e->getMessage(), 500);
        }
        log_activity($u['id'], 'Mouvement modifié', "ID $id (groupe)");
        out(['ok' => true]);
      }

      // Mouvement isolé (manuel / réception / don / prêt...) : quantité, lieu, usage et type (parmi
      // entry/exit/adjustment) modifiables. NB : modifier une réception ne resynchronise pas
      // purchase_order_lines.quantity_received (limitation connue, cf. rapport).
      $newLocationId = isset($b['location_id']) ? i($b, 'location_id') : (int)$row['location_id'];
      $newUsage = isset($b['usage']) ? s($b, 'usage') : $row['usage'];
      $newType = isset($b['type']) ? s($b, 'type') : $row['type'];
      if (!in_array($newUsage, ['boutique', 'equipement', 'non_affecte'], true)) fail('Usage invalide');
      if ($newType !== $row['type'] && !in_array($newType, ['entry', 'exit', 'adjustment'], true)) fail('Type de mouvement invalide');

      $pdo->beginTransaction();
      try {
        adjust_stock_level((int)$row['variant_id'], (int)$row['location_id'], $row['usage'], -movement_delta($row['type'], (float)$row['quantity']));
        $curAtNew = get_stock_qty((int)$row['variant_id'], $newLocationId, $newUsage);
        $reapply = movement_delta($newType, $newQty);
        if ($curAtNew + $reapply < -1e-9) {
          $pdo->rollBack();
          fail('Modification refusée : stock insuffisant à la nouvelle destination', 409);
        }
        adjust_stock_level((int)$row['variant_id'], $newLocationId, $newUsage, $reapply);
        $pdo->prepare('UPDATE stock_movements SET quantity=?, location_id=?, usage=?, type=? WHERE id=?')
            ->execute([$newQty, $newLocationId, $newUsage, $newType, $id]);
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        fail('Modification impossible : ' . $e->getMessage(), 500);
      }
      log_activity($u['id'], 'Mouvement modifié', "ID $id");
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'stock_transfer': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $variantId = i($b, 'variant_id'); $from = i($b, 'from_location_id'); $to = i($b, 'to_location_id'); $qty = n($b, 'quantity');
    if (!$variantId || !$from || !$to || $from === $to || $qty <= 0) fail('Variante, lieux distincts et quantité (> 0) requis');
    $usage = s($b, 'usage', 'boutique');
    if (!in_array($usage, ['boutique', 'equipement', 'non_affecte'], true)) fail('Usage invalide');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $refId = apply_stock_movement($variantId, $from, 'exit', $qty, 'Transfert', 'transfert', null, (int)$u['id'], $usage);
      $pdo->prepare('UPDATE stock_movements SET ref_id=? WHERE id=?')->execute([$refId, $refId]);
      apply_stock_movement($variantId, $to, 'entry', $qty, 'Transfert', 'transfert', $refId, (int)$u['id'], $usage);
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Transfert impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Transfert de stock', "$qty");
    out(['ok' => true]);
  }

  /* Dispatche du stock 'non_affecte' (reçu mais pas encore réparti) vers boutique et/ou équipement.
   * Les deux (ou trois) mouvements de l'opération partagent le même ref_id pour rester traités en groupe
   * lors d'une modification/suppression ultérieure (cf. action stock_movements). */
  case 'stock_dispatch': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $variantId = i($b, 'variant_id'); $locationId = i($b, 'location_id');
    $qtyBoutique = n($b, 'qty_boutique'); $qtyEquipement = n($b, 'qty_equipement');
    if (!$variantId || !$locationId) fail('Variante et lieu requis');
    if ($qtyBoutique < 0 || $qtyEquipement < 0) fail('Quantités invalides');
    $total = $qtyBoutique + $qtyEquipement;
    if ($total <= 0) fail('Indiquer une quantité à répartir (boutique et/ou équipement)');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $refId = apply_stock_movement($variantId, $locationId, 'exit', $total, 'Dispatch', 'dispatch', null, (int)$u['id'], 'non_affecte');
      $pdo->prepare('UPDATE stock_movements SET ref_id=? WHERE id=?')->execute([$refId, $refId]);
      if ($qtyBoutique > 0) apply_stock_movement($variantId, $locationId, 'entry', $qtyBoutique, 'Dispatch', 'dispatch', $refId, (int)$u['id'], 'boutique');
      if ($qtyEquipement > 0) apply_stock_movement($variantId, $locationId, 'entry', $qtyEquipement, 'Dispatch', 'dispatch', $refId, (int)$u['id'], 'equipement');
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Dispatch impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Dispatch de stock', "Variante $variantId : $qtyBoutique boutique / $qtyEquipement équipement");
    out(['ok' => true]);
  }

  /* ============ ÉQUIPEMENT : PRÊTS & DONS ============ */

  /* Prêt : ne touche pas stock_levels (le club reste propriétaire), juste une ligne equipment_loans.
   * Rendu : passe status='rendu' + return_date. Suppression : uniquement pour annuler un prêt en cours. */
  case 'equipment_loans': {
    $u = require_auth();
    if ($method === 'GET') {
      $status = s($_GET, 'status');
      $sql = "SELECT el.*, a.name AS article_name, v.label AS variant_label, loc.name AS location_name
              FROM equipment_loans el
              JOIN article_variants v ON v.id = el.variant_id
              JOIN articles a ON a.id = v.article_id
              JOIN locations loc ON loc.id = el.location_id";
      $params = [];
      if (in_array($status, ['pret', 'rendu'], true)) { $sql .= ' WHERE el.status = ?'; $params[] = $status; }
      $sql .= ' ORDER BY el.status = \'pret\' DESC, el.loan_date DESC';
      $st = db()->prepare($sql); $st->execute($params);
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      $variantId = i($b, 'variant_id'); $locationId = i($b, 'location_id');
      $player = s($b, 'player_name'); $qty = n($b, 'quantity', 1);
      if (!$variantId || !$locationId || !$player || $qty <= 0) fail('Variante, lieu, joueur et quantité (> 0) requis');
      $available = get_available_qty($variantId, $locationId, 'equipement');
      if ($qty > $available + 1e-9) fail("Stock équipement insuffisant : $available disponible(s)", 409);
      db()->prepare('INSERT INTO equipment_loans (variant_id, location_id, player_name, quantity, created_by) VALUES (?,?,?,?,?)')
          ->execute([$variantId, $locationId, $player, $qty, (int)$u['id']]);
      log_activity($u['id'], 'Prêt équipement', "$player x$qty");
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); $status = s($b, 'status');
      if ($status !== 'rendu') fail('Seul le retour (statut "rendu") est modifiable');
      $loan = db()->query("SELECT * FROM equipment_loans WHERE id=$id")->fetch();
      if (!$loan) fail('Prêt introuvable', 404);
      if ($loan['status'] === 'rendu') fail('Ce prêt est déjà marqué comme rendu');
      db()->prepare("UPDATE equipment_loans SET status='rendu', return_date=datetime('now') WHERE id=?")->execute([$id]);
      log_activity($u['id'], 'Retour équipement', "Prêt $id");
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      $loan = db()->query("SELECT * FROM equipment_loans WHERE id=$id")->fetch();
      if (!$loan) fail('Prêt introuvable', 404);
      if ($loan['status'] !== 'pret') fail('Seul un prêt en cours peut être annulé (utilisez la suppression pour corriger une erreur de saisie)');
      db()->prepare('DELETE FROM equipment_loans WHERE id = ?')->execute([$id]);
      log_activity($u['id'], 'Prêt annulé', "ID $id");
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Don définitif : sort réellement l'article de l'inventaire (contrairement au prêt). */
  case 'equipment_don': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $variantId = i($b, 'variant_id'); $locationId = i($b, 'location_id');
    $player = s($b, 'player_name'); $qty = n($b, 'quantity', 1);
    if (!$variantId || !$locationId || !$player || $qty <= 0) fail('Variante, lieu, joueur et quantité (> 0) requis');
    apply_stock_movement($variantId, $locationId, 'exit', $qty, "Don à $player", 'don', null, (int)$u['id'], 'equipement');
    log_activity($u['id'], 'Don équipement', "$player x$qty");
    out(['ok' => true]);
  }

  /* ============ DEMANDES ============ */

  case 'requests': {
    $u = require_auth();
    if ($method === 'GET') {
      // cart_id : regroupe les lignes soumises ensemble depuis le panier demandeur (voir action request_cart).
      // Nullable pour compat : les demandes créées avant cette fonctionnalité restent des lignes isolées (cart_id NULL).
      // variant_attrs : concatène les valeurs d'attributs de la variante (ex. "Rouge / M") triées couleur d'abord,
      // via une sous-requête déjà triée (GROUP_CONCAT respecte l'ordre des lignes qui lui sont fournies en SQLite).
      // sale_price_ttc / purchase_price : prix de l'article au moment de la lecture (pas figés à la création de la
      // demande) ; le total par ligne (quantity * sale_price_ttc) et par panier est calculé côté frontend.
      // available_boutique : stock boutique disponible pour la variante, tous lieux confondus (utilisé côté staff
      // pour choisir entre le bouton Ajouter (depuis le stock) et À commander).
      $sql = "SELECT r.*, a.name AS article_name, v.label AS variant_label, v.barcode,
                     a.sale_price_ttc, a.purchase_price,
                     (SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE variant_id = r.variant_id AND usage = 'boutique') AS available_boutique,
                     (SELECT GROUP_CONCAT(value, ' / ') FROM (
                        SELECT value FROM variant_attributes WHERE variant_id = r.variant_id ORDER BY is_primary DESC, attribute_id
                      )) AS variant_attrs,
                     COALESCE(NULLIF(TRIM(rc.guest_first_name || ' ' || rc.guest_last_name), ''), req.name) AS requester_name,
                     COALESCE(NULLIF(rc.guest_email, ''), req.email) AS requester_email,
                     rc.guest_phone AS requester_phone, rc.source AS request_source,
                     rc.promo_code AS cart_promo_code, rc.discount_percent AS cart_discount_percent,
                     rev.name AS reviewer_name,
                     rc.motive AS cart_motive, rc.created_at AS cart_created_at
              FROM requests r
              JOIN article_variants v ON v.id = r.variant_id
              JOIN articles a ON a.id = v.article_id
              JOIN users req ON req.id = r.requester_id
              LEFT JOIN users rev ON rev.id = r.reviewed_by
              LEFT JOIN request_carts rc ON rc.id = r.cart_id";
      $params = [];
      // Scoping sécurité inchangé : un demandeur ne voit que ses propres lignes, cart_id ou non.
      if ($u['role'] === 'demandeur') { $sql .= ' WHERE r.requester_id = ?'; $params[] = $u['id']; }
      $sql .= " ORDER BY COALESCE(rc.created_at, r.created_at) DESC, COALESCE(rc.id, r.id) DESC, r.id ASC";
      $st = db()->prepare($sql); $st->execute($params);
      out($st->fetchAll());
    }
    if ($method === 'POST') {
      $variantId = i($b, 'variant_id'); $qty = n($b, 'quantity', 1);
      if (!$variantId || $qty <= 0) fail('Variante et quantité (> 0) requis');
      db()->prepare('INSERT INTO requests (variant_id, quantity, requester_id, motive) VALUES (?,?,?,?)')
          ->execute([$variantId, $qty, $u['id'], s($b, 'motive')]);
      log_activity($u['id'], 'Demande créée', "Variante $variantId x$qty");
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      // Conservé pour compatibilité ascendante : avant l'introduction des boutons Ajouter/À commander (qui font
      // approbation + traitement en un clic depuis le statut pending), le flux passait par 'approved' ici puis
      // par une action de commande séparée (voir orders_quick_from_request). D'éventuelles demandes déjà en
      // statut 'approved' avant cette mise à jour restent traitables via le bouton Commander (compat) dans l'UI.
      // Le rejet, lui, reste utilisé normalement pour toute demande pending.
      $id = i($b, 'id'); $status = s($b, 'status');
      if (!in_array($status, ['approved', 'rejected'], true)) fail('Statut invalide');
      db()->prepare("UPDATE requests SET status=?, reviewed_by=?, reviewed_at=datetime('now'), reject_reason=? WHERE id=?")
          ->execute([$status, $u['id'], $status === 'rejected' ? s($b, 'reject_reason') : '', $id]);
      log_activity($u['id'], 'Demande ' . ($status === 'approved' ? 'validée' : 'rejetée'), "ID $id");
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      $r = db()->query("SELECT requester_id, status FROM requests WHERE id=$id")->fetch();
      if (!$r) fail('Demande introuvable', 404);
      if ($u['role'] === 'demandeur' && ((int)$r['requester_id'] !== (int)$u['id'] || $r['status'] !== 'pending')) fail('Vous ne pouvez retirer que vos demandes en attente', 403);
      db()->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* Soumission groupée : plusieurs lignes (variant_id, quantity) envoyées en une fois par un demandeur,
     créées comme un seul "panier" (request_carts) + une ligne 'requests' par item avec le même cart_id.
     Coexiste avec l'action 'requests' POST (demande unique, comportement historique conservé). */
  case 'request_cart': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $items = $b['items'] ?? [];
    if (!is_array($items) || !count($items)) fail('Le panier est vide');
    $motive = s($b, 'motive');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO request_carts (requester_id, motive) VALUES (?,?)')->execute([$u['id'], $motive]);
      $cartId = (int) $pdo->lastInsertId();
      $insReq = $pdo->prepare('INSERT INTO requests (variant_id, quantity, requester_id, motive, cart_id) VALUES (?,?,?,?,?)');
      $count = 0;
      foreach ($items as $item) {
        $variantId = (int)($item['variant_id'] ?? 0);
        $qty = (float)($item['quantity'] ?? 0);
        if (!$variantId || $qty <= 0) continue;
        $insReq->execute([$variantId, $qty, $u['id'], $motive, $cartId]);
        $count++;
      }
      if (!$count) { $pdo->rollBack(); fail('Aucune ligne valide dans le panier'); }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      fail('Création du panier impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Panier de demandes créé', "Panier $cartId ($count article(s))");
    out(['ok' => true, 'cart_id' => $cartId, 'count' => $count]);
  }

  /* ============ CODES PROMO (gestion staff) ============ */

  case 'promo_codes': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT * FROM promo_codes ORDER BY created_at DESC')->fetchAll());
    }
    if ($method === 'POST') {
      $code = strtoupper(trim(s($b, 'code')));
      if (!$code) fail('Code requis');
      $discount = n($b, 'discount_percent');
      if ($discount <= 0 || $discount > 100) fail('Rabais invalide (entre 0 et 100 %)');
      $dup = db()->prepare('SELECT id FROM promo_codes WHERE code = ?'); $dup->execute([$code]);
      if ($dup->fetch()) fail('Ce code existe déjà');
      db()->prepare('INSERT INTO promo_codes (code, discount_percent, active, expires_at, max_uses, created_by) VALUES (?,?,?,?,?,?)')
          ->execute([$code, $discount, i($b,'active',1), s($b,'expires_at') ?: null, isset($b['max_uses']) && $b['max_uses'] !== '' ? i($b,'max_uses') : null, $u['id']]);
      log_activity($u['id'], 'Code promo créé', $code);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      $code = strtoupper(trim(s($b, 'code')));
      if (!$code) fail('Code requis');
      $discount = n($b, 'discount_percent');
      if ($discount <= 0 || $discount > 100) fail('Rabais invalide (entre 0 et 100 %)');
      $dup = db()->prepare('SELECT id FROM promo_codes WHERE code = ? AND id != ?'); $dup->execute([$code, $id]);
      if ($dup->fetch()) fail('Ce code existe déjà');
      db()->prepare('UPDATE promo_codes SET code=?, discount_percent=?, active=?, expires_at=?, max_uses=? WHERE id=?')
          ->execute([$code, $discount, i($b,'active',1), s($b,'expires_at') ?: null, isset($b['max_uses']) && $b['max_uses'] !== '' ? i($b,'max_uses') : null, $id]);
      log_activity($u['id'], 'Code promo modifié', $code);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM promo_codes WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ BOUTIQUE PUBLIQUE (/shop, sans authentification) ============
     Trois actions volontairement en dehors de require_auth()/require_role() : elles doivent rester
     accessibles à n'importe quel visiteur muni du lien public. Toute écriture y est strictement limitée
     (validation serveur des variantes/quantités, jamais de champ libre injecté tel quel en SQL). */

  case 'shop_catalog': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $products = db()->query("SELECT a.id, a.name, a.article_number, a.photo, a.unit, a.sale_price_ttc,
                                     a.category_id, c.name AS category_name, c.parent_id AS category_parent_id, pc.name AS parent_category_name
                              FROM articles a
                              LEFT JOIN categories c ON c.id = a.category_id
                              LEFT JOIN categories pc ON pc.id = c.parent_id
                              WHERE a.active = 1
                              ORDER BY a.name COLLATE NOCASE")->fetchAll();
    $variants = db()->query("SELECT v.id, v.article_id, v.label, v.barcode
                              FROM article_variants v JOIN articles a ON a.id = v.article_id
                              WHERE v.active = 1 AND a.active = 1 AND v.shop_visible = 1 ORDER BY v.label COLLATE NOCASE")->fetchAll();
    $levels = db()->query("SELECT variant_id, SUM(quantity) AS qty FROM stock_levels WHERE usage = 'boutique' GROUP BY variant_id")->fetchAll();
    $vAttrs = db()->query('SELECT va.variant_id, va.attribute_id, va.value, va.is_primary, at.name AS attribute_name, av.color_code, av.sort_order
                           FROM variant_attributes va
                           JOIN attributes at ON at.id = va.attribute_id
                           LEFT JOIN attribute_values av ON av.attribute_id = va.attribute_id AND av.value = va.value
                           ORDER BY va.is_primary DESC')->fetchAll();
    $colorPhotos = db()->query('SELECT * FROM article_color_photos')->fetchAll();
    $stockByVariant = []; foreach ($levels as $l) $stockByVariant[$l['variant_id']] = (float) $l['qty'];
    $attrsByVariant = []; foreach ($vAttrs as $va) $attrsByVariant[$va['variant_id']][] = $va;
    $colorsByArticle = []; foreach ($colorPhotos as $cp) $colorsByArticle[$cp['article_id']][] = $cp;
    $variantsByArticle = [];
    foreach ($variants as &$v) {
      $v['total_stock'] = $stockByVariant[$v['id']] ?? 0;
      $v['attributes'] = $attrsByVariant[$v['id']] ?? [];
      $variantsByArticle[$v['article_id']][] = $v;
    }
    foreach ($products as &$p) {
      $p['variants'] = $variantsByArticle[$p['id']] ?? [];
      $p['color_photos'] = $colorsByArticle[$p['id']] ?? [];
    }
    $products = array_values(array_filter($products, fn($p) => count($p['variants']) > 0));
    out($products);
  }

  /* Validation à la volée pendant la saisie côté /shop (avant soumission finale, revalidée dans shop_request). */
  case 'shop_validate_promo': {
    if ($method !== 'GET') fail('Méthode non supportée', 405);
    $promo = validate_promo_code(s($_GET, 'code'));
    if (!$promo) fail('Code promo invalide ou expiré', 404);
    out(['valid' => true, 'code' => $promo['code'], 'discount_percent' => (float) $promo['discount_percent']]);
  }

  /* Même principe que 'request_cart' (panier groupé) mais pour un visiteur non authentifié : l'identité
     (nom, prénom, e-mail, téléphone — tous obligatoires) est saisie librement et stockée sur request_carts,
     le panier est rattaché au compte système guest_user_id(). Un jeton d'annulation à usage unique est
     renvoyé au visiteur pour lui permettre d'annuler sa demande tant qu'elle est en attente (voir shop_cancel). */
  case 'shop_request': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $firstName = s($b, 'first_name'); $lastName = s($b, 'last_name');
    $email = strtolower(s($b, 'email')); $phone = s($b, 'phone');
    if (!$firstName || !$lastName) fail('Nom et prénom requis');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('E-mail valide requis');
    if (!$phone) fail('Numéro de téléphone requis');
    $items = $b['items'] ?? [];
    if (!is_array($items) || !count($items)) fail('Le panier est vide');
    $motive = s($b, 'motive');
    $promoCodeInput = s($b, 'promo_code');
    $promo = $promoCodeInput !== '' ? validate_promo_code($promoCodeInput) : null;
    if ($promoCodeInput !== '' && !$promo) fail('Code promo invalide ou expiré');
    $discountPercent = $promo ? (float) $promo['discount_percent'] : 0;
    $guestId = guest_user_id();
    $token = bin2hex(random_bytes(16));
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO request_carts (requester_id, motive, guest_first_name, guest_last_name, guest_email, guest_phone, cancel_token, source, promo_code, discount_percent) VALUES (?,?,?,?,?,?,?,?,?,?)')
          ->execute([$guestId, $motive, $firstName, $lastName, $email, $phone, $token, 'shop', $promo ? $promo['code'] : '', $discountPercent]);
      $cartId = (int) $pdo->lastInsertId();
      if ($promo) {
        $pdo->prepare('UPDATE promo_codes SET used_count = used_count + 1 WHERE id = ?')->execute([$promo['id']]);
      }
      $insReq = $pdo->prepare('INSERT INTO requests (variant_id, quantity, requester_id, motive, cart_id) VALUES (?,?,?,?,?)');
      $count = 0;
      foreach ($items as $item) {
        $variantId = (int)($item['variant_id'] ?? 0);
        $qty = (float)($item['quantity'] ?? 0);
        if (!$variantId || $qty <= 0) continue;
        // Vérifie que la variante existe, appartient à un article actif et est bien ouverte à la boutique
        // publique (id forgé ou variante réservée envoyée directement à l'API, en dehors du catalogue affiché : ignoré).
        $ok = $pdo->prepare('SELECT v.id FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.id = ? AND v.active = 1 AND a.active = 1 AND v.shop_visible = 1');
        $ok->execute([$variantId]);
        if (!$ok->fetchColumn()) continue;
        $insReq->execute([$variantId, $qty, $guestId, $motive, $cartId]);
        $count++;
      }
      if (!$count) { $pdo->rollBack(); fail('Aucune ligne valide dans le panier'); }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      fail('Envoi de la demande impossible : ' . $e->getMessage(), 500);
    }
    log_activity(null, 'Demande boutique publique créée', "$firstName $lastName ($email) — panier $cartId ($count article(s))");
    out(['ok' => true, 'cart_id' => $cartId, 'cancel_token' => $token]);
  }

  /* Annulation self-service par le visiteur, authentifiée par le jeton reçu à la soumission (pas de compte).
     Refusée si le staff a déjà commencé à traiter une des lignes (statut != pending). */
  case 'shop_cancel': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $cartId = i($b, 'cart_id'); $token = s($b, 'token');
    if (!$cartId || !$token) fail('Requête invalide');
    $st = db()->prepare("SELECT * FROM request_carts WHERE id = ? AND source = 'shop'");
    $st->execute([$cartId]);
    $cart = $st->fetch();
    if (!$cart || $cart['cancel_token'] === '' || !hash_equals((string) $cart['cancel_token'], $token)) fail('Demande introuvable', 404);
    $pending = db()->prepare("SELECT COUNT(*) FROM requests WHERE cart_id = ? AND status != 'pending'");
    $pending->execute([$cartId]);
    if ((int) $pending->fetchColumn() > 0) fail('Cette demande est déjà en cours de traitement, contactez le club pour l\'annuler', 409);
    db()->prepare('DELETE FROM requests WHERE cart_id = ?')->execute([$cartId]);
    if ($cart['promo_code']) {
      db()->prepare('UPDATE promo_codes SET used_count = MAX(0, used_count - 1) WHERE code = ?')->execute([$cart['promo_code']]);
    }
    log_activity(null, 'Demande boutique publique annulée', "Panier $cartId (" . $cart['guest_first_name'] . ' ' . $cart['guest_last_name'] . ')');
    out(['ok' => true]);
  }

  /* Vue staff, ligne 'pending' avec stock boutique suffisant : décrémente le stock et clôt la demande directement
     en 'received' (équivalent à approbation + traitement en un clic, remplace l'ancien double flux approve->order
     pour ce cas). Si le stock est réparti sur plusieurs lieux, consomme lieu par lieu (du plus fourni au moins
     fourni) jusqu'à couvrir la quantité demandée, plutôt que d'exiger un unique lieu suffisant à lui seul. */
  case 'request_fulfill_stock': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $reqId = i($b, 'request_id');
    $r = db()->query("SELECT * FROM requests WHERE id=$reqId")->fetch();
    if (!$r) fail('Demande introuvable', 404);
    if ($r['status'] !== 'pending') fail('Cette demande n\'est plus en attente');
    $variantId = (int) $r['variant_id']; $needed = (float) $r['quantity'];
    $stocksSt = db()->prepare("SELECT location_id, quantity FROM stock_levels WHERE variant_id = ? AND usage = 'boutique' AND quantity > 0 ORDER BY quantity DESC");
    $stocksSt->execute([$variantId]);
    $rows = $stocksSt->fetchAll();
    $totalAvailable = array_sum(array_column($rows, 'quantity'));
    if ($totalAvailable < $needed - 1e-9) fail('Stock boutique insuffisant pour satisfaire cette demande', 409);
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $remaining = $needed;
      foreach ($rows as $row) {
        if ($remaining <= 1e-9) break;
        $take = min((float) $row['quantity'], $remaining);
        apply_stock_movement($variantId, (int) $row['location_id'], 'exit', $take, 'Demande satisfaite depuis le stock boutique', 'demande', $reqId, (int) $u['id'], 'boutique');
        $remaining -= $take;
      }
      $pdo->prepare("UPDATE requests SET status='received', reviewed_by=?, reviewed_at=datetime('now') WHERE id=?")->execute([$u['id'], $reqId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      fail('Ajout depuis le stock impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Demande satisfaite depuis le stock', "ID $reqId");
    out(['ok' => true]);
  }

  /* Vue staff, ligne 'pending' sans stock boutique suffisant : rattache la demande à un brouillon de commande
     fournisseur existant (même fournisseur préféré de l'article, statut draft, le plus récent), ou en crée un
     nouveau si aucun n'existe. Fusionne avec une ligne existante pour la même variante dans ce brouillon (sans
     recalculer son prix unitaire) plutôt que de dupliquer la ligne. Passe la demande en 'ordered'. */
  case 'request_to_order': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $reqId = i($b, 'request_id');
    $r = db()->query("SELECT * FROM requests WHERE id=$reqId")->fetch();
    if (!$r) fail('Demande introuvable', 404);
    if ($r['status'] !== 'pending') fail('Cette demande n\'est plus en attente');
    $v = db()->query("SELECT v.id AS variant_id, a.supplier_id, a.purchase_price, a.department_id FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.id={$r['variant_id']}")->fetch();
    if (!$v || !$v['supplier_id']) fail('L\'article n\'a pas de fournisseur préféré défini');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $draft = $pdo->query("SELECT id FROM purchase_orders WHERE status='draft' AND supplier_id={$v['supplier_id']} ORDER BY id DESC LIMIT 1")->fetch();
      if ($draft) {
        $orderId = (int) $draft['id'];
      } else {
        $orderDate = date('Y-m-d');
        $pdo->prepare('INSERT INTO purchase_orders (supplier_id, order_date, created_by) VALUES (?,?,?)')->execute([$v['supplier_id'], $orderDate, $u['id']]);
        $orderId = (int) $pdo->lastInsertId();
        assign_order_season($pdo, $orderId, '', $orderDate);
      }
      $existingLine = $pdo->query("SELECT id FROM purchase_order_lines WHERE order_id=$orderId AND variant_id={$v['variant_id']}")->fetch();
      if ($existingLine) {
        $pdo->prepare('UPDATE purchase_order_lines SET quantity_ordered = quantity_ordered + ? WHERE id = ?')
            ->execute([$r['quantity'], $existingLine['id']]);
      } else {
        $pdo->prepare('INSERT INTO purchase_order_lines (order_id, variant_id, quantity_ordered, unit_price) VALUES (?,?,?,?)')
            ->execute([$orderId, $v['variant_id'], $r['quantity'], $v['purchase_price']]);
      }
      // Pré-répartition budgétaire automatique (best-effort, TVA incluse) sur le département de l'article,
      // ajustable ensuite depuis la fiche commande. Sans département sur l'article, la commande reste
      // incomplète et sa sortie de brouillon sera bloquée tant qu'elle n'est pas répartie manuellement.
      if ($v['department_id']) {
        $vatRate = (float) setting('vat_rate', '8.1');
        $amountTtc = round($r['quantity'] * $v['purchase_price'] * (1 + $vatRate / 100), 2);
        $pdo->prepare('INSERT INTO purchase_order_budget_allocations (order_id, department_id, amount) VALUES (?,?,?)
                       ON CONFLICT(order_id, department_id) DO UPDATE SET amount = amount + excluded.amount')
            ->execute([$orderId, $v['department_id'], $amountTtc]);
      }
      $pdo->prepare('INSERT OR IGNORE INTO purchase_order_requests (order_id, request_id) VALUES (?,?)')->execute([$orderId, $reqId]);
      $pdo->prepare("UPDATE requests SET status='ordered', reviewed_by=?, reviewed_at=datetime('now') WHERE id=?")->execute([$u['id'], $reqId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      fail('Ajout à la commande impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Demande ajoutée à une commande', "ID $reqId vers commande $orderId");
    out(['ok' => true, 'order_id' => $orderId]);
  }

  /* ============ COMMANDES FOURNISSEURS ============ */

  case 'orders': {
    $u = require_auth();
    if ($method === 'GET') {
      $id = i($_GET, 'id');
      if ($id) {
        $o = db()->query("SELECT po.*, s.name AS supplier_name,
                           COALESCE(sc.email, s.email) AS supplier_email,
                           COALESCE(sa.address, s.address) AS supplier_address,
                           COALESCE(sc.phone, s.phone) AS supplier_phone,
                           us.name AS created_by_name
                           FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                           LEFT JOIN users us ON us.id = po.created_by
                           LEFT JOIN supplier_addresses sa ON sa.id = (SELECT id FROM supplier_addresses WHERE supplier_id = s.id ORDER BY id LIMIT 1)
                           LEFT JOIN supplier_contacts sc ON sc.id = (SELECT id FROM supplier_contacts WHERE supplier_id = s.id ORDER BY id LIMIT 1)
                           WHERE po.id=$id")->fetch();
        if (!$o) fail('Commande introuvable', 404);
        $o['lines'] = db()->query("SELECT pol.*, a.name AS article_name, a.article_number, a.unit, v.label AS variant_label, v.barcode
                                    FROM purchase_order_lines pol
                                    JOIN article_variants v ON v.id = pol.variant_id
                                    JOIN articles a ON a.id = v.article_id
                                    WHERE pol.order_id=$id")->fetchAll();
        $o['requests'] = db()->query("SELECT r.* FROM purchase_order_requests por JOIN requests r ON r.id = por.request_id WHERE por.order_id=$id")->fetchAll();
        $o['budget_allocations'] = db()->query("SELECT poba.*, d.name AS department_name FROM purchase_order_budget_allocations poba JOIN departments d ON d.id = poba.department_id WHERE poba.order_id=$id")->fetchAll();
        $o['invoices'] = db()->query("SELECT * FROM invoices WHERE order_id=$id ORDER BY created_at DESC")->fetchAll();
        foreach ($o['invoices'] as &$inv) {
          $inv['lines'] = db()->query("SELECT il.*, a.name AS article_name, a.article_number, v.label AS variant_label
                                        FROM invoice_lines il
                                        JOIN article_variants v ON v.id = il.variant_id
                                        JOIN articles a ON a.id = v.article_id
                                        WHERE il.invoice_id={$inv['id']}")->fetchAll();
          $inv['anomalies'] = db()->query("SELECT ia.*, a.name AS article_name, a.article_number
                                            FROM invoice_anomalies ia
                                            LEFT JOIN article_variants v ON v.id = ia.variant_id
                                            LEFT JOIN articles a ON a.id = v.article_id
                                            WHERE ia.invoice_id={$inv['id']} ORDER BY ia.resolved, ia.created_at")->fetchAll();
        }
        unset($inv);
        // Totaux HT / TVA / TTC (unit_price des lignes de commande est HT).
        $totalHt = (float) db()->query("SELECT COALESCE(SUM(quantity_ordered * unit_price),0) FROM purchase_order_lines WHERE order_id=$id")->fetchColumn();
        $vatRate = (float) setting('vat_rate', '8.1');
        $o['total_ht'] = $totalHt;
        $o['vat_rate'] = $vatRate;
        $o['vat_amount'] = round($totalHt * $vatRate / 100, 2);
        $o['total_ttc'] = round($totalHt + $o['vat_amount'], 2);
        $o['order_number'] = format_order_number($o['season'], $o['order_seq']);
        out($o);
      }
      $rows = db()->query('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC')->fetchAll();
      $vatRateList = (float) setting('vat_rate', '8.1');
      foreach ($rows as &$r) {
        $totalHt = (float) db()->query("SELECT COALESCE(SUM(quantity_ordered * unit_price),0) FROM purchase_order_lines WHERE order_id={$r['id']}")->fetchColumn();
        // Total TTC affiché dans la liste, cohérent avec la fiche détail (o.total_ttc) et la répartition budgétaire.
        $r['total'] = round($totalHt * (1 + $vatRateList / 100), 2);
        $r['order_number'] = format_order_number($r['season'], $r['order_seq']);
      }
      out($rows);
    }
    if ($method === 'POST') {
      $supplierId = i($b, 'supplier_id'); $lines = $b['lines'] ?? [];
      if (!$supplierId || !is_array($lines) || !count($lines)) fail('Fournisseur et au moins une ligne requis');
      // Validé avant l'ouverture de la transaction : fail() termine la requête immédiatement (exit), on ne veut
      // pas planter avec une transaction SQLite ouverte.
      $totalHt = 0.0;
      foreach ($lines as $l) {
        $qty = (float)($l['quantity'] ?? 0);
        if ((int)($l['variant_id'] ?? 0) && $qty > 0) $totalHt += $qty * (float)($l['unit_price'] ?? 0);
      }
      // La répartition budgétaire porte sur le total TTC (TVA incluse), pas le HT des lignes.
      $vatRate = (float) setting('vat_rate', '8.1');
      $totalTtc = round($totalHt * (1 + $vatRate / 100), 2);
      $allocations = validate_allocations(is_array($b['allocations'] ?? null) ? $b['allocations'] : [], $totalTtc);
      $orderDate = s($b, 'order_date') ?: date('Y-m-d');
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $pdo->prepare('INSERT INTO purchase_orders (supplier_id, order_date, expected_date, created_by, notes) VALUES (?,?,?,?,?)')
            ->execute([$supplierId, $orderDate, s($b, 'expected_date') ?: null, $u['id'], s($b, 'notes')]);
        $orderId = (int) $pdo->lastInsertId();
        assign_order_season($pdo, $orderId, s($b, 'season'), $orderDate);
        $insLine = $pdo->prepare('INSERT INTO purchase_order_lines (order_id, variant_id, quantity_ordered, unit_price) VALUES (?,?,?,?)');
        foreach ($lines as $l) {
          $vid = (int)($l['variant_id'] ?? 0); $qty = (float)($l['quantity'] ?? 0);
          if (!$vid || $qty <= 0) continue;
          $insLine->execute([$orderId, $vid, $qty, (float)($l['unit_price'] ?? 0)]);
        }
        $insAlloc = $pdo->prepare('INSERT INTO purchase_order_budget_allocations (order_id, department_id, amount) VALUES (?,?,?)');
        foreach ($allocations as $a) $insAlloc->execute([$orderId, $a['department_id'], $a['amount']]);
        $reqIds = $b['request_ids'] ?? [];
        if (is_array($reqIds)) {
          $linkReq = $pdo->prepare('INSERT OR IGNORE INTO purchase_order_requests (order_id, request_id) VALUES (?,?)');
          $updReq = $pdo->prepare("UPDATE requests SET status='ordered' WHERE id=? AND status='approved'");
          foreach ($reqIds as $rid) { $linkReq->execute([$orderId, (int)$rid]); $updReq->execute([(int)$rid]); }
        }
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        fail('Création de la commande impossible : ' . $e->getMessage(), 500);
      }
      log_activity($u['id'], 'Commande créée', "ID $orderId");
      out(['ok' => true, 'id' => $orderId]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id'); $status = s($b, 'status');
      $vatRate = (float) setting('vat_rate', '8.1');
      $current = db()->query("SELECT status, order_date, created_at FROM purchase_orders WHERE id=$id")->fetch();
      if (!$current) fail('Commande introuvable', 404);
      // Édition du contenu (lignes, dates, notes, fournisseur) : uniquement tant que la commande est en brouillon.
      // Le changement de statut lui-même (draft -> sent -> ...) reste toujours autorisé indépendamment.
      $contentEdit = isset($b['order_date']) || isset($b['expected_date']) || isset($b['notes']) || isset($b['supplier_id']) || isset($b['lines']);
      if ($contentEdit && $current['status'] !== 'draft') {
        fail('Seule une commande en brouillon peut être modifiée');
      }
      $fields = []; $vals = [];
      if ($status) {
        if (!in_array($status, ['draft', 'sent', 'confirmed', 'received_partial', 'received_total', 'cancelled'], true)) fail('Statut invalide');
        // Une commande créée automatiquement (depuis une demande, sans passer par le formulaire) peut encore
        // n'avoir aucune répartition budgétaire : on bloque la sortie du brouillon tant qu'elle n'est pas complète,
        // plutôt que de laisser une commande envoyée disparaître silencieusement du suivi budgétaire. L'annulation
        // reste toujours possible même incomplète, puisqu'elle sort la commande du suivi budgétaire (voir requête
        // 'spent' : les commandes annulées sont exclues comme les brouillons).
        if ($status !== 'draft' && $status !== 'cancelled' && $current['status'] === 'draft' && !isset($b['lines'])) {
          $totalHt = (float) db()->query("SELECT COALESCE(SUM(quantity_ordered * unit_price),0) FROM purchase_order_lines WHERE order_id=$id")->fetchColumn();
          $totalTtc = round($totalHt * (1 + $vatRate / 100), 2);
          $allocated = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM purchase_order_budget_allocations WHERE order_id=$id")->fetchColumn();
          if (abs($allocated - $totalTtc) > 0.01) fail('Complète la répartition budgétaire de cette commande (colonne de droite) avant de l\'envoyer');
        }
        if ($status === 'cancelled') {
          // Annulation : gérée intégralement dans sa propre transaction (statut + reversion du stock si la
          // commande avait déjà été réceptionnée), donc pas ajoutée à $fields comme les autres champs qui,
          // eux, partagent une même UPDATE non transactionnelle plus bas.
          $pdo = db();
          $pdo->beginTransaction();
          try {
            if (in_array($current['status'], ['received_partial', 'received_total'], true)) {
              // Reversion : une sortie de stock miroir pour chaque entrée créée par la réception de cette
              // commande (voir orders_receive / orders_receive_scan, ref_type='reception'), regroupée par
              // variante/lieu/usage. Échoue (fail → 409) si une partie de ce stock a déjà été consommée
              // ailleurs (vente, dispatch...) : l'annulation est alors bloquée plutôt que de faire passer le
              // stock en négatif, il faut d'abord régulariser le stock manuellement.
              $movs = $pdo->query("SELECT variant_id, location_id, usage, SUM(quantity) AS qty FROM stock_movements
                                    WHERE ref_type = 'reception' AND ref_id = $id AND type = 'entry'
                                    GROUP BY variant_id, location_id, usage")->fetchAll();
              foreach ($movs as $m) {
                apply_stock_movement((int)$m['variant_id'], (int)$m['location_id'], 'exit', (float)$m['qty'], 'Annulation commande', 'order_cancel', $id, (int)$u['id'], $m['usage']);
              }
              $pdo->exec("UPDATE purchase_order_lines SET quantity_received = 0 WHERE order_id = $id");
            }
            // Les demandes liées repassent en 'approved' : elles restent validées par le staff mais redeviennent
            // éligibles à une nouvelle commande (bouton Commander), plutôt que de rester bloquées en 'ordered'
            // sans commande active derrière elles.
            $pdo->prepare("UPDATE requests SET status='approved' WHERE status='ordered' AND id IN (SELECT request_id FROM purchase_order_requests WHERE order_id=?)")->execute([$id]);
            $pdo->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=?")->execute([$id]);
            $pdo->commit();
          } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail('Annulation impossible : ' . $e->getMessage(), 500);
          }
          log_activity($u['id'], 'Commande annulée', "ID $id");
          out(['ok' => true]); // statut déjà appliqué et committé ci-dessus, on s'arrête là
        }
        $fields[] = 'status = ?'; $vals[] = $status;
        if ($status === 'sent') { $fields[] = "sent_at = datetime('now')"; }
      }
      if (isset($b['order_date'])) { $fields[] = 'order_date = ?'; $vals[] = s($b, 'order_date') ?: null; }
      if (isset($b['expected_date'])) { $fields[] = 'expected_date = ?'; $vals[] = s($b, 'expected_date') ?: null; }
      if (isset($b['notes'])) { $fields[] = 'notes = ?'; $vals[] = s($b, 'notes'); }
      if (isset($b['supplier_id'])) { $fields[] = 'supplier_id = ?'; $vals[] = i($b, 'supplier_id'); }
      if ($fields) {
        $vals[] = $id;
        db()->prepare('UPDATE purchase_orders SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
      }
      if (isset($b['lines']) && is_array($b['lines'])) {
        // La répartition budgétaire doit être fournie avec les lignes (le formulaire commande envoie toujours
        // les deux ensemble) : sinon une répartition existante pourrait ne plus correspondre au nouveau total.
        if (!isset($b['allocations'])) fail('Répartition budgétaire requise');
        // Validé avant l'ouverture de la transaction : fail() termine la requête immédiatement (exit).
        $totalHt = 0.0;
        foreach ($b['lines'] as $l) {
          $qty = (float)($l['quantity'] ?? $l['quantity_ordered'] ?? 0);
          if ((int)($l['variant_id'] ?? 0) && $qty > 0) $totalHt += $qty * (float)($l['unit_price'] ?? 0);
        }
        $totalTtc = round($totalHt * (1 + $vatRate / 100), 2);
        $allocations = validate_allocations(is_array($b['allocations']) ? $b['allocations'] : [], $totalTtc);
        $pdo = db();
        $pdo->beginTransaction();
        try {
          $pdo->prepare('DELETE FROM purchase_order_lines WHERE order_id = ?')->execute([$id]);
          $insLine = $pdo->prepare('INSERT INTO purchase_order_lines (order_id, variant_id, quantity_ordered, unit_price) VALUES (?,?,?,?)');
          foreach ($b['lines'] as $l) {
            $vid = (int)($l['variant_id'] ?? 0); $qty = (float)($l['quantity'] ?? $l['quantity_ordered'] ?? 0);
            if (!$vid || $qty <= 0) continue;
            $insLine->execute([$id, $vid, $qty, (float)($l['unit_price'] ?? 0)]);
          }
          $pdo->prepare('DELETE FROM purchase_order_budget_allocations WHERE order_id = ?')->execute([$id]);
          $insAlloc = $pdo->prepare('INSERT INTO purchase_order_budget_allocations (order_id, department_id, amount) VALUES (?,?,?)');
          foreach ($allocations as $a) $insAlloc->execute([$id, $a['department_id'], $a['amount']]);
          $pdo->commit();
        } catch (Exception $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          fail('Modification des lignes impossible : ' . $e->getMessage(), 500);
        }
      }
      // Répartition budgétaire seule (sans toucher aux lignes) : autorisée quel que soit le statut de la
      // commande, contrairement au reste du contenu — utile pour corriger l'attribution après envoi.
      if (isset($b['allocations']) && !isset($b['lines'])) {
        $totalHt = (float) db()->query("SELECT COALESCE(SUM(quantity_ordered * unit_price),0) FROM purchase_order_lines WHERE order_id=$id")->fetchColumn();
        $totalTtc = round($totalHt * (1 + $vatRate / 100), 2);
        $allocations = validate_allocations(is_array($b['allocations']) ? $b['allocations'] : [], $totalTtc);
        $pdo = db();
        $pdo->beginTransaction();
        try {
          $pdo->prepare('DELETE FROM purchase_order_budget_allocations WHERE order_id = ?')->execute([$id]);
          $insAlloc = $pdo->prepare('INSERT INTO purchase_order_budget_allocations (order_id, department_id, amount) VALUES (?,?,?)');
          foreach ($allocations as $a) $insAlloc->execute([$id, $a['department_id'], $a['amount']]);
          $pdo->commit();
        } catch (Exception $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          fail('Modification de la répartition impossible : ' . $e->getMessage(), 500);
        }
      }
      // Saison seule (colonne de droite, comme la répartition) : autorisée quel que soit le statut. Reprend
      // un nouveau numéro de séquence dans la saison cible (voir assign_order_season()).
      if (isset($b['season']) && !isset($b['lines'])) {
        assign_order_season(db(), $id, s($b, 'season'), $current['order_date'] ?: $current['created_at']);
      }
      if (!$fields && !isset($b['lines']) && !isset($b['allocations']) && !isset($b['season'])) fail('Rien à modifier');
      log_activity($u['id'], 'Commande modifiée', "ID $id");
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'orders_quick_from_request': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $reqId = i($b, 'request_id');
    $r = db()->query("SELECT * FROM requests WHERE id=$reqId")->fetch();
    if (!$r) fail('Demande introuvable', 404);
    $v = db()->query("SELECT v.*, a.supplier_id, a.purchase_price, a.department_id FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.id={$r['variant_id']}")->fetch();
    if (!$v || !$v['supplier_id']) fail('L\'article n\'a pas de fournisseur préféré défini');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $orderDate = date('Y-m-d');
      $pdo->prepare('INSERT INTO purchase_orders (supplier_id, order_date, created_by) VALUES (?,?,?)')->execute([$v['supplier_id'], $orderDate, $u['id']]);
      $orderId = (int) $pdo->lastInsertId();
      assign_order_season($pdo, $orderId, '', $orderDate);
      $pdo->prepare('INSERT INTO purchase_order_lines (order_id, variant_id, quantity_ordered, unit_price) VALUES (?,?,?,?)')
          ->execute([$orderId, $v['id'], $r['quantity'], $v['purchase_price']]);
      // Pré-répartition budgétaire automatique (best-effort, TVA incluse), voir commentaire équivalent dans request_to_order.
      if ($v['department_id']) {
        $vatRate = (float) setting('vat_rate', '8.1');
        $amountTtc = round($r['quantity'] * $v['purchase_price'] * (1 + $vatRate / 100), 2);
        $pdo->prepare('INSERT INTO purchase_order_budget_allocations (order_id, department_id, amount) VALUES (?,?,?)')
            ->execute([$orderId, $v['department_id'], $amountTtc]);
      }
      $pdo->prepare('INSERT INTO purchase_order_requests (order_id, request_id) VALUES (?,?)')->execute([$orderId, $reqId]);
      $pdo->prepare("UPDATE requests SET status='ordered' WHERE id=?")->execute([$reqId]);
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Création rapide impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Commande rapide créée', "Depuis demande $reqId");
    out(['ok' => true, 'id' => $orderId]);
  }

  case 'orders_receive': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $orderId = i($b, 'order_id'); $locationId = i($b, 'location_id'); $lines = $b['lines'] ?? [];
    if (!$orderId || !$locationId || !is_array($lines)) fail('Commande, lieu de réception et lignes requis');
    $pdo = db();
    $pdo->beginTransaction();
    // Détail des variantes réceptionnées lors de cet appel (avec code-barres), pour permettre au front
    // de proposer automatiquement l'impression des étiquettes correspondantes après validation.
    $received = [];
    try {
      foreach ($lines as $l) {
        $lineId = (int)($l['line_id'] ?? 0); $qty = (float)($l['quantity_received'] ?? 0);
        if (!$lineId || $qty <= 0) continue;
        $line = $pdo->query("SELECT * FROM purchase_order_lines WHERE id=$lineId")->fetch();
        if (!$line) continue;
        $pdo->prepare('UPDATE purchase_order_lines SET quantity_received = quantity_received + ? WHERE id = ?')->execute([$qty, $lineId]);
        // usage 'non_affecte' : le stock reçu n'est pas encore réparti boutique/équipement (fait par un futur écran "Dispatcher").
        apply_stock_movement((int)$line['variant_id'], $locationId, 'entry', $qty, 'Réception commande', 'reception', $orderId, (int)$u['id'], 'non_affecte');
        $variant = $pdo->query("SELECT v.barcode, v.label, a.name AS article_name, a.sale_price_ttc FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.id=" . (int)$line['variant_id'])->fetch();
        if ($variant && $variant['barcode'] !== '') {
          $received[] = [
            'variant_id'     => (int)$line['variant_id'],
            'quantity'       => $qty,
            'barcode'        => $variant['barcode'],
            'display_name'   => $variant['article_name'] . ($variant['label'] && $variant['label'] !== 'Standard' ? ' — ' . $variant['label'] : ''),
            'sale_price_ttc' => (float)$variant['sale_price_ttc'],
          ];
        }
      }
      $totals = $pdo->query("SELECT SUM(quantity_ordered) AS o, SUM(quantity_received) AS r FROM purchase_order_lines WHERE order_id=$orderId")->fetch();
      $newStatus = ((float)$totals['r'] >= (float)$totals['o']) ? 'received_total' : 'received_partial';
      $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$newStatus, $orderId]);
      if ($newStatus === 'received_total') {
        $pdo->exec("UPDATE requests SET status='received' WHERE id IN (SELECT request_id FROM purchase_order_requests WHERE order_id=$orderId)");
      }
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Réception impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Réception commande', "ID $orderId");
    out(['ok' => true, 'status' => $newStatus, 'received' => $received]);
  }

  // Réception unitaire par scan de code-barres : incrémente quantity_received de 1 pour la ligne correspondante.
  // Choix : le dépassement de la quantité commandée est autorisé (utile en cas de sur-livraison réelle du fournisseur)
  // mais signalé au client via 'exceeded' => true pour affichage d'un avertissement, plutôt que d'être bloqué.
  case 'orders_receive_scan': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $orderId = i($b, 'order_id'); $barcode = s($b, 'barcode'); $locationId = i($b, 'location_id');
    if (!$orderId || !$barcode || !$locationId) fail('Commande, code-barres et lieu de réception requis');
    $vst = db()->prepare('SELECT id FROM article_variants WHERE barcode = ?');
    $vst->execute([$barcode]);
    $vid = (int) $vst->fetchColumn();
    if (!$vid) fail('Aucune variante pour ce code-barres', 404);
    $line = db()->query("SELECT * FROM purchase_order_lines WHERE order_id=$orderId AND variant_id=$vid")->fetch();
    if (!$line) fail("Cet article ne fait pas partie de cette commande", 404);
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE purchase_order_lines SET quantity_received = quantity_received + 1 WHERE id = ?')->execute([$line['id']]);
      apply_stock_movement($vid, $locationId, 'entry', 1, 'Réception commande (scan)', 'reception', $orderId, (int)$u['id'], 'non_affecte');
      $totals = $pdo->query("SELECT SUM(quantity_ordered) AS o, SUM(quantity_received) AS r FROM purchase_order_lines WHERE order_id=$orderId")->fetch();
      $newStatus = ((float)$totals['r'] >= (float)$totals['o']) ? 'received_total' : 'received_partial';
      $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$newStatus, $orderId]);
      if ($newStatus === 'received_total') {
        $pdo->exec("UPDATE requests SET status='received' WHERE id IN (SELECT request_id FROM purchase_order_requests WHERE order_id=$orderId)");
      }
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Réception impossible : ' . $e->getMessage(), 500);
    }
    $newQtyReceived = (float)$line['quantity_received'] + 1;
    log_activity($u['id'], 'Réception scan', "Commande $orderId, variante $vid");
    // Variante scannée, renvoyée pour permettre au front d'accumuler les quantités reçues
    // en vue de la préparation automatique des étiquettes après validation de la réception.
    $variant = db()->query("SELECT v.label, a.name AS article_name, a.sale_price_ttc FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.id=$vid")->fetch();
    out([
      'ok' => true,
      'line_id' => (int) $line['id'],
      'variant_id' => $vid,
      'quantity_received' => $newQtyReceived,
      'quantity_ordered' => (float) $line['quantity_ordered'],
      'exceeded' => $newQtyReceived > (float)$line['quantity_ordered'],
      'status' => $newStatus,
      'barcode' => $barcode,
      'display_name' => $variant ? $variant['article_name'] . ($variant['label'] && $variant['label'] !== 'Standard' ? ' — ' . $variant['label'] : '') : '',
      'sale_price_ttc' => (float) ($variant['sale_price_ttc'] ?? 0),
    ]);
  }

  case 'order_export_csv': {
    $u = require_auth();
    $id = i($_GET, 'id');
    $o = db()->query("SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id=$id")->fetch();
    if (!$o) fail('Commande introuvable', 404);
    $lines = db()->query("SELECT pol.*, a.article_number, a.name AS article_name, v.label AS variant_label, v.barcode
                           FROM purchase_order_lines pol
                           JOIN article_variants v ON v.id = pol.variant_id
                           JOIN articles a ON a.id = v.article_id
                           WHERE pol.order_id=$id")->fetchAll();
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=UTF-8');
    $orderNumber = format_order_number($o['season'], $o['order_seq']);
    header('Content-Disposition: attachment; filename="bon-commande-' . str_replace('/', '-', $orderNumber) . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Bon de commande ' . $orderNumber], ';');
    fputcsv($fh, ['Fournisseur', $o['supplier_name']], ';');
    fputcsv($fh, ['Date de commande', $o['order_date'] ? date('d.m.Y', strtotime($o['order_date'])) : date('d.m.Y')], ';');
    fputcsv($fh, ['Livraison prévue', $o['expected_date'] ?? ''], ';');
    fputcsv($fh, [], ';');
    fputcsv($fh, ['Référence article', 'Désignation', 'Variante', 'Code-barres', 'Quantité commandée', 'Prix unitaire HT', 'Total HT'], ';');
    foreach ($lines as $l) {
      fputcsv($fh, [
        $l['article_number'],
        $l['article_name'],
        $l['variant_label'],
        $l['barcode'],
        $l['quantity_ordered'],
        number_format((float)$l['unit_price'], 2, '.', ''),
        number_format((float)$l['quantity_ordered'] * (float)$l['unit_price'], 2, '.', ''),
      ], ';');
    }
    fclose($fh);
    exit;
  }

  case 'invoice_upload': {
    $u = require_auth();
    if (empty($_FILES['file'])) fail('Aucun fichier reçu');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
    if ($f['size'] > 20 * 1024 * 1024) fail('Fichier trop volumineux (20 Mo max)');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','png','jpg','jpeg'], true)) fail('Format non autorisé (.' . $ext . ')');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); file_put_contents($dir . '/index.html', ''); }
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) fail('Impossible d\'enregistrer le fichier', 500);
    $orderId = i($_POST, 'order_id');
    // Lignes facturées saisies manuellement (pas d'OCR dans ce projet) : JSON [{variant_id, quantity_invoiced, unit_price_invoiced}].
    $linesRaw = $_POST['lines'] ?? '[]';
    $invLines = json_decode((string)$linesRaw, true);
    if (!is_array($invLines)) $invLines = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO invoices (order_id, filename, amount, invoice_date, uploaded_by, status) VALUES (?,?,?,?,?,?)')
          ->execute([$orderId, $stored, (float)($_POST['amount'] ?? 0), $_POST['invoice_date'] ?? null, $u['id'], 'a_controler']);
      $invoiceId = (int) $pdo->lastInsertId();
      $insLine = $pdo->prepare('INSERT INTO invoice_lines (invoice_id, variant_id, quantity_invoiced, unit_price_invoiced) VALUES (?,?,?,?)');
      $poLines = $pdo->query("SELECT * FROM purchase_order_lines WHERE order_id=$orderId")->fetchAll();
      $poByVariant = [];
      foreach ($poLines as $pl) { $poByVariant[(int)$pl['variant_id']] = $pl; }
      $insAnomaly = $pdo->prepare('INSERT INTO invoice_anomalies (invoice_id, variant_id, type, expected_value, actual_value) VALUES (?,?,?,?,?)');
      $anomalyCount = 0;
      foreach ($invLines as $l) {
        $vid = (int)($l['variant_id'] ?? 0);
        if (!$vid) continue;
        $qtyInv = (float)($l['quantity_invoiced'] ?? 0);
        $priceInv = (float)($l['unit_price_invoiced'] ?? 0);
        $insLine->execute([$invoiceId, $vid, $qtyInv, $priceInv]);
        if (!isset($poByVariant[$vid])) {
          $insAnomaly->execute([$invoiceId, $vid, 'unknown_article', '', (string)$vid]);
          $anomalyCount++;
          continue;
        }
        $pl = $poByVariant[$vid];
        if ((float)$pl['quantity_ordered'] !== $qtyInv) {
          $insAnomaly->execute([$invoiceId, $vid, 'quantity_mismatch', (string)$pl['quantity_ordered'], (string)$qtyInv]);
          $anomalyCount++;
        }
        if (abs((float)$pl['unit_price'] - $priceInv) > 0.005) {
          $insAnomaly->execute([$invoiceId, $vid, 'price_mismatch', (string)$pl['unit_price'], (string)$priceInv]);
          $anomalyCount++;
        }
      }
      $finalStatus = $anomalyCount > 0 ? 'a_controler' : 'approuvee';
      $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$finalStatus, $invoiceId]);
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Import de la facture impossible : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Facture ajoutée', "Commande $orderId");
    out(['ok' => true, 'id' => $invoiceId, 'status' => $finalStatus, 'anomalies' => $anomalyCount]);
  }

  case 'invoice': {
    $u = require_auth();
    if ($method !== 'PUT') fail('Méthode non supportée', 405);
    $id = i($b, 'id'); $status = s($b, 'status');
    if (!$id || !in_array($status, ['a_controler', 'approuvee', 'en_attente_paiement', 'payee'], true)) fail('Facture et statut valides requis');
    db()->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$status, $id]);
    log_activity($u['id'], 'Statut facture modifié', "Facture $id -> $status");
    out(['ok' => true]);
  }

  case 'invoice_anomaly': {
    $u = require_auth();
    if ($method !== 'PUT') fail('Méthode non supportée', 405);
    $id = i($b, 'id');
    if (!$id) fail('Anomalie requise');
    db()->prepare('UPDATE invoice_anomalies SET resolved=? WHERE id=?')->execute([!empty($b['resolved']) ? 1 : 0, $id]);
    out(['ok' => true]);
  }

  case 'download': {
    $u = require_auth();
    $file = basename(s($_GET, 'file'));
    /* Le droit depend de la NATURE du fichier, pas de l'action. L'ancien modele
       accordait le telechargement de n'importe quel fichier de uploads/ a tous
       les roles, factures fournisseurs incluses. On distingue desormais. */
    $isInvoice = false;
    try {
      $stChk = db()->prepare('SELECT 1 FROM invoices WHERE filename = ? LIMIT 1');
      $stChk->execute([$file]);
      $isInvoice = (bool) $stChk->fetchColumn();
    } catch (Throwable $e) { $isInvoice = true; }  // en cas de doute, on restreint
    mfc_require_perm($isInvoice ? 'commandes.invoices.manage' : 'commandes.catalog.view');
    $path = __DIR__ . '/uploads/' . $file;
    if (!$file || !file_exists($path)) fail('Fichier introuvable', 404);
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($file) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
  }

  case 'backup_db': {
    $u = require_auth();
    db(); // s'assure que la connexion (et le fichier) existe avant la sauvegarde
    if (!file_exists(DB_FILE)) fail('Base de données introuvable', 404);
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="commandes-sauvegarde-' . date('Y-m-d-His') . '.sqlite"');
    header('Content-Length: ' . filesize(DB_FILE));
    readfile(DB_FILE);
    exit;
  }

  /* ============ BUDGETS ============ */

  case 'budgets': {
    $u = require_auth();
    if ($method === 'GET') {
      $fy = s($_GET, 'fiscal_year') ?: fiscal_year();
      $rows = db()->query("SELECT b.*, d.name AS department_name FROM budgets b JOIN departments d ON d.id = b.department_id WHERE b.fiscal_year = '$fy'")->fetchAll();
      foreach ($rows as &$r) {
        // Dépensé = somme des répartitions budgétaires (saisies sur chaque commande, voir case 'orders'), des
        // commandes explicitement affiliées à cette saison (po.season), pas d'une plage de dates : une commande
        // peut être ré-affiliée manuellement à une autre saison que celle déduite de sa date (colonne de droite).
        $st = db()->prepare("SELECT COALESCE(SUM(poba.amount),0)
                              FROM purchase_order_budget_allocations poba
                              JOIN purchase_orders po ON po.id = poba.order_id
                              WHERE poba.department_id = ? AND po.status NOT IN ('draft','cancelled') AND po.season = ?");
        $st->execute([$r['department_id'], $fy]);
        $r['spent'] = (float) $st->fetchColumn();
      }
      out(['fiscal_year' => $fy, 'budgets' => $rows]);
    }
    if ($method === 'POST') {
      $depId = i($b, 'department_id'); $fy = s($b, 'fiscal_year') ?: fiscal_year();
      if (!$depId) fail('Département requis');
      try {
        db()->prepare('INSERT INTO budgets (department_id, fiscal_year, amount_allocated) VALUES (?,?,?)')
            ->execute([$depId, $fy, n($b, 'amount_allocated')]);
      } catch (PDOException $e) { fail('Un budget existe déjà pour ce département sur cet exercice'); }
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE budgets SET amount_allocated=? WHERE id=?')->execute([n($b,'amount_allocated'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM budgets WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ RAPPORTS ============ */

  case 'reports': {
    $u = require_auth();
    $from = s($_GET, 'from') ?: '2000-01-01';
    $to = s($_GET, 'to') ?: '2999-12-31';
    $catId = i($_GET, 'category_id');
    $deptId = i($_GET, 'department_id');
    $supplierId = i($_GET, 'supplier_id');
    $locationId = i($_GET, 'location_id');

    $sql = "SELECT po.id AS order_id, po.created_at, po.status, s.name AS supplier_name, a.id AS article_id, a.name AS article_name,
                   v.id AS variant_id, v.label AS variant_label,
                   c.id AS category_id, c.name AS category_name, pol.quantity_ordered, pol.unit_price,
                   (pol.quantity_ordered * pol.unit_price) AS total
            FROM purchase_order_lines pol
            JOIN purchase_orders po ON po.id = pol.order_id
            JOIN suppliers s ON s.id = po.supplier_id
            JOIN article_variants v ON v.id = pol.variant_id
            JOIN articles a ON a.id = v.article_id
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE po.status NOT IN ('draft','cancelled') AND date(po.created_at) BETWEEN ? AND ?";
    $params = [$from, $to];
    // category_id peut désigner une catégorie racine (on inclut alors ses sous-catégories) ou une sous-catégorie précise.
    if ($catId) { $sql .= ' AND (c.id = ? OR c.parent_id = ?)'; $params[] = $catId; $params[] = $catId; }
    if ($deptId) { $sql .= ' AND a.department_id = ?'; $params[] = $deptId; }
    if ($supplierId) { $sql .= ' AND s.id = ?'; $params[] = $supplierId; }
    $st = db()->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll();

    if ($locationId) {
      // Filtre additionnel : uniquement les variantes ayant du stock sur ce lieu
      $variantIds = array_column(db()->query("SELECT DISTINCT variant_id FROM stock_levels WHERE location_id=$locationId")->fetchAll(), 'variant_id');
      $rows = array_values(array_filter($rows, fn($r) => in_array($r['variant_id'], $variantIds)));
    }

    if (s($_GET, 'format') === 'odoo') {
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="export-odoo-' . date('Y-m-d') . '.csv"');
      $out = fopen('php://output', 'w');
      fputcsv($out, ['Date', 'Reference', 'Partner', 'Product', 'Analytic Account', 'Quantity', 'Unit Price', 'Total']);
      foreach ($rows as $r) {
        $product = $r['article_name'] . ($r['variant_label'] && $r['variant_label'] !== 'Standard' ? ' — ' . $r['variant_label'] : '');
        fputcsv($out, [substr($r['created_at'], 0, 10), 'PO' . $r['order_id'], $r['supplier_name'], $product, $r['category_name'] ?? '', $r['quantity_ordered'], $r['unit_price'], $r['total']]);
      }
      fclose($out);
      exit;
    }

    $byCategory = [];
    foreach ($rows as $r) {
      $key = $r['category_name'] ?? 'Sans catégorie';
      $byCategory[$key] = ($byCategory[$key] ?? 0) + $r['total'];
    }
    out(['rows' => $rows, 'by_category' => $byCategory, 'total' => array_sum(array_column($rows, 'total'))]);
  }

  // Valeur de stock actuellement possédée par le club (quantity reflète toujours le stock réel :
  // un prêt d'équipement ne décrémente pas quantity, seul un don le fait via stock_movements type=exit).
  case 'stock_value': {
    $u = require_auth();
    $catId = i($_GET, 'category_id');
    $deptId = i($_GET, 'department_id');

    $sql = "SELECT sl.usage AS usage_type, COALESCE(SUM(sl.quantity * a.purchase_price), 0) AS value, COALESCE(SUM(sl.quantity), 0) AS qty
            FROM stock_levels sl
            JOIN article_variants v ON v.id = sl.variant_id
            JOIN articles a ON a.id = v.article_id
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE 1=1";
    $params = [];
    if ($catId) { $sql .= ' AND (c.id = ? OR c.parent_id = ?)'; $params[] = $catId; $params[] = $catId; }
    if ($deptId) { $sql .= ' AND a.department_id = ?'; $params[] = $deptId; }
    $sql .= ' GROUP BY sl.usage';
    $st = db()->prepare($sql); $st->execute($params);
    $byUsage = $st->fetchAll();

    $total = array_sum(array_column($byUsage, 'value'));
    out(['total' => $total, 'by_usage' => $byUsage]);
  }

  /* ============ ACTIVITÉ & DASHBOARD ============ */

  case 'activity': {
    $u = require_auth();
    out(db()->query('SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 20')->fetchAll());
  }

  case 'dashboard': {
    $u = require_auth();
    $pdo = db();
    $fy = s($_GET, 'fiscal_year') ?: fiscal_year();
    $requestStaleDays = (int) setting('request_stale_days', '5');
    $orderForgottenDays = (int) setting('order_forgotten_days', '7');

    $budgets = $pdo->query("SELECT b.*, d.name AS department_name FROM budgets b JOIN departments d ON d.id = b.department_id WHERE b.fiscal_year = '$fy'")->fetchAll();
    foreach ($budgets as &$bud) {
      $st = $pdo->prepare("SELECT COALESCE(SUM(poba.amount),0)
                            FROM purchase_order_budget_allocations poba
                            JOIN purchase_orders po ON po.id = poba.order_id
                            WHERE poba.department_id = ? AND po.status NOT IN ('draft','cancelled') AND po.season = ?");
      $st->execute([$bud['department_id'], $fy]);
      $bud['spent'] = (float) $st->fetchColumn();
    }

    $alerts = [];
    foreach ($pdo->query("SELECT po.id, po.season, po.order_seq, po.expected_date, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                           WHERE po.status IN ('sent','confirmed') AND po.expected_date IS NOT NULL AND date(po.expected_date) < date('now')") as $o) {
      $orderNumber = format_order_number($o['season'], $o['order_seq']);
      $alerts[] = ['type' => 'reception_retard', 'label' => "Réception en retard — commande $orderNumber ({$o['supplier_name']})", 'ref_id' => $o['id']];
    }
    foreach ($pdo->query("SELECT r.id, r.variant_id, a.name AS article_name FROM requests r
                           JOIN article_variants v ON v.id = r.variant_id JOIN articles a ON a.id = v.article_id
                           WHERE r.status='approved' AND julianday('now') - julianday(r.reviewed_at) > $orderForgottenDays") as $r) {
      $alerts[] = ['type' => 'commande_oubliee', 'label' => "Commande oubliée — demande validée pour {$r['article_name']}", 'ref_id' => $r['id']];
    }
    foreach ($pdo->query("SELECT r.id, r.variant_id, a.name AS article_name FROM requests r
                           JOIN article_variants v ON v.id = r.variant_id JOIN articles a ON a.id = v.article_id
                           WHERE r.status='pending' AND julianday('now') - julianday(r.created_at) > $requestStaleDays") as $r) {
      $alerts[] = ['type' => 'demande_bloquee', 'label' => "Demande bloquée — {$r['article_name']} en attente", 'ref_id' => $r['id']];
    }
    foreach ($budgets as $bud) {
      if ((float)$bud['amount_allocated'] > 0 && $bud['spent'] > (float)$bud['amount_allocated']) {
        $alerts[] = ['type' => 'budget_depasse', 'label' => "Budget dépassé — {$bud['department_name']}", 'ref_id' => $bud['id']];
      }
    }

    $lowStock = $pdo->query("SELECT v.id, (a.name || CASE WHEN v.label != '' AND v.label != 'Standard' THEN ' — ' || v.label ELSE '' END) AS name, v.alert_threshold, COALESCE(SUM(sl.quantity),0) AS total
                              FROM article_variants v
                              JOIN articles a ON a.id = v.article_id
                              LEFT JOIN stock_levels sl ON sl.variant_id = v.id
                              WHERE v.active=1 AND v.alert_threshold > 0
                              GROUP BY v.id HAVING total <= v.alert_threshold")->fetchAll();

    $recentOrders = $pdo->query('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC LIMIT 5')->fetchAll();
    foreach ($recentOrders as &$ro) $ro['order_number'] = format_order_number($ro['season'], $ro['order_seq']);
    out([
      'fiscal_year' => $fy,
      'pending_requests' => (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pending'")->fetchColumn(),
      'orders_in_progress' => (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','sent','confirmed')")->fetchColumn(),
      'budgets' => $budgets,
      'alerts' => $alerts,
      'low_stock' => $lowStock,
      'recent_orders' => $recentOrders,
    ]);
  }

  case 'seasons_list': {
    require_auth();
    out(seasons_list());
  }

  /* Taux de TVA seul, accessible à tout le staff (pas seulement admin, contrairement à 'settings') : nécessaire
   * côté client pour calculer le total TTC d'une commande en cours de saisie, avant tout envoi au serveur. */
  case 'vat_rate': {
    $u = require_auth();
    out(['rate' => (float) setting('vat_rate', '8.1')]);
  }

  /* ============ INTÉGRATION CAISSE ============ */

  /* "Vendable en caisse" ne se base plus sur articles.nature (déprécié) mais sur le stock affecté à
   * l'usage 'boutique' : un article est vendable dès qu'il a été dispatché au moins une fois vers la
   * boutique (une ligne stock_levels usage='boutique' existe pour sa variante, même si la quantité
   * actuelle est retombée à 0 suite aux ventes). */
  case 'vendable_articles': {
    cmd_require_caisse();
    out(db()->query("SELECT v.id, v.barcode, a.sale_price_ttc,
                             (a.name || CASE WHEN v.label != '' AND v.label != 'Standard' THEN ' — ' || v.label ELSE '' END) AS name
                      FROM article_variants v JOIN articles a ON a.id = v.article_id
                      WHERE v.active=1 AND a.active=1 AND v.barcode != ''
                        AND EXISTS (SELECT 1 FROM stock_levels sl WHERE sl.variant_id = v.id AND sl.usage = 'boutique')
                      ORDER BY name COLLATE NOCASE")->fetchAll());
  }

  case 'decrement_stock': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    cmd_require_caisse();
    $barcode = s($b, 'barcode'); $qty = n($b, 'quantity', 1);
    if (!$barcode || $qty <= 0) fail('Code-barres et quantité requis');
    $st = db()->prepare("SELECT v.* FROM article_variants v
                          WHERE v.barcode = ? AND EXISTS (SELECT 1 FROM stock_levels sl WHERE sl.variant_id = v.id AND sl.usage = 'boutique')");
    $st->execute([$barcode]);
    $variant = $st->fetch();
    if (!$variant) fail('Article vendable introuvable pour ce code-barres', 404);
    $locId = i($b, 'location_id') ?: (int) db()->query('SELECT id FROM locations WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$locId) fail('Aucun lieu de stockage disponible', 500);
    apply_stock_movement((int)$variant['id'], $locId, 'exit', $qty, 'Vente Caisse', 'vente', null, null, 'boutique');
    out(['ok' => true]);
  }

  default:
    fail('Action inconnue : ' . $action, 404);
}
