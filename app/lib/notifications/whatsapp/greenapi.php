<?php
$requirements = [
    'name' => 'Green API',
    'fields' => ['instance_id', 'token'],
    'icon' => 'call'
];

class GreenapiProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://api.green-api.com';

    public function __construct($config) {
        $this->config = $config;
        // Green API recommends the INSTANCE-SPECIFIC host (https://{first4ofId}.api
        // .greenapi.com) over the generic one "to avoid technical issues". Derive it
        // from instance_id so sends + SetSettings hit the right endpoint; an explicit
        // config['api_url'] overrides; falls back to the generic host.
        $explicit = trim((string) ($config['api_url'] ?? ''));
        if ($explicit !== '' && preg_match('#^https://[a-z0-9.\-]+$#i', rtrim($explicit, '/'))) {
            $this->baseUrl = rtrim($explicit, '/');
        } else {
            $iid = preg_replace('/[^0-9]/', '', (string) ($config['instance_id'] ?? ''));
            if (strlen($iid) >= 4) {
                $this->baseUrl = 'https://' . substr($iid, 0, 4) . '.api.greenapi.com';
            }
        }
    }

    /** The resolved instance API host (so callers like SetSettings use the same). */
    public function getBaseUrl() { return $this->baseUrl; }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            // Validate required configuration
            if (empty($this->config['instance_id']) || empty($this->config['token'])) {
                throw new Exception('Green API configuration incomplete. Instance ID and Token are required.');
            }

            // Clean phone number
            $phone = $this->cleanPhoneNumber($to);
            $chatId = $phone . '@c.us';
            

            // Prepare message data
            $messageData = [
                'chatId' => $chatId,
                'message' => $message
            ];

            // Send message via Green API
            $result = $this->sendWhatsAppMessage($messageData);

            if ($result['success']) {
                return true;
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Green API Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a FILE (e.g. the ticket PDF) to a WhatsApp chat via Green API's
     * sendFileByUpload (multipart upload — no public URL needed). Verified against
     * https://green-api.com/en/docs/api/sending/SendFileByUpload/ :
     *   POST {mediaHost}/waInstance{id}/sendFileByUpload/{token}  (multipart/form-data)
     *   fields: chatId, file, fileName, caption(<=1024). Max 100MB. Returns idMessage.
     * Green API recommends the media.green-api.com host for uploads; an explicit
     * config['media_url'] overrides. Returns true on success, false otherwise.
     *
     * @param string $to       recipient phone (any format; cleaned here)
     * @param string $filePath absolute path to a readable local file
     * @param string $fileName file name shown to the recipient (must include ext)
     * @param string $caption  optional caption (<=1024 chars)
     */
    public function sendFile($to, $filePath, $fileName = '', $caption = '') {
        try {
            if (empty($this->config['instance_id']) || empty($this->config['token'])) {
                throw new Exception('Green API configuration incomplete. Instance ID and Token are required.');
            }
            if (!is_string($filePath) || $filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
                throw new Exception('File not found or not readable for WhatsApp upload.');
            }
            // 100MB cap per Green API docs — guard before uploading.
            $size = @filesize($filePath);
            if ($size !== false && $size > 100 * 1024 * 1024) {
                throw new Exception('File exceeds the 100MB WhatsApp upload limit.');
            }

            $phone  = $this->cleanPhoneNumber($to);
            $chatId = $phone . '@c.us';
            if ($fileName === '') { $fileName = basename($filePath); }

            // Media host: Green API recommends media.green-api.com for uploads.
            // Allow an explicit override via config['media_url']; else the documented host.
            $mediaHost = trim((string) ($this->config['media_url'] ?? ''));
            if ($mediaHost === '' || !preg_match('#^https://[a-z0-9.\-]+$#i', rtrim($mediaHost, '/'))) {
                $mediaHost = 'https://media.green-api.com';
            } else {
                $mediaHost = rtrim($mediaHost, '/');
            }

            $instanceId = $this->config['instance_id'];
            $token      = $this->config['token'];
            // Token is a URL path segment (Green API design) — NEVER log this URL.
            $url = $mediaHost . "/waInstance{$instanceId}/sendFileByUpload/{$token}";

            // Resolve a sane MIME so the recipient's client previews the document.
            $mime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) { $detected = finfo_file($fi, $filePath); finfo_close($fi); if (is_string($detected) && $detected !== '') { $mime = $detected; } }
            } elseif (strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf') {
                $mime = 'application/pdf';
            }

            $postFields = [
                'chatId'   => $chatId,
                'fileName' => $fileName,
                'file'     => new \CURLFile($filePath, $mime, $fileName),
            ];
            if ($caption !== '') { $postFields['caption'] = mb_substr((string) $caption, 0, 1024); }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,   // array => multipart/form-data
                CURLOPT_TIMEOUT => 120,              // uploads can be slow
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log("Green API sendFile CURL error (HTTP {$httpCode})");
                $this->lastError = 'CURL Error: ' . $curlError;
                return false;
            }

            $responseData = json_decode((string) $response, true);
            if ($httpCode === 200 && isset($responseData['idMessage'])) {
                return true;
            }
            $this->lastError = "HTTP {$httpCode}: " . $this->getErrorMessage($httpCode, $responseData);
            error_log('Green API sendFile failed: ' . $this->lastError);
            return false;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Green API sendFile Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an INTERACTIVE reply-buttons message via Green API (BETA per Green API
     * docs — "may work unstably"). Verified against
     * https://green-api.com/en/docs/api/sending/SendInteractiveButtonsReply/ :
     *   POST {apiHost}/waInstance{id}/sendInteractiveButtonsReply/{token}
     *   body: { chatId, body, buttons:[{buttonId,buttonText}], header?, footer? }
     *   MAX 3 buttons, buttonText <= 25 chars. Returns idMessage.
     * The caller is responsible for keeping choices <= 3 (Green API has NO working
     * list method — SendListMessage is archived/403). Returns true on success.
     *
     * @param string $to      recipient phone
     * @param string $body    message text
     * @param array  $buttons [ ['buttonId'=>string,'buttonText'=>string], ... ] (<=3)
     * @param string $header  optional title
     * @param string $footer  optional footer
     */
    public function sendInteractiveButtons($to, $body, array $buttons, $header = '', $footer = '') {
        try {
            if (empty($this->config['instance_id']) || empty($this->config['token'])) {
                throw new Exception('Green API configuration incomplete.');
            }
            $buttons = array_values(array_filter($buttons, static function ($b) {
                return is_array($b) && trim((string) ($b['buttonText'] ?? '')) !== '';
            }));
            if ($buttons === []) { return false; }
            // Green API hard cap: max 3 buttons, 25-char text, unique ids.
            $buttons = array_slice($buttons, 0, 3);
            foreach ($buttons as $i => &$b) {
                $b = [
                    'buttonId'   => (string) ($b['buttonId'] ?? ('b' . ($i + 1))),
                    'buttonText' => mb_substr(trim((string) $b['buttonText']), 0, 25),
                ];
            }
            unset($b);

            $phone  = $this->cleanPhoneNumber($to);
            $payload = [
                'chatId'  => $phone . '@c.us',
                'body'    => (string) $body,
                'buttons' => $buttons,
            ];
            if (trim((string) $header) !== '') { $payload['header'] = (string) $header; }
            if (trim((string) $footer) !== '') { $payload['footer'] = (string) $footer; }

            $instanceId = $this->config['instance_id'];
            $token      = $this->config['token'];
            // Token is a URL path segment — NEVER log this URL.
            $url = $this->baseUrl . "/waInstance{$instanceId}/sendInteractiveButtonsReply/{$token}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log("Green API sendInteractive CURL error (HTTP {$httpCode})");
                $this->lastError = 'CURL Error: ' . $curlError;
                return false;
            }
            $responseData = json_decode((string) $response, true);
            if ($httpCode === 200 && isset($responseData['idMessage'])) { return true; }
            $this->lastError = "HTTP {$httpCode}: " . $this->getErrorMessage($httpCode, $responseData);
            error_log('Green API sendInteractive failed: ' . $this->lastError);
            return false;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Green API sendInteractive Error: ' . $e->getMessage());
            return false;
        }
    }

    private function sendWhatsAppMessage($messageData) {
        $instanceId = $this->config['instance_id'];
        $token = $this->config['token'];
        
        // Green API requires the token as a URL path segment (their REST design),
        // so it CANNOT move to a header. Therefore we must NEVER log this URL — it
        // contains the secret token. Log only a redacted form if needed.
        $url = $this->baseUrl . "/waInstance{$instanceId}/sendMessage/{$token}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($messageData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            // SECURITY: verify TLS certs (was false — exposed token + message to MITM).
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            error_log("Green API CURL error (HTTP {$httpCode})");
        }


        if ($curlError) {
            return [
                'success' => false,
                'message' => 'CURL Error: ' . $curlError
            ];
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200) {
            if (isset($responseData['idMessage'])) {
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully',
                    'message_id' => $responseData['idMessage']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Unexpected response format: ' . $response
                ];
            }
        } else {
            $errorMessage = $this->getErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "HTTP {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        // Remove all non-digit characters
        $cleaned = preg_replace('/[^\d]/', '', $phone);
        
        // Remove leading zeros if any
        $cleaned = ltrim($cleaned, '0');

        return $cleaned;
    }

    private function getErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters. Check phone number format.',
            401 => 'Unauthorized - Invalid token or instance ID',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - Instance not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Internal Server Error - Green API server error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['message'])) {
            return $responseData['message'];
        }

        if (isset($responseData['error'])) {
            return $responseData['error'];
        }

        return 'Unknown error occurred. Response: ' . json_encode($responseData);
    }

    public function testConnection() {
        try {
            if (empty($this->config['instance_id']) || empty($this->config['token'])) {
                throw new Exception('Instance ID and Token are required for testing connection');
            }

            $instanceId = $this->config['instance_id'];
            $token = $this->config['token'];
            
            // Token is a URL path segment (Green API design) — never log this URL.
            $url = $this->baseUrl . "/waInstance{$instanceId}/getSettings/{$token}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                // SECURITY: verify TLS certs (was false).
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);


            if ($curlError) {
                throw new Exception('CURL Error: ' . $curlError);
            }

            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                return [
                    'success' => true,
                    'message' => 'Green API connection successful. Instance is ready.'
                ];
            } else {
                $errorMessage = $this->getErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            error_log("Green API Connection Test Failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Green API connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'GreenapiProvider';
?>