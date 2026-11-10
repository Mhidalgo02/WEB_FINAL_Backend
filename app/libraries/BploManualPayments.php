<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

// One full-payment assessment per application; all proof attempts remain in the ledger.
class BploManualPayments
{
    private $db;
    public function __construct($db) { $this->db = $db; }

    public static function installed($db)
    {
        $setting = $db->table('bplo_settings')->where('setting_key', 'manual_payments_schema')->get();
        return $setting && (json_decode($setting['setting_json'], true)['version'] ?? 0) === 1;
    }

    public static function cents($value, $allowZero = false)
    {
        if (!is_string($value) && !is_int($value)) throw new DomainException('Enter an amount with at most two decimal places.', 422);
        $value = trim((string)$value);
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value)) throw new DomainException('Enter an amount with at most two decimal places.', 422);
        $parts = explode('.', $value);
        $cents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        if ($cents > 100000000 || $cents < ($allowZero ? 0 : 1)) throw new DomainException('Amount must be between ' . ($allowZero ? '0' : '0.01') . ' and 1,000,000 pesos.', 422);
        return $cents;
    }

    private function text($data, $key, $max, $required = true)
    {
        $value = $data[$key] ?? '';
        if (!is_string($value) || strlen(trim($value)) > $max || ($required && trim($value) === '')) {
            throw new DomainException('Provide a valid ' . $key . ' (up to ' . $max . ' characters).', 422);
        }
        return trim($value);
    }

    private function change($applicationId, $callback)
    {
        $this->db->transaction();
        try {
            // The installed builder has no row-lock API. Serialize assessments, proofs, reviews and releases.
            $app = $this->db->raw('SELECT * FROM bplo_applications WHERE id = ? FOR UPDATE', [$applicationId])->fetch();
            if (!$app) throw new DomainException('Application not found.', 404);
            if (in_array($app['status'], ['Released', 'Rejected'], true)) throw new DomainException('Payments are closed for released or rejected applications.', 409);
            $callback($app);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    private function event($app, $actor, $title, $message)
    {
        $this->db->table('bplo_audit')->insert(['account_id' => $actor['id'], 'actor' => $actor['name'],
            'action' => $title, 'reference' => $app['reference_number'], 'details' => $message]);
        $this->db->table('bplo_notifications')->insert(['account_id' => $app['account_id'], 'title' => $title,
            'message' => $message, 'reference' => $app['reference_number']]);
    }

    public function state($id) { return $this->states([$id])[(string)$id]; }

    public static function noticeFingerprint($fee)
    {
        $values = [];
        foreach (['amount_cents', 'purpose', 'method', 'account_name', 'account_number', 'instructions', 'assessed_at'] as $key) $values[] = (string)($fee[$key] ?? '');
        return hash('sha256', json_encode($values));
    }

    public function states($ids)
    {
        if (!$ids) return [];
        $result = [];
        foreach ($ids as $id) $result[(string)$id] = ['assessment' => null, 'submissions' => [], 'paymentStatus' => 'Not assessed', 'notice' => null];
        foreach ($this->db->table('bplo_payment_assessments')->in('application_id', $ids)->get_all() as $row) {
            $result[(string)$row['application_id']]['assessment'] = $row;
            $result[(string)$row['application_id']]['paymentStatus'] = (int)$row['amount_cents'] === 0 ? 'No fee required' : 'Unpaid';
        }
        foreach ($this->db->table('bplo_payment_submissions')
            ->select('id,application_id,amount_cents,method,payment_reference,paid_on,file_name,review_status,review_remarks,reviewed_by,reviewed_at,submitted_at')
            ->in('application_id', $ids)->order_by('id', 'ASC')->get_all() as $row) {
            $entry = &$result[(string)$row['application_id']];
            $entry['submissions'][] = $row;
            $entry['paymentStatus'] = $row['review_status'] === 'Verified' ? 'Paid' : ($row['review_status'] === 'Pending' ? 'Awaiting verification' : 'Rejected');
            unset($entry);
        }
        $noticeKeys = array_map(function ($id) { return 'payment_notice_' . $id; }, $ids);
        foreach ($this->db->table('bplo_settings')->in('setting_key', $noticeKeys)->get_all() as $setting) {
            $id = substr($setting['setting_key'], strlen('payment_notice_'));
            $notice = json_decode($setting['setting_json'], true) ?: [];
            $fee = $result[$id]['assessment'] ?? null;
            if ($fee && ($notice['fingerprint'] ?? '') === self::noticeFingerprint($fee)) $result[$id]['notice'] = $notice;
        }
        return $result;
    }

    public function assess($id, $actor, $data)
    {
        if ($actor['role'] !== 'admin') throw new DomainException('Administrator access required.', 403);
        $amount = self::cents($data['amount'] ?? '', true);
        $purpose = $this->text($data, 'purpose', 500);
        $method = $amount ? $this->text($data, 'method', 30) : '';
        if ($amount && !in_array($method, ['GCash', 'Maya', 'Bank Transfer'], true)) throw new DomainException('Select GCash, Maya or Bank Transfer.', 422);
        $fields = ['amount_cents' => $amount, 'purpose' => $purpose, 'method' => $method,
            'account_name' => $amount ? $this->text($data, 'accountName', 180) : '',
            'account_number' => $amount ? $this->text($data, 'accountNumber', 100) : '',
            'instructions' => $this->text($data, 'instructions', 2000, false), 'assessed_by' => $actor['id'],
            'assessed_at' => gmdate('Y-m-d H:i:s')];
        $this->change($id, function ($app) use ($id, $actor, $fields) {
            if ($this->db->table('bplo_payment_submissions')->where('application_id', $id)->get()) {
                throw new DomainException('The assessment is locked because payment proof has already been submitted.', 409);
            }
            $existing = $this->db->table('bplo_payment_assessments')->where('application_id', $id)->get();
            if ($existing) $this->db->table('bplo_payment_assessments')->where('application_id', $id)->update($fields);
            else $this->db->table('bplo_payment_assessments')->insert(array_merge(['application_id' => $id], $fields));
            $this->event($app, $actor, 'Fee assessment saved', $fields['amount_cents'] === 0 ? 'No fee required: ' . $fields['purpose'] : 'Amount due: PHP ' . number_format($fields['amount_cents'] / 100, 2, '.', '') . '. ' . $fields['purpose']);
        });
    }

    public function submit($id, $actor, $data, $proof)
    {
        if ($actor['role'] !== 'applicant') throw new DomainException('Only applicants can submit payment proof.', 403);
        $amount = self::cents($data['amount'] ?? '');
        $reference = $this->text($data, 'reference', 100);
        if (!preg_match('/^[a-zA-Z0-9 ._\/-]{4,100}$/D', $reference)) throw new DomainException('Enter a valid transaction reference (4 to 100 characters).', 422);
        $paidOn = $this->text($data, 'paidOn', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn, new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d') !== $paidOn || $paidOn > (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) {
            throw new DomainException('Enter a valid payment date that is not in the future.', 422);
        }
        $this->change($id, function ($app) use ($id, $actor, $amount, $reference, $paidOn, $proof) {
            if ((string)$app['account_id'] !== (string)$actor['id']) throw new DomainException('Not permitted.', 403);
            $state = $this->state($id);
            $fee = $state['assessment'];
            if (!$fee || (int)$fee['amount_cents'] === 0) throw new DomainException('No payable fee has been assessed.', 409);
            if (!in_array($state['paymentStatus'], ['Unpaid', 'Rejected'], true)) throw new DomainException('A payment is already awaiting verification or has been paid.', 409);
            if ($amount !== (int)$fee['amount_cents']) throw new DomainException('The payment amount must match the assessed amount due.', 422);
            $key = hash('sha256', $fee['method'] . ':' . strtoupper(preg_replace('/[\s\-._\/]/', '', $reference)));
            foreach (['reference_key' => $key, 'proof_hash' => $proof['proof_hash']] as $field => $value) {
                if ($this->db->table('bplo_payment_submissions')->where($field, $value)->get()) throw new DomainException('This payment reference or proof is already awaiting review or verified.', 409);
            }
            $this->db->table('bplo_payment_submissions')->insert([
                'application_id' => $id, 'amount_cents' => $amount, 'method' => $fee['method'],
                'payment_reference' => $reference, 'reference_key' => $key, 'paid_on' => $paidOn,
                'file_name' => $proof['file_name'], 'storage_name' => $proof['storage_name'],
                'mime_type' => $proof['mime_type'], 'file_size' => $proof['file_size'], 'proof_hash' => $proof['proof_hash'],
            ]);
            $this->event($app, $actor, 'Payment proof submitted', 'Payment proof is awaiting administrator verification.');
            foreach ($this->db->table('bplo_accounts')->select('id')->where(['role' => 'admin', 'is_active' => 1])->get_all() as $admin) {
                $this->db->table('bplo_notifications')->insert(['account_id' => $admin['id'], 'title' => 'Payment awaiting verification',
                    'message' => 'Review the payment proof for ' . $app['reference_number'] . '.', 'reference' => $app['reference_number']]);
            }
        });
    }

    public function review($id, $submissionId, $actor, $data)
    {
        if ($actor['role'] !== 'admin') throw new DomainException('Administrator access required.', 403);
        $status = $data['status'] ?? '';
        if (!in_array($status, ['Verified', 'Rejected'], true)) throw new DomainException('Select Verified or Rejected.', 422);
        $remarks = $this->text($data, 'remarks', 1000, $status === 'Rejected');
        $this->change($id, function ($app) use ($id, $submissionId, $actor, $status, $remarks) {
            $row = $this->db->table('bplo_payment_submissions')->where(['id' => $submissionId, 'application_id' => $id])->get();
            if (!$row) throw new DomainException('Payment submission not found.', 404);
            if ($row['review_status'] !== 'Pending') throw new DomainException('This payment has already been reviewed.', 409);
            $values = ['review_status' => $status, 'review_remarks' => $remarks, 'reviewed_by' => $actor['id'], 'reviewed_at' => gmdate('Y-m-d H:i:s')];
            if ($status === 'Rejected') { $values['reference_key'] = null; $values['proof_hash'] = null; }
            $this->db->table('bplo_payment_submissions')->where('id', $submissionId)->update($values);
            $this->event($app, $actor, 'Payment ' . strtolower($status), $status === 'Verified' ? 'Payment received and verified by an administrator.' . ($remarks ? ' ' . $remarks : '') : 'Payment proof rejected: ' . $remarks . ' You may submit corrected proof.');
        });
    }

    public function assertReleasable($id)
    {
        if (!in_array($this->state($id)['paymentStatus'], ['Paid', 'No fee required'], true)) {
            throw new DomainException('Before release, verify the payment or save a no-fee assessment in Payments.', 409);
        }
    }
}
