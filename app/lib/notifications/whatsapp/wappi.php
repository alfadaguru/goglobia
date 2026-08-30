<?php
// $requirements = [
//     'name' => 'Wappi.pro',
//     'fields' => ['profile_id', 'token', 'phone_number'],
//     'icon' => 'call'
// ];

class WappiProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://wappi.pro/api';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['profile_id']) || empty($this->config['token']) || empty($this->config['phone_number'])) {
                throw new Exception('Wappi.pro configuration incomplete. Profile ID, Token, and Phone Number are required.');
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
            error_log("Wappi.pro Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendWhatsAppMessage($to, $message) {
        $profileId = $this->config['profile_id'];
        $token = $this->config['token'];
        
        $url = $this->baseUrl . "/sync/message/send?profile_id={$profileId}";

        $postData = [
            'recipient' => $to,
            'body' => $message,
            'type' => 'text'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . $token
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
            if (isset($responseData['status']) && $responseData['status'] === 'success') {
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully via Wappi.pro',
                    'message_id' => $responseData['message_id'] ?? ''
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Wappi.pro API error: ' . ($responseData['message'] ?? 'Unknown error')
                ];
            }
        } else {
            $errorMessage = $this->getWappiErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "Wappi.pro API Error {$httpCode}: " . $errorMessage
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

    private function getWappiErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid token',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - Profile not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Wappi.pro Server Error'
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

        return 'Unknown Wappi.pro API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['profile_id']) || empty($this->config['token'])) {
                throw new Exception('Profile ID and Token are required for testing connection');
            }

            $profileId = $this->config['profile_id'];
            $token = $this->config['token'];
            
            $url = $this->baseUrl . "/sync/profile/get?profile_id={$profileId}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $token
                ],
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
                $profileStatus = $responseData['status'] ?? 'unknown';
                
                return [
                    'success' => true,
                    'message' => 'Wappi.pro connection successful. Profile status: ' . $profileStatus
                ];
            } else {
                $errorMessage = $this->getWappiErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Wappi.pro connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'WappiProvider';
?>