<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/BploApiBase.php';

class BploDocumentsController extends BploApiBase
{
    public function uploads($ref)
    {
        $record = $this->row($ref);
        $actor = $this->jwtless();
        if ($actor['role'] !== 'admin'
            && !in_array($record['status'], ['Pending','Missing Requirements'], true)) {
            $this->fail('Uploads are closed after review. Contact BPLO staff.', 403);
        }
        $type = trim((string)($_POST['documentType'] ?? ''));
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !$type || strlen($type) > 190) {
            $this->fail('Valid document type and file are required.', 422);
        }
        if ($file['size'] > 5 * 1024 * 1024 || $file['size'] <= 0) {
            $this->fail('File must be 5 MB or less.', 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['application/pdf','image/png','image/jpeg'], true)) {
            $this->fail('Only PDF, PNG and JPG files are allowed.', 422);
        }
        $filename = basename((string)$file['name']);
        $filename = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $filename);
        $saveName = bin2hex(random_bytes(22));
        if (!move_uploaded_file($file['tmp_name'], $this->storage() . '/' . $saveName)) {
            $this->fail('Unable to save uploaded document.', 500);
        }
        try {
            $previous = $this->db->table('bplo_documents')
                ->select('id,storage_name')
                ->where(['application_id' => $record['id'], 'document_type' => $type])
                ->order_by('id', 'DESC')->limit(1)->get();
            if ($previous) {
                $this->db->table('bplo_documents')->where('id', $previous['id'])->update([
                    'file_name' => $filename, 'storage_name' => $saveName,
                    'mime_type' => $mime, 'file_size' => $file['size'],
                    'review_status' => 'Pending', 'remarks' => null,
                ]);
                @unlink($this->storage() . '/' . $previous['storage_name']);
                $docId = $previous['id'];
            } else {
                $docId = $this->db->table('bplo_documents')->insert([
                    'application_id' => $record['id'], 'document_type' => $type,
                    'file_name' => $filename, 'storage_name' => $saveName,
                    'mime_type' => $mime, 'file_size' => $file['size'],
                ]);
            }
        } catch (Throwable $e) {
            @unlink($this->storage() . '/' . $saveName);
            throw $e;
        }
        $this->audit('Document uploaded', $ref, $type);
        $this->send(['message' => 'Uploaded', 'id' => (string)$docId], 201);
    }

    public function documents($ref)
    {
        $this->admin();
        $record = $this->row($ref);
        $b = $this->body();
        $type = (string)($b['documentType'] ?? '');
        $status = (string)($b['status'] ?? '');
        if (!in_array($status, ['Pending','Under Review','Verified','Approved',
            'Rejected','Missing Requirements'], true)) {
            $this->fail('Invalid review status.', 422);
        }
        $this->db->table('bplo_documents')
            ->where(['application_id' => $record['id'], 'document_type' => $type])
            ->update(['review_status' => $status, 'remarks' => trim((string)($b['remarks'] ?? ''))]);
        $this->notify($record['account_id'], 'Document review', 'Review status: ' . $status, $ref);
        $this->audit('Document reviewed', $ref, $type);
        $this->send(['message' => 'Reviewed']);
    }

    public function metadata($ref)
    {
        $this->row($ref);
        $this->fail('Use the secure upload endpoint for documents.', 422);
    }

    public function documentFile($ref, $id, $action)
    {
        $record = $this->row($ref);
        $doc = $this->db->table('bplo_documents')
            ->where(['id' => $id, 'application_id' => $record['id']])->get();
        if (!$doc) $this->fail('Document not found.', 404);
        if ($action === 'delete') {
            $actor = $this->jwtless();
            if ($actor['role'] !== 'admin'
                && !in_array($record['status'], ['Pending','Missing Requirements'], true)) {
                $this->fail('Documents cannot be removed after review.', 403);
            }
            $this->db->table('bplo_documents')->where('id', $id)->delete();
            @unlink($this->storage() . '/' . $doc['storage_name']);
            $this->audit('Document removed', $ref);
            $this->send(['message' => 'Removed']);
        }
        if ($action === 'download') {
            $path = $this->storage() . '/' . $doc['storage_name'];
            if (!is_file($path)) $this->fail('File missing.', 404);
            header('Content-Type: ' . $doc['mime_type']);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc['file_name']) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        $this->fail('Unsupported document action.', 404);
    }

}
