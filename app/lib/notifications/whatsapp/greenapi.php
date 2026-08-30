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
    }

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

    private function sendWhatsAppMessage($messageData) {
        $instanceId = $this->config['instance_id'];
        $token = $this->config['token'];
        
        $url = $this->baseUrl . "/waInstance{$instanceId}/sendMessage/{$token}";

        error_log("Green API Request URL: " . $url);
        error_log("Green API Request Data: " . json_encode($messageData));

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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_VERBOSE => true // Debug mode
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        error_log("Green API Response - HTTP Code: $httpCode");
        error_log("Green API CURL Error: $curlError");


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
        
        error_log("Cleaned Phone: $cleaned");
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
            
            $url = $this->baseUrl . "/waInstance{$instanceId}/getSettings/{$token}";

            error_log("Green API Connection Test URL: " . $url);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            error_log("Green API Connection Test - HTTP Code: $httpCode");


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