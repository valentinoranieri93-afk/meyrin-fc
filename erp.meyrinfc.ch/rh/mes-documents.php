<?php
/**
 * Espace personnel (fiches de paie, pièces d'engagement, dossier libre) pour un employé ou
 * joueur — accès par lien à token, sans compte ni mot de passe. Page volontairement
 * indépendante du reste du module RH : les personnes qui la consultent ne sont pas des
 * utilisatrices de l'ERP.
 *
 * Les pièces d'engagement et le dossier libre n'existent que pour les employés (tables
 * employee_documents/employee_files référencent employees, pas players) : un joueur ne voit
 * que ses fiches de paie, section déjà existante et inchangée.
 */
declare(strict_types=1);

$dbFile = __DIR__ . '/data/rh.sqlite';
if (!is_file($dbFile)) { http_response_code(500); exit('Configuration indisponible.'); }

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function esc(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$token = trim((string)($_GET['token'] ?? ''));
$person = null;
$personType = '';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
  $st = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE access_token = ?');
  $st->execute([$token]);
  $person = $st->fetch();
  $personType = 'employee';
  if (!$person) {
    $st = $pdo->prepare('SELECT id, first_name, last_name FROM players WHERE access_token = ?');
    $st->execute([$token]);
    $person = $st->fetch();
    $personType = 'player';
  }
}

$monthLabels = ['01'=>'janvier','02'=>'février','03'=>'mars','04'=>'avril','05'=>'mai','06'=>'juin','07'=>'juillet','08'=>'août','09'=>'septembre','10'=>'octobre','11'=>'novembre','12'=>'décembre'];

/* Catalogue des pièces d'engagement, dupliqué depuis index.html (DOC_TYPES) : cette page est
 * volontairement statique et sans dépendance JS, dupliquer une petite liste est plus simple
 * que la partager entre un contexte serveur et un contexte navigateur. */
$docTypeLabels = [
  'casier_special'        => 'Extrait spécial du casier judiciaire',
  'contrat_signe'         => 'Contrat de travail signé',
  'piece_identite'        => 'Copie pièce d\'identité',
  'permis_sejour'         => 'Permis de séjour / travail',
  'attestation_avs'       => 'Attestation AVS',
  'coordonnees_bancaires' => 'Coordonnées bancaires',
];

$payslips = [];
$pieces = [];
$files = [];
if ($person) {
  $st = $pdo->prepare("SELECT period, payslip_filename FROM payroll_payments
    WHERE person_type = ? AND person_id = ? AND payslip_filename != '' ORDER BY period DESC");
  $st->execute([$personType, $person['id']]);
  $payslips = $st->fetchAll();

  if ($personType === 'employee') {
    $st = $pdo->prepare("SELECT doc_type, received, file_name FROM employee_documents WHERE employee_id = ? AND file_name != '' ORDER BY doc_type");
    $st->execute([$person['id']]);
    $pieces = $st->fetchAll();

    $st = $pdo->prepare('SELECT id, label, file_name, uploaded_at FROM employee_files WHERE employee_id = ? ORDER BY uploaded_at DESC');
    $st->execute([$person['id']]);
    $files = $st->fetchAll();
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Meyrin FC — Mon dossier</title>
<style>
  :root{ --jaune:#ffdd00; --noir:#15140F; --gris:#6E6C61; --ligne:#E9E6DC; --bg:#F6F4ED; --carte:#fff; }
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,"Segoe UI",Inter,sans-serif;background:var(--bg);color:var(--noir);padding:32px 16px}
  .wrap{max-width:520px;margin:0 auto}
  .head{display:flex;align-items:center;gap:12px;margin-bottom:24px}
  .badge{width:40px;height:40px;background:var(--noir);border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--jaune);font-size:15px}
  h1{font-size:18px;margin:0}
  .sub{font-size:13px;color:var(--gris);margin-top:2px}
  h2{font-size:14px;margin:26px 0 10px}
  .card{background:var(--carte);border:1px solid var(--ligne);border-radius:14px;overflow:hidden}
  .row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;border-bottom:1px solid var(--ligne)}
  .row:last-child{border-bottom:none}
  .row-label{font-weight:600;font-size:14px;text-transform:capitalize}
  .row-sub{font-size:12px;color:var(--gris);margin-top:2px;text-transform:none}
  a.dl{background:var(--jaune);color:var(--noir);text-decoration:none;font-weight:700;font-size:13px;padding:8px 14px;border-radius:8px;white-space:nowrap}
  .empty{padding:28px 18px;text-align:center;color:var(--gris);font-size:14px}
  .error{background:var(--carte);border:1px solid var(--ligne);border-radius:14px;padding:28px;text-align:center;color:var(--gris)}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="badge">FC</div>
    <div>
      <h1>Meyrin FC</h1>
      <div class="sub">Mon dossier</div>
    </div>
  </div>
  <?php if (!$person): ?>
    <div class="error">Lien invalide ou expiré. Contacte le club pour en obtenir un nouveau.</div>
  <?php else: ?>
    <p class="sub" style="margin-bottom:6px">Bonjour <?= esc($person['first_name']) ?>.</p>

    <h2>Fiches de paie</h2>
    <div class="card">
      <?php if (!$payslips): ?>
        <div class="empty">Aucune fiche disponible pour le moment.</div>
      <?php else: foreach ($payslips as $d): [$y, $m] = explode('-', $d['period']); ?>
        <div class="row">
          <span class="row-label"><?= esc(($monthLabels[$m] ?? $m) . ' ' . $y) ?></span>
          <a class="dl" href="mon-document.php?token=<?= esc($token) ?>&period=<?= esc($d['period']) ?>" target="_blank">Télécharger</a>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php if ($personType === 'employee'): ?>
      <h2>Mes pièces d'engagement</h2>
      <div class="card">
        <?php if (!$pieces): ?>
          <div class="empty">Aucune pièce numérisée pour le moment.</div>
        <?php else: foreach ($pieces as $p): ?>
          <div class="row">
            <span class="row-label" style="text-transform:none"><?= esc($docTypeLabels[$p['doc_type']] ?? $p['doc_type']) ?></span>
            <a class="dl" href="mon-piece.php?token=<?= esc($token) ?>&doc_type=<?= esc($p['doc_type']) ?>" target="_blank">Ouvrir</a>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <h2>Autres documents</h2>
      <div class="card">
        <?php if (!$files): ?>
          <div class="empty">Aucun document pour le moment.</div>
        <?php else: foreach ($files as $f): ?>
          <div class="row">
            <span>
              <span class="row-label" style="text-transform:none"><?= esc($f['label'] ?: $f['file_name']) ?></span>
              <div class="row-sub"><?= esc(substr($f['uploaded_at'], 0, 10)) ?></div>
            </span>
            <a class="dl" href="mon-fichier.php?token=<?= esc($token) ?>&id=<?= (int)$f['id'] ?>" target="_blank">Ouvrir</a>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
