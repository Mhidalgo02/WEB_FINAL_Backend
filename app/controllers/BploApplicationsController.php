
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';
require_once dirname(__DIR__) . '/libraries/BploManualPayments.php';

class BploApplicationsController extends BploApiBase
{
    public function applications()
    {
        $actor = $this->jwtless();
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            $query = $this->db->table('bplo_applications');

            if ($actor['role'] !== 'admin') {
                $query->where('account_id', $actor['id']);
            }

            $apps = $query->order_by('id', 'DESC')->get_all();

            $this->send([
                'data' => $this->formatApplications($apps)
            ]);
        }

        if ($method === 'POST') {
            $b = $this->body();

            $business = trim((string)($b['businessName'] ?? ''));
            $address = trim((string)($b['businessAddress'] ?? ''));
            $service = (string)($b['serviceType'] ?? '');

            $allowed = [
                'barangay-clearance',
                'business-registration',
                'fire-safety',
                'sanitary-health',
                'business-tax-fees',
                'renewal-inspection',
                'building-occupancy',
            ];

            if (
                strlen($business) < 2 ||
                strlen($address) < 5 ||
                !in_array($service, $allowed, true)
            ) {
                $this->fail(
                    'Business name, address and valid service are required.',
                    422
                );
            }

            $ownerId = $actor['id'];

            if ($actor['role'] === 'admin') {
                $email = strtolower(trim(
                    (string)($b['ownerAccountEmail'] ?? $b['email'] ?? '')
                ));

                $owner = $this->db->table('bplo_accounts')
                    ->select('id')
                    ->where([
                        'email' => $email,
                        'role' => 'applicant',
                        'is_active' => 1
                    ])
                    ->get();

                if (!$owner) {
                    $this->fail(
                        'Create the applicant account first, then use its email when adding an application.',
                        422
                    );
                }

                $ownerId = $owner['id'];
            }

            $ref = 'BP-' . gmdate('Y') . '-' .
                strtoupper(bin2hex(random_bytes(5)));

            $b['ownerAccountEmail'] =
                $actor['role'] === 'admin'
                ? $email
                : $actor['email'];

            $this->db->transaction();

            try {
                $id = $this->db->table('bplo_applications')->insert([
                    'reference_number' => $ref,
                    'account_id' => $ownerId,
                    'details_json' => json_encode($b),
                ]);

                $this->db->table('bplo_status_history')->insert([
                    'application_id' => $id,
                    'status' => 'Pending',
                    'remarks' => 'Submitted',
                ]);

                $this->notify(
                    $ownerId,
                    'Application submitted',
                    'Application received for review.',
                    $ref
                );

                $this->audit(
                    'Application submitted',
                    $ref,
                    $business
                );

                $this->db->commit();

            } catch (\Throwable $e) {
                $this->db->roll_back();
                throw $e;
            }

            // EMAIL + SMS AFTER SUCCESSFUL SAVE
            $this->sendExternalNotification(
                $ownerId,
                'Application submitted',
                'Application received for review.',
                $ref
            );

            $this->send(
                $this->formatApplication($this->row($ref)),
                201
            );
        }

        $this->fail('Method not allowed.', 405);
    }

    public function application($ref)
    {
        $record = $this->row($ref);
        $actor = $this->jwtless();
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            $this->send($this->formatApplication($record));
        }

        if ($method === 'DELETE') {
            $this->admin();

            $this->db->transaction();
            try {
                $this->db->raw('SELECT id FROM bplo_applications WHERE id = ? FOR UPDATE', [$record['id']])->fetch();
                // Preserve assessments and payment history, including rejected proof attempts.
                if (BploManualPayments::installed($this->db) && $this->db->table('bplo_payment_assessments')->where('application_id', $record['id'])->get()) {
                    throw new DomainException('Applications with fee assessments cannot be deleted. Keep them for payment history.', 409);
                }
                $docs = $this->db->table('bplo_documents')->select('storage_name')->where('application_id', $record['id'])->get_all();
                $this->db->table('bplo_applications')->where('id', $record['id'])->delete();
                $this->audit('Application deleted', $ref);
                $this->db->commit();
            } catch (\DomainException $e) {
                $this->db->roll_back();
                $this->fail($e->getMessage(), $e->getCode());
            } catch (\Throwable $e) {
                $this->db->roll_back();
                throw $e;
            }

            foreach ($docs as $doc) {
                @unlink(
                    $this->storage() . '/' . $doc['storage_name']
                );
            }

            $this->send(['message' => 'Deleted']);
        }

        if ($method === 'PUT') {
            if (
                $actor['role'] !== 'admin' &&
                !in_array(
                    $record['status'],
                    ['Pending', 'Missing Requirements'],
                    true
                )
            ) {
                $this->fail(
                    'This application can no longer be edited.',
                    403
                );
            }

            $b = $this->body();

            unset(
                $b['ownerAccountEmail'],
                $b['referenceNumber'],
                $b['status'],
                $b['documents'],
                $b['statusHistory']
            );

            $data = array_merge(
                json_decode($record['details_json'], true) ?: [],
                $b
            );

            if (
                strlen(trim((string)($data['businessName'] ?? ''))) < 2 ||
                strlen(trim((string)($data['businessAddress'] ?? ''))) < 5
            ) {
                $this->fail(
                    'Business name/address invalid.',
                    422
                );
            }

            $this->db->table('bplo_applications')
                ->where('id', $record['id'])
                ->update([
                    'details_json' => json_encode($data)
                ]);

            $this->audit('Application edited', $ref);
            $this->send(['message' => 'Updated']);
        }

        $this->fail('Method not allowed.', 405);
    }

    public function status($ref)
    {
        $this->admin();

        $record = $this->row($ref);
        $b = $this->body();

        $status = (string)($b['status'] ?? '');
        $remarks = trim((string)($b['remarks'] ?? ''));

        $allowedStatuses = [
            'Pending',
            'Under Review',
            'Missing Requirements',
            'Approved',
            'Rejected',
            'Released'
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $this->fail('Invalid status.', 422);
        }

        if (
            $status === $record['status'] &&
            $remarks === ($record['remarks'] ?? '')
        ) {
            $this->send(['message' => 'No change']);
        }

        $this->db->transaction();

        try {
            // Payment mutations lock the same application row, preventing release during an assessment change.
            $this->db->raw('SELECT id FROM bplo_applications WHERE id = ? FOR UPDATE', [$record['id']])->fetch();
            if ($status === 'Released') {
                if (!BploManualPayments::installed($this->db)) {
                    throw new DomainException('Set up manual payments first: php scripts/setup_manual_payments.php', 503);
                }
                (new BploManualPayments($this->db))->assertReleasable($record['id']);
            }
            $this->db->table('bplo_applications')
                ->where('id', $record['id'])
                ->update([
                    'status' => $status,
                    'remarks' => $remarks
                ]);

            $this->db->table('bplo_status_history')->insert([
                'application_id' => $record['id'],
                'status' => $status,
                'remarks' => $remarks
            ]);

            $this->notify(
                $record['account_id'],
                'Application status updated',
                'Application status: ' . $status,
                $ref
            );

            $this->audit(
                'Status changed',
                $ref,
                $status
            );

            $this->db->commit();

        } catch (\DomainException $e) {
            $this->db->roll_back();
            $this->fail($e->getMessage(), $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }

        // EMAIL + SMS AFTER STATUS UPDATE
        $this->sendExternalNotification(
            $record['account_id'],
            'Application status updated',
            'Application status: ' . $status,
            $ref
        );

        $this->send([
            'message' => 'Status updated'
        ]);
    }

    public function inspection($ref)
    {
        $this->admin();

        $record = $this->row($ref);
        $b = $this->body();
        $date = (string)($b['date'] ?? '');
        $time = (string)($b['time'] ?? '');
        $inspector = trim((string)($b['inspector'] ?? ''));
        $remarks = trim((string)($b['remarks'] ?? ''));

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
            !preg_match('/^\d{2}:\d{2}$/', $time) ||
            $inspector === ''
        ) {
            $this->fail(
                'Valid inspection date, time and inspector required.',
                422
            );
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) {
            $this->fail('Valid inspection date required.', 422);
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        if ($hour > 23 || $minute > 59) {
            $this->fail('Valid inspection time required.', 422);
        }

        $account = $this->db->table('bplo_accounts')
            ->select('email,name')
            ->where('id', $record['account_id'])
            ->get();
        $recipient = strtolower(trim((string)($account['email'] ?? '')));
        if (!$account || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->fail(
                'The applicant account has no valid email address. The schedule was not saved.',
                422
            );
        }

        $b['date'] = $date;
        $b['time'] = $time;
        $b['inspector'] = $inspector;
        $b['remarks'] = $remarks;

        $this->db->table('bplo_applications')
            ->where('id', $record['id'])
            ->update([
                'inspection_json' => json_encode($b)
            ]);

        $this->notify(
            $record['account_id'],
            'Inspection scheduled',
            'An inspection has been scheduled for ' . $date . ' at ' . $time
                . ' Philippine Time. Inspector: ' . $inspector . '.',
            $ref
        );

        $this->audit('Inspection scheduled', $ref);

        $timezone = new \DateTimeZone('Asia/Manila');
        $scheduledAt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date . ' ' . $time,
            $timezone
        );
        $safe = static function ($value) {
            return htmlspecialchars(
                (string)($value ?? '—'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        };
        $details = json_decode($record['details_json'] ?? '', true) ?: [];
        $dateLabel = $scheduledAt ? $scheduledAt->format('F j, Y') : $date;
        $timeLabel = $scheduledAt ? $scheduledAt->format('g:i A') : $time;
        $applicantName = $account['name'] ?: 'Applicant';
        $businessName = $details['businessName'] ?? 'your business';
        require_once dirname(__DIR__) . '/libraries/BploMailer.php';
        $emailReady = getenv('BPLO_EMAIL_ENABLED') === '1' && BploMailer::isConfigured();

        $emailSent = false;
        $emailMessage = '';
        if ($emailReady) {
            require_once dirname(__DIR__) . '/libraries/BploMailer.php';
            $mailer = new BploMailer();
            $subject = 'BPLO Inspection Scheduled - ' . $ref;
            $plainMessage = "Dear {$applicantName},\n\n"
                . "Your business inspection has been scheduled.\n\n"
                . "Business: {$businessName}\n"
                . "Date: {$dateLabel}\n"
                . "Time: {$timeLabel} (Philippine Time)\n"
                . "Inspector: {$inspector}\n"
                . 'Remarks: ' . ($remarks !== '' ? $remarks : 'Please prepare the required business documents.') . "\n"
                . "Reference: {$ref}\n\n"
                . "Calapan City Business Permits and Licensing Office";
            $htmlMessage = '<!doctype html><html><body style="margin:0;padding:24px;background:#f3f5f3;font-family:Arial,sans-serif;color:#263b32">'
                . '<main style="max-width:600px;margin:auto;padding:28px;background:#fff;border:1px solid #dfe5df;border-top:4px solid #a88943">'
                . '<p style="margin:0 0 6px;color:#786638;font-size:12px">CALAPAN CITY</p>'
                . '<h1 style="margin:0 0 22px;color:#193e31;font-size:20px">Business inspection scheduled</h1>'
                . '<p>Dear ' . $safe($applicantName) . ',</p>'
                . '<p>Your business inspection has been scheduled. Please see the details below.</p>'
                . '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
                . '<tr><td style="padding:9px;border-bottom:1px solid #e5e8e2;font-weight:bold">Business</td><td style="padding:9px;border-bottom:1px solid #e5e8e2">' . $safe($businessName) . '</td></tr>'
                . '<tr><td style="padding:9px;border-bottom:1px solid #e5e8e2;font-weight:bold">Date</td><td style="padding:9px;border-bottom:1px solid #e5e8e2">' . $safe($dateLabel) . '</td></tr>'
                . '<tr><td style="padding:9px;border-bottom:1px solid #e5e8e2;font-weight:bold">Time</td><td style="padding:9px;border-bottom:1px solid #e5e8e2">' . $safe($timeLabel) . ' (Philippine Time)</td></tr>'
                . '<tr><td style="padding:9px;border-bottom:1px solid #e5e8e2;font-weight:bold">Inspector</td><td style="padding:9px;border-bottom:1px solid #e5e8e2">' . $safe($inspector) . '</td></tr>'
                . '<tr><td style="padding:9px;border-bottom:1px solid #e5e8e2;font-weight:bold">Remarks</td><td style="padding:9px;border-bottom:1px solid #e5e8e2">' . $safe($remarks !== '' ? $remarks : 'Please prepare the required business documents.') . '</td></tr>'
                . '<tr><td style="padding:9px;font-weight:bold">Reference</td><td style="padding:9px">' . $safe($ref) . '</td></tr>'
                . '</table><p style="margin:24px 0 0;color:#536258;font-size:13px">Calapan City Business Permits and Licensing Office</p>'
                . '</main></body></html>';

            $emailSent = $mailer->send(
                $recipient,
                $subject,
                $htmlMessage,
                $plainMessage
            );
        }

        if (!$emailSent) {
            $emailMessage = $emailReady
                ? 'The schedule was saved, but the inspection email could not be sent. Check the backend SMTP settings and try again.'
                : 'The schedule was saved, but the inspection email was not sent because outbound email is not configured on the backend.';
        }

        // The detailed inspection email was sent above; send the automatic SMS without a second email.
        $this->sendExternalNotification(
            $record['account_id'],
            'Inspection scheduled',
            'An inspection has been scheduled for ' . $date . ' at ' . $time
                . ' Philippine Time. Inspector: ' . $inspector . '.',
            $ref,
            true
        );

        $this->send([
            'message' => $emailSent
                ? 'Inspection schedule saved and email sent to the applicant.'
                : $emailMessage,
            'scheduleSaved' => true,
            'emailSent' => $emailSent,
        ]);
    }
}
