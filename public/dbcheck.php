<?php
header('Content-Type: text/plain');
$ca = getenv('DB_SSL_CA');
echo "ca path: $ca\n";
echo "is_file: " . var_export(is_file($ca), true) . "\n";
echo "is_readable: " . var_export(is_readable($ca), true) . "\n";
echo "perms: " . substr(sprintf('%o', @fileperms($ca)), -4) . "\n";
$c = @file_get_contents($ca);
echo "size: " . strlen((string)$c) . " bytes\n";
echo "starts with: " . substr((string)$c, 0, 27) . "\n";
echo "certificates in file: " . substr_count((string)$c, 'BEGIN CERTIFICATE') . "\n\n";

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME');
foreach ([true, false] as $verify) {
    echo "--- verify server cert: " . ($verify ? 'on' : 'off') . " ---\n";
    try {
        new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_SSL_CA => $ca,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $verify,
        ]);
        echo "CONNECTED\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}