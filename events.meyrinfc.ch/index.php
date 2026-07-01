<?php
$required_app = 'events';
require_once __DIR__ . '/guard.php';
// Utilisateur authentifié et autorisé — on sert l'application
readfile(__DIR__ . '/index.html');
