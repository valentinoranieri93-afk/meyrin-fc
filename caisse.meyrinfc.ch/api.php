<?php
/**
 * Caisse Meyrin FC — API backend
 * Lit et écrit les données dans caisse_data.json
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// ============================================================
// ⚙️  CONFIGURATION — À MODIFIER AVANT DE METTRE EN LIGNE
// ============================================================
define('SECRET_KEY', 'MEYRIN_CAISSE_2026_VAL');   // ← Changer ceci
define('DATA_FILE',  __DIR__ . '/caisse_data.json');
// ============================================================

// Vérification de la clé secrète
$key = $_GET['key'] ?? '';
if ($key !== SECRET_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

// Lecture des données
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists(DATA_FILE)) {
        echo file_get_contents(DATA_FILE);
    } else {
        echo json_encode(['pin' => null, 'days' => []]);
    }
    exit;
}

// Écriture des données
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');

    // Vérification que le JSON est valide
    $data = json_decode($body, true);
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON invalide']);
        exit;
    }

    // Écriture atomique (LOCK_EX évite les conflits)
    $ok = file_put_contents(DATA_FILE, $body, LOCK_EX);
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Impossible d\'écrire le fichier']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
