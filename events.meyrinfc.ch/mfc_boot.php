<?php
/**
 * mfc_boot.php — Amorceur d'authentification. Remplace guard.php.
 *
 * Ce fichier est identique dans toutes les applications et ne contient
 * AUCUN secret : il se contente de localiser le socle commun
 * erp.meyrinfc.ch/lib/mfc_auth.php, qui reste la seule source de vérité
 * pour la clé JWT, les permissions et la révocation.
 *
 * Usage :
 *   require_once __DIR__ . '/mfc_boot.php';
 *   $user = mfc_require_login('caisse');   // page HTML
 *   $user = mfc_require_api('caisse');     // API JSON
 */

$_mfc_candidates = [
    /* Module interne de l'ERP (ex. rh/) : le socle est un dossier plus haut. */
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
    /* Échouer bruyamment plutôt que de laisser passer sans authentification :
       un socle introuvable ne doit jamais se traduire par un accès ouvert. */
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
