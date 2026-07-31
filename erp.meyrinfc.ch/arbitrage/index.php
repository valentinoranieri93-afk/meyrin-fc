<?php
/**
 * Point d'entrée d'Arbitrage. Authentification par la session unique de l'ERP.
 * Les permissions sont injectées dans la page pour que l'interface masque ce
 * que la personne n'a pas le droit de faire ; api.php les revérifie.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('arbitrage');

$roles     = json_decode((string)file_get_contents(DATA_DIR . 'roles.json'), true) ?: [];
$roleLabel = $roles[$user['role'] ?? '']['label'] ?? ($user['role'] ?? '');

$perms = [
    'matchesView'   => mfc_can('arbitrage.matches.view'),
    'matchesEdit'   => mfc_can('arbitrage.matches.edit'),
    'matchesPay'    => mfc_can('arbitrage.matches.pay'),
    'teamsManage'   => mfc_can('arbitrage.teams.manage'),
    'seasonsManage' => mfc_can('arbitrage.seasons.manage'),
    'importRun'     => mfc_can('arbitrage.import.run'),
    'statsView'     => mfc_can('arbitrage.stats.view'),
    'name'          => $user['name'] ?? '',
    'roleLabel'     => $roleLabel,
    /* Équipes, catégories et saisons viennent-elles du référentiel de l'ERP ?
       Si oui, l'interface ne propose plus de les modifier ici. api.php applique
       la même règle : masquer un champ n'est pas une sécurité. */
    'clubManaged'   => !mfc_club_is_empty(),
    'erpUrl'        => ERP_URL,
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
