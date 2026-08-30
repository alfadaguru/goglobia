<?php
$requirements = [
    'name' => 'Mailgun',
    'fields' => ['api_key', 'domain', 'region']
];

class MailgunProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    // Cheap, local completeness check (no network call) — used to warn admins
    // when they save an obviously incomplete configuration.
    public function isConfigured() {
        return !empty($this->config['api_key']) && !empty($this->config['domain']);
    }

    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['api_key']) || empty($this->config['domain'])) {
                throw new Exception('Mailgun configuration incomplete. Please check API key and domain.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@' . $this->config['domain'];
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaMailgunAPI($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);
            
            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Mailgun Error: " . $e->getMessage());
            return false;
        }
    }

    public function sendWithAttachment($to_email, $to_name, $subject, $body_html, $body_text = null, $attachment_path = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['api_key']) || empty($this->config['domain'])) {
                throw new Exception('Mailgun configuration incomplete. Please check API key and domain.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@' . $this->config['domain'];
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaMailgunAPI($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name, $attachment_path);
            
            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Mailgun Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaMailgunAPI($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name, $attachment_path = null) {
        $apiKey = $this->config['api_key'];
        $domain = $this->config['domain'];
        $region = $this->config['region'] ?? 'us';
        
        $apiEndpoint = $region === 'eu' 
            ? 'https://api.eu.mailgun.net/v3/' . $domain . '/messages'
            : 'https://api.mailgun.net/v3/' . $domain . '/messages';

        $postData = [
            'from' => "{$from_name} <{$from_email}>",
            'to' => "{$to_name} <{$to_email}>",
            'subject' => $subject,
            'html' => $body_html,
            'text' => $body_text
        ];

        // Add attachment if provided
        if ($attachment_path && file_exists($attachment_path)) {
            $postData['attachment'] = new CURLFile($attachment_path);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $apiKey);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && isset($responseData['id'])) {
            return [
                'success' => true,
                'message' => 'Email sent successfully via Mailgun',
                'message_id' => $responseData['id']
            ];
        } else {
            $errorMsg = $responseData['message'] ?? 'Unknown error occurred';
            throw new Exception("Mailgun API Error ($httpCode): " . $errorMsg);
        }
    }

    public function testConnection() {
        try {
            if (empty($this->config['api_key'])) {
                throw new Exception('API key is required');
            }
            
            if (empty($this->config['domain'])) {
                throw new Exception('Domain is required');
            }

            $apiKey = $this->config['api_key'];
            $domain = $this->config['domain'];
            $region = $this->config['region'] ?? 'us';
            
            if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
                throw new Exception('Invalid domain format');
            }
            
            $apiEndpoint = $region === 'eu' 
                ? 'https://api.eu.mailgun.net/v3/domains'
                : 'https://api.mailgun.net/v3/domains';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "api:{$apiKey}",
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception("cURL Error: " . $curlError);
            }

            $responseData = json_decode($response, true);

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Mailgun connection successful - API key valid'
                ];
            } else {
                $errorMsg = $responseData['message'] ?? 'Unknown API error';
                
                switch ($httpCode) {
                    case 401:
                        throw new Exception("Invalid API key - Please check your Mailgun private key");
                    case 404:
                        throw new Exception("Domain not found - Verify domain spelling and region");
                    default:
                        throw new Exception("API Error ($httpCode): " . $errorMsg);
                }
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'MailgunProvider';
?>