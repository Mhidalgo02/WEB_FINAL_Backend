<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Send an SMS using the registered TextBee Android gateway. */
class BploSms
{
    private $lastError = '';
    public function getLastError() { return $this->lastError; }

    public function send($phone, $message)
    {
        $this->lastError = '';
        $apiKey = trim((string)(getenv('TEXTBEE_API_KEY') ?: ($_ENV['TEXTBEE_API_KEY'] ?? '')));
        $deviceId = trim((string)(getenv('TEXTBEE_DEVICE_ID') ?: ($_ENV['TEXTBEE_DEVICE_ID'] ?? '')));

        if ($apiKey === '' || !function_exists('curl_init')) {
            $this->lastError = 'TextBee needs a configured API key and PHP cURL.';
            error_log('BPLO TextBee SMS: missing API key or PHP cURL extension.');
            return false;
        }

        $phone = preg_replace('/\D+/', '', (string)$phone);
        if (preg_match('/^09\d{9}$/', $phone)) {
            $phone = '63' . substr($phone, 1);
        } elseif (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '63' . $phone;
        }
        if (!preg_match('/^639\d{9}$/', $phone)) {
            $this->lastError = 'The applicant needs a valid Philippine mobile number.';
            error_log('BPLO TextBee SMS: invalid Philippine mobile number.');
            return false;
        }

        $payload = [
            'recipients' => ['+' . $phone],
            'message' => (string)$message,
        ];
        if ($deviceId !== '') {
            $payload['deviceId'] = $deviceId;
        }
        $json = json_encode($payload);
        if ($json === false) { $this->lastError = 'Unable to prepare the TextBee SMS request.'; return false; }

        $ch = curl_init('https://api.textbee.dev/api/v1/gateway/send-sms');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $data = is_string($response) ? json_decode($response, true) : null;

        if ($response === false || $status !== 200) {
            error_log('BPLO TextBee SMS request failed (HTTP ' . $status . '): ' . $error);
            $detail = $data['message'] ?? $data['error'] ?? $error;
            if (!is_string($detail)) $detail = 'Check the TextBee device, API key and gateway history.';
            $detail = str_replace($apiKey, '[redacted]', strip_tags($detail));
            $this->lastError = 'TextBee HTTP ' . $status . ': ' . substr(preg_replace('/[\r\n]+/', ' ', $detail), 0, 350);
            return false;
        }
        // Accepted for dispatch; delivery must be checked in TextBee history.
        $accepted = is_array($data) && !empty($data['data']['success']);
        if (!$accepted) $this->lastError = 'TextBee did not accept the SMS. Check the registered device and gateway history.';
        return $accepted;
    }
}
