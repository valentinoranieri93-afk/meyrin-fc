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

session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 14,
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* CORS restreint aux endpoints appelés par caisse.meyrinfc.ch (auth par clé API, pas par cookie) */
if (in_array($_GET['action'] ?? '', ['decrement_stock', 'vendable_articles'], true)) {
  header('Access-Control-Allow-Origin: https://caisse.meyrinfc.ch');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');
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
    contact_name TEXT DEFAULT '',
    email TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    address TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    nature TEXT NOT NULL DEFAULT 'interne', -- vendable | interne
    unit TEXT DEFAULT 'pièce',
    photo TEXT DEFAULT '',
    supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL,
    purchase_price REAL NOT NULL DEFAULT 0,
    sale_price_ttc REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
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
  CREATE TABLE IF NOT EXISTS variant_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    attribute_id INTEGER NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
    value TEXT DEFAULT '',
    UNIQUE(variant_id, attribute_id)
  );
  CREATE TABLE IF NOT EXISTS stock_levels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    quantity REAL NOT NULL DEFAULT 0,
    UNIQUE(variant_id, location_id)
  );
  CREATE TABLE IF NOT EXISTS stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES article_variants(id) ON DELETE CASCADE,
    location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    type TEXT NOT NULL, -- entry | exit | adjustment | transfer
    quantity REAL NOT NULL,
    reason TEXT DEFAULT '',
    ref_type TEXT DEFAULT 'manuel', -- reception | demande | vente | manuel | transfert
    ref_id INTEGER DEFAULT NULL,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
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
  CREATE TABLE IF NOT EXISTS purchase_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'draft', -- draft | sent | confirmed | received_partial | received_total
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
  CREATE TABLE IF NOT EXISTS purchase_order_requests (
    order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    PRIMARY KEY (order_id, request_id)
  );
  CREATE TABLE IF NOT EXISTS budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    fiscal_year TEXT NOT NULL,
    amount_allocated REAL NOT NULL DEFAULT 0,
    UNIQUE(category_id, fiscal_year)
  );
  CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    invoice_date TEXT DEFAULT NULL,
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
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

  // Réglages par défaut
  $defaults = [
    'fiscal_year_start_month' => '7',
    'request_stale_days'      => '5',
    'order_forgotten_days'    => '7',
    'caisse_api_key'          => bin2hex(random_bytes(16)),
    'label_format'            => '62x29',
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

function current_user(): ?array {
  if (empty($_SESSION['uid'])) return null;
  $st = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? AND active = 1');
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch();
  return $u ?: null;
}

function require_auth(): array {
  $u = current_user();
  if (!$u) fail('Non authentifié', 401);
  return $u;
}

/** view : les 3 rôles. edit : responsable + admin. admin : réservé à l'admin. */
function require_role(array $u, string $level): void {
  $ok = match ($level) {
    'view'  => in_array($u['role'], ['demandeur', 'responsable', 'admin'], true),
    'edit'  => in_array($u['role'], ['responsable', 'admin'], true),
    'admin' => $u['role'] === 'admin',
    default => false,
  };
  if (!$ok) fail('Droits insuffisants pour cette action', 403);
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

/** Ajuste le stock d'un article à un emplacement (créé la ligne si absente) et journalise le mouvement. */
function apply_stock_movement(int $variantId, int $locationId, string $type, float $qty, string $reason, string $refType, ?int $refId, ?int $userId): void {
  $pdo = db();
  $delta = in_array($type, ['entry'], true) ? $qty : (in_array($type, ['exit'], true) ? -$qty : $qty);
  $pdo->prepare('INSERT INTO stock_levels (variant_id, location_id, quantity) VALUES (?,?,0)
                 ON CONFLICT(variant_id, location_id) DO NOTHING')->execute([$variantId, $locationId]);
  $pdo->prepare('UPDATE stock_levels SET quantity = quantity + ? WHERE variant_id = ? AND location_id = ?')
      ->execute([$delta, $variantId, $locationId]);
  $pdo->prepare('INSERT INTO stock_movements (variant_id, location_id, type, quantity, reason, ref_type, ref_id, user_id) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$variantId, $locationId, $type, $qty, $reason, $refType, $refId, $userId]);
}

/* ---------------------------------------------------------------- Router */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

switch ($action) {

  /* ============ AUTH & SETUP ============ */

  case 'status': {
    $count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    out(['installed' => $count > 0, 'user' => current_user()]);
  }

  case 'setup': {
    $count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) fail('L\'application est déjà installée', 403);
    $name = s($b, 'name'); $email = strtolower(s($b, 'email')); $pass = (string)($b['password'] ?? '');
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Nom et e-mail valides requis');
    if (strlen($pass) < 8) fail('Mot de passe : 8 caractères minimum');
    $st = db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)');
    $st->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
    $_SESSION['uid'] = (int) db()->lastInsertId();
    session_regenerate_id(true);
    log_activity($_SESSION['uid'], 'Installation', 'Compte administrateur créé');
    out(['ok' => true, 'user' => current_user()]);
  }

  case 'login': {
    $email = strtolower(s($b, 'email')); $pass = (string)($b['password'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
      usleep(400000);
      fail('E-mail ou mot de passe incorrect', 401);
    }
    $_SESSION['uid'] = (int) $u['id'];
    session_regenerate_id(true);
    out(['ok' => true, 'user' => current_user()]);
  }

  case 'logout': {
    session_destroy();
    out(['ok' => true]);
  }

  case 'me': {
    out(['user' => require_auth()]);
  }

  case 'change_password': {
    $u = require_auth();
    $old = (string)($b['old'] ?? ''); $new = (string)($b['new'] ?? '');
    if (strlen($new) < 8) fail('Nouveau mot de passe : 8 caractères minimum');
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify($old, (string)$st->fetchColumn())) fail('Mot de passe actuel incorrect', 403);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    out(['ok' => true]);
  }

  /* ============ UTILISATEURS (admin) ============ */

  case 'users': {
    $u = require_auth();
    if ($method === 'GET') {
      require_role($u, 'view');
      out(db()->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY id')->fetchAll());
    }
    require_role($u, 'admin');
    if ($method === 'POST') {
      $name = s($b, 'name'); $email = strtolower(s($b, 'email'));
      $pass = (string)($b['password'] ?? ''); $role = s($b, 'role', 'demandeur');
      if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Nom et e-mail valides requis');
      if (strlen($pass) < 8) fail('Mot de passe : 8 caractères minimum');
      if (!in_array($role, ['admin', 'responsable', 'demandeur'], true)) fail('Rôle invalide');
      try {
        db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
      } catch (PDOException $e) { fail('Cet e-mail est déjà utilisé'); }
      log_activity($u['id'], 'Utilisateur créé', "$name ($role)");
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      $fields = []; $vals = [];
      if (isset($b['name']))  { $fields[] = 'name = ?';  $vals[] = s($b, 'name'); }
      if (isset($b['email'])) { $fields[] = 'email = ?'; $vals[] = strtolower(s($b, 'email')); }
      if (isset($b['role'])) {
        $role = s($b, 'role');
        if (!in_array($role, ['admin', 'responsable', 'demandeur'], true)) fail('Rôle invalide');
        if ($role !== 'admin') {
          $admins = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
          $isAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE id=$id AND role='admin'")->fetchColumn();
          if ($isAdmin && $admins <= 1) fail('Impossible : il doit rester au moins un administrateur');
        }
        $fields[] = 'role = ?'; $vals[] = $role;
      }
      if (isset($b['active'])) {
        $act = i($b, 'active');
        if (!$act) {
          $admins = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
          $isAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE id=$id AND role='admin'")->fetchColumn();
          if ($isAdmin && $admins <= 1) fail('Impossible de désactiver le dernier administrateur');
        }
        $fields[] = 'active = ?'; $vals[] = $act;
      }
      if (isset($b['password']) && $b['password'] !== '') {
        if (strlen((string)$b['password']) < 8) fail('Mot de passe : 8 caractères minimum');
        $fields[] = 'password_hash = ?'; $vals[] = password_hash((string)$b['password'], PASSWORD_DEFAULT);
      }
      if (!$fields) fail('Rien à modifier');
      $vals[] = $id;
      db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
      log_activity($u['id'], 'Utilisateur modifié', "ID $id");
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      if ($id === (int)$u['id']) fail('Vous ne pouvez pas supprimer votre propre compte');
      $isAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE id=$id AND role='admin'")->fetchColumn();
      $admins = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
      if ($isAdmin && $admins <= 1) fail('Impossible de supprimer le dernier administrateur');
      db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
      log_activity($u['id'], 'Utilisateur supprimé', "ID $id");
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ PARAMÈTRES (settings) ============ */

  case 'settings': {
    $u = require_auth();
    if ($method === 'GET') {
      require_role($u, 'admin');
      $rows = db()->query('SELECT key, value FROM settings')->fetchAll();
      $out = [];
      foreach ($rows as $r) $out[$r['key']] = $r['value'];
      out($out);
    }
    require_role($u, 'admin');
    if ($method === 'PUT') {
      foreach (['fiscal_year_start_month', 'request_stale_days', 'order_forgotten_days', 'label_format'] as $k) {
        if (isset($b[$k])) set_setting($k, (string)$b[$k]);
      }
      log_activity($u['id'], 'Paramètres modifiés');
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ CATÉGORIES ============ */

  case 'categories': {
    $u = require_auth();
    if ($method === 'GET') { require_role($u, 'view'); out(db()->query('SELECT * FROM categories ORDER BY name COLLATE NOCASE')->fetchAll()); }
    require_role($u, 'edit');
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      db()->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE categories SET name=? WHERE id=?')->execute([s($b,'name'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM categories WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ATTRIBUTS PERSONNALISÉS ============ */

  case 'attributes': {
    $u = require_auth();
    if ($method === 'GET') {
      require_role($u, 'view');
      $rows = db()->query('SELECT * FROM attributes ORDER BY name COLLATE NOCASE')->fetchAll();
      $values = db()->query('SELECT * FROM attribute_values ORDER BY value COLLATE NOCASE')->fetchAll();
      $byAttr = [];
      foreach ($values as $v) { $byAttr[$v['attribute_id']][] = $v; }
      foreach ($rows as &$r) { $r['values'] = $byAttr[$r['id']] ?? []; }
      out($rows);
    }
    require_role($u, 'edit');
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
    require_role($u, 'edit');
    if ($method === 'POST') {
      $attrId = i($b, 'attribute_id'); $value = s($b, 'value');
      if (!$attrId || !$value) fail('Attribut et valeur requis');
      try {
        db()->prepare('INSERT INTO attribute_values (attribute_id, value) VALUES (?,?)')->execute([$attrId, $value]);
      } catch (PDOException $e) { fail('Cette valeur existe déjà pour cet attribut'); }
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM attribute_values WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ LIEUX DE STOCKAGE ============ */

  case 'locations': {
    $u = require_auth();
    if ($method === 'GET') { require_role($u, 'view'); out(db()->query('SELECT * FROM locations ORDER BY active DESC, name COLLATE NOCASE')->fetchAll()); }
    require_role($u, 'edit');
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
      require_role($u, 'view');
      $rows = db()->query('SELECT * FROM suppliers ORDER BY name COLLATE NOCASE')->fetchAll();
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
      }
      out($rows);
    }
    require_role($u, 'edit');
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Nom requis');
      db()->prepare('INSERT INTO suppliers (name, contact_name, email, phone, address, notes) VALUES (?,?,?,?,?,?)')
          ->execute([$name, s($b,'contact_name'), s($b,'email'), s($b,'phone'), s($b,'address'), s($b,'notes')]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE suppliers SET name=?, contact_name=?, email=?, phone=?, address=?, notes=? WHERE id=?')
          ->execute([s($b,'name'), s($b,'contact_name'), s($b,'email'), s($b,'phone'), s($b,'address'), s($b,'notes'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ ARTICLES (catalogue) ============ */

  case 'articles': {
    $u = require_auth();
    if ($method === 'GET') {
      require_role($u, 'view');
      $products = db()->query('SELECT a.*, c.name AS category_name, s.name AS supplier_name FROM articles a
                            LEFT JOIN categories c ON c.id = a.category_id
                            LEFT JOIN suppliers s ON s.id = a.supplier_id
                            ORDER BY a.name COLLATE NOCASE')->fetchAll();
      $variants = db()->query('SELECT * FROM article_variants ORDER BY label COLLATE NOCASE')->fetchAll();
      $levels = db()->query('SELECT sl.variant_id, sl.location_id, sl.quantity, loc.name AS location_name FROM stock_levels sl JOIN locations loc ON loc.id = sl.location_id')->fetchAll();
      $vAttrs = db()->query('SELECT va.variant_id, va.attribute_id, va.value, at.name AS attribute_name FROM variant_attributes va JOIN attributes at ON at.id = va.attribute_id')->fetchAll();
      $levelsByVariant = []; foreach ($levels as $l) { $levelsByVariant[$l['variant_id']][] = $l; }
      $attrsByVariant = []; foreach ($vAttrs as $va) { $attrsByVariant[$va['variant_id']][] = $va; }
      $variantsByArticle = [];
      foreach ($variants as &$v) {
        $v['stock_by_location'] = $levelsByVariant[$v['id']] ?? [];
        $v['total_stock'] = array_sum(array_column($v['stock_by_location'], 'quantity'));
        $v['attributes'] = $attrsByVariant[$v['id']] ?? [];
        $variantsByArticle[$v['article_id']][] = $v;
      }
      foreach ($products as &$p) {
        $p['variants'] = $variantsByArticle[$p['id']] ?? [];
        $p['total_stock'] = array_sum(array_column($p['variants'], 'total_stock'));
        $p['variant_count'] = count($p['variants']);
      }
      out($products);
    }
    require_role($u, 'edit');
    if ($method === 'POST') {
      $name = s($b, 'name'); if (!$name) fail('Le nom de l\'article est requis');
      $nature = in_array(s($b,'nature'), ['vendable','interne'], true) ? s($b,'nature') : 'interne';
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $pdo->prepare('INSERT INTO articles (name, category_id, nature, unit, supplier_id, purchase_price, sale_price_ttc) VALUES (?,?,?,?,?,?,?)')
            ->execute([$name, i($b,'category_id') ?: null, $nature, s($b,'unit','pièce'), i($b,'supplier_id') ?: null, n($b,'purchase_price'), n($b,'sale_price_ttc')]);
        $articleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO article_variants (article_id, label) VALUES (?, ?)')->execute([$articleId, 'Standard']);
        $variantId = (int) $pdo->lastInsertId();
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        fail('Création impossible : ' . $e->getMessage(), 500);
      }
      log_activity($u['id'], 'Article créé', $name);
      out(['ok' => true, 'id' => $articleId, 'default_variant_id' => $variantId]);
    }
    if ($method === 'PUT') {
      $nature = in_array(s($b,'nature'), ['vendable','interne'], true) ? s($b,'nature') : 'interne';
      db()->prepare('UPDATE articles SET name=?, category_id=?, nature=?, unit=?, supplier_id=?, purchase_price=?, sale_price_ttc=?, active=? WHERE id=?')
          ->execute([s($b,'name'), i($b,'category_id') ?: null, $nature, s($b,'unit','pièce'), i($b,'supplier_id') ?: null, n($b,'purchase_price'), n($b,'sale_price_ttc'), i($b,'active',1), i($b,'id')]);
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
    require_role($u, 'edit');
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $articleId = i($b, 'article_id'); $attributeId = i($b, 'attribute_id');
    $locationId = i($b, 'location_id');
    $items = $b['items'] ?? [];
    if (!$articleId || !$attributeId || !is_array($items) || !count($items)) fail('Article, attribut et au moins une valeur requis');
    $pdo = db();
    $pdo->beginTransaction();
    $created = 0;
    try {
      $insVar = $pdo->prepare('INSERT INTO article_variants (article_id, label, barcode, alert_threshold) VALUES (?,?,?,?)');
      $insAttr = $pdo->prepare('INSERT INTO variant_attributes (variant_id, attribute_id, value) VALUES (?,?,?)');
      foreach ($items as $item) {
        $value = trim((string)($item['value'] ?? ''));
        if ($value === '') continue;
        $barcode = trim((string)($item['barcode'] ?? ''));
        $threshold = (int)($item['alert_threshold'] ?? 0);
        $qty = (float)($item['quantity'] ?? 0);
        try {
          $insVar->execute([$articleId, $value, $barcode, $threshold]);
        } catch (PDOException $e) { fail("Le code-barres « $barcode » est déjà utilisé par une autre variante"); }
        $variantId = (int) $pdo->lastInsertId();
        $insAttr->execute([$variantId, $attributeId, $value]);
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
    require_role($u, 'edit');
    if ($method === 'PUT') {
      $id = i($b, 'id');
      try {
        db()->prepare('UPDATE article_variants SET barcode=?, alert_threshold=?, active=? WHERE id=?')
            ->execute([s($b,'barcode'), i($b,'alert_threshold'), i($b,'active',1), $id]);
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

  case 'article_lookup': {
    $u = require_auth();
    require_role($u, 'view');
    $barcode = s($_GET, 'barcode');
    if (!$barcode) fail('Code-barres requis');
    $st = db()->prepare('SELECT v.*, a.name AS article_name, a.nature, a.category_id FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.barcode = ?');
    $st->execute([$barcode]);
    $v = $st->fetch();
    if (!$v) fail('Aucune variante pour ce code-barres', 404);
    out($v);
  }

  case 'article_photo': {
    $u = require_auth();
    require_role($u, 'edit');
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

  case 'articles_import': {
    $u = require_auth();
    require_role($u, 'edit');
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $rows = $b['articles'] ?? null;
    if (!is_array($rows) || !count($rows)) fail('Aucun article à importer');
    $pdo = db();
    $cats = [];
    foreach ($pdo->query('SELECT id, name FROM categories') as $c) $cats[mb_strtolower(trim($c['name']))] = (int)$c['id'];
    $existingBarcodes = [];
    foreach ($pdo->query("SELECT id, barcode FROM article_variants WHERE barcode != ''") as $v) $existingBarcodes[$v['barcode']] = (int)$v['id'];
    $created = 0; $skipped = 0;
    $pdo->beginTransaction();
    try {
      $insCat = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
      $insArt = $pdo->prepare('INSERT INTO articles (name, category_id, nature, unit, purchase_price, sale_price_ttc) VALUES (?,?,?,?,?,?)');
      $insVar = $pdo->prepare('INSERT INTO article_variants (article_id, label, barcode, alert_threshold) VALUES (?,?,?,?)');
      foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? '')); if ($name === '') { $skipped++; continue; }
        $barcode = trim((string)($row['barcode'] ?? ''));
        if ($barcode !== '' && isset($existingBarcodes[$barcode])) { $skipped++; continue; }
        $catName = trim((string)($row['category'] ?? ''));
        $catId = null;
        if ($catName !== '') {
          $key = mb_strtolower($catName);
          if (!isset($cats[$key])) { $insCat->execute([$catName]); $cats[$key] = (int)$pdo->lastInsertId(); }
          $catId = $cats[$key];
        }
        $nature = in_array($row['nature'] ?? '', ['vendable','interne'], true) ? $row['nature'] : 'interne';
        $insArt->execute([$name, $catId, $nature, (string)($row['unit'] ?? 'pièce'), (float)($row['purchase_price'] ?? 0), (float)($row['sale_price_ttc'] ?? 0)]);
        $articleId = (int) $pdo->lastInsertId();
        $insVar->execute([$articleId, 'Standard', $barcode, (int)($row['alert_threshold'] ?? 0)]);
        if ($barcode !== '') $existingBarcodes[$barcode] = (int)$pdo->lastInsertId();
        $created++;
      }
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Import interrompu, aucune donnée enregistrée : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Import catalogue', "$created créé(s), $skipped ignoré(s)");
    out(['ok' => true, 'created' => $created, 'skipped' => $skipped]);
  }

  case 'article_history': {
    $u = require_auth();
    require_role($u, 'view');
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
    require_role($u, 'view');
    out(db()->query("SELECT sl.*, a.name AS article_name, v.label AS variant_label, v.alert_threshold, loc.name AS location_name
                      FROM stock_levels sl
                      JOIN article_variants v ON v.id = sl.variant_id
                      JOIN articles a ON a.id = v.article_id
                      JOIN locations loc ON loc.id = sl.location_id
                      ORDER BY a.name COLLATE NOCASE, v.label COLLATE NOCASE")->fetchAll());
  }

  case 'stock_movement': {
    $u = require_auth();
    require_role($u, 'edit');
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $variantId = i($b, 'variant_id'); $locationId = i($b, 'location_id');
    $type = s($b, 'type'); $qty = n($b, 'quantity');
    if (!$variantId || !$locationId || $qty <= 0) fail('Variante, lieu et quantité (> 0) requis');
    if (!in_array($type, ['entry', 'exit', 'adjustment'], true)) fail('Type de mouvement invalide');
    apply_stock_movement($variantId, $locationId, $type, $qty, s($b, 'reason'), 'manuel', null, (int)$u['id']);
    log_activity($u['id'], 'Mouvement de stock', "$type de $qty");
    out(['ok' => true]);
  }

  case 'stock_transfer': {
    $u = require_auth();
    require_role($u, 'edit');
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $variantId = i($b, 'variant_id'); $from = i($b, 'from_location_id'); $to = i($b, 'to_location_id'); $qty = n($b, 'quantity');
    if (!$variantId || !$from || !$to || $from === $to || $qty <= 0) fail('Variante, lieux distincts et quantité (> 0) requis');
    apply_stock_movement($variantId, $from, 'exit', $qty, 'Transfert', 'transfert', null, (int)$u['id']);
    apply_stock_movement($variantId, $to, 'entry', $qty, 'Transfert', 'transfert', null, (int)$u['id']);
    log_activity($u['id'], 'Transfert de stock', "$qty");
    out(['ok' => true]);
  }

  /* ============ DEMANDES ============ */

  case 'requests': {
    $u = require_auth();
    if ($method === 'GET') {
      require_role($u, 'view');
      $sql = "SELECT r.*, a.name AS article_name, v.label AS variant_label, v.barcode, req.name AS requester_name, rev.name AS reviewer_name
              FROM requests r
              JOIN article_variants v ON v.id = r.variant_id
              JOIN articles a ON a.id = v.article_id
              JOIN users req ON req.id = r.requester_id
              LEFT JOIN users rev ON rev.id = r.reviewed_by";
      $params = [];
      if ($u['role'] === 'demandeur') { $sql .= ' WHERE r.requester_id = ?'; $params[] = $u['id']; }
      $sql .= " ORDER BY r.status = 'pending' DESC, r.created_at DESC";
      $st = db()->prepare($sql); $st->execute($params);
      out($st->fetchAll());
    }
    require_role($u, 'view');
    if ($method === 'POST') {
      $variantId = i($b, 'variant_id'); $qty = n($b, 'quantity', 1);
      if (!$variantId || $qty <= 0) fail('Variante et quantité (> 0) requis');
      db()->prepare('INSERT INTO requests (variant_id, quantity, requester_id, motive) VALUES (?,?,?,?)')
          ->execute([$variantId, $qty, $u['id'], s($b, 'motive')]);
      log_activity($u['id'], 'Demande créée', "Variante $variantId x$qty");
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      require_role($u, 'edit');
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

  /* ============ COMMANDES FOURNISSEURS ============ */

  case 'orders': {
    $u = require_auth();
    if ($method === 'GET') {
      require_role($u, 'view');
      $id = i($_GET, 'id');
      if ($id) {
        $o = db()->query("SELECT po.*, s.name AS supplier_name, s.email AS supplier_email, s.address AS supplier_address, s.phone AS supplier_phone, us.name AS created_by_name
                           FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                           LEFT JOIN users us ON us.id = po.created_by
                           WHERE po.id=$id")->fetch();
        if (!$o) fail('Commande introuvable', 404);
        $o['lines'] = db()->query("SELECT pol.*, a.name AS article_name, a.unit, v.label AS variant_label, v.barcode
                                    FROM purchase_order_lines pol
                                    JOIN article_variants v ON v.id = pol.variant_id
                                    JOIN articles a ON a.id = v.article_id
                                    WHERE pol.order_id=$id")->fetchAll();
        $o['requests'] = db()->query("SELECT r.* FROM purchase_order_requests por JOIN requests r ON r.id = por.request_id WHERE por.order_id=$id")->fetchAll();
        $o['invoices'] = db()->query("SELECT * FROM invoices WHERE order_id=$id")->fetchAll();
        out($o);
      }
      $rows = db()->query('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC')->fetchAll();
      foreach ($rows as &$r) {
        $r['total'] = (float) db()->query("SELECT COALESCE(SUM(quantity_ordered * unit_price),0) FROM purchase_order_lines WHERE order_id={$r['id']}")->fetchColumn();
      }
      out($rows);
    }
    require_role($u, 'edit');
    if ($method === 'POST') {
      $supplierId = i($b, 'supplier_id'); $lines = $b['lines'] ?? [];
      if (!$supplierId || !is_array($lines) || !count($lines)) fail('Fournisseur et au moins une ligne requis');
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $pdo->prepare('INSERT INTO purchase_orders (supplier_id, expected_date, created_by, notes) VALUES (?,?,?,?)')
            ->execute([$supplierId, s($b, 'expected_date') ?: null, $u['id'], s($b, 'notes')]);
        $orderId = (int) $pdo->lastInsertId();
        $insLine = $pdo->prepare('INSERT INTO purchase_order_lines (order_id, variant_id, quantity_ordered, unit_price) VALUES (?,?,?,?)');
        foreach ($lines as $l) {
          $vid = (int)($l['variant_id'] ?? 0); $qty = (float)($l['quantity'] ?? 0);
          if (!$vid || $qty <= 0) continue;
          $insLine->execute([$orderId, $vid, $qty, (float)($l['unit_price'] ?? 0)]);
        }
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
      $fields = []; $vals = [];
      if ($status) {
        if (!in_array($status, ['draft', 'sent', 'confirmed', 'received_partial', 'received_total'], true)) fail('Statut invalide');
        $fields[] = 'status = ?'; $vals[] = $status;
        if ($status === 'sent') { $fields[] = "sent_at = datetime('now')"; }
      }
      if (isset($b['expected_date'])) { $fields[] = 'expected_date = ?'; $vals[] = s($b, 'expected_date') ?: null; }
      if (isset($b['notes'])) { $fields[] = 'notes = ?'; $vals[] = s($b, 'notes'); }
      if (!$fields) fail('Rien à modifier');
      $vals[] = $id;
      db()->prepare('UPDATE purchase_orders SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
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
    require_role($u, 'edit');
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $reqId = i($b, 'request_id');
    $r = db()->query("SELECT * FROM requests WHERE id=$reqId")->fetch();
    if (!$r) fail('Demande introuvable', 404);
    $v = db()->query("SELECT v.*, a.supplier_id, a.purchase_price FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.id={$r['variant_id']}")->fetch();
    if (!$v || !$v['supplier_id']) fail('L\'article n\'a pas de fournisseur préféré défini');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO purchase_orders (supplier_id, created_by) VALUES (?,?)')->execute([$v['supplier_id'], $u['id']]);
      $orderId = (int) $pdo->lastInsertId();
      $pdo->prepare('INSERT INTO purchase_order_lines (order_id, variant_id, quantity_ordered, unit_price) VALUES (?,?,?,?)')
          ->execute([$orderId, $v['id'], $r['quantity'], $v['purchase_price']]);
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
    require_role($u, 'edit');
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $orderId = i($b, 'order_id'); $locationId = i($b, 'location_id'); $lines = $b['lines'] ?? [];
    if (!$orderId || !$locationId || !is_array($lines)) fail('Commande, lieu de réception et lignes requis');
    $pdo = db();
    $pdo->beginTransaction();
    try {
      foreach ($lines as $l) {
        $lineId = (int)($l['line_id'] ?? 0); $qty = (float)($l['quantity_received'] ?? 0);
        if (!$lineId || $qty <= 0) continue;
        $line = $pdo->query("SELECT * FROM purchase_order_lines WHERE id=$lineId")->fetch();
        if (!$line) continue;
        $pdo->prepare('UPDATE purchase_order_lines SET quantity_received = quantity_received + ? WHERE id = ?')->execute([$qty, $lineId]);
        apply_stock_movement((int)$line['variant_id'], $locationId, 'entry', $qty, 'Réception commande', 'reception', $orderId, (int)$u['id']);
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
    out(['ok' => true, 'status' => $newStatus]);
  }

  case 'invoice_upload': {
    $u = require_auth();
    require_role($u, 'edit');
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
    db()->prepare('INSERT INTO invoices (order_id, filename, amount, invoice_date, uploaded_by) VALUES (?,?,?,?,?)')
        ->execute([i($_POST, 'order_id'), $stored, (float)($_POST['amount'] ?? 0), $_POST['invoice_date'] ?? null, $u['id']]);
    out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
  }

  case 'download': {
    $u = require_auth();
    require_role($u, 'view');
    $file = basename(s($_GET, 'file'));
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
    require_role($u, 'admin');
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
      require_role($u, 'view');
      $fy = s($_GET, 'fiscal_year') ?: fiscal_year();
      [$fyStart, $fyEnd] = fiscal_year_range($fy);
      $rows = db()->query("SELECT b.*, c.name AS category_name FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.fiscal_year = '$fy'")->fetchAll();
      foreach ($rows as &$r) {
        $st = db()->prepare("SELECT COALESCE(SUM(pol.quantity_ordered * pol.unit_price),0)
                              FROM purchase_order_lines pol
                              JOIN article_variants v ON v.id = pol.variant_id
                              JOIN articles a ON a.id = v.article_id
                              JOIN purchase_orders po ON po.id = pol.order_id
                              WHERE a.category_id = ? AND po.status != 'draft' AND date(po.created_at) BETWEEN ? AND ?");
        $st->execute([$r['category_id'], $fyStart, $fyEnd]);
        $r['spent'] = (float) $st->fetchColumn();
      }
      out(['fiscal_year' => $fy, 'budgets' => $rows]);
    }
    require_role($u, 'edit');
    if ($method === 'POST') {
      $catId = i($b, 'category_id'); $fy = s($b, 'fiscal_year') ?: fiscal_year();
      if (!$catId) fail('Catégorie requise');
      try {
        db()->prepare('INSERT INTO budgets (category_id, fiscal_year, amount_allocated) VALUES (?,?,?)')
            ->execute([$catId, $fy, n($b, 'amount_allocated')]);
      } catch (PDOException $e) { fail('Un budget existe déjà pour cette catégorie sur cet exercice'); }
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
    require_role($u, 'view');
    $from = s($_GET, 'from') ?: '2000-01-01';
    $to = s($_GET, 'to') ?: '2999-12-31';
    $catId = i($_GET, 'category_id');
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
            WHERE po.status != 'draft' AND date(po.created_at) BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($catId) { $sql .= ' AND c.id = ?'; $params[] = $catId; }
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

  /* ============ ACTIVITÉ & DASHBOARD ============ */

  case 'activity': {
    $u = require_auth();
    require_role($u, 'view');
    out(db()->query('SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 20')->fetchAll());
  }

  case 'dashboard': {
    $u = require_auth();
    require_role($u, 'view');
    $pdo = db();
    $fy = fiscal_year();
    $requestStaleDays = (int) setting('request_stale_days', '5');
    $orderForgottenDays = (int) setting('order_forgotten_days', '7');

    [$fyStart, $fyEnd] = fiscal_year_range($fy);
    $budgets = $pdo->query("SELECT b.*, c.name AS category_name FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.fiscal_year = '$fy'")->fetchAll();
    foreach ($budgets as &$bud) {
      $st = $pdo->prepare("SELECT COALESCE(SUM(pol.quantity_ordered * pol.unit_price),0)
                            FROM purchase_order_lines pol
                            JOIN article_variants v ON v.id = pol.variant_id
                            JOIN articles a ON a.id = v.article_id
                            JOIN purchase_orders po ON po.id = pol.order_id
                            WHERE a.category_id = ? AND po.status != 'draft' AND date(po.created_at) BETWEEN ? AND ?");
      $st->execute([$bud['category_id'], $fyStart, $fyEnd]);
      $bud['spent'] = (float) $st->fetchColumn();
    }

    $alerts = [];
    foreach ($pdo->query("SELECT po.id, po.expected_date, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                           WHERE po.status IN ('sent','confirmed') AND po.expected_date IS NOT NULL AND date(po.expected_date) < date('now')") as $o) {
      $alerts[] = ['type' => 'reception_retard', 'label' => "Réception en retard — commande #{$o['id']} ({$o['supplier_name']})", 'ref_id' => $o['id']];
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
        $alerts[] = ['type' => 'budget_depasse', 'label' => "Budget dépassé — {$bud['category_name']}", 'ref_id' => $bud['id']];
      }
    }

    $lowStock = $pdo->query("SELECT v.id, (a.name || CASE WHEN v.label != '' AND v.label != 'Standard' THEN ' — ' || v.label ELSE '' END) AS name, v.alert_threshold, COALESCE(SUM(sl.quantity),0) AS total
                              FROM article_variants v
                              JOIN articles a ON a.id = v.article_id
                              LEFT JOIN stock_levels sl ON sl.variant_id = v.id
                              WHERE v.active=1 AND v.alert_threshold > 0
                              GROUP BY v.id HAVING total <= v.alert_threshold")->fetchAll();

    out([
      'fiscal_year' => $fy,
      'pending_requests' => (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pending'")->fetchColumn(),
      'orders_in_progress' => (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','sent','confirmed')")->fetchColumn(),
      'budgets' => $budgets,
      'alerts' => $alerts,
      'low_stock' => $lowStock,
      'recent_orders' => $pdo->query('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC LIMIT 5')->fetchAll(),
    ]);
  }

  /* ============ INTÉGRATION CAISSE ============ */

  case 'vendable_articles': {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key || !hash_equals(setting('caisse_api_key'), $key)) fail('Clé API invalide', 401);
    out(db()->query("SELECT v.id, v.barcode, a.sale_price_ttc,
                             (a.name || CASE WHEN v.label != '' AND v.label != 'Standard' THEN ' — ' || v.label ELSE '' END) AS name
                      FROM article_variants v JOIN articles a ON a.id = v.article_id
                      WHERE a.nature='vendable' AND v.active=1 AND a.active=1 AND v.barcode != ''
                      ORDER BY name COLLATE NOCASE")->fetchAll());
  }

  case 'decrement_stock': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key || !hash_equals(setting('caisse_api_key'), $key)) fail('Clé API invalide', 401);
    $barcode = s($b, 'barcode'); $qty = n($b, 'quantity', 1);
    if (!$barcode || $qty <= 0) fail('Code-barres et quantité requis');
    $st = db()->prepare("SELECT v.* FROM article_variants v JOIN articles a ON a.id = v.article_id WHERE v.barcode = ? AND a.nature = 'vendable'");
    $st->execute([$barcode]);
    $variant = $st->fetch();
    if (!$variant) fail('Article vendable introuvable pour ce code-barres', 404);
    $locId = i($b, 'location_id') ?: (int) db()->query('SELECT id FROM locations WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$locId) fail('Aucun lieu de stockage disponible', 500);
    apply_stock_movement((int)$variant['id'], $locId, 'exit', $qty, 'Vente Caisse', 'vente', null, null);
    out(['ok' => true]);
  }

  default:
    fail('Action inconnue : ' . $action, 404);
}
