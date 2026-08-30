<?php
// $requirements = [
//     'name' => 'Vonage (Nexmo)',
//     'fields' => ['api_key', 'api_secret', 'from_number'],
//     'icon' => 'sms'
// ];

class NexmoProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://rest.nexmo.com';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['api_key']) || empty($this->config['api_secret']) || empty($this->config['from_number'])) {
                throw new Exception('Nexmo configuration incomplete. API Key, API Secret, and From Number are required.');
            }

            $phone = $this->cleanPhoneNumber($to);
            $result = $this->sendSMS($phone, $message);

            if ($result['success']) {
                return true;
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Nexmo SMS Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendSMS($to, $message) {
        $apiKey = $this->config['api_key'];
        $apiSecret = $this->config['api_secret'];
        $from = $this->config['from_number'];
        
        $url = $this->baseUrl . '/sms/json';

        $postData = [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'from' => $from,
            'to' => $to,
            'text' => $message
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return [
                'success' => false,
                'message' => 'CURL Error: ' . $curlError
            ];
        }

        $responseData = json_decode($response, true);

        if (isset($responseData['messages'])) {
            $messageStatus = $responseData['messages'][0];
            if ($messageStatus['status'] == '0') {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully via Nexmo',
                    'message_id' => $messageStatus['message-id'] ?? ''
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Nexmo API error: ' . $messageStatus['error-text']
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'Unexpected response format from Nexmo'
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        return $cleaned;
    }

    public function testConnection() {
        try {
            if (empty($this->config['api_key']) || empty($this->config['api_secret'])) {
                throw new Exception('API Key and API Secret are required for testing connection');
            }

            $apiKey = $this->config['api_key'];
            $apiSecret = $this->config['api_secret'];
            
            $url = $this->baseUrl . '/account/get-balance?' . http_build_query([
                'api_key' => $apiKey,
                'api_secret' => $apiSecret
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception('CURL Error: ' . $curlError);
            }

            $responseData = json_decode($response, true);

            if (isset($responseData['value'])) {
                $balance = $responseData['value'];
                return [
                    'success' => true,
                    'message' => 'Nexmo connection successful. Balance: ' . $balance . ' EUR'
                ];
            } else {
                throw new Exception('Failed to get account balance: ' . ($responseData['error-code-label'] ?? 'Unknown error'));
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Nexmo connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'NexmoProvider';
?>