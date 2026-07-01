<?php
if (!defined('ERP_ROOT')) die('Direct access denied.');

// Clé secrète JWT — à changer après le premier déploiement
define('JWT_SECRET',    'w`ct^\'3:O[Y1Su?Xp+V,P{Mo54B.ab]9HRE~>JDn;_=}g!"L');
define('JWT_DURATION',  8 * 3600);        // Durée session : 8h
define('COOKIE_NAME',   'mfc_session');
define('COOKIE_DOMAIN', '.meyrinfc.ch');  // Partagé entre tous les sous-domaines
define('ERP_URL',       'https://erp.meyrinfc.ch');
define('CLUB_NAME',     'Meyrin FC');
define('DATA_DIR',      __DIR__ . '/data/');
