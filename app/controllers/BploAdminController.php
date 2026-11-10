<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';

class BploAdminController extends BploApiBase
{
    public function auditLogs()
    {
        $this->admin();
        $rows = $this->db->table('bplo_audit')
            ->select('id,actor,action,reference,details,created_at')
            ->order_by('id', 'DESC')->limit(500)->get_all();
        foreach ($rows as &$r) {
            $r['at'] = $r['created_at'];
            unset($r['created_at']);
        }
        unset($r);
        $this->send(['data' => $rows]);
    }

    public function settings()
    {
        $this->admin();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $record = $this->db->table('bplo_settings')
                ->select('setting_json')->where('setting_key', 'general')->get();
            $this->send($record ? (json_decode($record['setting_json'], true) ?: []) : []);
        }
        $b = $this->body();
        unset($b['smtpPassword'], $b['smsToken'], $b['apiKey']);
        $json = json_encode($b);
        $exists = $this->db->table('bplo_settings')
            ->select('setting_key')->where('setting_key', 'general')->get();
        if ($exists) {
            $this->db->table('bplo_settings')->where('setting_key', 'general')
                ->update(['setting_json' => $json]);
        } else {
            $this->db->table('bplo_settings')->insert([
                'setting_key' => 'general', 'setting_json' => $json,
            ]);
        }
        $this->audit('Settings updated');
        $this->send(['message' => 'Saved']);
    }
}
