<?php
// Only for manual_payments_http_test.mjs: never loads .env or the application's database config.
$testDir = getenv('BPLO_PAYMENT_HTTP_TEST_DIR');
if (PHP_SAPI !== 'cli-server' || !$testDir || !is_dir($testDir)) { http_response_code(404); exit; }
define('PREVENT_DIRECT_ACCESS', true);
define('ROOT_DIR', $testDir . DIRECTORY_SEPARATOR);
function database_config() { return ['main' => ['driver' => 'sqlite', 'path' => ROOT_DIR . 'payments.sqlite']]; }
class HttpTestDatabaseErrors {
    public function show_database_error($message, $sql, $bindings, $error) { throw $error; }
}
function load_class($name, $dir = '') { return new HttpTestDatabaseErrors(); }
require_once dirname(__DIR__) . '/scheme/database/Database.php';
require_once dirname(__DIR__) . '/scheme/kernel/Controller.php';
require_once dirname(__DIR__) . '/app/controllers/BploPaymentsController.php';
class HttpTestDatabase extends Database {
    public function raw($sql, $args = []) { return parent::raw(str_replace(' FOR UPDATE', '', $sql), $args); }
}
class HttpPaymentController extends BploPaymentsController {
    public function __construct($db) { $this->db = $db; }
    protected function body() { return json_decode(file_get_contents('php://input'), true) ?: []; }
    protected function send($data, $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; }
    protected function fail($message, $code = 400) { $this->send(['message' => $message], $code); }
    protected function paymentNotifier() {
        $fake = new class { public function send(...$args) { return true; } };
        return new BploPaymentNotifications($this->db, $fake, $fake, fn($key) => in_array($key, ['BPLO_EMAIL_ENABLED', 'BPLO_SMS_ENABLED'], true) ? '1' : 'fixture-configured');
    }
}
$initialize = !is_file(ROOT_DIR . 'payments.sqlite');
$db = new HttpTestDatabase();
$db->raw('PRAGMA foreign_keys = ON');
if ($initialize) {
    $schema = explode('INSERT INTO bplo_settings', file_get_contents(dirname(__DIR__) . '/database/bplo_schema.sql'))[0];
    $schema = preg_replace('/^--.*$/m', '', $schema);
    $schema = str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
    $schema = str_replace(['BIGINT UNSIGNED', "ENUM('applicant','admin')", ' ON UPDATE CURRENT_TIMESTAMP', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['INTEGER', 'TEXT', '', ''], $schema);
    $schema = preg_replace('/^\s*KEY \([^\n]+\),\s*$/m', '', $schema);
    foreach (explode(';', $schema) as $sql) if (trim($sql) !== '') $db->raw($sql);
    $db->table('bplo_settings')->insert(['setting_key' => 'manual_payments_schema', 'setting_json' => '{"version":1}']);
    foreach ([[1, 'admin', 'a'], [2, 'applicant', 'b'], [3, 'applicant', 'c']] as [$id, $role, $token]) {
        $db->table('bplo_accounts')->insert(['id' => $id, 'role' => $role, 'name' => 'HTTP test ' . $id, 'email' => $id . '@example.test', 'phone' => '0917000000' . $id, 'password_hash' => 'unused']);
        $db->table('bplo_sessions')->insert(['token_hash' => hash('sha256', str_repeat($token, 64)), 'account_id' => $id, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    }
    foreach ([[1, 2], [2, 3]] as [$id, $owner]) {
        $db->table('bplo_applications')->insert(['id' => $id, 'account_id' => $owner, 'reference_number' => 'HTTP-' . $id, 'details_json' => '{"businessName":"HTTP test"}']);
    }
}
$controller = new HttpPaymentController($db);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' && $path === '/api/bplo/payments') $controller->index();
if (preg_match('#^/api/bplo/applications/(HTTP-[12])/payment(?:/(assessment|notice|submissions)(?:/(\d+)/(proof|review))?)?$#D', $path, $parts)) {
    $ref = $parts[1]; $action = $parts[2] ?? ''; $id = $parts[3] ?? ''; $detail = $parts[4] ?? '';
    if ($method === 'GET' && !$action) $controller->application($ref);
    if ($method === 'PUT' && $action === 'assessment') $controller->assessment($ref);
    if ($method === 'POST' && $action === 'notice') $controller->resendNotice($ref);
    if ($method === 'POST' && $action === 'submissions' && !$id) $controller->submit($ref);
    if ($method === 'PUT' && $detail === 'review') $controller->review($ref, $id);
    if ($method === 'GET' && $detail === 'proof') $controller->proof($ref, $id);
}
http_response_code(404);
header('Content-Type: application/json');
echo '{"message":"Not found"}';
