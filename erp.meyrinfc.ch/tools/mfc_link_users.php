<?php
/**
 * mfc_link_users.php — Rapprochement des comptes locaux des applications
 * avec les comptes de l'ERP (etape 3 du chantier SSO).
 *
 * GARANTIES :
 *  - Aucune suppression de ligne, jamais.
 *  - Aucune colonne modifiee a part `erp_id`.
 *  - Une sauvegarde datee de chaque base est faite avant toute ecriture.
 *  - Sans confirmation explicite, la page est en lecture seule.
 *
 * Reserve aux administrateurs. A SUPPRIMER DU SERVEUR une fois le
 * rapprochement termine.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/mfc_auth.php';

$session = mfc_session();
if (!$session)                          { header('Location: ' . ERP_URL . '/'); exit; }
if (empty($session['settings']))        { http_response_code(403); exit('Reserve aux administrateurs.'); }

/* Bases connues. Le nom de fichier est resolu dynamiquement si absent. */
const APPS = [
    'arbitrage' => 'arbitrage.sqlite',
    'sponsors'  => 'sponsorflow.sqlite',
    'events'    => 'evenements.sqlite',
    'commandes' => 'commandes.sqlite',
];

/* Comptes techniques a ne jamais rattacher a une personne. */
const PROTECTED_EMAILS = ['boutique-publique@commandes.meyrinfc.ch'];

/* ------------------------------------------------------------- Helpers */

function erp_users(): array {
    $u = json_decode(file_get_contents(DATA_DIR . 'users.json'), true) ?: [];
    return array_values(array_filter($u, fn($x) => ($x['active'] ?? true)));
}

function db_path(string $slug): ?string {
    $base = mfc_app_path($slug);
    if (!$base) return null;
    $f = $base . '/data/' . APPS[$slug];
    if (is_file($f)) return $f;
    $g = glob($base . '/data/*.sqlite') ?: [];
    return $g[0] ?? null;
}

function open_db(string $file): PDO {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function has_erp_id(PDO $pdo): bool {
    foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $c) {
        if ($c['name'] === 'erp_id') return true;
    }
    return false;
}

/** Copie datee de la base avant toute ecriture. Echoue bruyamment si impossible. */
function backup_db(string $file): string {
    $dest = $file . '.bak-' . date('Ymd-His');
    if (!copy($file, $dest)) {
        throw new RuntimeException('Sauvegarde impossible de ' . basename($file) . ' — aucune ecriture effectuee.');
    }
    return $dest;
}

/* --------------------------------------------------- Etat courant (lecture) */

$erpUsers = erp_users();
$erpById  = [];
$erpByMail = [];
foreach ($erpUsers as $u) {
    $erpById[$u['id']] = $u;
    $mail = strtolower(trim($u['email'] ?? ''));
    if ($mail !== '') $erpByMail[$mail] = $u['id'];
}

$state = [];   // slug => ['file'=>, 'error'=>, 'rows'=>[...]]
foreach (array_keys(APPS) as $slug) {
    $entry = ['file' => db_path($slug), 'error' => null, 'rows' => [], 'erp_id_col' => false];
    if (!$entry['file']) { $entry['error'] = 'Base introuvable.'; $state[$slug] = $entry; continue; }
    try {
        $pdo = open_db($entry['file']);
        $t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        if (!$t) { $entry['error'] = 'Pas de table users.'; $state[$slug] = $entry; continue; }
        $entry['erp_id_col'] = has_erp_id($pdo);
        $cols = $entry['erp_id_col'] ? 'id, name, email, role, active, erp_id' : 'id, name, email, role, active';
        foreach ($pdo->query('SELECT ' . $cols . ' FROM users ORDER BY id')->fetchAll() as $r) {
            $mail = strtolower(trim((string)$r['email']));
            $r['protected']  = in_array($mail, PROTECTED_EMAILS, true);
            $r['suggestion'] = $r['protected'] ? '' : ($erpByMail[$mail] ?? '');
            $r['erp_id']     = $r['erp_id'] ?? null;
            $entry['rows'][] = $r;
        }
    } catch (Throwable $e) {
        $entry['error'] = 'Erreur de lecture : ' . $e->getMessage();
    }
    $state[$slug] = $entry;
}

/* ----------------------------------------------------------- Ecriture */

$report = null;
if (($_POST['confirm'] ?? '') === 'oui') {
    $map    = $_POST['map'] ?? [];     // map[slug][local_id] = erp_id | ''
    $report = ['ok' => [], 'skip' => [], 'error' => [], 'backups' => []];

    foreach ($map as $slug => $pairs) {
        if (!isset($state[$slug]) || $state[$slug]['error']) continue;
        $wanted = array_filter($pairs, fn($v) => $v !== '');
        if (!$wanted) { continue; }

        /* Un meme compte ERP ne peut pas etre rattache a deux comptes locaux
           de la MEME app : on ne saurait plus lequel utiliser ensuite. */
        $dups = array_diff_assoc($wanted, array_unique($wanted));
        if ($dups) {
            $report['error'][] = $slug . ' : le meme compte ERP est affecte a plusieurs comptes locaux. Rien n\'a ete ecrit pour cette application.';
            continue;
        }

        try {
            $file = $state[$slug]['file'];
            $report['backups'][] = basename(backup_db($file));
            $pdo = open_db($file);
            if (!has_erp_id($pdo)) {
                /* Additif : ALTER TABLE ADD COLUMN ne touche aucune donnee existante. */
                $pdo->exec('ALTER TABLE users ADD COLUMN erp_id TEXT');
            }
            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE users SET erp_id = ? WHERE id = ?');
            foreach ($wanted as $localId => $erpId) {
                if (!isset($erpById[$erpId])) { $report['skip'][] = "$slug #$localId : compte ERP inconnu."; continue; }
                $upd->execute([(string)$erpId, (int)$localId]);
                $report['ok'][] = $slug . ' #' . (int)$localId . ' → ' . $erpById[$erpId]['name'] . ' (' . $erpId . ')';
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $report['error'][] = $slug . ' : ' . $e->getMessage();
        }
    }

    /* Rechargement de l'etat pour afficher le resultat reel, pas l'attendu. */
    foreach (array_keys(APPS) as $slug) {
        if ($state[$slug]['error']) continue;
        try {
            $pdo = open_db($state[$slug]['file']);
            $state[$slug]['erp_id_col'] = has_erp_id($pdo);
            $cols = $state[$slug]['erp_id_col'] ? 'id, name, email, role, active, erp_id' : 'id, name, email, role, active';
            $rows = [];
            foreach ($pdo->query('SELECT ' . $cols . ' FROM users ORDER BY id')->fetchAll() as $r) {
                $mail = strtolower(trim((string)$r['email']));
                $r['protected']  = in_array($mail, PROTECTED_EMAILS, true);
                $r['suggestion'] = $r['protected'] ? '' : ($erpByMail[$mail] ?? '');
                $r['erp_id']     = $r['erp_id'] ?? null;
                $rows[] = $r;
            }
            $state[$slug]['rows'] = $rows;
        } catch (Throwable $e) { /* l'etat affiche reste celui d'avant */ }
    }
}

$missingMail = array_filter($erpUsers, fn($u) => trim($u['email'] ?? '') === '');
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapprochement des comptes · Meyrin FC</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,sans-serif;background:#F6F4ED;color:#15140F;padding:24px;line-height:1.5}
.wrap{max-width:980px;margin:0 auto}
h1{font-size:22px;margin-bottom:4px}
.sub{color:#6E6C61;font-size:13px;margin-bottom:20px}
.card{background:#fff;border:1px solid #E9E6DC;border-radius:14px;padding:20px 22px;margin-bottom:16px}
h2{font-size:14px;margin-bottom:4px}
.hint{color:#6E6C61;font-size:12.5px;margin-bottom:14px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6E6C61;padding:6px 8px;border-bottom:1px solid #E9E6DC}
td{padding:8px;border-bottom:1px solid #F0EDE4;vertical-align:middle}
tr:last-child td{border-bottom:none}
select{padding:6px 8px;border:1px solid #E9E6DC;border-radius:8px;font:inherit;font-size:12.5px;background:#fff;max-width:260px}
.b{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.ok{background:#E4F4EA;color:#1F7A47}.warn{background:#FCF3DE;color:#96690E}.nf{background:#F0EDE4;color:#6E6C61}.ko{background:#FBE7E4;color:#C0392B}
.note{border-radius:10px;padding:12px 14px;font-size:13px;margin-bottom:14px}
.note-info{background:#FCF8E8;border:1px solid #EFE4BE}
.note-ok{background:#E4F4EA;border:1px solid #BFE3CD}
.note-ko{background:#FBE7E4;border:1px solid #F0C9C3}
.actions{position:sticky;bottom:0;background:#F6F4ED;padding:16px 0;border-top:1px solid #E9E6DC;margin-top:8px}
button{padding:11px 22px;border:none;border-radius:10px;background:#15140F;color:#FFD000;font:inherit;font-weight:700;cursor:pointer}
ul{margin:6px 0 0 18px;font-size:12.5px}
code{font-size:11.5px;color:#6E6C61}
</style></head><body><div class="wrap">

<h1>Rapprochement des comptes</h1>
<div class="sub">Relie chaque compte local d'application à son compte ERP. Aucune ligne n'est supprimée, aucune autre colonne n'est modifiée.</div>

<?php if ($report): ?>
  <?php if ($report['ok']): ?>
  <div class="note note-ok"><strong>Rattachements effectués :</strong>
    <ul><?php foreach ($report['ok'] as $l): ?><li><?= htmlspecialchars($l) ?></li><?php endforeach; ?></ul>
    <?php if ($report['backups']): ?><div style="margin-top:8px">Sauvegardes créées : <code><?= htmlspecialchars(implode(', ', $report['backups'])) ?></code></div><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($report['skip']): ?>
  <div class="note note-info"><strong>Ignorés :</strong><ul><?php foreach ($report['skip'] as $l): ?><li><?= htmlspecialchars($l) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($report['error']): ?>
  <div class="note note-ko"><strong>Erreurs :</strong><ul><?php foreach ($report['error'] as $l): ?><li><?= htmlspecialchars($l) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($missingMail): ?>
<div class="note note-info">
  <strong>E-mails manquants dans l'ERP.</strong> Ces comptes ne peuvent pas être proposés automatiquement :
  <?= htmlspecialchars(implode(', ', array_map(fn($u) => $u['name'], $missingMail))) ?>.
  Renseignez leur e-mail dans Paramètres &gt; Utilisateurs, puis rechargez cette page.
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="confirm" value="oui">

<?php foreach ($state as $slug => $app): ?>
<div class="card">
  <h2><?= htmlspecialchars($slug) ?></h2>
  <?php if ($app['error']): ?>
    <div class="hint" style="color:#C0392B"><?= htmlspecialchars($app['error']) ?></div>
  <?php else: ?>
    <div class="hint"><code><?= htmlspecialchars((string)$app['file']) ?></code></div>
    <table>
      <tr><th>Compte local</th><th>E-mail</th><th>État</th><th>Rattacher au compte ERP</th></tr>
      <?php foreach ($app['rows'] as $r): ?>
      <tr>
        <td><strong>#<?= (int)$r['id'] ?></strong> <?= htmlspecialchars((string)$r['name']) ?>
            <?= $r['active'] ? '' : ' <span class="b nf">inactif</span>' ?></td>
        <td><code><?= htmlspecialchars((string)$r['email']) ?></code></td>
        <td>
          <?php if ($r['protected']): ?><span class="b warn">compte système</span>
          <?php elseif ($r['erp_id']): ?><span class="b ok">rattaché</span>
          <?php elseif ($r['suggestion']): ?><span class="b nf">correspondance trouvée</span>
          <?php else: ?><span class="b nf">sans correspondance</span><?php endif; ?>
        </td>
        <td>
          <?php if ($r['protected']): ?>
            <span style="font-size:12px;color:#6E6C61">Jamais rattaché — porte les paniers de la boutique publique.</span>
          <?php else: ?>
            <select name="map[<?= htmlspecialchars($slug) ?>][<?= (int)$r['id'] ?>]">
              <option value="">— ne pas rattacher —</option>
              <?php $sel = $r['erp_id'] ?: $r['suggestion']; ?>
              <?php foreach ($erpUsers as $eu): ?>
                <option value="<?= htmlspecialchars($eu['id']) ?>" <?= $sel === $eu['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($eu['name']) ?> (<?= htmlspecialchars($eu['login']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="note note-info">
  Les comptes laissés sur « ne pas rattacher » <strong>restent en base avec toutes leurs données</strong>.
  Ils ne serviront simplement plus à se connecter. C'est le comportement attendu pour les comptes de test
  et pour le compte partagé <code>info@meyrinfc.ch</code>.
</div>

<div class="actions">
  <button type="submit" onclick="return confirm('Écrire les rattachements ? Une sauvegarde de chaque base est créée avant.')">
    Enregistrer les rattachements
  </button>
</div>
</form>

<div class="card">
  <h2>Après validation</h2>
  <div class="hint" style="margin:0">Supprimez ce fichier du serveur. Les sauvegardes <code>.bak-*</code> peuvent rester le temps de vérifier, puis être retirées.</div>
</div>

</div></body></html>
