<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';

class BploNotificationsController extends BploApiBase
{
    public function readAll()
    {
        $this->notifications('read-all');
    }

    public function notifications($id = null)
    {
        $actor = $this->jwtless();
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET') {
            $query = $this->db->table('bplo_notifications')
                ->select('id,title,message,reference,is_read,created_at');
            if ($actor['role'] !== 'admin') $query->where('account_id', $actor['id']);
            $rows = $query->order_by('id', 'DESC')->limit(300)->get_all();
            foreach ($rows as &$row) {
                $row['text'] = $row['message'];
                $row['read'] = $row['is_read'];
                $row['createdAt'] = $row['created_at'];
                unset($row['message'], $row['is_read'], $row['created_at']);
            }
            unset($row);
            $this->send(['data' => $rows]);
        }
        $query = $this->db->table('bplo_notifications');
        if ($actor['role'] !== 'admin') $query->where('account_id', $actor['id']);
        if ($id === 'read-all') {
            $query->update(['is_read' => 1]);
        } else {
            $query->where('id', $id)->update(['is_read' => 1]);
        }
        $this->send(['message' => 'Read']);
    }
}
