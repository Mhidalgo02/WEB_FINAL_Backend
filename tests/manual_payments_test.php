<?php
// Isolated integration tests: actual LavaLust query builder + SQLite, no .env or live DB access.
define('PREVENT_DIRECT_ACCESS', true);
function database_config() { return ['main' => ['driver' => 'sqlite', 'path' => ':memory:']]; }
class TestDatabaseErrors {
    public function show_database_error($message, $sql, $bindings, $error) { throw $error; }
}
function load_class($name, $dir = '') { return new TestDatabaseErrors(); }
require_once dirname(__DIR__) . '/scheme/database/Database.php';
require_once dirname(__DIR__) . '/scheme/kernel/Controller.php';
require_once dirname(__DIR__) . '/app/libraries/BploManualPayments.php';
require_once dirname(__DIR__) . '/app/controllers/BploPaymentsController.php';
require_once dirname(__DIR__) . '/app/controllers/BploApplicationsController.php';
class PaymentTestDatabase extends Database {
    public function raw($sql, $args = []) { return parent::raw(str_replace(' FOR UPDATE', '', $sql), $args); }
}
class TestResponse extends RuntimeException {
    public $data;
    public function __construct($data, $code) { parent::__construct($data['message'] ?? 'Response', $code); $this->data = $data; }
}
trait PaymentTestRequest {
    private $testActor;
    private $testBody;
    public function __construct($db, $actor, $body = []) { $this->db = $db; $this->testActor = $actor; $this->testBody = $body; }
    protected function jwtless() { return $this->testActor; }
    protected function body() { return $this->testBody; }
    protected function send($data, $code = 200) { throw new TestResponse($data, $code); }
    protected function fail($message, $code = 400) { throw new TestResponse(['message' => $message], $code); }
    protected function sendExternalNotification($accountId, $title, $message, $ref = null, $skipEmail = false) {}
}
class PaymentTestController extends BploPaymentsController { use PaymentTestRequest; }
class ApplicationTestController extends BploApplicationsController { use PaymentTestRequest; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function denied($callback, $code, $contains = '') {
    try { $callback(); } catch (DomainException $e) {
        check($e->getCode() === $code && (!$contains || strpos($e->getMessage(), $contains) !== false), 'Unexpected denial: ' . $e->getMessage()); return;
    }
    throw new RuntimeException('Expected denial ' . $code);
}
function response($callback, $code) {
    try { $callback(); } catch (TestResponse $e) { check($e->getCode() === $code, 'HTTP ' . $e->getCode() . ': ' . $e->getMessage()); return $e->data; }
    throw new RuntimeException('Expected API response ' . $code);
}
$db = new PaymentTestDatabase();
$db->raw('PRAGMA foreign_keys = ON');
// Translate only MySQL DDL syntax; use the production schema's real table/column/constraint definitions.
$schema = explode('INSERT INTO bplo_settings', file_get_contents(dirname(__DIR__) . '/database/bplo_schema.sql'))[0];
$schema = preg_replace('/^--.*$/m', '', $schema);
$schema = str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
$schema = str_replace(['BIGINT UNSIGNED', "ENUM('applicant','admin')", ' ON UPDATE CURRENT_TIMESTAMP', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['INTEGER', 'TEXT', '', ''], $schema);
$schema = preg_replace('/^\s*KEY \([^\n]+\),\s*$/m', '', $schema);
foreach (explode(';', $schema) as $sql) if (trim($sql) !== '') $db->raw($sql);
$db->table('bplo_settings')->insert(['setting_key' => 'manual_payments_schema', 'setting_json' => '{"version":1}']);
foreach ([[1, 'admin'], [2, 'applicant'], [3, 'applicant']] as [$id, $role]) {
    $db->table('bplo_accounts')->insert(['id' => $id, 'role' => $role, 'name' => 'Test ' . $id, 'email' => $id . '@example.test', 'password_hash' => 'unused']);
}
foreach ([[1, 2], [2, 3], [3, 2], [4, 2], [5, 2], [6, 2]] as [$id, $owner]) {
    $db->table('bplo_applications')->insert(['id' => $id, 'account_id' => $owner, 'reference_number' => 'BP-TEST-' . $id, 'details_json' => '{"businessName":"Test business"}']);
}
$admin = ['id' => '1', 'role' => 'admin', 'name' => 'Admin'];
$owner = ['id' => '2', 'role' => 'applicant', 'name' => 'Owner'];
$other = ['id' => '3', 'role' => 'applicant', 'name' => 'Other'];
$payments = new BploManualPayments($db);
$fee = ['amount' => '100.25', 'purpose' => 'Test fee', 'method' => 'GCash', 'accountName' => 'Test recipient', 'accountNumber' => '09000000000', 'instructions' => 'Test only'];
$proof = ['file_name' => 'proof.png', 'storage_name' => str_repeat('a', 48), 'mime_type' => 'image/png', 'file_size' => 100, 'proof_hash' => hash('sha256', 'test-proof')];
$submission = ['amount' => '100.25', 'reference' => 'REF-12345', 'paidOn' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')];
check(BploManualPayments::installed($db), 'Schema marker');
check(BploManualPayments::cents('0.01') === 1 && BploManualPayments::cents('100.25') === 10025, 'Integer centavos');
foreach (['-1', '1.001', '1e3', '1000000.01', '0', [], 1.23] as $amount) denied(fn() => BploManualPayments::cents($amount), 422);
denied(fn() => $payments->assess(1, $owner, $fee), 403);
denied(fn() => $payments->assertReleasable(1), 409);
$payments->assess(1, $admin, $fee);
$payments->assess(2, $admin, $fee);
$payments->assess(3, $admin, $fee);
check($payments->state(1)['paymentStatus'] === 'Unpaid', 'Assessment starts unpaid');
$payments->assess(1, $admin, array_merge($fee, ['purpose' => 'Updated fee purpose']));
denied(fn() => $payments->submit(1, $other, $submission, $proof), 403);
denied(fn() => $payments->submit(1, $admin, $submission, $proof), 403);
denied(fn() => $payments->submit(1, $owner, array_merge($submission, ['amount' => '99']), $proof), 422);
denied(fn() => $payments->submit(1, $owner, array_merge($submission, ['paidOn' => '2099-01-01']), $proof), 422);
denied(fn() => $payments->submit(1, $owner, array_merge($submission, ['paidOn' => '2026-02-30']), $proof), 422);
$payments->submit(1, $owner, $submission, $proof);
check($payments->state(1)['paymentStatus'] === 'Awaiting verification', 'Proof cannot mark payment paid');
check(!array_key_exists('storage_name', $payments->state(1)['submissions'][0]), 'Private storage path omitted');
denied(fn() => $payments->assertReleasable(1), 409);
denied(fn() => $payments->assess(1, $admin, $fee), 409, 'locked');
denied(fn() => $payments->submit(1, $owner, $submission, $proof), 409);
denied(fn() => $payments->submit(2, $other, array_merge($submission, ['reference' => 'ref 12345']), array_merge($proof, ['proof_hash' => hash('sha256', 'different')])), 409);
denied(fn() => $payments->submit(2, $other, array_merge($submission, ['reference' => 'DIFFERENT']), $proof), 409);
$first = $payments->state(1)['submissions'][0]['id'];
denied(fn() => $payments->review(1, $first, $owner, ['status' => 'Verified']), 403);
denied(fn() => $payments->review(2, $first, $admin, ['status' => 'Verified']), 404);
denied(fn() => $payments->review(1, $first, $admin, ['status' => 'Rejected', 'remarks' => '']), 422);
$payments->review(1, $first, $admin, ['status' => 'Rejected', 'remarks' => 'Proof unreadable']);
check($payments->state(1)['paymentStatus'] === 'Rejected', 'Rejection state');
denied(fn() => $payments->review(1, $first, $admin, ['status' => 'Verified']), 409);
$payments->submit(1, $owner, $submission, $proof);
$attempts = $payments->state(1)['submissions'];
check(count($attempts) === 2 && $attempts[0]['review_remarks'] === 'Proof unreadable', 'Retry retains rejected history');
$payments->review(1, $attempts[1]['id'], $admin, ['status' => 'Verified', 'remarks' => 'Matched recipient transaction']);
$payments->assertReleasable(1);
check($payments->state(1)['paymentStatus'] === 'Paid', 'Verified payment');
check($db->table('bplo_applications')->where('id', 1)->get()['status'] === 'Pending', 'Payment verification does not approve or release application');
denied(fn() => $payments->submit(3, $owner, $submission, $proof), 409);
$payments->assess(4, $admin, ['amount' => '0', 'purpose' => 'Service has no fee']);
$payments->assertReleasable(4);
check($payments->state(4)['paymentStatus'] === 'No fee required', 'Explicit no-fee assessment');
denied(fn() => $payments->submit(4, $owner, $submission, $proof), 409);
// Controller tests exercise the real role and application-ownership checks with isolated actors.
$_SERVER['REQUEST_METHOD'] = 'GET';
$list = response(fn() => (new PaymentTestController($db, $other))->index(), 200)['data'];
check(count($list) === 1 && $list[0]['referenceNumber'] === 'BP-TEST-2', 'Payment list filtered by owner');
response(fn() => (new PaymentTestController($db, $other))->application('BP-TEST-1'), 403);
response(fn() => (new PaymentTestController($db, $other))->proof('BP-TEST-1', $first), 403);
response(fn() => (new PaymentTestController($db, $owner, $fee))->assessment('BP-TEST-1'), 403);
response(fn() => (new PaymentTestController($db, $owner))->review('BP-TEST-1', $first), 403);
response(fn() => (new PaymentTestController($db, $admin))->proof('BP-TEST-2', $first), 404);
$_SERVER['REQUEST_METHOD'] = 'PUT';
response(fn() => (new ApplicationTestController($db, $admin, ['status' => 'Released']))->status('BP-TEST-3'), 409);
check($db->table('bplo_applications')->where('id', 3)->get()['status'] === 'Pending', 'Failed release rolled back');
response(fn() => (new ApplicationTestController($db, $admin, ['status' => 'Released']))->status('BP-TEST-5'), 409);
response(fn() => (new ApplicationTestController($db, $admin, ['status' => 'Released']))->status('BP-TEST-1'), 200);
response(fn() => (new ApplicationTestController($db, $admin, ['status' => 'Released']))->status('BP-TEST-4'), 200);
denied(fn() => $payments->assess(1, $admin, $fee), 409);
$_SERVER['REQUEST_METHOD'] = 'DELETE';
response(fn() => (new ApplicationTestController($db, $admin))->application('BP-TEST-1'), 409);

// Automatic fee notices use injected providers: no SMTP/TextBee calls or real recipients in tests.
class FeeNoticeMail {
    public $messages = [];
    public $succeed = true;
    public function send($recipient, $subject, $html, $plain = null) { $this->messages[] = compact('recipient', 'subject', 'html', 'plain'); return $this->succeed; }
    public function getLastError() { return 'Test SMTP refusal'; }
}
class FeeNoticeSms {
    public $messages = [];
    public $succeed = false;
    public function send($recipient, $text) { $this->messages[] = compact('recipient', 'text'); return $this->succeed; }
    public function getLastError() { return 'TextBee HTTP 503: test gateway unavailable'; }
}
class FeeNoticeController extends PaymentTestController {
    private $notifier;
    public function __construct($db, $actor, $body, $notifier) { parent::__construct($db, $actor, $body); $this->notifier = $notifier; }
    protected function paymentNotifier() { return $this->notifier; }
}
$mail = new FeeNoticeMail();
$sms = new FeeNoticeSms();
$enabled = fn($key) => in_array($key, ['BPLO_EMAIL_ENABLED', 'BPLO_SMS_ENABLED'], true) ? '1' : 'test-configured';
$notifier = new BploPaymentNotifications($db, $mail, $sms, $enabled);
$db->table('bplo_accounts')->where('id', 2)->update(['phone' => '09171234567']);
$db->table('bplo_settings')->insert(['setting_key' => 'notification_prefs_2', 'setting_json' => '{"email":false,"sms":false}']);
$noticeFee = array_merge($fee, ['amount' => '500.25', 'purpose' => 'Fee <script>test</script>']);
$saved = response(fn() => (new FeeNoticeController($db, $admin, $noticeFee, $notifier))->assessment('BP-TEST-5'), 200);
check($saved['notice']['email']['status'] === 'sent' && $saved['notice']['sms']['status'] === 'failed', 'Separate email and SMS outcomes');
check($payments->state(5)['assessment']['amount_cents'] === 50025, 'Notification failure does not remove assessment');
check($payments->state(5)['notice']['sms']['detail'] === 'TextBee HTTP 503: test gateway unavailable', 'Actionable provider error retained');
check(count($mail->messages) === 1 && count($sms->messages) === 1, 'Both channels attempted despite optional-update preferences');
check($mail->messages[0]['recipient'] === '2@example.test' && $sms->messages[0]['recipient'] === '09171234567', 'Registered applicant contacts used');
check(strpos($mail->messages[0]['plain'], 'PHP 500.25') !== false && strpos($mail->messages[0]['plain'], 'BP-TEST-5') !== false, 'Email contains exact amount and reference');
check(strpos($mail->messages[0]['plain'], '09000000000') !== false && strpos($mail->messages[0]['html'], '&lt;script&gt;') !== false, 'Payment instructions included and email HTML escaped');
check(strpos($sms->messages[0]['text'], 'PHP 500.25') !== false && strpos($sms->messages[0]['text'], 'BP-TEST-5') !== false, 'SMS contains exact amount and reference');
response(fn() => (new FeeNoticeController($db, $owner, [], $notifier))->resendNotice('BP-TEST-5'), 403);
$sms->succeed = true;
$retried = response(fn() => (new FeeNoticeController($db, $admin, [], $notifier))->resendNotice('BP-TEST-5'), 200);
check($retried['notice']['sms']['status'] === 'accepted' && count($mail->messages) === 1 && count($sms->messages) === 2, 'Retry skips already-sent email');
$notifier->send(5, $admin);
check(count($mail->messages) === 1 && count($sms->messages) === 2, 'Successful notices are not dispatched twice on retry');
$payments->assess(5, $admin, array_merge($noticeFee, ['amount' => '600.25']));
check($payments->state(5)['notice'] === null, 'Old delivery results hidden after amount changes');
$notifier->send(5, $admin);
check(count($mail->messages) === 2 && count($sms->messages) === 3 && strpos($sms->messages[2]['text'], 'PHP 600.25') !== false, 'Changed fee creates new notices');
$payments->assess(6, $admin, ['amount' => '0', 'purpose' => 'No fee for this service']);
$noFeeNotice = $notifier->send(6, $admin);
check($noFeeNotice['email']['status'] === 'sent' && strpos($sms->messages[3]['text'], 'No payment required') !== false, 'No-fee notice clearly states no payment required');
$payments->assess(6, $admin, ['amount' => '0', 'purpose' => 'Revised no-fee reason']);
$disabled = new BploPaymentNotifications($db, $mail, $sms, fn($key) => '');
check($disabled->send(6, $admin)['sms']['status'] === 'disabled', 'Disabled providers reported honestly');
$missing = new BploPaymentNotifications($db, $mail, $sms, fn($key) => $key === 'SMTP_PASSWORD' ? '' : $enabled($key));
check($missing->send(6, $admin)['email']['status'] === 'not_configured', 'Missing SMTP setup is actionable');
$db->table('bplo_accounts')->where('id', 2)->update(['email' => 'bad-address', 'phone' => 'invalid']);
$badContact = $notifier->send(6, $admin);
check($badContact['email']['status'] === 'invalid_recipient', 'Invalid recipient email reported');
// SMS was accepted in the preceding retry and is not resent when only email needs correction.
check(count($sms->messages) === 5, 'Successful SMS retained while fixing email');
$payments->assess(6, $admin, ['amount' => '0', 'purpose' => 'Contact-validation trial']);
$badContacts = $notifier->send(6, $admin);
check($badContacts['email']['status'] === 'invalid_recipient' && $badContacts['sms']['status'] === 'invalid_recipient', 'Both invalid registered contacts reported without provider calls');
$db->table('bplo_accounts')->where('id', 2)->update(['email' => '2@example.test', 'phone' => '09171234567']);
check($notifier->send(6, $admin)['email']['status'] === 'sent', 'Corrected email can be retried');
$db->table('bplo_applications')->where('id', 6)->update(['status' => 'Released']);
denied(fn() => $notifier->send(6, $admin), 409);
denied(fn() => $notifier->send(1, $admin), 409);
$db->table('bplo_applications')->insert(['id' => 7, 'account_id' => 2, 'reference_number' => 'BP-TEST-7', 'details_json' => '{}']);
$payments->assess(7, $admin, $fee);
$brevoConfig = fn($key) => ['MAIL_TRANSPORT' => 'brevo', 'BPLO_EMAIL_ENABLED' => '1', 'BPLO_SMS_ENABLED' => '0',
    'BREVO_API_KEY' => 'test-api-key', 'SMTP_FROM_EMAIL' => 'sender@example.test'][$key] ?? '';
$brevoNotice = (new BploPaymentNotifications($db, $mail, $sms, $brevoConfig))->send(7, $admin);
check($brevoNotice['email']['status'] === 'sent', 'Brevo HTTPS configuration does not require SMTP credentials');
$db->table('bplo_settings')->where('setting_key', 'manual_payments_schema')->delete();
response(fn() => (new PaymentTestController($db, $admin))->index(), 503);
check(count($db->table('bplo_audit')->get_all()) >= 10, 'Payment events audited');
check(count($db->table('bplo_notifications')->where('account_id', 1)->get_all()) === 2, 'Admins notified of both proof submissions');
echo "PASS: centavos, roles, ownership, assessment lock, duplicate references/proofs, rejection/retry, verification, audit/notifications, protected proof access and release blocking.\n";
echo "PASS: automatic fee email/SMS, exact amounts and recipients, HTML escaping, partial failure persistence, retry without duplicate delivery, revised fees, no-fee notices, provider configuration and contact validation.\n";
