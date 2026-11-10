
<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class BploMailer
{
    private $lastError = '';
    public function getLastError() { return $this->lastError; }

    public static function isConfigured()
    {
        $keys = getenv('MAIL_TRANSPORT') === 'brevo'
            ? ['BREVO_API_KEY', 'SMTP_FROM_EMAIL']
            : ['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'];
        foreach ($keys as $key) if (trim((string)getenv($key)) === '') return false;
        return true;
    }

    public function send($recipient, $subject, $message, $plainMessage = null)
    {
        $this->lastError = '';
        if (getenv('MAIL_TRANSPORT') === 'brevo') return $this->sendBrevo($recipient, $subject, $message, $plainMessage);
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Host = getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USERNAME');
            $mail->Password = getenv('SMTP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
            $mail->Timeout = 10;

            $mail->setFrom(
                getenv('SMTP_FROM_EMAIL'),
                getenv('SMTP_FROM_NAME') ?: 'BPLO Academic Project'
            );

            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = $plainMessage ?? strip_tags($message);

            return $mail->send();
        } catch (Exception $e) {
            error_log('BPLO mail error: ' . $mail->ErrorInfo);
            $detail = strip_tags($mail->ErrorInfo ?: $e->getMessage());
            foreach (['SMTP_PASSWORD', 'SMTP_USERNAME'] as $key) {
                $secret = (string)getenv($key);
                if ($secret !== '') $detail = str_replace($secret, '[redacted]', $detail);
            }
            $this->lastError = 'SMTP: ' . substr(preg_replace('/[\r\n]+/', ' ', $detail), 0, 350);
            return false;
        }
    }

    private function sendBrevo($recipient, $subject, $html, $plain)
    {
        if (!self::isConfigured() || !function_exists('curl_init')) {
            $this->lastError = 'Brevo HTTPS email needs BREVO_API_KEY, SMTP_FROM_EMAIL and PHP cURL.';
            return false;
        }
        $key = (string)getenv('BREVO_API_KEY');
        $payload = json_encode([
            'sender' => ['email' => getenv('SMTP_FROM_EMAIL'), 'name' => getenv('SMTP_FROM_NAME') ?: 'Calapan City BPLO'],
            'to' => [['email' => $recipient]], 'subject' => $subject,
            'htmlContent' => $html, 'textContent' => $plain ?? strip_tags($html),
        ]);
        $handle = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($status === 201 && !empty($data['messageId'])) return true;
        $detail = $data['message'] ?? $error;
        if (!is_string($detail) || $detail === '') $detail = 'Check the API key, verified sender and Brevo delivery history.';
        $this->lastError = 'Brevo HTTP ' . $status . ': ' . substr(str_replace($key, '[redacted]', strip_tags($detail)), 0, 350);
        error_log('BPLO email failed: ' . $this->lastError);
        return false;
    }
}
