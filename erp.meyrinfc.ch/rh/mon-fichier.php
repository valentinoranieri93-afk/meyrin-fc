<?php
/** Téléchargement d'un document du dossier libre (employee_files) depuis l'espace personnel
 * (rh/mes-documents.php), gardé par token. L'id seul ne suffit pas : il doit appartenir à
 * l'employé identifié par le token, sinon n'importe quel id devinable ouvrirait le dossier
 * d'un autre employé. */
declare(strict_types=1);

$dbFile = __DIR__ . '/data/rh.sqlite';
if (!is_file($dbFile)) { http_response_code(500); exit('Configuration indisponible.'); }

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$token = trim((string)($_GET['token'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || !$id) {
  http_response_code(403); exit('Lien invalide.');
}

$st = $pdo->prepare('SELECT id FROM employees WHERE access_token = ?');
$st->execute([$token]);
$employeeId = $st->fetchColumn();
if (!$employeeId) { http_response_code(403); exit('Lien invalide ou expiré.'); }

$st = $pdo->prepare('SELECT file_path, file_name, file_mime FROM employee_files WHERE id = ? AND employee_id = ?');
$st->execute([$id, $employeeId]);
$row = $st->fetch();
if (!$row || !is_file($row['file_path'])) { http_response_code(404); exit('Document introuvable.'); }

header('Content-Type: ' . $row['file_mime']);
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . rawurlencode($row['file_name']) . '"');
header('Content-Length: ' . filesize($row['file_path']));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($row['file_path']);
