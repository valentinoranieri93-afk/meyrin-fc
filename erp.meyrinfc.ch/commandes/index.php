<?php
/**
 * Point d'entrée du CRM Commande. Authentification par la session unique de l'ERP.
 *
 * Ne concerne QUE l'interface interne. La boutique publique (/shop) est
 * volontairement indépendante et reste accessible sans compte.
 */

require_once __DIR__ . '/mfc_boot.php';

$user = mfc_require_login('commandes');

$roles     = json_decode((string)file_get_contents(DATA_DIR . 'roles.json'), true) ?: [];
$roleLabel = $roles[$user['role'] ?? '']['label'] ?? ($user['role'] ?? '');

$perms = [
    'catalogView'     => mfc_can('commandes.catalog.view'),
    'catalogEdit'     => mfc_can('commandes.catalog.edit'),
    'stockView'       => mfc_can('commandes.stock.view'),
    'stockMove'       => mfc_can('commandes.stock.move'),
    'requestsCreate'  => mfc_can('commandes.requests.create'),
    'requestsValidate'=> mfc_can('commandes.requests.validate'),
    'ordersView'      => mfc_can('commandes.orders.view'),
    'ordersEdit'      => mfc_can('commandes.orders.edit'),
    'ordersReceive'   => mfc_can('commandes.orders.receive'),
    'suppliersManage' => mfc_can('commandes.suppliers.manage'),
    'budgetsView'     => mfc_can('commandes.budgets.view'),
    'budgetsEdit'     => mfc_can('commandes.budgets.edit'),
    'invoicesManage'  => mfc_can('commandes.invoices.manage'),
    'reportsView'     => mfc_can('commandes.reports.view'),
    'settingsManage'  => mfc_can('commandes.settings.manage'),
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
