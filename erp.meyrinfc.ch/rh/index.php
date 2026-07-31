<?php
/**
 * Point d'entrée du module RH. Authentification par la session unique de l'ERP.
 *
 * Les permissions sont injectées dans la page pour que l'interface masque ce
 * que la personne n'a pas le droit de faire. api.php les revérifie de son
 * côté : masquer un onglet n'est pas une sécurité.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('rh');

$perms = [
    /* Les équipes et catégories viennent-elles du référentiel de l'ERP ?
       Si oui, l'interface les affiche sans permettre de les renommer ici, et
       renvoie vers l'ERP. api.php applique la même règle de son côté. */
    'clubManaged'   => !mfc_club_is_empty(),
    'erpUrl'        => ERP_URL,
    'teamsView'     => mfc_can('rh.teams.view'),
    'teamsEdit'     => mfc_can('rh.teams.edit'),
    'employeesView' => mfc_can('rh.employees.view'),
    'employeesEdit' => mfc_can('rh.employees.edit'),
    'indemnites'    => mfc_can('rh.indemnites.edit'),
    'payrollView'   => mfc_can('rh.payroll.view'),
    'payrollEdit'   => mfc_can('rh.payroll.edit'),
    'primes'        => mfc_can('rh.primes.edit'),
    'imports'       => mfc_can('rh.imports.run'),
    'exportCompta'  => mfc_can('rh.export.compta'),
    'name'          => $user['name'] ?? '',
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
/* Jamais de cache : la page porte les permissions de la personne connectée,
   qui changent dès qu'un rôle est modifié. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $html;
