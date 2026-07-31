<?php
/**
 * Module Contacts — API backend.
 *
 * Interface du référentiel partagé (lib/mfc_contacts.php). Le magasin vit dans
 * le socle et non ici : ce module en est le premier consommateur, pas le
 * propriétaire. C'est ce qui évite que six modules ouvrent le fichier d'un
 * septième.
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

require_once __DIR__ . '/mfc_boot.php';

if (ob_get_level() > 0) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

$session = mfc_require_api('contacts');

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

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b      = body();

/* ---------------------------------------------------------------- Permissions
 *
 * Table déclarative, vérifiée en un seul point. REFUS PAR DÉFAUT : une action
 * absente exige contacts.edit, pas un accès libre.
 */
const CT_PERMS = [
    'me'               => null,
    'list'             => 'contacts.view',
    'get'              => 'contacts.view',
    'qualities'        => 'contacts.view',
    'save'             => 'contacts.edit',
    'relation_save'    => 'contacts.edit',
    'relation_delete'  => 'contacts.edit',
    'duplicates'       => 'contacts.merge',
    'merge'            => 'contacts.merge',
    'separate'         => 'contacts.merge',
    'import_preview'   => 'contacts.import',
    'import_commit'    => 'contacts.import',
    'batches'          => 'contacts.import',
    'batch_rollback'   => 'contacts.import',
    'sync_modules'     => 'contacts.import',
    'export'           => 'contacts.export',
];

$rule = array_key_exists($action, CT_PERMS) ? CT_PERMS[$action] : 'contacts.edit';
if ($rule !== null) mfc_require_perm($rule);

$canBank = mfc_can('contacts.bank');
$pdo     = mfc_contacts_db();

/** Retire l'IBAN des données renvoyées à qui n'a pas le droit de le voir. */
function strip_bank(array $row, bool $canBank): array {
    if (!$canBank) { unset($row['iban']); $row['iban_hidden'] = true; }
    return $row;
}

switch ($action) {

case 'me':
    out(['user' => ['name' => $session['name'] ?? '', 'login' => $session['login'] ?? '']]);

/* ------------------------------------------------------------------ LISTE */

case 'list': {
    $q        = trim((string)($_GET['q'] ?? ''));
    $type     = trim((string)($_GET['type'] ?? ''));
    $quality  = trim((string)($_GET['quality'] ?? ''));
    $active   = $_GET['active'] ?? '1';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(200, max(10, (int)($_GET['per_page'] ?? 50)));

    $where = ['1=1']; $params = [];
    if ($active !== 'all') { $where[] = 'c.active = ?'; $params[] = (int)$active; }
    if ($type !== '')      { $where[] = 'c.type = ?';   $params[] = $type; }
    if ($quality !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM contact_qualities q WHERE q.contact_id = c.id AND q.quality = ?)';
        $params[] = $quality;
    }
    if ($q !== '') {
        /* La recherche porte aussi sur la forme comparable du nom, stockée sur
           la fiche : taper « daloisio » doit trouver « D'ALOISIO », et les DEUX
           homonymes s'il y en a deux. */
        $like = '%' . $q . '%';
        $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.city LIKE ?
                     OR c.norm_name LIKE ?)';
        /* La requête subit la même réduction que la colonne (accents, ponctuation
           et espaces retirés) : « d aloisio » comme « D'ALOISIO » cherchent tous
           deux « daloisio ». */
        $normQ = str_replace(' ', '', mfc_contacts_norm_text($q));
        array_push($params, $like, $like, $like, $like, '%' . $normQ . '%');
    }
    $w = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM contacts c WHERE $w");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $off = ($page - 1) * $perPage;
    $st  = $pdo->prepare("SELECT c.* FROM contacts c WHERE $w
                          ORDER BY c.last_name COLLATE NOCASE, c.first_name COLLATE NOCASE
                          LIMIT $perPage OFFSET $off");
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $r['qualities'] = mfc_contacts_qualities($pdo, (int)$r['id']);
        $lk = $pdo->prepare('SELECT module FROM contact_links WHERE contact_id = ? ORDER BY module');
        $lk->execute([(int)$r['id']]);
        $r['modules'] = $lk->fetchAll(PDO::FETCH_COLUMN);
        $r = strip_bank($r, $canBank);
    }
    unset($r);

    out(['contacts' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
         'pending_duplicates' => mfc_contacts_pending_duplicates($pdo)]);
}

case 'qualities': {
    $rows = $pdo->query('SELECT quality, COUNT(*) n FROM contact_qualities
                         GROUP BY quality ORDER BY n DESC, quality')->fetchAll();
    out(['qualities' => $rows,
         'types' => $pdo->query('SELECT type, COUNT(*) n FROM contacts GROUP BY type')->fetchAll()]);
}

case 'get': {
    $id = i($_GET, 'id');
    $st = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) fail('Contact introuvable', 404);

    $c['qualities'] = mfc_contacts_qualities($pdo, $id);

    $lk = $pdo->prepare('SELECT module, local_id FROM contact_links WHERE contact_id = ? ORDER BY module');
    $lk->execute([$id]);
    $c['links'] = $lk->fetchAll();

    $rl = $pdo->prepare('SELECT r.id, r.type, r.comment, r.from_id, r.to_id,
                                a.first_name fa, a.last_name la, a.ref_id ra,
                                b.first_name fb, b.last_name lb, b.ref_id rb
                         FROM contact_relations r
                         JOIN contacts a ON a.id = r.from_id
                         JOIN contacts b ON b.id = r.to_id
                         WHERE r.from_id = ? OR r.to_id = ?');
    $rl->execute([$id, $id]);
    $c['relations'] = $rl->fetchAll();

    out(['contact' => strip_bank($c, $canBank)]);
}

/* --------------------------------------------------------------- ÉCRITURE */

case 'save': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $id = i($b, 'id');

    $last = s($b, 'last_name');
    if ($last === '') fail('Le nom est obligatoire');
    $type = s($b, 'type', 'personne');
    if (!in_array($type, ['personne', 'organisation'], true)) fail('Type invalide');

    $fields = ['type' => $type, 'first_name' => s($b, 'first_name'), 'last_name' => $last,
               'email' => mfc_contacts_norm_email(s($b, 'email')), 'phone' => s($b, 'phone'),
               'mobile' => s($b, 'mobile'), 'street' => s($b, 'street'), 'zip' => s($b, 'zip'),
               'city' => s($b, 'city'), 'country' => s($b, 'country'),
               'birth_date' => s($b, 'birth_date'), 'lang' => s($b, 'lang', 'fr'),
               'job_title' => s($b, 'job_title'), 'org_ref_id' => s($b, 'org_ref_id'),
               'notes' => s($b, 'notes'), 'active' => i($b, 'active', 1)];

    /* L'IBAN n'est modifiable qu'avec la permission dédiée : sans elle, le
       champ n'est même pas envoyé par l'interface, et une requête forgée ne
       doit pas pouvoir l'écraser. */
    if ($canBank && array_key_exists('iban', $b)) {
        $fields['iban'] = mfc_contacts_norm_iban(s($b, 'iban'));
    }

    if ($fields['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['birth_date'])) {
        fail('Date de naissance : format attendu AAAA-MM-JJ');
    }

    if ($id > 0) {
        $set = []; $val = [];
        foreach ($fields as $k => $v) { $set[] = "$k = ?"; $val[] = $v; }
        $val[] = $id;
        $pdo->prepare('UPDATE contacts SET ' . implode(', ', $set) . ", updated_at = datetime('now') WHERE id = ?")
            ->execute($val);
    } else {
        /* Création manuelle : elle passe par le même dédoublonnage que les
           imports, sinon l'écran deviendrait la porte d'entrée des doublons. */
        $r = mfc_contacts_ingest($pdo, $fields + ['qualities' => (array)($b['qualities'] ?? [])]);
        $id = $r['contact_id'];
        if ($r['verdict'] === MFC_CONTACTS_CERTAIN) {
            out(['ok' => true, 'id' => $id, 'merged' => true,
                 'message' => 'Ce contact existait déjà (' . implode(', ', $r['reasons']) . '), sa fiche a été complétée.']);
        }
    }

    if (array_key_exists('qualities', $b)) {
        $pdo->prepare('DELETE FROM contact_qualities WHERE contact_id = ?')->execute([$id]);
        mfc_contacts_add_qualities($pdo, $id, (array)$b['qualities']);
    }
    mfc_contacts_store_keys($pdo, $id, $fields);
    mfc_contacts_refresh_norm($pdo, $id);

    out(['ok' => true, 'id' => $id]);
}

case 'relation_save': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $from = i($b, 'from_id'); $to = i($b, 'to_id'); $type = s($b, 'type');
    if (!$from || !$to) fail('Deux contacts sont requis');
    if ($from === $to)   fail('Un contact ne peut pas être en relation avec lui-même');
    if (!in_array($type, ['parent_de', 'responsable_legal_de', 'conjoint_de', 'travaille_chez'], true)) {
        fail('Type de relation invalide');
    }
    $pdo->prepare('INSERT OR IGNORE INTO contact_relations (from_id, to_id, type, comment) VALUES (?,?,?,?)')
        ->execute([$from, $to, $type, s($b, 'comment')]);
    out(['ok' => true]);
}

case 'relation_delete': {
    $pdo->prepare('DELETE FROM contact_relations WHERE id = ?')->execute([i($_GET, 'id')]);
    out(['ok' => true]);
}

/* --------------------------------------------------------------- DOUBLONS */

case 'duplicates': {
    $rows = $pdo->query('SELECT d.*,
                                a.id ida, a.first_name fa, a.last_name la, a.email ea, a.city ca,
                                a.birth_date da, a.type ta,
                                b.id idb, b.first_name fb, b.last_name lb, b.email eb, b.city cb,
                                b.birth_date db, b.type tb
                         FROM contact_duplicates d
                         JOIN contacts a ON a.id = d.contact_id
                         JOIN contacts b ON b.id = d.other_id
                         WHERE d.status = "pending"
                         ORDER BY d.score DESC, a.last_name')->fetchAll();
    foreach ($rows as &$r) {
        foreach (['a' => 'ida', 'b' => 'idb'] as $k => $idf) {
            $lk = $pdo->prepare('SELECT module FROM contact_links WHERE contact_id = ?');
            $lk->execute([(int)$r[$idf]]);
            $r['modules_' . $k] = $lk->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    unset($r);
    out(['duplicates' => $rows, 'total' => count($rows)]);
}

case 'merge': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $keep = i($b, 'keep_id'); $drop = i($b, 'drop_id');
    if (!$keep || !$drop) fail('Deux contacts sont requis');
    if (!mfc_contacts_merge($pdo, $keep, $drop)) fail('Fusion impossible : contact introuvable');
    out(['ok' => true, 'message' => 'Fiches fusionnées.']);
}

case 'separate': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    mfc_contacts_separate($pdo, i($b, 'a'), i($b, 'b'));
    out(['ok' => true, 'message' => 'Marqués comme deux personnes distinctes.']);
}

/* ------------------------------------------------- ALIMENTATION AUTOMATIQUE */

/**
 * Reprend les personnes déjà présentes dans les autres modules.
 *
 * Idempotente : chaque objet source est rattaché par une clé de provenance
 * (module + identifiant local), donc relancer la synchro ne recrée rien.
 */
case 'sync_modules': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    require_once __DIR__ . '/sync_modules.php';
    $batch = mfc_contacts_batch_open($pdo, 'modules', '', (string)($session['login'] ?? ''));
    $stats = contacts_sync_all($pdo, $batch);
    mfc_contacts_batch_close($pdo, $batch, $stats);
    out(['ok' => true, 'batch_id' => $batch, 'stats' => $stats,
         'pending_duplicates' => mfc_contacts_pending_duplicates($pdo)]);
}

/* ----------------------------------------------------------------- IMPORT */

case 'import_preview':
case 'import_commit': {
    require_once __DIR__ . '/import.php';
    contacts_import_run($pdo, $action === 'import_commit', (string)($session['login'] ?? ''), $canBank);
}

case 'batches': {
    out(['batches' => $pdo->query('SELECT * FROM contact_batches ORDER BY id DESC LIMIT 50')->fetchAll()]);
}

case 'batch_rollback': {
    if ($method !== 'POST') fail('Méthode non supportée', 405);
    $id = i($b, 'id');
    $st = $pdo->prepare('SELECT * FROM contact_batches WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) fail('Lot introuvable', 404);
    mfc_contacts_backup();
    $res = mfc_contacts_batch_rollback($pdo, $id);
    out(['ok' => true, 'deleted' => $res['deleted'],
         'message' => $res['deleted'] . ' fiche(s) créée(s) par ce lot ont été supprimées.']);
}

/* ----------------------------------------------------------------- EXPORT */

case 'export': {
    $st = $pdo->query('SELECT * FROM contacts ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE');
    $rows = $st->fetchAll();
    $cols = ['ref_id','type','first_name','last_name','email','phone','mobile','street','zip','city',
             'country','birth_date','lang','job_title','org_ref_id','active'];
    if ($canBank) $cols[] = 'iban';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");                    // BOM, pour qu'Excel lise l'UTF-8
    fputcsv($o, array_merge($cols, ['qualites']), ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) $line[] = $r[$c] ?? '';
        $line[] = implode(';', mfc_contacts_qualities($pdo, (int)$r['id']));
        fputcsv($o, $line, ';');
    }
    fclose($o);
    exit;
}

default:
    fail('Action inconnue : ' . htmlspecialchars($action, ENT_QUOTES), 404);
}
