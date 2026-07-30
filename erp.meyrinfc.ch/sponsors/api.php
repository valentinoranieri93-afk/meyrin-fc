<?php
/**
 * SponsorFlow — API backend
 * PHP 8.x + SQLite (PDO). Aucune dépendance externe.
 * Compatible hébergement mutualisé Infomaniak.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Capture les erreurs fatales et exceptions pour renvoyer un message JSON exploitable
   (au lieu d'une page vide ou d'un « Erreur serveur » opaque côté interface). */
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
   unique de l'ERP (cookie JWT). Conserver une session PHP ici deviendrait
   d'ailleurs dangereux le jour ou toutes les apps partageront le meme domaine,
   puisqu'elles partageraient alors le meme $_SESSION et se prendraient
   mutuellement pour d'autres utilisateurs. */
require_once __DIR__ . '/mfc_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------------------------------------------------------------- DB */

const DB_DIR = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/sponsorflow.sqlite';

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  if (!is_dir(DB_DIR)) mkdir(DB_DIR, 0775, true);
  // Protège le dossier data/ si .htaccess absent
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
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'viewer', -- admin | editor | viewer
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS sponsors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sector TEXT DEFAULT '',
    tier TEXT DEFAULT 'Bronze',          -- Platine | Or | Argent | Bronze
    status TEXT DEFAULT 'Actif',         -- Actif | Renouvellement | À risque | Inactif
    website TEXT DEFAULT '',
    address TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sponsor_id INTEGER NOT NULL REFERENCES sponsors(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    role TEXT DEFAULT '',
    email TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    is_primary INTEGER NOT NULL DEFAULT 0
  );
  CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT DEFAULT 'Terrain',     -- Terrain | Équipement | Digital | Événement | Hospitalité | Autre
    total_slots INTEGER NOT NULL DEFAULT 1,
    price REAL NOT NULL DEFAULT 0,       -- prix conseillé par emplacement / an
    description TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sponsor_id INTEGER NOT NULL REFERENCES sponsors(id) ON DELETE CASCADE,
    amount REAL NOT NULL DEFAULT 0,
    end_date TEXT DEFAULT NULL
  );
  CREATE TABLE IF NOT EXISTS contracts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sponsor_id INTEGER NOT NULL REFERENCES sponsors(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    type TEXT DEFAULT '',
    amount REAL NOT NULL DEFAULT 0,      -- montant annuel CHF
    start_date TEXT,
    end_date TEXT,
    status TEXT DEFAULT 'En cours',      -- En cours | Archivé
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS opportunities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sector TEXT DEFAULT '',
    value REAL NOT NULL DEFAULT 0,
    stage INTEGER NOT NULL DEFAULT 0,    -- 0..5
    owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    note TEXT DEFAULT '',
    hot INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    detail TEXT DEFAULT '',
    due_date TEXT DEFAULT NULL,
    done INTEGER NOT NULL DEFAULT 0,
    assignee_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    sponsor_id INTEGER REFERENCES sponsors(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sponsor_id INTEGER REFERENCES sponsors(id) ON DELETE SET NULL,
    label TEXT NOT NULL,
    category TEXT DEFAULT 'Autre',
    filename TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0,
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
 * Ligne `users` locale correspondant a la personne connectee.
 *
 * La table `users` n'authentifie plus rien, mais elle reste indispensable :
 * owner_id des opportunites, assignee_id des taches, uploaded_by des documents
 * et user_id du journal pointent dessus. Elle sert donc d'annuaire local, et
 * mfc_local_user() y rattache le compte ERP sans jamais toucher aux lignes
 * existantes.
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

/* require_role() a disparu : les droits ne dependent plus d'un role local
   (viewer/editor/admin) mais des permissions portees par le jeton, verifiees
   en un seul point par la table SPONSORS_PERMS du routeur. */

function log_activity(?int $uid, string $action, string $detail = ''): void {
  $st = db()->prepare('INSERT INTO activity (user_id, action, detail) VALUES (?,?,?)');
  $st->execute([$uid, $action, $detail]);
}

function s(array $b, string $k, string $def = ''): string { return trim((string)($b[$k] ?? $def)); }
function n(array $b, string $k, float $def = 0): float { return (float)($b[$k] ?? $def); }
function i(array $b, string $k, int $def = 0): int { return (int)($b[$k] ?? $def); }

/** Minuscule tolérante aux accents, sans dépendre de l'extension mbstring. */
function lc(string $s): string {
  return strtolower(strtr($s,
    'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ',
    'àáâãäåçèéêëìíîïñòóôõöùúûüý'));
}

/** Statuts de contrat valides. « En cours » reste accepté pour rétrocompatibilité. */
const CONTRACT_STATUSES = ['Contrat à envoyer', 'Facture à envoyer', 'Attente de paiement', 'Actif', 'Archivé', 'En cours'];
function valid_contract_status(string $s, string $default = 'Actif'): string {
  return in_array($s, CONTRACT_STATUSES, true) ? $s : $default;
}

/* ---------------------------------------------------------------- Router */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

/* ---------------------------------------------------------------- Permissions
 *
 * Table déclarative, vérifiée en un seul point plutôt que dans chaque bloc.
 * Format : action => permission, ou ['GET' => perm, 'write' => perm].
 * 'write' couvre POST, PUT, PATCH et DELETE.
 *
 * REFUS PAR DÉFAUT : une action absente de cette table exige partners.edit.
 * Ajouter un point d'entrée sans y penser le rend inaccessible, pas public.
 *
 * Choix à connaître : consulter le pipeline d'opportunités ou les tâches
 * relève de la lecture du CRM (partners.view), alors que les modifier exige
 * la permission dédiée. Sans ça, un profil en lecture seule ne verrait ni
 * le pipeline ni les relances, ce qui viderait son écran d'accueil.
 */
const SPONSORS_PERMS = [
  'me'            => null,
  'users'         => ['GET' => 'partners.view', 'write' => null], // écriture traitée dans le bloc
  'sponsors'      => ['GET' => 'partners.view',  'write' => 'partners.edit'],
  'contacts'      => ['GET' => 'partners.view',  'write' => 'partners.edit'],
  'products'      => ['GET' => 'contracts.view', 'write' => 'contracts.edit'],
  'assignments'   => ['GET' => 'contracts.view', 'write' => 'contracts.edit'],
  'contracts'     => ['GET' => 'contracts.view', 'write' => 'contracts.edit'],
  'opportunities' => ['GET' => 'partners.view',  'write' => 'opportunities.manage'],
  'tasks'         => ['GET' => 'partners.view',  'write' => 'tasks.manage'],
  'documents'     => ['GET' => 'documents.view', 'write' => 'documents.upload'],
  'download'      => 'documents.view',
  'activity'      => 'dashboard.view',
  'dashboard'     => 'dashboard.view',
  'import'        => 'partners.edit',
];

/* Points d'entrée supprimés : l'authentification est désormais centralisée. */
const SPONSORS_GONE = ['login', 'logout', 'setup', 'status', 'change_password'];
if (in_array($action, SPONSORS_GONE, true)) {
    fail("Cette application n'a plus de connexion propre. Utilisez l'ERP : " . ERP_URL, 410);
}

/* Session ERP + accès à l'application. Répond 401/403 en JSON sinon. */
mfc_require_api('sponsors');

$sp_rule = array_key_exists($action, SPONSORS_PERMS) ? SPONSORS_PERMS[$action] : 'partners.edit';
if (is_array($sp_rule)) {
    $sp_rule = ($method === 'GET') ? $sp_rule['GET'] : $sp_rule['write'];
}
if ($sp_rule !== null) {
    mfc_require_perm('sponsors.' . $sp_rule);
}

switch ($action) {

  /* ============ SESSION ============ */

  /* status, setup, login, logout et change_password ont ete supprimes :
     l'authentification est centralisee dans l'ERP. Les appels a ces actions
     sont interceptes plus haut et renvoient un 410 explicite. */

  case 'me': {
    out(['user' => require_auth()]);
  }

  /* ============ UTILISATEURS (admin) ============ */

  /* Annuaire local, en lecture seule : il alimente les listes « responsable »
     et « assigné à ». La création et la modification des comptes se font
     exclusivement dans l'ERP, seul endroit où vit désormais une identité. */
  case 'users': {
    require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY id')->fetchAll());
    }
    fail("Les comptes se gèrent dans l'ERP : " . ERP_URL, 403);
  }

  case 'sponsors': {
    $u = require_auth();
    if ($method === 'GET') {
      $sponsors = db()->query('SELECT * FROM sponsors ORDER BY name COLLATE NOCASE')->fetchAll();
      $contacts = db()->query('SELECT * FROM contacts ORDER BY is_primary DESC, name')->fetchAll();
      $bySponsor = [];
      foreach ($contacts as $c) $bySponsor[$c['sponsor_id']][] = $c;
      foreach ($sponsors as &$sp) {
        $sp['contacts'] = $bySponsor[$sp['id']] ?? [];
        $sp['annual'] = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM contracts WHERE sponsor_id={$sp['id']} AND status != 'Archivé'")->fetchColumn();
        $sp['next_end'] = db()->query("SELECT MIN(end_date) FROM contracts WHERE sponsor_id={$sp['id']} AND status != 'Archivé' AND end_date >= date('now')")->fetchColumn() ?: null;
      }
      out($sponsors);
    }
    if ($method === 'POST') {
      $name = s($b, 'name');
      if (!$name) fail('Le nom du sponsor est requis');
      db()->prepare('INSERT INTO sponsors (name, sector, tier, status, website, address, notes) VALUES (?,?,?,?,?,?,?)')
          ->execute([$name, s($b,'sector'), s($b,'tier','Bronze'), s($b,'status','Actif'), s($b,'website'), s($b,'address'), s($b,'notes')]);
      $id = (int) db()->lastInsertId();
      log_activity($u['id'], 'Sponsor créé', $name);
      out(['ok' => true, 'id' => $id]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      db()->prepare('UPDATE sponsors SET name=?, sector=?, tier=?, status=?, website=?, address=?, notes=? WHERE id=?')
          ->execute([s($b,'name'), s($b,'sector'), s($b,'tier'), s($b,'status'), s($b,'website'), s($b,'address'), s($b,'notes'), $id]);
      log_activity($u['id'], 'Sponsor modifié', s($b,'name'));
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      $name = db()->query("SELECT name FROM sponsors WHERE id=$id")->fetchColumn();
      db()->prepare('DELETE FROM sponsors WHERE id = ?')->execute([$id]);
      log_activity($u['id'], 'Sponsor supprimé', (string)$name);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'contacts': {
    $u = require_auth();
    if ($method === 'POST') {
      $sid = i($b, 'sponsor_id'); $name = s($b, 'name');
      if (!$sid || !$name) fail('Sponsor et nom requis');
      if (i($b, 'is_primary')) db()->prepare('UPDATE contacts SET is_primary=0 WHERE sponsor_id=?')->execute([$sid]);
      db()->prepare('INSERT INTO contacts (sponsor_id, name, role, email, phone, is_primary) VALUES (?,?,?,?,?,?)')
          ->execute([$sid, $name, s($b,'role'), s($b,'email'), s($b,'phone'), i($b,'is_primary')]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      if (i($b, 'is_primary')) {
        $sid = (int) db()->query("SELECT sponsor_id FROM contacts WHERE id=$id")->fetchColumn();
        db()->prepare('UPDATE contacts SET is_primary=0 WHERE sponsor_id=?')->execute([$sid]);
      }
      db()->prepare('UPDATE contacts SET name=?, role=?, email=?, phone=?, is_primary=? WHERE id=?')
          ->execute([s($b,'name'), s($b,'role'), s($b,'email'), s($b,'phone'), i($b,'is_primary'), $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM contacts WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ PRODUITS (supports) & ATTRIBUTIONS ============ */

  case 'products': {
    $u = require_auth();
    if ($method === 'GET') {
      $products = db()->query('SELECT * FROM products ORDER BY category, name')->fetchAll();
      $assign = db()->query('SELECT a.*, s.name AS sponsor_name FROM assignments a JOIN sponsors s ON s.id = a.sponsor_id')->fetchAll();
      $byProduct = [];
      foreach ($assign as $a) $byProduct[$a['product_id']][] = $a;
      foreach ($products as &$p) {
        $p['assignments'] = $byProduct[$p['id']] ?? [];
        $p['used_slots'] = count($p['assignments']);
        $p['revenue'] = array_sum(array_map(fn($a) => (float)$a['amount'], $p['assignments']));
      }
      out($products);
    }
    if ($method === 'POST') {
      $name = s($b, 'name');
      if (!$name) fail('Le nom du support est requis');
      db()->prepare('INSERT INTO products (name, category, total_slots, price, description) VALUES (?,?,?,?,?)')
          ->execute([$name, s($b,'category','Terrain'), max(1, i($b,'total_slots',1)), n($b,'price'), s($b,'description')]);
      log_activity($u['id'], 'Support créé', $name);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE products SET name=?, category=?, total_slots=?, price=?, description=? WHERE id=?')
          ->execute([s($b,'name'), s($b,'category'), max(1, i($b,'total_slots',1)), n($b,'price'), s($b,'description'), i($b,'id')]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM products WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'assignments': {
    $u = require_auth();
    if ($method === 'POST') {
      $pid = i($b, 'product_id'); $sid = i($b, 'sponsor_id');
      if (!$pid || !$sid) fail('Support et sponsor requis');
      $p = db()->query("SELECT total_slots, (SELECT COUNT(*) FROM assignments WHERE product_id=$pid) AS used FROM products WHERE id=$pid")->fetch();
      if (!$p) fail('Support introuvable', 404);
      if ((int)$p['used'] >= (int)$p['total_slots']) fail('Plus d\'emplacement disponible sur ce support');
      db()->prepare('INSERT INTO assignments (product_id, sponsor_id, amount, end_date) VALUES (?,?,?,?)')
          ->execute([$pid, $sid, n($b,'amount'), s($b,'end_date') ?: null]);
      log_activity($u['id'], 'Support attribué', "Produit $pid → sponsor $sid");
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM assignments WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ CONTRATS ============ */

  case 'contracts': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT c.*, s.name AS sponsor_name FROM contracts c JOIN sponsors s ON s.id = c.sponsor_id ORDER BY c.end_date IS NULL, c.end_date')->fetchAll());
    }
    if ($method === 'POST') {
      $sid = i($b, 'sponsor_id'); $label = s($b, 'label');
      if (!$sid || !$label) fail('Sponsor et libellé requis');
      db()->prepare('INSERT INTO contracts (sponsor_id, label, type, amount, start_date, end_date, status) VALUES (?,?,?,?,?,?,?)')
          ->execute([$sid, $label, s($b,'type'), n($b,'amount'), s($b,'start_date') ?: null, s($b,'end_date') ?: null, valid_contract_status(s($b,'status'), 'Contrat à envoyer')]);
      log_activity($u['id'], 'Contrat créé', $label);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      db()->prepare('UPDATE contracts SET sponsor_id=?, label=?, type=?, amount=?, start_date=?, end_date=?, status=? WHERE id=?')
          ->execute([i($b,'sponsor_id'), s($b,'label'), s($b,'type'), n($b,'amount'), s($b,'start_date') ?: null, s($b,'end_date') ?: null, valid_contract_status(s($b,'status')), i($b,'id')]);
      log_activity($u['id'], 'Contrat modifié', s($b,'label'));
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM contracts WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ PIPELINE ============ */

  case 'opportunities': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT o.*, u.name AS owner_name FROM opportunities o LEFT JOIN users u ON u.id = o.owner_id ORDER BY o.stage, o.created_at DESC')->fetchAll());
    }
    if ($method === 'POST') {
      $name = s($b, 'name');
      if (!$name) fail('Le nom de l\'opportunité est requis');
      db()->prepare('INSERT INTO opportunities (name, sector, value, stage, owner_id, note, hot) VALUES (?,?,?,?,?,?,?)')
          ->execute([$name, s($b,'sector'), n($b,'value'), max(0, min(5, i($b,'stage'))), i($b,'owner_id') ?: $u['id'], s($b,'note'), i($b,'hot')]);
      log_activity($u['id'], 'Opportunité créée', $name);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      if (array_keys($b) === ['id', 'stage'] || (isset($b['stage']) && count($b) === 2)) {
        db()->prepare('UPDATE opportunities SET stage=? WHERE id=?')->execute([max(0, min(5, i($b,'stage'))), $id]);
        out(['ok' => true]);
      }
      db()->prepare('UPDATE opportunities SET name=?, sector=?, value=?, stage=?, owner_id=?, note=?, hot=? WHERE id=?')
          ->execute([s($b,'name'), s($b,'sector'), n($b,'value'), max(0, min(5, i($b,'stage'))), i($b,'owner_id') ?: null, s($b,'note'), i($b,'hot'), $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM opportunities WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ TÂCHES ============ */

  case 'tasks': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT t.*, u.name AS assignee_name, s.name AS sponsor_name
                       FROM tasks t LEFT JOIN users u ON u.id = t.assignee_id
                       LEFT JOIN sponsors s ON s.id = t.sponsor_id
                       ORDER BY t.done, t.due_date IS NULL, t.due_date')->fetchAll());
    }
    if ($method === 'POST') {
      $title = s($b, 'title');
      if (!$title) fail('Le titre de la tâche est requis');
      db()->prepare('INSERT INTO tasks (title, detail, due_date, assignee_id, sponsor_id) VALUES (?,?,?,?,?)')
          ->execute([$title, s($b,'detail'), s($b,'due_date') ?: null, i($b,'assignee_id') ?: $u['id'], i($b,'sponsor_id') ?: null]);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'PUT') {
      $id = i($b, 'id');
      if (isset($b['done']) && count($b) === 2) {
        db()->prepare('UPDATE tasks SET done=? WHERE id=?')->execute([i($b,'done'), $id]);
        out(['ok' => true]);
      }
      db()->prepare('UPDATE tasks SET title=?, detail=?, due_date=?, assignee_id=?, sponsor_id=?, done=? WHERE id=?')
          ->execute([s($b,'title'), s($b,'detail'), s($b,'due_date') ?: null, i($b,'assignee_id') ?: null, i($b,'sponsor_id') ?: null, i($b,'done'), $id]);
      out(['ok' => true]);
    }
    if ($method === 'DELETE') {
      db()->prepare('DELETE FROM tasks WHERE id = ?')->execute([i($_GET, 'id')]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  /* ============ DOCUMENTS ============ */

  case 'documents': {
    $u = require_auth();
    if ($method === 'GET') {
      out(db()->query('SELECT d.*, s.name AS sponsor_name, u.name AS uploader
                       FROM documents d LEFT JOIN sponsors s ON s.id = d.sponsor_id
                       LEFT JOIN users u ON u.id = d.uploaded_by
                       ORDER BY d.created_at DESC')->fetchAll());
    }
    if ($method === 'POST') {
      if (empty($_FILES['file'])) fail('Aucun fichier reçu');
      $f = $_FILES['file'];
      if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
      if ($f['size'] > 20 * 1024 * 1024) fail('Fichier trop volumineux (20 Mo max)');
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $allowed = ['pdf','png','jpg','jpeg','webp','xlsx','xls','docx','doc','pptx','zip','csv','svg'];
      if (!in_array($ext, $allowed, true)) fail('Type de fichier non autorisé (.' . $ext . ')');
      $dir = __DIR__ . '/uploads';
      if (!is_dir($dir)) { mkdir($dir, 0775, true); file_put_contents($dir . '/index.html', ''); }
      /* Les fichiers ne sont servis que par l'action `download`, qui revérifie
         le droit documents.view. Interdire l'accès direct compte doublement
         ici : le .svg est accepté à l'envoi, et un SVG ouvert directement dans
         le navigateur peut exécuter du script. La lecture depuis le disque par
         PHP n'est pas concernée par cette règle. */
      if (!file_exists("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\n");
      $stored = bin2hex(random_bytes(12)) . '.' . $ext;
      if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) fail('Impossible d\'enregistrer le fichier', 500);
      db()->prepare('INSERT INTO documents (sponsor_id, label, category, filename, size, uploaded_by) VALUES (?,?,?,?,?,?)')
          ->execute([i($_POST,'sponsor_id') ?: null, s($_POST,'label') ?: $f['name'], s($_POST,'category','Autre'), $stored, (int)$f['size'], $u['id']]);
      log_activity($u['id'], 'Document ajouté', s($_POST,'label') ?: $f['name']);
      out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    if ($method === 'DELETE') {
      $id = i($_GET, 'id');
      $fn = db()->query("SELECT filename FROM documents WHERE id=$id")->fetchColumn();
      if ($fn && file_exists(__DIR__ . "/uploads/$fn")) unlink(__DIR__ . "/uploads/$fn");
      db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
      out(['ok' => true]);
    }
    fail('Méthode non supportée', 405);
  }

  case 'download': {
    $u = require_auth();
    $id = i($_GET, 'id');
    $st = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) fail('Document introuvable', 404);
    $path = __DIR__ . '/uploads/' . basename($d['filename']);
    if (!file_exists($path)) fail('Fichier manquant sur le serveur', 404);
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($d['label']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
  }

  /* ============ ACTIVITÉ & DASHBOARD ============ */

  case 'activity': {
    $u = require_auth();
    out(db()->query('SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 20')->fetchAll());
  }

  case 'dashboard': {
    $u = require_auth();
    $d = db();
    $active = (int) $d->query("SELECT COUNT(*) FROM sponsors WHERE status != 'Inactif'")->fetchColumn();
    $revenue = (float) $d->query("SELECT COALESCE(SUM(amount),0) FROM contracts WHERE status != 'Archivé'")->fetchColumn();
    $expiring = $d->query("SELECT c.id, c.label, c.amount, c.end_date, s.name AS sponsor_name,
                            CAST(julianday(c.end_date) - julianday('now') AS INTEGER) AS days_left
                            FROM contracts c JOIN sponsors s ON s.id = c.sponsor_id
                            WHERE c.status != 'Archivé' AND c.end_date IS NOT NULL
                              AND julianday(c.end_date) - julianday('now') BETWEEN 0 AND 180
                            ORDER BY c.end_date")->fetchAll();
    $oppOpen = $d->query('SELECT COUNT(*) AS n, COALESCE(SUM(value),0) AS v FROM opportunities WHERE stage < 5')->fetch();
    $tasksLate = (int) $d->query("SELECT COUNT(*) FROM tasks WHERE done = 0 AND due_date IS NOT NULL AND due_date < date('now')")->fetchColumn();
    $byTier = $d->query("SELECT s.tier, COALESCE(SUM(c.amount),0) AS total
                          FROM contracts c JOIN sponsors s ON s.id = c.sponsor_id
                          WHERE c.status != 'Archivé' GROUP BY s.tier")->fetchAll();
    $monthly = $d->query("SELECT strftime('%Y-%m', start_date) AS m, SUM(amount) AS total
                           FROM contracts WHERE status != 'Archivé' AND start_date IS NOT NULL
                           GROUP BY m ORDER BY m DESC LIMIT 12")->fetchAll();
    $recentSponsors = $d->query('SELECT * FROM sponsors ORDER BY id DESC LIMIT 4')->fetchAll();
    out([
      'active_sponsors' => $active,
      'annual_revenue' => $revenue,
      'expiring' => $expiring,
      'opportunities' => $oppOpen,
      'tasks_late' => $tasksLate,
      'by_tier' => $byTier,
      'monthly' => array_reverse($monthly),
      'recent_sponsors' => $recentSponsors,
    ]);
  }

  /* ============ IMPORT EN MASSE ============ */

  case 'import': {
    $u = require_auth();
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $sponsors = $b['sponsors'] ?? null;
    if (!is_array($sponsors) || !count($sponsors)) fail('Aucun sponsor à importer');

    $pdo = db();
    $existing = [];
    foreach ($pdo->query('SELECT id, name FROM sponsors') as $row) {
      $existing[lc(trim($row['name']))] = (int) $row['id'];
    }
    $created = 0; $skipped = 0; $contractsAdded = 0; $contactsAdded = 0;

    $pdo->beginTransaction();
    try {
      $insSp = $pdo->prepare('INSERT INTO sponsors (name, sector, tier, status, website, address, notes) VALUES (?,?,?,?,?,?,?)');
      $insCt = $pdo->prepare('INSERT INTO contacts (sponsor_id, name, role, email, phone, is_primary) VALUES (?,?,?,?,?,1)');
      $insK  = $pdo->prepare('INSERT INTO contracts (sponsor_id, label, type, amount, start_date, end_date, status) VALUES (?,?,?,?,?,?,?)');
      foreach ($sponsors as $sp) {
        $name = trim((string) ($sp['name'] ?? ''));
        if ($name === '') continue;
        $key = lc($name);
        if (isset($existing[$key])) { $skipped++; continue; }
        $tier = in_array($sp['tier'] ?? '', ['Platine','Or','Argent','Bronze'], true) ? $sp['tier'] : 'Bronze';
        $status = in_array($sp['status'] ?? '', ['Actif','Renouvellement','À risque','Inactif'], true) ? $sp['status'] : 'Actif';
        $insSp->execute([$name, (string)($sp['sector']??''), $tier, $status, (string)($sp['website']??''), (string)($sp['address']??''), (string)($sp['notes']??'')]);
        $sid = (int) $pdo->lastInsertId();
        $existing[$key] = $sid; $created++;
        if (!empty($sp['contact']) && !empty($sp['contact']['name'])) {
          $c = $sp['contact'];
          $insCt->execute([$sid, (string)$c['name'], (string)($c['role']??''), (string)($c['email']??''), (string)($c['phone']??'')]);
          $contactsAdded++;
        }
        foreach (($sp['contracts'] ?? []) as $k) {
          if (empty($k['label'])) continue;
          $kstatus = valid_contract_status((string)($k['status'] ?? ''), 'Actif');
          $insK->execute([$sid, (string)$k['label'], (string)($k['type']??''), (float)($k['amount']??0), ($k['start_date']??'') ?: null, ($k['end_date']??'') ?: null, $kstatus]);
          $contractsAdded++;
        }
      }
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      fail('Import interrompu, aucune donnée enregistrée : ' . $e->getMessage(), 500);
    }
    log_activity($u['id'], 'Import de sponsors', "$created créé(s), $skipped ignoré(s), $contractsAdded contrat(s)");
    out(['ok' => true, 'created' => $created, 'skipped' => $skipped, 'contracts' => $contractsAdded, 'contacts' => $contactsAdded]);
  }

  default:
    fail('Action inconnue : ' . $action, 404);
}
