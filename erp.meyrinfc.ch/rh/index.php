<?php
require_once __DIR__ . '/guard.php';
ob_clean();
readfile(__DIR__ . '/index.html');
