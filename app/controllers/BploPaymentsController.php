<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';
require_once dirname(__DIR__) . '/libraries/BploManualPayments.php';
require_once dirname(__DIR__) . '/libraries/BploPaymentNotifications.php';

class BploPaymentsController extends BploApiBase
{
    private function payments()
    {
        if (!BploManualPayments::installed($this->db)) {
            $this->fail('Manual payments need database setup. Run php scripts/setup_manual_payments.php from backend.', 503);
        }
        return new BploManualPayments($this->db);
    }

    public function index()
    {
        $actor = $this->jwtless();
        $payments = $this->payments();
        $query = $this->db->table('bplo_applications');
        if ($actor['role'] !== 'admin') $query->where('account_id', $actor['id']);
        $apps = $query->order_by('id', 'ASC')->get_all();
        $states = $payments->states(array_column($apps, 'id'));
        $data = [];
        foreach ($apps as $app) {
            $details = json_decode($app['details_json'], true) ?: [];
            $data[] = array_merge([
                'referenceNumber' => $app['reference_number'], 'businessName' => $details['businessName'] ?? '',
                'fullName' => $details['fullName'] ?? '', 'applicationStatus' => $app['status'],
            ], $states[(string)$app['id']]);
        }
        $this->send(['data' => $data]);
    }

    public function application($ref)
    {
        $app = $this->row($ref);
        $this->send($this->payments()->state($app['id']));
    }

    public function assessment($ref)
    {
        $actor = $this->admin();
        $app = $this->row($ref);
        try { $this->payments()->assess($app['id'], $actor, $this->body()); }
        catch (DomainException $e) { $this->fail($e->getMessage(), $e->getCode() ?: 422); }
        try { $notice = $this->paymentNotifier()->send($app['id'], $actor); }
        catch (Throwable $e) {
            error_log('BPLO fee saved; notification dispatch did not complete: ' . $e->getMessage());
            $detail = $e instanceof DomainException ? $e->getMessage() : 'Notification attempt did not complete. Check the backend log and retry.';
            $notice = ['email' => ['status' => 'not_sent', 'detail' => $detail], 'sms' => ['status' => 'not_sent', 'detail' => $detail]];
        }
        $this->send(['message' => 'Fee assessment saved. Check the email and SMS notification results below.', 'notice' => $notice]);
    }

    protected function paymentNotifier() { return new BploPaymentNotifications($this->db); }

    public function resendNotice($ref)
    {
        $actor = $this->admin();
        $app = $this->row($ref);
        $this->payments();
        try { $notice = $this->paymentNotifier()->send($app['id'], $actor); }
        catch (DomainException $e) { $this->fail($e->getMessage(), $e->getCode() ?: 409); }
        $this->send(['message' => 'Notification retry completed. Check the email and SMS results below.', 'notice' => $notice]);
    }

    private function proofDirectory()
    {
        $dir = ROOT_DIR . 'runtime/private_payments';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) $this->fail('Payment upload directory unavailable.', 503);
        return $dir;
    }

    public function submit($ref)
    {
        $actor = $this->jwtless();
        if ($actor['role'] !== 'applicant') $this->fail('Only the applicant can submit payment proof.', 403);
        $app = $this->row($ref);
        $payments = $this->payments();
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
            $this->fail('Upload a nonempty PDF, JPG or PNG no larger than 5 MB.', 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) $this->fail('Only PDF, JPG and PNG proof files are allowed.', 422);
        $name = substr(preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename($file['name'])), 0, 200);
        $storage = bin2hex(random_bytes(24));
        $proof = ['file_name' => $name ?: 'payment-proof', 'storage_name' => $storage,
            'mime_type' => $mime, 'file_size' => $file['size'], 'proof_hash' => hash_file('sha256', $file['tmp_name'])];
        $path = $this->proofDirectory() . '/' . $storage;
        if (!move_uploaded_file($file['tmp_name'], $path)) $this->fail('Unable to save payment proof.', 500);
        try { $payments->submit($app['id'], $actor, $_POST, $proof); }
        catch (Throwable $e) {
            @unlink($path);
            if ($e instanceof DomainException) $this->fail($e->getMessage(), $e->getCode() ?: 422);
            if ($e instanceof PDOException && ((string)$e->getCode() === '23000' || strpos($e->getMessage(), 'SQLSTATE[23000]') !== false)) {
                $this->fail('This payment reference or proof is already awaiting review or verified.', 409);
            }
            throw $e;
        }
        $this->send(['message' => 'Proof submitted. Payment awaits administrator verification.'], 201);
    }

    public function review($ref, $id)
    {
        $actor = $this->admin();
        $app = $this->row($ref);
        try { $this->payments()->review($app['id'], $id, $actor, $this->body()); }
        catch (DomainException $e) { $this->fail($e->getMessage(), $e->getCode() ?: 422); }
        $this->send(['message' => 'Payment review saved.']);
    }

    public function proof($ref, $id)
    {
        $app = $this->row($ref);
        $this->payments();
        $proof = $this->db->table('bplo_payment_submissions')->where(['id' => $id, 'application_id' => $app['id']])->get();
        if (!$proof) $this->fail('Payment proof not found.', 404);
        $path = $this->proofDirectory() . '/' . $proof['storage_name'];
        if (!is_file($path)) $this->fail('Payment proof file is missing.', 404);
        // Framework includes can leave whitespace in PHP's output buffer; keep binary proofs intact.
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $proof['mime_type']);
        header('Content-Disposition: attachment; filename="' . $proof['file_name'] . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
