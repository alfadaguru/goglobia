<?php
// $requirements = [
//     'name' => 'UltraMsg',
//     'fields' => ['instance_id', 'token', 'phone_number'],
//     'icon' => 'call'
// ];

class UltramsgProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://api.ultramsg.com';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['instance_id']) || empty($this->config['token']) || empty($this->config['phone_number'])) {
                throw new Exception('UltraMsg configuration incomplete. Instance ID, Token, and Phone Number are required.');
            }

            $phone = $this->cleanPhoneNumber($to);
            $result = $this->sendWhatsAppMessage($phone, $message);

            if ($result['success']) {
                return true;
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("UltraMsg Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendWhatsAppMessage($to, $message) {
        $instanceId = $this->config['instance_id'];
        $token = $this->config['token'];
        
        $url = $this->baseUrl . "/{$instanceId}/messages/chat";

        $postData = [
            'token' => $token,
            'to' => $to,
            'body' => $message
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
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

        if ($httpCode === 200) {
            if (isset($responseData['sent']) && $responseData['sent'] === 'true') {
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully via UltraMsg',
                    'message_id' => $responseData['id'] ?? ''
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'UltraMsg API error: ' . ($responseData['error'] ?? 'Unknown error')
                ];
            }
        } else {
            $errorMessage = $this->getUltraMsgErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "UltraMsg API Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        if (strpos($cleaned, '+') !== 0) {
            $cleaned = '+' . $cleaned;
        }
        return $cleaned;
    }

    private function getUltraMsgErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid token',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - Instance not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'UltraMsg Server Error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['error'])) {
            return $responseData['error'];
        }

        return 'Unknown UltraMsg API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['instance_id']) || empty($this->config['token'])) {
                throw new Exception('Instance ID and Token are required for testing connection');
            }

            $instanceId = $this->config['instance_id'];
            $token = $this->config['token'];
            
            $url = $this->baseUrl . "/{$instanceId}/instance/status?token={$token}";

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

            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                $instanceStatus = $responseData['status'] ?? 'unknown';
                
                return [
                    'success' => true,
                    'message' => 'UltraMsg connection successful. Instance status: ' . $instanceStatus
                ];
            } else {
                $errorMessage = $this->getUltraMsgErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'UltraMsg connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'UltramsgProvider';
?>