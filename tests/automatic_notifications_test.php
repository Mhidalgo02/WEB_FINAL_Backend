<?php
// Reuse the isolated LavaLust/SQLite test database. All providers below are fakes.
require_once __DIR__ . '/manual_payments_test.php';

class AutomaticNoticeController extends BploApiBase
{
    private $mail;
    private $sms;
    public function __construct($db, $mail, $sms) { $this->db = $db; $this->mail = $mail; $this->sms = $sms; }
    protected function notificationMailer() { return $this->mail; }
    protected function notificationSms() { return $this->sms; }
    public function dispatch($id, $skipEmail = false) {
        $this->sendExternalNotification($id, 'Application status updated', 'Application status: Under Review', 'BP-AUTO-TEST', $skipEmail);
    }
}
class AutomaticMail
{
    public $messages = [];
    public $throw = false;
    public function send($recipient, $subject, $html, $plain = null) {
        $this->messages[] = compact('recipient', 'subject', 'html', 'plain');
        if ($this->throw) throw new RuntimeException('Simulated email failure');
        return true;
    }
}
class AutomaticSms
{
    public $messages = [];
    public function send($recipient, $message) { $this->messages[] = compact('recipient', 'message'); return true; }
}
$emailFlag = getenv('BPLO_EMAIL_ENABLED');
$smsFlag = getenv('BPLO_SMS_ENABLED');
try {
    putenv('BPLO_EMAIL_ENABLED=1');
    putenv('BPLO_SMS_ENABLED=1');
    $mail = new AutomaticMail();
    $sms = new AutomaticSms();
    $automatic = new AutomaticNoticeController($db, $mail, $sms);
    // The existing test database contains an old account preference record with both channels false.
    $oldPrefs = $db->table('bplo_settings')->where('setting_key', 'notification_prefs_2')->get();
    check(json_decode($oldPrefs['setting_json'], true) === ['email' => false, 'sms' => false], 'Legacy disabled preferences fixture');
    $automatic->dispatch(2);
    check(count($mail->messages) === 1 && count($sms->messages) === 1, 'Both channels send despite legacy disabled preferences');
    check($mail->messages[0]['recipient'] === '2@example.test' && $sms->messages[0]['recipient'] === '09171234567', 'Registered account contacts used');
    check(strpos($mail->messages[0]['plain'], 'Under Review') !== false && strpos($sms->messages[0]['message'], 'BP-AUTO-TEST') !== false, 'Event and reference included');
    $db->table('bplo_accounts')->where('id', 3)->update(['phone' => '09171234568']);
    $automatic->dispatch(3);
    check(count($mail->messages) === 2 && count($sms->messages) === 2, 'New applicants with no saved preferences receive both channels');
    $automatic->dispatch(2, true);
    check(count($mail->messages) === 2 && count($sms->messages) === 3, 'Inspection helper skips duplicate email and still sends SMS');
    $mail->throw = true;
    $automatic->dispatch(2);
    check(count($mail->messages) === 3 && count($sms->messages) === 4, 'SMS still attempted when email throws');
    $mail->throw = false;
    putenv('BPLO_EMAIL_ENABLED=0');
    $automatic->dispatch(2);
    check(count($mail->messages) === 3 && count($sms->messages) === 5, 'Backend-disabled email leaves SMS operational');
    putenv('BPLO_SMS_ENABLED=0');
    $automatic->dispatch(2);
    check(count($mail->messages) === 3 && count($sms->messages) === 5, 'Backend switches still control provider availability');
    check($db->table('bplo_settings')->where('setting_key', 'notification_prefs_3')->get() === false, 'No preference record is needed or created');
    echo "PASS: automatic email/SMS for existing and new applicants, legacy preferences ignored, independent channel failures, and no duplicate inspection email.\n";
} finally {
    putenv($emailFlag === false ? 'BPLO_EMAIL_ENABLED' : 'BPLO_EMAIL_ENABLED=' . $emailFlag);
    putenv($smsFlag === false ? 'BPLO_SMS_ENABLED' : 'BPLO_SMS_ENABLED=' . $smsFlag);
}
