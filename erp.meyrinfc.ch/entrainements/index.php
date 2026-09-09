<?php
/**
 * Point d'entrée du module Entraînements. Authentification par la session
 * unique de l'ERP. Les permissions sont injectées dans la page pour que
 * l'interface masque ce que la personne n'a pas le droit de faire ;
 * api.php les revérifie systématiquement.
 *
 * LOT 1 : page d'accueil minimale (vérification du déploiement et du schéma).
 * L'interface complète arrive à l'étape 4.
 */

declare(strict_types=1);

require_once __DIR__ . '/mfc_boot.php';
require_once __DIR__ . '/lib_entrainements.php';

$user  = mfc_require_login('entrainements');
$perms = et_perms_pour_page($user);

$html   = (string) file_get_contents(__DIR__ . '/index.html');
$inject = '<script>window.MFC = ' . json_encode($perms, JSON_UNESCAPED_UNICODE) . ';</script>';

if (stripos($html, '</head>') !== false) {
    $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1);
} else {
    $html = $inject . $html;
}

if (ob_get_level() > 0) ob_clean();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $html;
