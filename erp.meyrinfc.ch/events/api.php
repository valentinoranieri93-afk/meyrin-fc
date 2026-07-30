<?php
/**
 * CRM Événements — Meyrin FC — API backend
 * PHP 8.x + SQLite (PDO). Aucune dépendance externe.
 * Même structure que le CRM Sponsors (login + stockage SQLite).
 * Compatible hébergement mutualisé Infomaniak.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Plus de session PHP propre a l'application : l'identite vient de la session
   unique de l'ERP (cookie JWT). */
require_once __DIR__ . '/mfc_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------------------------------------------------------------- DB */

const DB_DIR  = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/evenements.sqlite';
const COLLECTIONS = ['events', 'volunteers', 'sponsors', 'materiel', 'documents'];

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
    role TEXT NOT NULL DEFAULT 'viewer',      -- admin | editor | viewer
    active INTEGER NOT NULL DEFAULT 1,
    last_login TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS records (
    collection TEXT NOT NULL,                  -- events | volunteers | sponsors | materiel | documents
    rec_id INTEGER NOT NULL,
    payload TEXT NOT NULL,                      -- objet JSON complet
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (collection, rec_id)
  );
  CREATE TABLE IF NOT EXISTS settings (
    skey TEXT PRIMARY KEY,
    svalue TEXT
  );
  ");
}

/* ---- Données de démonstration (chargées à la création du 1er compte) ---- */
function seed_demo(): void {
  $pdo = db();
  $n = (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn();
  if ($n > 0) return;
  $seed = @json_decode((string)@file_get_contents(__DIR__ . '/data/seed.json'), true);
  if (is_array($seed)) {
    $ins = $pdo->prepare('INSERT INTO records (collection, rec_id, payload) VALUES (?,?,?)');
    foreach (COLLECTIONS as $c) {
      foreach (($seed[$c] ?? []) as $row) {
        $ins->execute([$c, (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE)]);
      }
    }
  }
  set_setting('club', [
    'nom' => 'Meyrin FC', 'saison' => '2025–2026',
    'email' => 'administration@meyrinfc.ch', 'lieu' => 'Stade des Vergers, Meyrin',
  ]);
}

/* ---------------------------------------------------------------- Helpers */

function body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return $_POST ?: [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function out($data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }
function s(array $b, string $k, string $def = ''): string { return trim((string)($b[$k] ?? $def)); }
function n(array $b, string $k, float $def = 0): float { return (float)($b[$k] ?? $def); }
function i(array $b, string $k, int $def = 0): int { return (int)($b[$k] ?? $def); }

/**
 * Ligne `users` locale de la personne connectee. La table n'authentifie plus
 * rien mais reste l'annuaire auquel se rattachent les donnees existantes.
 */
function current_user(): ?array {
  static $u = null;
  if ($u !== null) return $u;
  $s = mfc_session();
  if (!$s) return null;
  $u = mfc_local_user(db(), $s);
  return $u;
}
function require_auth(): array { $u = current_user(); if (!$u) fail('Non authentifié', 401); return $u; }

/**
 * Permission requise par collection.
 *
 * Ici les droits portent sur la COLLECTION et non sur l'action, parce que
 * `data`, `save` et `delete` sont generiques et servent les cinq collections.
 * La liste des sponsors cote evenements fait partie du planning : elle n'a
 * rien a voir avec le CRM Sponsoring, qui a ses propres droits.
 *
 * REFUS PAR DEFAUT : une collection absente de cette table est inaccessible.
 */
const COLLECTION_PERMS = [
  'events'     => ['view' => 'planning.view', 'edit' => 'planning.edit'],
  'sponsors'   => ['view' => 'planning.view', 'edit' => 'planning.edit'],
  'volunteers' => ['view' => 'planning.view', 'edit' => 'volunteers.manage'],
  'materiel'   => ['view' => 'planning.view', 'edit' => 'materiel.manage'],
  'documents'  => ['view' => 'planning.view', 'edit' => 'documents.manage'],
];

function collection_perm(string $c, string $level): string {
  if (!isset(COLLECTION_PERMS[$c])) fail('Collection inconnue', 400);
  return 'events.' . COLLECTION_PERMS[$c][$level];
}

function load_collection(string $c): array {
  $st = db()->prepare('SELECT payload FROM records WHERE collection = ? ORDER BY rec_id');
  $st->execute([$c]);
  $out = [];
  foreach ($st->fetchAll() as $r) { $p = json_decode($r['payload'], true); if (is_array($p)) $out[] = $p; }
  return $out;
}
function next_id(string $c): int {
  $st = db()->prepare('SELECT COALESCE(MAX(rec_id),0)+1 FROM records WHERE collection = ?');
  $st->execute([$c]);
  return (int)$st->fetchColumn();
}
function upsert_record(string $c, array $rec): void {
  $st = db()->prepare('INSERT INTO records (collection, rec_id, payload) VALUES (?,?,?)
                       ON CONFLICT(collection, rec_id) DO UPDATE SET payload = excluded.payload, updated_at = datetime(\'now\')');
  $st->execute([$c, (int)$rec['id'], json_encode($rec, JSON_UNESCAPED_UNICODE)]);
}
function load_settings(): array {
  $out = [];
  foreach (db()->query('SELECT skey, svalue FROM settings')->fetchAll() as $r) $out[$r['skey']] = json_decode($r['svalue'], true);
  return $out;
}
function set_setting(string $k, $v): void {
  db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue')
      ->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
function list_users(): array {
  return db()->query('SELECT id, name, email, role, active, last_login, created_at FROM users ORDER BY id')->fetchAll();
}

/* ---------------------------------------------------------------- Router */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

/* Points d'entree supprimes : l'authentification est centralisee dans l'ERP. */
if (in_array($action, ['login', 'logout', 'setup', 'status', 'change_password'], true)) {
  fail("Cette application n'a plus de connexion propre. Utilisez l'ERP : " . ERP_URL, 410);
}

/* Session ERP + acces a l'application. Repond 401/403 en JSON sinon. */
mfc_require_api('events');

switch ($action) {

  /* ============ SESSION ============ */

  /* status, setup, login, logout et change_password ont ete supprimes :
     l'authentification est centralisee dans l'ERP. Les appels a ces actions
     sont interceptes plus haut et renvoient un 410 explicite. */

  case 'me': { out(['user' => require_auth()]); }

  /* ============ UTILISATEURS (admin) ============ */

  /* Annuaire local, en lecture seule : il alimente les listes de benevoles et
     de responsables. Les comptes se gerent exclusivement dans l'ERP. */
  case 'users': {
    require_auth();
    if ($method === 'GET') out(list_users());
    fail("Les comptes se gèrent dans l'ERP : " . ERP_URL, 403);
  }

  case 'data': {
    $u = require_auth();
    /* Une collection interdite est renvoyee vide plutot que de faire echouer
       tout l'appel : l'interface se dessine sans ce bloc au lieu de rester
       blanche. C'est le meme point d'entree qui alimente tous les ecrans. */
    $res = ['user' => $u, 'collections' => [], 'settings' => load_settings()];
    foreach (COLLECTIONS as $c) {
      $res['collections'][$c] = mfc_can(collection_perm($c, 'view')) ? load_collection($c) : [];
    }
    $res['users'] = list_users();   // annuaire, alimente les listes deroulantes
    out($res);
  }

  case 'save': {
    $u = require_auth();
    $c = $_GET['collection'] ?? '';
    if (!in_array($c, COLLECTIONS, true)) fail('Collection inconnue');
    mfc_require_perm(collection_perm($c, 'edit'));
    $rec = $b;
    $id = i($rec, 'id');
    if ($id <= 0) { $id = next_id($c); }
    $rec['id'] = $id;
    upsert_record($c, $rec);
    out(['ok' => true, 'record' => $rec]);
  }

  case 'delete': {
    $u = require_auth();
    $c = $_GET['collection'] ?? ''; $id = i($_GET, 'id');
    if (!in_array($c, COLLECTIONS, true)) fail('Collection inconnue');
    mfc_require_perm(collection_perm($c, 'edit'));
    db()->prepare('DELETE FROM records WHERE collection = ? AND rec_id = ?')->execute([$c, $id]);
    out(['ok' => true]);
  }

  case 'settings': {
    require_auth();
    if ($method === 'GET') { mfc_require_perm('events.planning.view'); out(load_settings()); }
    mfc_require_perm('events.settings.manage');
    foreach ($b as $k => $v) set_setting(substr((string)$k, 0, 60), $v);
    out(['ok' => true, 'settings' => load_settings()]);
  }

  /* ============ DOCUMENTS (téléversement) ============ */

  case 'upload': {
    $u = require_auth(); mfc_require_perm('events.documents.manage');
    if (empty($_FILES['file'])) fail('Aucun fichier reçu');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Erreur de téléversement (code ' . $f['error'] . ')');
    if ($f['size'] > 15 * 1024 * 1024) fail('Fichier trop volumineux (15 Mo max)');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf','png','jpg','jpeg','webp','xlsx','xls','docx','doc','pptx','zip','csv','txt'];
    if (!in_array($ext, $allowed, true)) fail('Type de fichier non autorisé (.' . $ext . ')');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    if (!file_exists("$dir/index.html")) file_put_contents("$dir/index.html", '');
    /* Les fichiers ne sont jamais servis par leur URL directe : ils passent par
       l'action `download`, qui revérifie les droits. On pose le garde-fou ici
       aussi, pour qu'un dossier recréé sur un nouveau serveur ne reparte jamais
       ouvert en attendant qu'on y pense. Le fichier est lu depuis le disque par
       PHP, ce que cette règle n'empêche pas. */
    if (!file_exists("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\n");
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) fail('Impossible d\'enregistrer le fichier', 500);
    $colMap = ['PDF'=>'#D8463A','PNG'=>'#7B59D8','JPG'=>'#7B59D8','JPEG'=>'#7B59D8','WEBP'=>'#7B59D8','XLSX'=>'#1F9D5B','XLS'=>'#1F9D5B','DOCX'=>'#3B6FE0','DOC'=>'#3B6FE0'];
    $typeU = strtoupper($ext);
    $id = next_id('documents');
    $rec = [
      'id' => $id,
      'n' => s($_POST, 'nom') ?: pathinfo($f['name'], PATHINFO_FILENAME),
      'type' => $typeU,
      'ev' => (isset($_POST['ev']) && $_POST['ev'] !== '') ? (int)$_POST['ev'] : null,
      'c' => $colMap[$typeU] ?? '#9A988C',
      'size' => $f['size'] >= 1048576 ? round($f['size']/1048576,1).' Mo' : round($f['size']/1024).' Ko',
      'date' => date('d.m.Y'),
      'file' => 'uploads/' . $stored,
    ];
    upsert_record('documents', $rec);
    out(['ok' => true, 'record' => $rec]);
  }

  /* ============ DOCUMENTS (téléchargement) ============ */

  /**
   * Sert un document via PHP plutôt que par son URL directe dans uploads/.
   *
   * Le nom stocké est imprévisible (96 bits d'aléa), donc le risque n'était pas
   * qu'on devine l'adresse : c'est qu'un lien, une fois connu, restait valable
   * pour toujours et pour tout le monde. Retirer ses droits à quelqu'un ne lui
   * retirait pas les liens déjà en sa possession, et un lien transféré par
   * message continuait d'ouvrir le fichier. Or ces documents contiennent des
   * listes de participants, donc des données personnelles.
   *
   * Passer par ici rétablit trois choses : la permission est revérifiée à
   * chaque téléchargement, la révocation d'un compte prend effet immédiatement,
   * et l'accès devient traçable.
   */
  case 'download': {
    require_auth();
    mfc_require_perm(collection_perm('documents', 'view'));

    $id  = i($_GET, 'id');
    $rec = null;
    foreach (load_collection('documents') as $d) {
      if ((int)($d['id'] ?? 0) === $id) { $rec = $d; break; }
    }
    if (!$rec) fail('Document introuvable', 404);

    /* basename() neutralise toute tentative de remontée de dossier, et on ne
       sert que ce qui est réellement référencé en base : un fichier orphelin
       ou déposé par un autre moyen dans uploads/ n'est pas servi. */
    $stored = basename((string)($rec['file'] ?? ''));
    $path   = __DIR__ . '/uploads/' . $stored;
    if ($stored === '' || !is_file($path)) fail('Fichier introuvable', 404);

    /* Les extensions acceptées à l'envoi excluent déjà tout format exécutable
       par le navigateur (ni .html ni .svg). On peut donc afficher PDF et images
       dans l'onglet, comme le faisait le lien direct, et forcer le
       téléchargement pour le reste. nosniff empêche le navigateur de deviner un
       type autre que celui annoncé. */
    $ext    = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    $inline = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
               'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    $type   = $inline[$ext] ?? 'application/octet-stream';
    $dispo  = isset($inline[$ext]) ? 'inline' : 'attachment';

    /* Nom lisible pour l'utilisateur, débarrassé de tout ce qui pourrait
       casser l'en-tête ou suggérer un autre dossier. */
    $nom = preg_replace('~[^\w .\-]+~u', '_', (string)($rec['n'] ?? 'document'));
    $nom = trim((string)$nom) !== '' ? $nom . '.' . $ext : $stored;

    header_remove('Content-Type');
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $dispo . '; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
  }

  default:
    fail('Action inconnue : ' . $action, 404);
}
