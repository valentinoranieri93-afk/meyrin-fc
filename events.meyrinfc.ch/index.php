<?php
/**
 * Point d'entrée du Hub Événements. Authentification par la session unique
 * de l'ERP. Les permissions sont injectées dans la page pour que l'interface
 * masque ce que la personne n'a pas le droit de faire ; api.php les revérifie.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('events');

$roles     = json_decode((string)file_get_contents(DATA_DIR . 'roles.json'), true) ?: [];
$roleLabel = $roles[$user['role'] ?? '']['label'] ?? ($user['role'] ?? '');

$perms = [
    'planningView'    => mfc_can('events.planning.view'),
    'planningEdit'    => mfc_can('events.planning.edit'),
    'volunteers'      => mfc_can('events.volunteers.manage'),
    'materiel'        => mfc_can('events.materiel.manage'),
    'documents'       => mfc_can('events.documents.manage'),
    'settingsManage'  => mfc_can('events.settings.manage'),
    'name'            => $user['name'] ?? '',
    'roleLabel'       => $roleLabel,
];

$html   = (string)file_get_contents(__DIR__ . '/index.html');
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
