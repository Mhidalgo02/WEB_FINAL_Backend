<?php
// Run privately from backend/: php scripts/create_bplo_admin.php
// Uses LavaLust's Database class / Query Builder, not a separate PDO connection.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$envFile = $root . '/.env';
foreach (is_file($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if (getenv($key) === false) putenv($key . '=' . $value);
}
if (!getenv('DB_HOST') || !getenv('DB_NAME')) {
    fwrite(STDERR, "Configure DB_HOST and DB_NAME in backend/.env or the Render environment first.\n"); exit(1);
}

function ask($label)
{
    echo $label;
    return trim((string)fgets(STDIN));
}

$email = strtolower(ask('Admin email: '));
$name = ask('Admin full name: ');
$password = ask('Create a strong password (input visible): ');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($name) < 3 || strlen($password) < 12) {
    fwrite(STDERR, "Valid email, name (3+ characters), and password (12+ characters) required.\n");
    exit(1);
}

// Bootstrap only the bundled LavaLust database infrastructure for this CLI script.
define('PREVENT_DIRECT_ACCESS', true);
define('ROOT_DIR', $root . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . 'app' . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . 'scheme' . DIRECTORY_SEPARATOR);
require_once SYSTEM_DIR . 'kernel/Routine.php';
require_once SYSTEM_DIR . 'database/Database.php';

try {
    $db = new Database();
    $existing = $db->table('bplo_accounts')->select('id')->where('email', $email)->get();
    if ($existing) {
        fwrite(STDERR, "Account already exists. No changes made.\n");
        exit(1);
    }
    $db->table('bplo_accounts')->insert([
        'role' => 'admin',
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo "Admin account created using LavaLust Query Builder.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Unable to create admin. Check schema, PHP and Aiven connection: " . $e->getMessage() . "\n");
    exit(1);
}
