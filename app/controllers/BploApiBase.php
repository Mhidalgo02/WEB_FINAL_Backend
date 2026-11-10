
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class BploApiBase extends Controller
{
    private $actor = null;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->database();
    }

    protected function fail($message, $code = 400)
    {
        $this->api->respond(['message' => $message], $code);
    }

    protected function send($data, $code = 200)
    {
        $this->api->respond($data, $code);
    }

    protected function body()
    {
        $data = $this->api->body();

        foreach (['password', 'oldPassword', 'newPassword'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = html_entity_decode(
                    $data[$key],
                    ENT_QUOTES,
                    'UTF-8'
                );
            }
        }

        return $data;
    }

    protected function jwtless()
    {
        if ($this->actor) return $this->actor;

        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $m)) {
            $this->fail('Please log in.', 401);
        }

        $session = $this->db->table('bplo_sessions')
            ->where('token_hash', hash('sha256', $m[1]))
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->get();

        if (!$session) {
            $this->fail('Session expired or account inactive. Please log in again.', 401);
        }

        $user = $this->db->table('bplo_accounts')
            ->select('id,role,name,email,phone,address,is_active')
            ->where('id', $session['account_id'])
            ->get();

        if (!$user || !$user['is_active']) {
            $this->fail('Session expired or account inactive. Please log in again.', 401);
        }

        $user['id'] = (string)$user['id'];
        unset($user['is_active']);

        $this->actor = $user;
        return $user;
    }

    protected function admin()
    {
        $actor = $this->jwtless();

        if ($actor['role'] !== 'admin') {
            $this->fail('Administrator access required.', 403);
        }

        return $actor;
    }

    protected function issue($user)
    {
        $raw = bin2hex(random_bytes(32));

        $this->db->table('bplo_sessions')->insert([
            'token_hash' => hash('sha256', $raw),
            'account_id' => $user['id'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);

        unset($user['password_hash'], $user['is_active']);
        $user['id'] = (string)$user['id'];

        $this->send(['token' => $raw, 'user' => $user]);
    }

    protected function audit($action, $ref = null, $details = '', $actorId = null)
    {
        $user = $this->actor;

        $this->db->table('bplo_audit')->insert([
            'account_id' => $actorId ?? ($user['id'] ?? null),
            'actor' => $user['name'] ?? 'Applicant',
            'action' => $action,
            'reference' => $ref,
            'details' => $details,
        ]);
    }

    // SAVE NOTIFICATIONS TO DATABASE
    protected function notify($accountId, $title, $message, $ref = null)
    {
        $this->db->table('bplo_notifications')->insert([
            'account_id' => $accountId,
            'title' => $title,
            'message' => $message,
            'reference' => $ref,
        ]);
    }

    // Send automatically after a successful save; old applicant preference records are no longer used.
    protected function sendExternalNotification(
        $accountId,
        $title,
        $message,
        $ref = null,
        $skipEmail = false
    ) {
        $emailEnabled = !$skipEmail && getenv('BPLO_EMAIL_ENABLED') === '1';
        $smsEnabled = getenv('BPLO_SMS_ENABLED') === '1';
        if (!$emailEnabled && !$smsEnabled) return;

        try {
            $account = $this->db->table('bplo_accounts')
                ->select('email,phone')->where('id', $accountId)->get();
        } catch (\Throwable $e) {
            error_log('BPLO notification account lookup failed: ' . $e->getMessage());
            return;
        }
        if (!$account) return;

        $text = $message . ($ref ? "\nReference: " . $ref : '');
        // Keep the channels independent: an email failure must not prevent the SMS attempt.
        if ($emailEnabled && filter_var($account['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            try {
                $html = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
                if (!$this->notificationMailer()->send($account['email'], 'BPLO - ' . $title, $html, $text)) {
                    error_log('BPLO email sending failed.');
                }
            } catch (\Throwable $e) {
                error_log('BPLO email notification error: ' . $e->getMessage());
            }
        }

        if ($smsEnabled && !empty($account['phone'])) {
            try {
                $smsText = 'BPLO: ' . str_replace("\n", ' ', $text);
                if (!$this->notificationSms()->send($account['phone'], $smsText)) {
                    error_log('BPLO SMS sending failed.');
                }
            } catch (\Throwable $e) {
                error_log('BPLO SMS notification error: ' . $e->getMessage());
            }
        }
    }

    protected function notificationMailer()
    {
        require_once dirname(__DIR__) . '/libraries/BploMailer.php';
        return new BploMailer();
    }

    protected function notificationSms()
    {
        require_once dirname(__DIR__) . '/libraries/BploSms.php';
        return new BploSms();
    }
    protected function row($ref, $allowOwn = true)
    {
        $actor = $this->jwtless();

        $record = $this->db->table('bplo_applications')
            ->where('reference_number', $ref)
            ->get();

        if (!$record) {
            $this->fail('Application not found.', 404);
        }

        if (
            $actor['role'] !== 'admin' &&
            (!$allowOwn || (string)$record['account_id'] !== $actor['id'])
        ) {
            $this->fail('Not permitted.', 403);
        }

        return $record;
    }

    protected function formatApplication($record)
    {
        return $this->formatApplications([$record])[0];
    }

    protected function formatApplications($records)
    {
        if (!$records) return [];

        $applicationIds = array_values(array_unique(array_column($records, 'id')));
        $accountIds = array_values(array_unique(array_column($records, 'account_id')));
        $referenceByApplication = [];
        foreach ($records as $record) {
            $referenceByApplication[(string)$record['id']] = $record['reference_number'];
        }
        $owners = [];
        foreach ($this->db->table('bplo_accounts')
            ->select('id,email')
            ->in('id', $accountIds)
            ->get_all() as $owner) {
            $owners[(string)$owner['id']] = $owner['email'];
        }

        $documents = [];
        foreach ($this->db->table('bplo_documents')
            ->select('id,application_id,document_type,file_name,mime_type,file_size,review_status,remarks,created_at')
            ->in('application_id', $applicationIds)
            ->order_by('id', 'ASC')
            ->get_all() as $doc) {
            $applicationId = (string)$doc['application_id'];
            $documents[$applicationId][] = [
                'id' => (string)$doc['id'],
                'documentType' => $doc['document_type'],
                'fileName' => $doc['file_name'],
                'mimeType' => $doc['mime_type'],
                'size' => $doc['file_size'],
                'reviewStatus' => $doc['review_status'],
                'reviewRemarks' => $doc['remarks'],
                'savedAt' => $doc['created_at'],
                'fileUrl' => '/applications/' .
                    rawurlencode($referenceByApplication[$applicationId]) .
                    '/documents/' . $doc['id'] . '/download',
            ];
        }

        $history = [];
        foreach ($this->db->table('bplo_status_history')
            ->select('application_id,status,remarks,updated_at')
            ->in('application_id', $applicationIds)
            ->order_by('id', 'ASC')
            ->get_all() as $item) {
            $applicationId = (string)$item['application_id'];
            $history[$applicationId][] = [
                'status' => $item['status'],
                'remarks' => $item['remarks'],
                'updatedAt' => $item['updated_at'],
            ];
        }

        $formatted = [];
        foreach ($records as $record) {
            $id = (string)$record['id'];
            $fields = json_decode($record['details_json'], true) ?: [];
            $formatted[] = array_merge($fields, [
                'referenceNumber' => $record['reference_number'],
                'ownerAccountEmail' => $owners[(string)$record['account_id']] ?? '',
                'status' => $record['status'],
                'remarks' => $record['remarks'] ?? '',
                'submittedAt' => $record['submitted_at'],
                'documents' => $documents[$id] ?? [],
                'statusHistory' => $history[$id] ?? [],
                'inspection' => $record['inspection_json']
                    ? json_decode($record['inspection_json'], true)
                    : null,
            ]);
        }

        return $formatted;
    }

    protected function storage()
    {
        $dir = ROOT_DIR . 'runtime/private_uploads';

        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            $this->fail('Upload directory unavailable.', 503);
        }

        return $dir;
    }
}
