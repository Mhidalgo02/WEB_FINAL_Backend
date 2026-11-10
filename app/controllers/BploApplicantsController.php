<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';

class BploApplicantsController extends BploApiBase
{
    public function applicants($id = null)
    {
        $this->admin();
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET') {
            $rows = $this->db->table('bplo_accounts')
                ->select('id,name,email,phone,address,is_active,created_at')
                ->where('role', 'applicant')->order_by('id', 'DESC')->get_all();
            foreach ($rows as &$r) {
                $r['id'] = (string)$r['id'];
                $r['active'] = $r['is_active'];
                unset($r['is_active']);
            }
            unset($r);
            $this->send(['data' => $rows]);
        }
        $b = $this->body();
        if ($method === 'DELETE') {
            $this->db->table('bplo_accounts')->where(['id' => $id, 'role' => 'applicant'])
                ->update(['is_active' => 0]);
            $this->audit('Applicant deactivated', null, (string)$id);
            $this->send(['message' => 'Deactivated, records retained']);
        }
        $name = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $phone = trim((string)($b['phone'] ?? ''));
        $address = trim((string)($b['address'] ?? ''));
        if (strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($address) < 5
            || !preg_match('/^(09\d{9}|\+639\d{9})$/', preg_replace('/[\s-]/', '', $phone))) {
            $this->fail('Valid applicant details required.', 422);
        }
        if ($method === 'POST') {
            $password = (string)($b['password'] ?? '');
            if (strlen($password) < 8) {
                $this->fail('Enter an initial password of 8+ characters for the new applicant.', 422);
            }
            $this->db->table('bplo_accounts')->insert([
                'role' => 'applicant', 'name' => $name, 'email' => $email,
                'phone' => $phone, 'address' => $address,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $this->audit('Applicant created', null, $email);
            $this->send(['message' => 'Created'], 201);
        }
        if ($method === 'PUT') {
            $this->db->table('bplo_accounts')->where(['id' => $id, 'role' => 'applicant'])
                ->update([
                    'name' => $name, 'email' => $email, 'phone' => $phone,
                    'address' => $address, 'is_active' => !empty($b['active']) ? 1 : 0,
                ]);
            $this->audit('Applicant updated', null, $email);
            $this->send(['message' => 'Updated']);
        }
        $this->fail('Method not allowed.', 405);
    }

}
