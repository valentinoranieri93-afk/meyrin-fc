<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$required_app = 'sponsors';
require_once __DIR__ . '/guard.php';
readfile(__DIR__ . '/index.html');
