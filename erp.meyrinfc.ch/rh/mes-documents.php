<?php
/**
 * Espace documents personnel (fiches de paie) pour un employé ou joueur — accès par lien à token,
 * sans compte ni mot de passe. Page volontairement indépendante du reste du module RH : les personnes
 * qui la consultent ne sont pas des utilisatrices de l'ERP.
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

$documents = [];
if ($person) {
  $st = $pdo->prepare("SELECT period, payslip_filename FROM payroll_payments
    WHERE person_type = ? AND person_id = ? AND payslip_filename != '' ORDER BY period DESC");
  $st->execute([$personType, $person['id']]);
  $documents = $st->fetchAll();
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Meyrin FC — Mes documents</title>
<style>
  :root{ --jaune:#ffdd00; --noir:#15140F; --gris:#6E6C61; --ligne:#E9E6DC; --bg:#F6F4ED; --carte:#fff; }
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,"Segoe UI",Inter,sans-serif;background:var(--bg);color:var(--noir);padding:32px 16px}
  .wrap{max-width:520px;margin:0 auto}
  .head{display:flex;align-items:center;gap:12px;margin-bottom:24px}
  .badge{width:40px;height:40px;background:var(--noir);border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--jaune);font-size:15px}
  h1{font-size:18px;margin:0}
  .sub{font-size:13px;color:var(--gris);margin-top:2px}
  .card{background:var(--carte);border:1px solid var(--ligne);border-radius:14px;overflow:hidden}
  .row{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--ligne)}
  .row:last-child{border-bottom:none}
  .row-label{font-weight:600;font-size:14px;text-transform:capitalize}
  a.dl{background:var(--jaune);color:var(--noir);text-decoration:none;font-weight:700;font-size:13px;padding:8px 14px;border-radius:8px}
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
      <div class="sub">Espace documents personnel</div>
    </div>
  </div>
  <?php if (!$person): ?>
    <div class="error">Lien invalide ou expiré. Contacte le club pour en obtenir un nouveau.</div>
  <?php else: ?>
    <p class="sub" style="margin-bottom:14px">Bonjour <?= esc($person['first_name']) ?>, voici tes fiches de paie disponibles.</p>
    <div class="card">
      <?php if (!$documents): ?>
        <div class="empty">Aucun document disponible pour le moment.</div>
      <?php else: foreach ($documents as $d): [$y, $m] = explode('-', $d['period']); ?>
        <div class="row">
          <span class="row-label"><?= esc(($monthLabels[$m] ?? $m) . ' ' . $y) ?></span>
          <a class="dl" href="mon-document.php?token=<?= esc($token) ?>&period=<?= esc($d['period']) ?>" target="_blank">Télécharger</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
