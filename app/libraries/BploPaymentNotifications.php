<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/BploManualPayments.php';

// Fee notices automatically use both enabled backend channels; no applicant opt-in is required.
// Only failures are retried. SMS acceptance means queued by TextBee, not confirmed handset delivery.
class BploPaymentNotifications
{
    private $db;
    private $mailer;
    private $sms;
    private $config;

    public function __construct($db, $mailer = null, $sms = null, $config = null)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->sms = $sms;
        $this->config = $config ?? function ($key) { return (string)(getenv($key) ?: ($_ENV[$key] ?? '')); };
    }

    public static function fingerprint($fee)
    {
        return BploManualPayments::noticeFingerprint($fee);
    }

    public static function messages($app, $fee, $account)
    {
        $amount = number_format((int)$fee['amount_cents'] / 100, 2, '.', ',');
        $noFee = (int)$fee['amount_cents'] === 0;
        $lines = [
            'Hello ' . $account['name'] . ',',
            'Application reference: ' . $app['reference_number'],
            $noFee ? 'No payment is required for this application (PHP 0.00).' : 'Amount to pay: PHP ' . $amount,
            'Fee / reason: ' . $fee['purpose'],
        ];
        if (!$noFee) {
            $lines[] = 'Payment method: ' . $fee['method'];
            $lines[] = 'Recipient account: ' . $fee['account_name'];
            $lines[] = 'Account / mobile number: ' . $fee['account_number'];
        }
        if ($fee['instructions'] !== '') $lines[] = 'Instructions: ' . $fee['instructions'];
        $lines[] = $noFee ? 'Check your applicant portal for application updates.' : 'After paying, open Payments in your applicant portal and submit the transaction reference, date and proof of payment for verification.';
        $plain = implode("\n", $lines);
        // SMS stays short; full recipient details and instructions remain available by email and in the portal.
        $sms = $noFee
            ? 'BPLO ' . $app['reference_number'] . ': No payment required (PHP 0.00). Check Payments in your applicant portal.'
            : 'BPLO ' . $app['reference_number'] . ': Amount to pay PHP ' . $amount . ' via ' . $fee['method'] . '. Open Payments in your applicant portal for recipient details and to upload proof after paying.';
        return ['subject' => 'BPLO payment assessment - ' . $app['reference_number'], 'plain' => $plain,
            'html' => '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>', 'sms' => $sms];
    }

    private function channel($channel, $account, $message)
    {
        $get = $this->config;
        $email = $channel === 'email';
        if ($get($email ? 'BPLO_EMAIL_ENABLED' : 'BPLO_SMS_ENABLED') !== '1') {
            return ['status' => 'disabled', 'detail' => $email ? 'Enable BPLO_EMAIL_ENABLED in the backend.' : 'Enable BPLO_SMS_ENABLED in the backend.'];
        }
        $emailSettings = $get('MAIL_TRANSPORT') === 'brevo' ? ['BREVO_API_KEY', 'SMTP_FROM_EMAIL'] : ['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'];
        $required = $email ? $emailSettings : ['TEXTBEE_API_KEY'];
        foreach ($required as $key) if (trim($get($key)) === '') return ['status' => 'not_configured', 'detail' => 'Missing backend setting: ' . $key . '.'];
        $recipient = trim((string)($account[$email ? 'email' : 'phone'] ?? ''));
        if (($email && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) || (!$email && !preg_match('/^(?:09\d{9}|(?:\+?63)?9\d{9})$/D', preg_replace('/[\s-]/', '', $recipient)))) {
            return ['status' => 'invalid_recipient', 'detail' => $email ? 'Update the applicant account with a valid email address.' : 'Update the applicant account with a valid Philippine mobile number.'];
        }
        try {
            if ($email) {
                if (!$this->mailer) { require_once __DIR__ . '/BploMailer.php'; $this->mailer = new BploMailer(); }
                $provider = $this->mailer;
                $accepted = $provider->send($recipient, $message['subject'], $message['html'], $message['plain']);
            } else {
                if (!$this->sms) { require_once __DIR__ . '/BploSms.php'; $this->sms = new BploSms(); }
                $provider = $this->sms;
                $accepted = $provider->send($recipient, $message['sms']);
            }
            if ($accepted) return ['status' => $email ? 'sent' : 'accepted', 'detail' => $email ? 'Accepted by the email provider; inbox delivery is not confirmed.' : 'Accepted by TextBee for dispatch; check gateway history for delivery.'];
            $detail = method_exists($provider, 'getLastError') ? $provider->getLastError() : '';
            return ['status' => 'failed', 'detail' => $detail ?: ($email ? 'Email sending failed. Check SMTP and sender configuration.' : 'SMS sending failed. Check TextBee device connectivity and SIM balance.')];
        } catch (Throwable $e) {
            error_log('BPLO payment ' . $channel . ' notification failed: ' . $e->getMessage());
            return ['status' => 'failed', 'detail' => $email ? 'Email sending failed. Check SMTP settings, sender verification and the backend log.' : 'SMS sending failed. Check TextBee configuration, device connectivity and the backend log.'];
        }
    }

    public function send($applicationId, $actor)
    {
        if ($actor['role'] !== 'admin') throw new DomainException('Administrator access required.', 403);
        $this->db->transaction();
        try {
            // Serialize sending against assessment edits and another retry to prevent duplicate dispatch.
            // The payment assessment is already committed; a provider failure never removes the fee.
            $app = $this->db->raw('SELECT * FROM bplo_applications WHERE id = ? FOR UPDATE', [$applicationId])->fetch();
            if (!$app) throw new DomainException('Application not found.', 404);
            $state = (new BploManualPayments($this->db))->state($applicationId);
            $fee = $state['assessment'];
            if (!$fee) throw new DomainException('Save a fee assessment before sending a payment notice.', 409);
            if (in_array($app['status'], ['Released', 'Rejected'], true) || $state['submissions']) throw new DomainException('Payment notices are closed after proof submission or application closure.', 409);
            $account = $this->db->table('bplo_accounts')->select('name,email,phone')->where('id', $app['account_id'])->get();
            if (!$account) throw new DomainException('Applicant account not found.', 404);
            $key = 'payment_notice_' . $applicationId;
            $record = $this->db->table('bplo_settings')->where('setting_key', $key)->get();
            $previous = $record ? (json_decode($record['setting_json'], true) ?: []) : [];
            $fingerprint = self::fingerprint($fee);
            $notice = ($previous['fingerprint'] ?? '') === $fingerprint ? $previous : ['fingerprint' => $fingerprint];
            $message = self::messages($app, $fee, $account);
            $attempted = [];
            foreach (['email', 'sms'] as $channel) {
                if (in_array($notice[$channel]['status'] ?? '', ['sent', 'accepted'], true)) continue;
                $notice[$channel] = $this->channel($channel, $account, $message);
                $notice[$channel]['attemptedAt'] = gmdate('Y-m-d H:i:s');
                $attempted[] = $channel . ': ' . $notice[$channel]['status'];
            }
            $json = json_encode($notice);
            if ($record) $this->db->table('bplo_settings')->where('setting_key', $key)->update(['setting_json' => $json]);
            else $this->db->table('bplo_settings')->insert(['setting_key' => $key, 'setting_json' => $json]);
            if ($attempted) $this->db->table('bplo_audit')->insert(['account_id' => $actor['id'], 'actor' => $actor['name'],
                'action' => 'Payment notice attempted', 'reference' => $app['reference_number'], 'details' => implode('; ', $attempted)]);
            $this->db->commit();
            return $notice;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
