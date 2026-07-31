<?php
/**
 * Point d'entrée du module Contacts. Authentification par la session unique
 * de l'ERP.
 *
 * Les permissions sont injectées dans la page pour que l'interface masque ce
 * que la personne n'a pas le droit de faire. api.php les revérifie de son
 * côté : masquer un bouton n'est pas une sécurité.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('contacts');

$perms = [
    'view'   => mfc_can('contacts.view'),
    'edit'   => mfc_can('contacts.edit'),
    'bank'   => mfc_can('contacts.bank'),
    'merge'  => mfc_can('contacts.merge'),
    'import' => mfc_can('contacts.import'),
    'export' => mfc_can('contacts.export'),
    'name'   => $user['name'] ?? '',
    'erpUrl' => ERP_URL,
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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $html;
