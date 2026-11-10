<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';

class BploAuthController extends BploApiBase
{
    public function auth($action)
    {
        if ($action === 'me') $this->send(['user' => $this->jwtless()]);
        if ($action === 'logout') {
            $this->jwtless();
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            $this->db->table('bplo_sessions')
            ->where('token_hash', hash('sha256', substr($header, 7)))->delete();
            $this->send(['message' => 'Logged out']);
        }

        $b = $this->body();
        if ($action === 'register') {
            $name = trim((string)($b['name'] ?? ''));
            $email = strtolower(trim((string)($b['email'] ?? '')));
            $phone = preg_replace('/[\s-]/', '', (string)($b['phone'] ?? ''));
            $address = trim((string)($b['address'] ?? ''));
            $password = (string)($b['password'] ?? '');
            if (strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)
                || strlen($address) < 5 || strlen($password) < 8) {
                $this->fail('Provide valid name, email, PH phone, address and password (8+ characters).', 422);
            }
            if ($this->db->table('bplo_accounts')->select('id')->where('email', $email)->get()) {
                $this->fail('Email already registered.', 409);
            }
            $id = $this->db->table('bplo_accounts')->insert([
                'role' => 'applicant', 'name' => $name, 'email' => $email,
                'phone' => $phone, 'address' => $address,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $user = $this->db->table('bplo_accounts')
                ->select('id,role,name,email,phone,address')->where('id', $id)->get();
            $this->audit('Applicant registered', null, $email, $user['id']);
            $this->issue($user);
        }
        if ($action === 'login') {
            $email = strtolower(trim((string)($b['email'] ?? '')));
            $password = (string)($b['password'] ?? '');
            $user = $this->db->table('bplo_accounts')
                ->select('id,role,name,email,phone,address,password_hash,is_active')
                ->where('email', $email)->get();
            if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
                $this->fail('Incorrect email or password.', 401);
            }
            if (!empty($b['role']) && $user['role'] !== $b['role']) {
                $this->fail('This account is not authorized for the selected workspace.', 403);
            }
            $this->issue($user);
        }
        $this->fail('Unsupported authentication action.', 404);
    }

    public function profile()
    {
        $user = $this->jwtless();
        $b = $this->body();
        if (!empty($b['oldPassword']) || !empty($b['newPassword'])) {
            $account = $this->db->table('bplo_accounts')
                ->select('password_hash')->where('id', $user['id'])->get();
            if (!$account || !password_verify((string)($b['oldPassword'] ?? ''), $account['password_hash'])) {
                $this->fail('Current password incorrect.', 403);
            }
            if (strlen((string)($b['newPassword'] ?? '')) < 8) {
                $this->fail('New password must have at least 8 characters.', 422);
            }
            $this->db->table('bplo_accounts')->where('id', $user['id'])
                ->update(['password_hash' => password_hash($b['newPassword'], PASSWORD_DEFAULT)]);
            $this->audit('Password changed', null, '', $user['id']);
            $this->send(['message' => 'Password updated']);
        }
        $name = trim((string)($b['name'] ?? ''));
        $phone = preg_replace('/[\s-]/', '', (string)($b['phone'] ?? ''));
        $address = trim((string)($b['address'] ?? ''));
        if (strlen($name) < 3 || !preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)
            || strlen($address) < 5) {
            $this->fail('Name, PH phone and address are required.', 422);
        }
        $this->db->table('bplo_accounts')->where('id', $user['id'])
            ->update(['name' => $name, 'phone' => $phone, 'address' => $address]);
        $this->send(['user' => array_merge($user, [
            'name' => $name, 'phone' => $phone, 'address' => $address,
        ])]);
    }

}
