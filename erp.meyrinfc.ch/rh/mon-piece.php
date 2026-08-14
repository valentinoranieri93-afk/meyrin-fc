<?php
/** Téléchargement d'une pièce d'engagement (checklist fixe) depuis l'espace personnel
 * (rh/mes-documents.php), gardé par token. Même schéma que mon-document.php pour les
 * fiches de paie, sur employee_documents plutôt que payroll_payments. */
declare(strict_types=1);

$dbFile = __DIR__ . '/data/rh.sqlite';
if (!is_file($dbFile)) { http_response_code(500); exit('Configuration indisponible.'); }

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$token = trim((string)($_GET['token'] ?? ''));
$docType = trim((string)($_GET['doc_type'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || $docType === '') {
  http_response_code(403); exit('Lien invalide.');
}

$st = $pdo->prepare('SELECT id FROM employees WHERE access_token = ?');
$st->execute([$token]);
$employeeId = $st->fetchColumn();
if (!$employeeId) { http_response_code(403); exit('Lien invalide ou expiré.'); }

$st = $pdo->prepare('SELECT file_path, file_name, file_mime FROM employee_documents WHERE employee_id = ? AND doc_type = ?');
$st->execute([$employeeId, $docType]);
$row = $st->fetch();
if (!$row || !$row['file_path'] || !is_file($row['file_path'])) { http_response_code(404); exit('Document introuvable.'); }

header('Content-Type: ' . $row['file_mime']);
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . rawurlencode($row['file_name']) . '"');
header('Content-Length: ' . filesize($row['file_path']));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($row['file_path']);
