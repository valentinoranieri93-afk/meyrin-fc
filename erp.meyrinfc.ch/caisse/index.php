<?php
/**
 * Point d'entrée de la Caisse. Authentification par la session unique de l'ERP.
 *
 * Les permissions de la personne connectée sont injectées dans la page pour que
 * l'interface masque ce qu'elle n'a pas le droit de faire. Le serveur les
 * revérifie de son côté (api.php) : masquer un bouton n'est pas une sécurité.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('caisse');

$perms = [
    'use'     => mfc_can('caisse.register.use'),
    'close'   => mfc_can('caisse.register.close'),
    'reports' => mfc_can('caisse.register.reports'),
    'name'    => $user['name'] ?? '',
];

$html   = (string)file_get_contents(__DIR__ . '/index.html');
$inject = '<script>window.MFC = ' . json_encode($perms, JSON_UNESCAPED_UNICODE) . ';</script>';

/* Injection avant la fermeture du head, avec repli en tête de document si la
   balise est absente, pour ne jamais servir la page sans ses permissions. */
if (stripos($html, '</head>') !== false) {
    $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1);
} else {
    $html = $inject . $html;
}

if (ob_get_level() > 0) ob_clean();
header('Content-Type: text/html; charset=utf-8');
/* Jamais de cache sur cette page : elle porte les permissions de la personne
   connectée, qui changent dès qu'un rôle est modifié. Une version en cache
   afficherait des droits périmés. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $html;
