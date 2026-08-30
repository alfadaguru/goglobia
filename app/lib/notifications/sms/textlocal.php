<?php
// $requirements = [
//     'name' => 'Textlocal',
//     'fields' => ['api_key', 'sender_name'],
//     'icon' => 'sms'
// ];

class TextlocalProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://api.textlocal.in';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['api_key']) || empty($this->config['sender_name'])) {
                throw new Exception('Textlocal configuration incomplete. API Key and Sender Name are required.');
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
            error_log("Textlocal SMS Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendSMS($to, $message) {
        $apiKey = $this->config['api_key'];
        $sender = $this->config['sender_name'];
        
        $url = $this->baseUrl . '/send/';

        $postData = [
            'apikey' => $apiKey,
            'sender' => $sender,
            'numbers' => $to,
            'message' => $message
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
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

        if (isset($responseData['status']) && $responseData['status'] == 'success') {
            return [
                'success' => true,
                'message' => 'SMS sent successfully via Textlocal',
                'message_id' => $responseData['batch_id'] ?? ''
            ];
        } else {
            $errorMessage = isset($responseData['errors'][0]['message']) ? 
                $responseData['errors'][0]['message'] : 'Unknown error';
            return [
                'success' => false,
                'message' => 'Textlocal API error: ' . $errorMessage
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        // Remove + for Textlocal
        $cleaned = ltrim($cleaned, '+');
        return $cleaned;
    }

    public function testConnection() {
        try {
            if (empty($this->config['api_key'])) {
                throw new Exception('API Key is required for testing connection');
            }

            $apiKey = $this->config['api_key'];
            
            $url = $this->baseUrl . '/balance/?apikey=' . urlencode($apiKey);

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

            if (isset($responseData['status']) && $responseData['status'] == 'success') {
                $balance = $responseData['balance']['sms'] ?? 0;
                return [
                    'success' => true,
                    'message' => 'Textlocal connection successful. SMS balance: ' . $balance
                ];
            } else {
                $errorMessage = isset($responseData['errors'][0]['message']) ? 
                    $responseData['errors'][0]['message'] : 'Unknown error';
                throw new Exception('Textlocal API error: ' . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Textlocal connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'TextlocalProvider';
?>