<?php
// Standalone API readiness; frontend files are deployed to their own static site.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$ready = is_file(__DIR__ . '/index.php') && is_file(dirname(__DIR__) . '/vendor/autoload.php') && extension_loaded('pdo_mysql');
http_response_code($ready ? 200 : 503);
echo json_encode(['status' => $ready ? 'ok' : 'not_ready']);

