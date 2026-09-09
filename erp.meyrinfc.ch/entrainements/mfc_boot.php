<?php
/**
 * mfc_boot.php — Amorceur d'authentification. Identique dans toutes les apps.
 *
 * Ne contient AUCUN secret : localise le socle commun
 * erp.meyrinfc.ch/lib/mfc_auth.php, seule source de vérité pour la clé JWT,
 * les permissions et la révocation.
 *
 * Usage :
 *   require_once __DIR__ . '/mfc_boot.php';
 *   $user = mfc_require_login('entrainements');   // page HTML
 *   $user = mfc_require_api('entrainements');     // API JSON
 */

$_mfc_candidates = [
    /* Module interne de l'ERP : le socle est un dossier plus haut. */
    __DIR__ . '/../lib/mfc_auth.php',
    /* Application sur son propre sous-domaine, dossier frère de l'ERP. */
    __DIR__ . '/../erp.meyrinfc.ch/lib/mfc_auth.php',
    dirname(__DIR__) . '/erp.meyrinfc.ch/lib/mfc_auth.php',
    dirname(__DIR__, 2) . '/erp.meyrinfc.ch/lib/mfc_auth.php',
    dirname(__DIR__, 3) . '/erp.meyrinfc.ch/lib/mfc_auth.php',
    __DIR__ . '/../sites/erp.meyrinfc.ch/lib/mfc_auth.php',
];

$_mfc_socle = null;
foreach ($_mfc_candidates as $_c) {
    if (is_file($_c)) { $_mfc_socle = $_c; break; }
}

if (!$_mfc_socle) {
    /* Échouer bruyamment plutôt que de laisser passer sans authentification. */
    http_response_code(500);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Configuration : socle d\'authentification introuvable.']);
    } else {
        echo 'Erreur de configuration : socle d\'authentification introuvable. Contactez l\'administrateur.';
    }
    exit;
}

require_once $_mfc_socle;
