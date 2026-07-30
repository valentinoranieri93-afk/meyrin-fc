<?php
/** Téléchargement d'une fiche de paie depuis l'espace documents personnel (rh/mes-documents.php), gardé par token. */
declare(strict_types=1);

$dbFile = __DIR__ . '/data/rh.sqlite';
if (!is_file($dbFile)) { http_response_code(500); exit('Configuration indisponible.'); }

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$token = trim((string)($_GET['token'] ?? ''));
$period = trim((string)($_GET['period'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
  http_response_code(403); exit('Lien invalide.');
}

$st = $pdo->prepare('SELECT id FROM employees WHERE access_token = ?');
$st->execute([$token]);
$person = $st->fetch();
$personType = 'employee';
if (!$person) {
  $st = $pdo->prepare('SELECT id FROM players WHERE access_token = ?');
  $st->execute([$token]);
  $person = $st->fetch();
  $personType = 'player';
}
if (!$person) { http_response_code(403); exit('Lien invalide ou expiré.'); }

$st = $pdo->prepare('SELECT payslip_path, payslip_filename FROM payroll_payments WHERE person_type = ? AND person_id = ? AND period = ?');
$st->execute([$personType, $person['id'], $period]);
$row = $st->fetch();
if (!$row || !$row['payslip_path'] || !is_file($row['payslip_path'])) { http_response_code(404); exit('Document introuvable.'); }

$ext = strtolower(pathinfo($row['payslip_path'], PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($row['payslip_filename']) . '"');
header('Content-Length: ' . filesize($row['payslip_path']));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($row['payslip_path']);
