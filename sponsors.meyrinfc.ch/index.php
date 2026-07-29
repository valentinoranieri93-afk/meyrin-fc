<?php
/**
 * Point d'entrée de SponsorFlow. Authentification par la session unique de l'ERP.
 *
 * Les permissions sont injectées dans la page pour que l'interface masque ce
 * que la personne n'a pas le droit de faire. api.php les revérifie de son
 * côté : masquer un bouton n'est pas une sécurité.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('sponsors');

$roles     = json_decode((string)file_get_contents(DATA_DIR . 'roles.json'), true) ?: [];
$roleLabel = $roles[$user['role'] ?? '']['label'] ?? ($user['role'] ?? '');

$perms = [
    'partnersView'  => mfc_can('sponsors.partners.view'),
    'partnersEdit'  => mfc_can('sponsors.partners.edit'),
    'contractsView' => mfc_can('sponsors.contracts.view'),
    'contractsEdit' => mfc_can('sponsors.contracts.edit'),
    'opportunities' => mfc_can('sponsors.opportunities.manage'),
    'tasks'         => mfc_can('sponsors.tasks.manage'),
    'documentsView' => mfc_can('sponsors.documents.view'),
    'documentsUp'   => mfc_can('sponsors.documents.upload'),
    'dashboardView' => mfc_can('sponsors.dashboard.view'),
    'name'          => $user['name'] ?? '',
    'roleLabel'     => $roleLabel,
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
/* Jamais de cache : la page porte les permissions de la personne connectée. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $html;
